<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Martis\Auth\TwoFactorChallengeLockout;
use Martis\Auth\TwoFactorPass;

/*
 * The 2FA challenge guards a 6-digit code, and was limited like the login
 * (20 a minute per user, no ceiling): someone who holds a user's password
 * could keep guessing for as long as they liked. It has a limiter of its own
 * (per user and per IP, tighter) and a lockout: after consecutive wrong codes
 * the pending session ends and the challenge refuses every code for a while.
 */

const TFL_SECRET = 'JBSWY3DPEHPK3PXP';
const TFL_PASSWORD = 'secret-pass-123';

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

function tflUser(string $email): User
{
    /** @var User $user */
    $user = User::forceCreate([
        'name' => ucfirst(explode('@', $email)[0]),
        'email' => $email,
        'password' => bcrypt(TFL_PASSWORD),
        'two_factor_secret' => encrypt(TFL_SECRET),
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => encrypt(json_encode([bcrypt('recovery-code-one')])),
    ]);

    return $user;
}

/** The valid codes now (the window is one step either side). */
function tflValidCodes(): array
{
    $step = (int) floor(now()->getTimestamp() / 30);

    return array_map(fn (int $offset): string => tfpTotp(TFL_SECRET, $step + $offset), [-1, 0, 1]);
}

/** A 6-digit code no step of the window accepts. */
function tflWrongCode(): string
{
    $valid = tflValidCodes();

    foreach (range(0, 999999) as $candidate) {
        $code = str_pad((string) $candidate, 6, '0', STR_PAD_LEFT);
        if (! in_array($code, $valid, true)) {
            return $code;
        }
    }
}

/** A user who signed in with their password and is at the challenge. */
function tflPending(User $user): void
{
    test()->actingAs($user);
}

function tflChallenge(string $code, bool $recovery = false): TestResponse
{
    return test()->postJson('/martis/api/2fa/challenge', ['code' => $code, 'use_recovery_code' => $recovery]);
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

    config()->set('auth.providers.users.model', User::class);
    cache()->flush();
});

// ── The limiter ─────────────────────────────────────────────────────────────

it('gives the challenge a limiter of its own, tighter than the login throttle', function () {
    $user = tflUser('a@example.com');
    auth()->guard()->setUser($user);

    $request = request()->create('/martis/api/2fa/challenge', 'POST');
    $request->server->set('REMOTE_ADDR', '198.51.100.7');

    /** @var array<int, Limit> $limits */
    $limits = (array) RateLimiter::limiter('martis-2fa-challenge')($request);

    expect($limits)->toHaveCount(2)
        ->and($limits[0]->key)->toContain('ip|198.51.100.7')
        // The keys name the Martis guard: user 5 of an `admins` guard is not user 5 of the site's.
        ->and($limits[1]->key)->toContain('|web|user|'.$user->getKey())
        ->and($limits[1]->maxAttempts)->toBe(5)
        ->and($limits[1]->maxAttempts)->toBeLessThan((int) config('martis.throttle.login_attempts'))
        ->and($limits[0]->maxAttempts)->toBe(15);

    config()->set('martis.throttle.two_factor_attempts', 3);
    config()->set('martis.throttle.two_factor_ip_attempts', 9);
    config()->set('martis.throttle.two_factor_minutes', 2);
    $limits = (array) RateLimiter::limiter('martis-2fa-challenge')($request);

    expect($limits[1]->maxAttempts)->toBe(3)
        ->and($limits[0]->maxAttempts)->toBe(9)
        ->and($limits[1]->decaySeconds)->toBe(120);
});

it('runs the challenge route behind that limiter and not behind the login throttle', function () {
    $throttles = array_values(array_filter(
        app('router')->getRoutes()->getByName('martis.api.2fa.challenge')->gatherMiddleware(),
        static fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, 'throttle:'),
    ));

    expect($throttles)->toBe(['throttle:120,1,martis-api:web:', 'throttle:martis-2fa-challenge']);
});

it('answers 429 past the per-user limit, even to the right code, without checking it', function () {
    config()->set('martis.throttle.two_factor_lockout_attempts', 0);
    config()->set('martis.throttle.two_factor_attempts', 3);
    $user = tflUser('a@example.com');
    tflPending($user);

    foreach (range(1, 3) as $ignored) {
        tflChallenge(tflWrongCode())->assertUnprocessable();
    }

    tflChallenge(tflValidCodes()[1])->assertStatus(429);
    expect(session(TwoFactorPass::SESSION_KEY))->toBeNull();
});

it('counts the limit per user: another user at the same IP still has their attempts', function () {
    config()->set('martis.throttle.two_factor_lockout_attempts', 0);
    config()->set('martis.throttle.two_factor_attempts', 2);
    $a = tflUser('a@example.com');
    $b = tflUser('b@example.com');

    tflPending($a);
    tflChallenge(tflWrongCode())->assertUnprocessable();
    tflChallenge(tflWrongCode())->assertUnprocessable();
    tflChallenge(tflWrongCode())->assertStatus(429);

    tflPending($b);
    tflChallenge(tflValidCodes()[1])->assertOk();
});

it('counts the limit per IP: one machine guessing at several users is stopped', function () {
    config()->set('martis.throttle.two_factor_lockout_attempts', 0);
    config()->set('martis.throttle.two_factor_ip_attempts', 3);
    $users = [tflUser('a@example.com'), tflUser('b@example.com'), tflUser('c@example.com'), tflUser('d@example.com')];

    foreach (array_slice($users, 0, 3) as $user) {
        tflPending($user);
        tflChallenge(tflWrongCode())->assertUnprocessable();
    }

    tflPending($users[3]);
    tflChallenge(tflValidCodes()[1])->assertStatus(429);

    // A different machine is not affected.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50']);
    tflChallenge(tflValidCodes()[1])->assertOk();
});

// ── The lockout ─────────────────────────────────────────────────────────────

describe('lockout', function () {
    beforeEach(function () {
        // Only the lockout can answer here.
        config()->set('martis.throttle.two_factor_attempts', 1000);
        config()->set('martis.throttle.two_factor_ip_attempts', 1000);
    });

    it('ends the pending session on the wrong code that reaches the limit, and says so', function () {
        Log::spy();
        $user = tflUser('a@example.com');
        tflPending($user);

        foreach (range(1, 4) as $ignored) {
            tflChallenge(tflWrongCode())->assertUnprocessable()->assertJsonMissingPath('two_factor_locked');
        }

        $locked = tflChallenge(tflWrongCode());
        $locked->assertForbidden()
            ->assertJson(['two_factor_locked' => true, 'message' => __('martis::profile.2fa_challenge_locked')]);

        auth()->forgetGuards();
        $this->assertGuest();
        $this->getJson('/martis/api/_meta/guards')->assertUnauthorized();
        Log::shouldHaveReceived('warning')->once();
    });

    it('refuses the right code, the recovery codes too, until the lockout has passed', function () {
        $user = tflUser('a@example.com');
        tflPending($user);
        foreach (range(1, 5) as $ignored) {
            tflChallenge(tflWrongCode());
        }

        // The user signs in again with the password: the challenge stays shut.
        $this->postJson('/martis/api/auth/login', ['email' => 'a@example.com', 'password' => TFL_PASSWORD])
            ->assertOk()->assertJson(['two_factor_required' => true]);
        tflChallenge(tflValidCodes()[1])->assertForbidden()->assertJson(['two_factor_locked' => true]);
        expect(session(TwoFactorPass::SESSION_KEY))->toBeNull();

        $this->postJson('/martis/api/auth/login', ['email' => 'a@example.com', 'password' => TFL_PASSWORD])->assertOk();
        tflChallenge('recovery-code-one', recovery: true)->assertForbidden();

        // After the lockout (15 minutes) a fresh sign-in and the right code get in.
        $this->travel(16)->minutes();
        $this->postJson('/martis/api/auth/login', ['email' => 'a@example.com', 'password' => TFL_PASSWORD])->assertOk();
        tflChallenge(tflValidCodes()[1])->assertOk();
    });

    it('does not start over with a new session: the count belongs to the user', function () {
        $user = tflUser('a@example.com');

        foreach (range(1, 4) as $ignored) {
            tflPending($user);
            tflChallenge(tflWrongCode())->assertUnprocessable();
            // A new sign-in, as someone who holds the password does.
            $this->postJson('/martis/api/auth/login', ['email' => 'a@example.com', 'password' => TFL_PASSWORD])->assertOk();
        }

        // The fifth wrong code of four sessions locks the user out.
        tflChallenge(tflWrongCode())->assertForbidden();
    });

    it('clears the count with the right code', function () {
        $user = tflUser('a@example.com');
        tflPending($user);

        foreach (range(1, 4) as $ignored) {
            tflChallenge(tflWrongCode())->assertUnprocessable();
        }
        tflChallenge(tflValidCodes()[1])->assertOk();

        // Four more wrong ones are fine again: the run started over.
        foreach (range(1, 4) as $ignored) {
            tflChallenge(tflWrongCode())->assertUnprocessable();
        }
        tflChallenge(tflWrongCode())->assertForbidden();
    });

    it('counts a wrong recovery code like a wrong TOTP code', function () {
        $user = tflUser('a@example.com');
        tflPending($user);

        foreach (range(1, 4) as $ignored) {
            tflChallenge('not-a-recovery-code', recovery: true)->assertUnprocessable();
        }

        tflChallenge('not-a-recovery-code', recovery: true)->assertForbidden();
    });

    it('locks out the user who failed, not the others', function () {
        $a = tflUser('a@example.com');
        $b = tflUser('b@example.com');
        tflPending($a);
        foreach (range(1, 5) as $ignored) {
            tflChallenge(tflWrongCode());
        }

        expect(TwoFactorChallengeLockout::locked($a))->toBeTrue()
            ->and(TwoFactorChallengeLockout::locked($b))->toBeFalse();

        tflPending($b);
        tflChallenge(tflValidCodes()[1])->assertOk();
    });

    it('can be turned off, and takes its limits from config', function () {
        config()->set('martis.throttle.two_factor_lockout_attempts', 0);
        $user = tflUser('a@example.com');
        tflPending($user);

        foreach (range(1, 12) as $ignored) {
            tflChallenge(tflWrongCode())->assertUnprocessable();
        }

        config()->set('martis.throttle.two_factor_lockout_attempts', 2);
        cache()->flush();
        tflChallenge(tflWrongCode())->assertUnprocessable();
        tflChallenge(tflWrongCode())->assertForbidden();
    });
});
