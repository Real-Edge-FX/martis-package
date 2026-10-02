<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Martis\Auth\MagicLinkService;
use Martis\Auth\TwoFactorPass;
use Martis\Impersonation\ImpersonationManager;

/*
 * The 2FA pass names the user who earned it (TwoFactorPass) and every sign-in
 * of the Martis guard forgets it (ResetTwoFactorPass). Before, the session
 * held a bare `martis_two_factor_passed` flag: a panel user who passed 2FA on
 * their own account kept it across a sign-in as someone else through
 * POST /login, a magic link, SSO or an invitation, and landed in the victim's
 * account with the challenge considered passed.
 */

const TFP_SECRET = 'JBSWY3DPEHPK3PXP';
const TFP_PASSWORD = 'secret-pass-123';

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

function tfpUser(string $email, bool $twoFactor = true): User
{
    /** @var User $user */
    $user = User::forceCreate([
        'name' => ucfirst(explode('@', $email)[0]),
        'email' => $email,
        'password' => bcrypt(TFP_PASSWORD),
        'two_factor_secret' => $twoFactor ? encrypt(TFP_SECRET) : null,
        'two_factor_confirmed_at' => $twoFactor ? now() : null,
    ]);

    return $user;
}

/** The pass the session of this user holds after the challenge. */
function tfpPass(User $user): array
{
    return [TwoFactorPass::SESSION_KEY => (string) $user->getKey()];
}

/** A request every panel user may make: only the 2FA gate can refuse it. */
function tfpProbe(): TestResponse
{
    return test()->getJson('/martis/api/_meta/guards');
}

/** The next request reads the guard from the session, as a new process would. */
function tfpNextRequest(): void
{
    auth()->forgetGuards();
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
});

it('does not carry the pass of one user to another through POST /login', function () {
    $a = tfpUser('a@example.com');
    $b = tfpUser('b@example.com');

    $this->actingAs($a)->withSession(tfpPass($a));
    tfpProbe()->assertOk();

    // The attacker posts the victim's credentials from the same browser session.
    $this->post('/martis/login', ['email' => 'b@example.com', 'password' => TFP_PASSWORD])->assertRedirect();
    $this->assertAuthenticatedAs($b);

    tfpNextRequest();
    tfpProbe()->assertStatus(423)->assertJson(['two_factor_required' => true]);
    expect(session(TwoFactorPass::SESSION_KEY))->toBeNull();
});

it('asks the same user for the challenge again after a new POST /login', function () {
    $a = tfpUser('a@example.com');

    $this->actingAs($a)->withSession(tfpPass($a));
    tfpProbe()->assertOk();

    $this->post('/martis/login', ['email' => 'a@example.com', 'password' => TFP_PASSWORD])->assertRedirect();

    tfpNextRequest();
    tfpProbe()->assertStatus(423);
});

it('does not carry the pass of one user to another through POST /api/auth/login', function () {
    $a = tfpUser('a@example.com');
    tfpUser('b@example.com');

    $this->actingAs($a)->withSession(tfpPass($a));

    $this->postJson('/martis/api/auth/login', ['email' => 'b@example.com', 'password' => TFP_PASSWORD])
        ->assertOk()
        ->assertJson(['two_factor_required' => true]);

    tfpNextRequest();
    tfpProbe()->assertStatus(423);
    $this->getJson('/martis/api/auth/user')->assertOk()->assertJson(['two_factor_pending' => true]);
});

it('does not honour the pass of another user, whichever way the session got it', function () {
    $a = tfpUser('a@example.com');
    $b = tfpUser('b@example.com');

    // No sign-in in between: the session just holds A's pass while B is signed in.
    $this->actingAs($b)->withSession(tfpPass($a));

    tfpProbe()->assertStatus(423);
    $this->getJson('/martis/api/auth/user')->assertOk()->assertJson(['two_factor_pending' => true]);
});

it('does not honour the bare flag the pass had before it named a user', function () {
    $a = tfpUser('a@example.com');

    $this->actingAs($a)->withSession(['martis_two_factor_passed' => true]);

    tfpProbe()->assertStatus(423);
    $this->getJson('/martis/api/auth/user')->assertOk()->assertJson(['two_factor_pending' => true]);
});

it('honours the pass of the user who earned it, on the panel API and the user endpoint', function () {
    $a = tfpUser('a@example.com');

    $this->actingAs($a)->withSession(tfpPass($a));

    tfpProbe()->assertOk();
    $this->getJson('/martis/api/auth/user')->assertOk()->assertJsonMissingPath('two_factor_pending')->assertJsonPath('email', 'a@example.com');
});

it('records the pass of the user who completes the challenge, and only theirs', function () {
    tfpUser('a@example.com');
    $b = tfpUser('b@example.com');

    $this->postJson('/martis/api/auth/login', ['email' => 'a@example.com', 'password' => TFP_PASSWORD])
        ->assertJson(['two_factor_required' => true]);
    tfpProbe()->assertStatus(423);

    $step = (int) floor(time() / 30);
    $this->postJson('/martis/api/2fa/challenge', ['code' => tfpTotp(TFP_SECRET, $step)])->assertOk();
    tfpProbe()->assertOk();

    // The same browser session now signs in as B: A's pass goes with A.
    $this->postJson('/martis/api/auth/login', ['email' => 'b@example.com', 'password' => TFP_PASSWORD])
        ->assertJson(['two_factor_required' => true]);
    tfpNextRequest();
    tfpProbe()->assertStatus(423);

    $this->postJson('/martis/api/2fa/challenge', ['code' => tfpTotp(TFP_SECRET, $step)])->assertOk();
    expect(session(TwoFactorPass::SESSION_KEY))->toBe((string) $b->getKey());
});

it('forgets the pass on every sign-in of the Martis guard, whichever route made it', function () {
    $a = tfpUser('a@example.com');
    $b = tfpUser('b@example.com');

    app('session.store')->put(TwoFactorPass::SESSION_KEY, (string) $a->getKey());

    // Any route that signs a user in fires Login: the controllers, a
    // magic link, SSO, an invitation, the remember-me cookie, impersonation.
    Auth::guard()->login($b);

    expect(app('session.store')->get(TwoFactorPass::SESSION_KEY))->toBeNull();
});

it('leaves the pass alone when another guard signs in', function () {
    $a = tfpUser('a@example.com');
    app('session.store')->put(TwoFactorPass::SESSION_KEY, (string) $a->getKey());

    Event::dispatch(new Login('some-other-guard', $a, false));

    expect(app('session.store')->get(TwoFactorPass::SESSION_KEY))->toBe((string) $a->getKey());
});

// ── The other sign-in routes ────────────────────────────────────────────────

it('does not carry the pass of one user to another through a magic link', function () {
    config()->set('martis.auth.magic_link.enabled', true);
    Schema::dropIfExists('password_reset_tokens');
    Schema::create('password_reset_tokens', function ($t) {
        $t->string('email')->primary();
        $t->string('token');
        $t->timestamp('created_at')->nullable();
    });

    $a = tfpUser('a@example.com');
    $b = tfpUser('b@example.com');
    $token = app(MagicLinkService::class)->issue('b@example.com');

    $this->actingAs($a)->withSession(tfpPass($a));
    $this->get('/martis/api/auth/magic-link/consume?'.http_build_query(['email' => 'b@example.com', 'token' => $token]))
        ->assertRedirect('/martis');
    $this->assertAuthenticatedAs($b);

    tfpNextRequest();
    tfpProbe()->assertStatus(423);
});

// ── Impersonation ───────────────────────────────────────────────────────────

describe('impersonation', function () {
    beforeEach(function () {
        config()->set('martis.impersonation.enabled', true);
        Gate::define('martis-impersonate', fn () => true);
    });

    it('hands the pass of the operator to the target while it lasts, and back on stop', function () {
        $operator = tfpUser('operator@example.com');
        $target = tfpUser('target@example.com');

        $this->actingAs($operator)->withSession(tfpPass($operator));
        $this->postJson('/martis/api/impersonation/start/'.$target->getKey())->assertOk();

        // The target has 2FA and the operator holds no code of theirs: the
        // pass crossed with the switch, and names the target now.
        expect(session(TwoFactorPass::SESSION_KEY))->toBe((string) $target->getKey());
        tfpProbe()->assertOk();

        $this->postJson('/martis/api/impersonation/stop')->assertOk()->assertJson(['active' => false]);

        expect(session(TwoFactorPass::SESSION_KEY))->toBe((string) $operator->getKey());
        tfpProbe()->assertOk();
    });

    it('hands over only a pass the operator earned', function () {
        $operator = tfpUser('operator@example.com');
        $target = tfpUser('target@example.com');

        // An operator the challenge has not cleared (a programmatic start,
        // outside the 2FA middleware) cannot clear the target's.
        $this->actingAs($operator);
        Auth::guard()->setUser($operator);
        app(ImpersonationManager::class)->start($target);

        expect(session(TwoFactorPass::SESSION_KEY))->toBeNull();
        tfpProbe()->assertStatus(423);

        app(ImpersonationManager::class)->stop();
        expect(session(TwoFactorPass::SESSION_KEY))->toBeNull();
    });
});
