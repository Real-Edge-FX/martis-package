<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Martis\Profile\TwoFactorService;

/*
 * A TOTP code is single-use. The replay guard recorded the wall-clock time of
 * the acceptance and derived the last step from it, so a code accepted for
 * the step after the current one (a client clock running ahead, a code seen
 * before it was current) was accepted again while that step was current; and
 * both the guard and the consumption of a recovery code were a read followed
 * by a save, so two requests carrying one code at once both passed. The step
 * start is recorded now, the update is conditional, and a recovery code is
 * consumed under a row lock.
 */

const TFR2_SECRET = 'JBSWY3DPEHPK3PXP';

/** A user model with no cast on the replay column, as the app's default User. */
class ReplayPlainUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

/** The same table, the replay column cast to a datetime. */
class ReplayCastUser extends User
{
    protected $table = 'users';

    protected $guarded = [];

    protected $casts = ['two_factor_last_used_at' => 'datetime'];
}

if (! function_exists('tfpTotp')) {
    /** The RFC 6238 code (SHA1, 6 digits, 30 s) of a base32 secret for a time step. */
    function tfpTotp(string $secret, int $step): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split(strtoupper($secret)) as $char) {
            $bits .= str_pad(decbin((int) strpos($alphabet, $char)), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $key .= chr(bindec($byte));
            }
        }

        $hash = hash_hmac('sha1', pack('N*', 0).pack('N*', $step), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = (((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3])) % 1000000;

        return str_pad((string) $value, 6, '0', STR_PAD_LEFT);
    }
}

/** The step that starts at `$step * 30`; time is `$seconds` into it. */
function tfr2At(int $step, int $seconds = 5): void
{
    Carbon::setTestNow(Carbon::createFromTimestamp($step * 30 + $seconds));
}

function tfr2Schema(bool $withReplayColumn = true): void
{
    Schema::dropIfExists('users');
    Schema::create('users', function ($table) use ($withReplayColumn) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->text('two_factor_secret')->nullable();
        $table->text('two_factor_recovery_codes')->nullable();
        $table->timestamp('two_factor_confirmed_at')->nullable();
        if ($withReplayColumn) {
            $table->timestamp('two_factor_last_used_at')->nullable();
        }
        $table->rememberToken();
        $table->timestamps();
    });
}

/** @param  class-string<User>  $model */
function tfr2User(string $model = ReplayPlainUser::class, array $recoveryCodes = []): User
{
    return $model::forceCreate([
        'name' => 'Replay',
        'email' => 'replay@example.com',
        'password' => bcrypt('password'),
        'two_factor_secret' => encrypt(TFR2_SECRET),
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => $recoveryCodes === [] ? null : encrypt(json_encode(array_map(fn (string $c) => bcrypt($c), $recoveryCodes))),
    ]);
}

afterEach(function () {
    Carbon::setTestNow();
});

// ── TOTP: the consumed step ─────────────────────────────────────────────────

it('refuses the code of the next step again once that step is current', function (string $model) {
    tfr2Schema();
    $user = tfr2User($model);
    $service = app(TwoFactorService::class);
    $step = 58_000_000;

    // A client clock running ahead: the code of the next step, sent early.
    tfr2At($step, 5);
    $early = tfpTotp(TFR2_SECRET, $step + 1);
    expect($service->verifyForUser($user, $early))->toBeTrue();

    // That step is now current: the same code is a replay. (The recorded wall clock
    // fell in the previous step, so it used to be accepted a second time.)
    tfr2At($step + 1, 5);
    expect($service->verifyForUser($model::find($user->getKey()), $early))->toBeFalse()
        ->and($service->verifyForUser($model::find($user->getKey()), tfpTotp(TFR2_SECRET, $step + 1)))->toBeFalse();

    // The step after it is still open.
    expect($service->verifyForUser($model::find($user->getKey()), tfpTotp(TFR2_SECRET, $step + 2)))->toBeTrue();
})->with(['plain model' => [ReplayPlainUser::class], 'datetime cast' => [ReplayCastUser::class]]);

it('records the start of the step, not the time of the acceptance', function (string $model) {
    tfr2Schema();
    $user = tfr2User($model);
    $step = 58_000_000;

    tfr2At($step, 17);
    expect(app(TwoFactorService::class)->verifyForUser($user, tfpTotp(TFR2_SECRET, $step)))->toBeTrue();

    $stored = DB::table('users')->where('id', $user->id)->value('two_factor_last_used_at');
    expect(Carbon::parse($stored)->getTimestamp())->toBe($step * 30)
        // and the instance of the request follows the row
        ->and(Carbon::parse((string) $user->getAttribute('two_factor_last_used_at'))->getTimestamp())->toBe($step * 30);
})->with(['plain model' => [ReplayPlainUser::class], 'datetime cast' => [ReplayCastUser::class]]);

it('refuses a second request that carries the same code at once', function (string $model) {
    tfr2Schema();
    $user = tfr2User($model);
    $service = app(TwoFactorService::class);
    $step = 58_000_000;
    tfr2At($step, 5);
    $code = tfpTotp(TFR2_SECRET, $step);

    // Two requests loaded the user before either recorded the step.
    $first = $model::find($user->getKey());
    $second = $model::find($user->getKey());
    expect($first->two_factor_last_used_at)->toBeNull()->and($second->two_factor_last_used_at)->toBeNull();

    expect($service->verifyForUser($first, $code))->toBeTrue()
        ->and($service->verifyForUser($second, $code))->toBeFalse();
})->with(['plain model' => [ReplayPlainUser::class], 'datetime cast' => [ReplayCastUser::class]]);

it('never lets an older step overwrite a newer one a concurrent request recorded', function () {
    tfr2Schema();
    $user = tfr2User();
    $service = app(TwoFactorService::class);
    $step = 58_000_000;
    tfr2At($step, 5);

    $slow = ReplayPlainUser::find($user->getKey());
    $fast = ReplayPlainUser::find($user->getKey());

    // The fast request is accepted for the newer step; the slow one still reads null.
    expect($service->verifyForUser($fast, tfpTotp(TFR2_SECRET, $step + 1)))->toBeTrue()
        ->and($service->verifyForUser($slow, tfpTotp(TFR2_SECRET, $step)))->toBeFalse();

    expect(Carbon::parse(DB::table('users')->where('id', $user->id)->value('two_factor_last_used_at'))->getTimestamp())->toBe(($step + 1) * 30);
});

it('still accepts a fresh code after a recorded one, and refuses the one recorded', function () {
    tfr2Schema();
    $user = tfr2User();
    $service = app(TwoFactorService::class);
    $step = 58_000_000;

    tfr2At($step, 5);
    expect($service->verifyForUser($user, tfpTotp(TFR2_SECRET, $step)))->toBeTrue();
    expect($service->verifyForUser(ReplayPlainUser::find($user->id), tfpTotp(TFR2_SECRET, $step)))->toBeFalse();

    tfr2At($step + 1, 5);
    expect($service->verifyForUser(ReplayPlainUser::find($user->id), tfpTotp(TFR2_SECRET, $step + 1)))->toBeTrue();
});

it('refuses a wrong or malformed code and records nothing', function () {
    tfr2Schema();
    $user = tfr2User();
    $service = app(TwoFactorService::class);
    tfr2At(58_000_000, 5);

    foreach (['000000', '12345', '1234567', 'abcdef', ''] as $code) {
        // '000000' could be right once in a million: skip that unlikely collision.
        if (in_array($code, [tfpTotp(TFR2_SECRET, 57_999_999), tfpTotp(TFR2_SECRET, 58_000_000), tfpTotp(TFR2_SECRET, 58_000_001)], true)) {
            continue;
        }
        expect($service->verifyForUser($user, $code))->toBeFalse();
    }

    expect(DB::table('users')->where('id', $user->id)->value('two_factor_last_used_at'))->toBeNull();
});

it('reads a value written before v2.4.0 (the wall clock of the acceptance) as the step it fell in', function () {
    tfr2Schema();
    $user = tfr2User();
    $step = 58_000_000;
    DB::table('users')->where('id', $user->id)->update(['two_factor_last_used_at' => Carbon::createFromTimestamp($step * 30 + 12)->toDateTimeString()]);
    $service = app(TwoFactorService::class);

    tfr2At($step, 20);
    expect($service->verifyForUser(ReplayPlainUser::find($user->id), tfpTotp(TFR2_SECRET, $step)))->toBeFalse()
        ->and($service->verifyForUser(ReplayPlainUser::find($user->id), tfpTotp(TFR2_SECRET, $step + 1)))->toBeTrue();
});

it('keeps accepting codes, with a warning, when the replay column is missing', function () {
    tfr2Schema(withReplayColumn: false);
    $user = tfr2User();
    Log::spy();
    $service = app(TwoFactorService::class);
    tfr2At(58_000_000, 5);
    $code = tfpTotp(TFR2_SECRET, 58_000_000);

    // Not failed closed: the code authenticates, as it did, and the warning says why it can be replayed.
    expect($service->verifyForUser($user, $code))->toBeTrue()
        ->and($service->verifyForUser($user, $code))->toBeTrue();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'two_factor_last_used_at'))
        ->once();
});

it('refuses a code when the step cannot be recorded, instead of authenticating through a guard that is off', function () {
    tfr2Schema();
    $user = tfr2User();
    tfr2At(58_000_000, 5);
    $service = app(TwoFactorService::class);

    // The column exists when probed and then the update fails.
    $service->verifyForUser($user, '000000');
    DB::statement('drop table users');
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->string('password');
        $table->text('two_factor_secret')->nullable();
        $table->timestamp('two_factor_confirmed_at')->nullable();
        $table->timestamps();
    });
    DB::table('users')->insert(['id' => $user->id, 'name' => 'x', 'email' => 'x@example.com', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);

    Exceptions::fake();
    expect($service->verifyForUser($user, tfpTotp(TFR2_SECRET, 58_000_000)))->toBeFalse();
    Exceptions::assertReportedCount(1);
});

// ── Recovery codes: single use under concurrency ────────────────────────────

it('consumes a recovery code once, whatever stale copy of the user asks', function () {
    tfr2Schema();
    $user = tfr2User(recoveryCodes: ['code-one', 'code-two', 'code-three']);
    $service = app(TwoFactorService::class);

    $first = ReplayPlainUser::find($user->id);
    $second = ReplayPlainUser::find($user->id);

    expect($service->verifyRecoveryCode($first, 'code-one'))->toBeTrue()
        // The second request read the list before the first consumed it.
        ->and($service->verifyRecoveryCode($second, 'code-one'))->toBeFalse();
});

it('does not bring a consumed recovery code back when another one is consumed from a stale copy', function () {
    tfr2Schema();
    $user = tfr2User(recoveryCodes: ['code-one', 'code-two', 'code-three']);
    $service = app(TwoFactorService::class);

    $first = ReplayPlainUser::find($user->id);
    $second = ReplayPlainUser::find($user->id);

    expect($service->verifyRecoveryCode($first, 'code-one'))->toBeTrue()
        ->and($service->verifyRecoveryCode($second, 'code-two'))->toBeTrue();

    // A save of the stale list would have written back code-one.
    $fresh = ReplayPlainUser::find($user->id);
    expect($service->verifyRecoveryCode($fresh, 'code-one'))->toBeFalse()
        ->and($service->verifyRecoveryCode($fresh, 'code-two'))->toBeFalse()
        ->and($service->verifyRecoveryCode($fresh, 'code-three'))->toBeTrue();
});

it('refuses a recovery code consumed by regenerating the set underneath it', function () {
    tfr2Schema();
    $user = tfr2User(recoveryCodes: ['code-one', 'code-two']);
    $service = app(TwoFactorService::class);

    $stale = ReplayPlainUser::find($user->id);
    $service->regenerateRecoveryCodes(ReplayPlainUser::find($user->id));

    expect($service->verifyRecoveryCode($stale, 'code-one'))->toBeFalse();
});

it('reads and rewrites the recovery codes inside one transaction, under a row lock', function () {
    tfr2Schema();
    $user = tfr2User(recoveryCodes: ['code-one', 'code-two']);
    $service = app(TwoFactorService::class);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = [$query->sql, DB::transactionLevel()];
    });

    expect($service->verifyRecoveryCode(ReplayPlainUser::find($user->id), 'code-two'))->toBeTrue();

    // The locking read and the update of the codes both ran in the transaction.
    $inTransaction = array_filter($queries, fn (array $q): bool => $q[1] >= 1);
    expect(array_map(fn (array $q): string => $q[0], $inTransaction))->toContain(
        'select * from "users" where "users"."id" = ? limit 1',
    );
    expect(collect($inTransaction)->contains(fn (array $q): bool => str_starts_with($q[0], 'update "users" set "two_factor_recovery_codes"')))->toBeTrue();
});

it('refuses an unknown recovery code and keeps the set', function () {
    tfr2Schema();
    $user = tfr2User(recoveryCodes: ['code-one']);
    $service = app(TwoFactorService::class);
    $before = DB::table('users')->where('id', $user->id)->value('two_factor_recovery_codes');

    expect($service->verifyRecoveryCode(ReplayPlainUser::find($user->id), 'not-a-code'))->toBeFalse()
        ->and(DB::table('users')->where('id', $user->id)->value('two_factor_recovery_codes'))->toBe($before);
});

// ── Through the challenge ───────────────────────────────────────────────────

it('answers the second challenge with the same code with 422, across sessions', function () {
    tfr2Schema();
    config()->set('auth.providers.users.model', ReplayPlainUser::class);
    $user = tfr2User();
    $step = (int) floor(time() / 30);
    $code = tfpTotp(TFR2_SECRET, $step);

    $this->actingAs($user);
    $this->postJson('/martis/api/2fa/challenge', ['code' => $code])->assertOk();

    // Another sign-in, another session, the same code: a replay.
    $this->flushSession();
    $this->actingAs(ReplayPlainUser::find($user->id));
    $this->postJson('/martis/api/2fa/challenge', ['code' => $code])->assertUnprocessable();
});
