<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Martis\Auth\RecoveryCodesRegeneratedNotification;
use Martis\Auth\TwoFactorPass;
use Martis\Profile\TwoFactorService;

/*
 * Recovery codes stand in for the TOTP factor at the challenge, so
 * regenerating them is as sensitive as disabling 2FA: it needs the current
 * password, the user is told by email, and neither it nor the other
 * factor- or identity-changing profile endpoints (2FA setup, confirm,
 * disable, the password change) answer while an operator impersonates the
 * user.
 */

/** A user model as an app ships it: Notifiable, so the owner is mailed. */
class TfrNotifiableUser extends User
{
    use Notifiable;

    protected $table = 'users';
}

const TFR_PASSWORD = 'Current-Password-1';

function tfrUser(string $email, bool $twoFactor = true): TfrNotifiableUser
{
    $codes = ['old-code-one', 'old-code-two'];

    /** @var TfrNotifiableUser $user */
    $user = TfrNotifiableUser::forceCreate([
        'name' => ucfirst(explode('@', $email)[0]),
        'email' => $email,
        'password' => Hash::make(TFR_PASSWORD),
        'two_factor_secret' => $twoFactor ? encrypt('JBSWY3DPEHPK3PXP') : null,
        'two_factor_confirmed_at' => $twoFactor ? now() : null,
        'two_factor_recovery_codes' => $twoFactor ? encrypt(json_encode(array_map(fn (string $c) => bcrypt($c), $codes))) : null,
    ]);

    return $user;
}

function tfrSignedIn(User $user): void
{
    test()->actingAs($user)->withSession([TwoFactorPass::SESSION_KEY => (string) $user->getKey()]);
}

function tfrStoredCodes(User $user): ?string
{
    return $user->newQuery()->whereKey($user->getKey())->value('two_factor_recovery_codes');
}

beforeEach(function () {
    Schema::dropIfExists('users');
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->text('two_factor_secret')->nullable();
        $table->text('two_factor_recovery_codes')->nullable();
        $table->timestamp('two_factor_confirmed_at')->nullable();
        $table->timestamp('two_factor_last_used_at')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });

    config()->set('auth.providers.users.model', TfrNotifiableUser::class);
});

// ── Regenerating the recovery codes ─────────────────────────────────────────

it('refuses to regenerate the recovery codes without the current password', function (array $payload) {
    Notification::fake();
    $user = tfrUser('owner@example.com');
    $before = tfrStoredCodes($user);
    tfrSignedIn($user);

    $this->postJson('/martis/api/profile/2fa/recovery-codes', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password']);

    expect(tfrStoredCodes($user))->toBe($before);
    Notification::assertNothingSent();
})->with([
    'no body' => [[]],
    'empty password' => [['current_password' => '']],
    'wrong password' => [['current_password' => 'not-the-password']],
]);

it('regenerates the recovery codes with the current password and hands over the new ones', function () {
    Notification::fake();
    $user = tfrUser('owner@example.com');
    $before = tfrStoredCodes($user);
    tfrSignedIn($user);

    $response = $this->postJson('/martis/api/profile/2fa/recovery-codes', ['current_password' => TFR_PASSWORD])
        ->assertOk()
        ->assertJsonStructure(['recovery_codes']);

    $codes = $response->json('recovery_codes');
    expect($codes)->toHaveCount((int) config('martis.profile.two_factor.recovery_codes', 8))
        ->and(tfrStoredCodes($user))->not->toBe($before);

    $service = app(TwoFactorService::class);
    $fresh = $user->fresh();
    expect($service->verifyRecoveryCode($fresh, 'old-code-one'))->toBeFalse()
        ->and($service->verifyRecoveryCode($fresh, $codes[0]))->toBeTrue();
});

it('tells the owner by email that the recovery codes were regenerated', function () {
    Notification::fake();
    $user = tfrUser('owner@example.com');
    tfrSignedIn($user);

    $this->postJson('/martis/api/profile/2fa/recovery-codes', ['current_password' => TFR_PASSWORD])->assertOk();

    Notification::assertSentToTimes($user, RecoveryCodesRegeneratedNotification::class, 1);
});

it('mails the address of the account when the user model is not Notifiable', function () {
    Notification::fake();
    config()->set('auth.providers.users.model', User::class);
    $user = User::forceCreate([
        'name' => 'Plain',
        'email' => 'plain@example.com',
        'password' => Hash::make(TFR_PASSWORD),
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => encrypt(json_encode([bcrypt('x')])),
    ]);
    tfrSignedIn($user);

    $this->postJson('/martis/api/profile/2fa/recovery-codes', ['current_password' => TFR_PASSWORD])->assertOk();

    Notification::assertSentOnDemand(
        RecoveryCodesRegeneratedNotification::class,
        fn (RecoveryCodesRegeneratedNotification $n, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'plain@example.com',
    );
});

it('regenerates the codes and answers 200 when the mail cannot be sent', function () {
    Exceptions::fake();
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('smtp is down'));
    $user = tfrUser('owner@example.com');
    $before = tfrStoredCodes($user);
    tfrSignedIn($user);

    $this->postJson('/martis/api/profile/2fa/recovery-codes', ['current_password' => TFR_PASSWORD])
        ->assertOk()
        ->assertJsonStructure(['recovery_codes']);

    expect(tfrStoredCodes($user))->not->toBe($before);
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'smtp is down');
});

it('words the email in every shipped locale', function (string $locale, string $subject) {
    app()->setLocale($locale);
    config()->set('app.name', 'Acme');

    $mail = (new RecoveryCodesRegeneratedNotification)->toMail(new stdClass);

    expect($mail->subject)->toBe($subject)
        ->and($mail->greeting)->not->toStartWith('martis::')
        ->and(implode(' ', $mail->introLines))->not->toContain('martis::');
})->with([
    ['en', 'Your Acme recovery codes were regenerated'],
    ['pt_PT', 'Os códigos de recuperação de Acme foram regenerados'],
    ['pt_BR', 'Os códigos de recuperação de Acme foram regenerados'],
]);

// ── While an operator impersonates the user ─────────────────────────────────

describe('while impersonating', function () {
    beforeEach(function () {
        config()->set('martis.impersonation.enabled', true);
        Gate::define('martis-impersonate', fn () => true);
    });

    function tfrImpersonate(User $target): void
    {
        $operator = tfrUser('operator@example.com');
        tfrSignedIn($operator);
        test()->postJson('/martis/api/impersonation/start/'.$target->getKey())->assertOk();
    }

    it('refuses the recovery codes, the 2FA disable and the password change, and changes nothing', function () {
        Notification::fake();
        $target = tfrUser('target@example.com');
        $codes = tfrStoredCodes($target);
        $password = $target->password;
        tfrImpersonate($target);

        $refused = __('martis::profile.refused_while_impersonating');

        $this->postJson('/martis/api/profile/2fa/recovery-codes', ['current_password' => TFR_PASSWORD])
            ->assertForbidden()->assertJson(['message' => $refused]);
        $this->deleteJson('/martis/api/profile/2fa', ['current_password' => TFR_PASSWORD])
            ->assertForbidden()->assertJson(['message' => $refused]);
        $this->postJson('/martis/api/profile/password', [
            'current_password' => TFR_PASSWORD,
            'password' => 'Brand-New-Password-9',
            'password_confirmation' => 'Brand-New-Password-9',
        ])->assertForbidden()->assertJson(['message' => $refused]);

        $fresh = $target->fresh();
        expect(tfrStoredCodes($target))->toBe($codes)
            ->and($fresh->two_factor_confirmed_at)->not->toBeNull()
            ->and($fresh->two_factor_secret)->not->toBeNull()
            ->and($fresh->password)->toBe($password);
        Notification::assertNothingSent();
    });

    it('refuses the 2FA setup and confirmation, and changes nothing', function () {
        $target = tfrUser('target@example.com', twoFactor: false);
        tfrImpersonate($target);

        $this->postJson('/martis/api/profile/2fa/setup')->assertForbidden();
        $this->postJson('/martis/api/profile/2fa/confirm', ['code' => '123456'])->assertForbidden();

        $fresh = $target->fresh();
        expect($fresh->two_factor_secret)->toBeNull()
            ->and($fresh->two_factor_confirmed_at)->toBeNull()
            ->and($fresh->two_factor_recovery_codes)->toBeNull();
    });

    it('keeps the endpoints open to the user once the impersonation stopped', function () {
        Notification::fake();
        $target = tfrUser('target@example.com');
        tfrImpersonate($target);
        $this->postJson('/martis/api/impersonation/stop')->assertOk();

        // Back as the operator: their own second factor is theirs to manage.
        $this->postJson('/martis/api/profile/2fa/recovery-codes', ['current_password' => TFR_PASSWORD])->assertOk();
    });
});
