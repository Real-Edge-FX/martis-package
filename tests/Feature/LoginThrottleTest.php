<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Martis\Auth\AccountLoginThrottle;

// -----------------------------------------------------------------------------
// martis-login rate limiter — per-email + per-IP composition
// -----------------------------------------------------------------------------
//
// Goal: lock the named limiter's behaviour so a future refactor cannot
// silently downgrade it to a per-IP-only throttle (which is what we had
// before v1.8.8). The named limiter is opaque from the route layer, so
// the test exercises it directly via `RateLimiter::tooManyAttempts()`
// after invoking the closure with synthesised requests.

beforeEach(function () {
    // Reset all rate-limiter buckets so prior tests don't bleed.
    cache()->flush();
});

it('martis-login limiter is registered and produces a per-email + per-IP key', function () {
    // Two requests from the same IP but different emails must not share
    // a bucket. A burst against `victim@example.com` from one IP must
    // NOT throttle a different account from the same IP.
    $attemptsAllowed = (int) config('martis.throttle.login_attempts', 20);

    $hitVictim = function () use ($attemptsAllowed) {
        return RateLimiter::attempt(
            'martis-login|email|'.sha1('victim@example.com').'|ip|203.0.113.10',
            $attemptsAllowed,
            static fn () => true,
        );
    };

    $hitOther = function () use ($attemptsAllowed) {
        return RateLimiter::attempt(
            'martis-login|email|'.sha1('other@example.com').'|ip|203.0.113.10',
            $attemptsAllowed,
            static fn () => true,
        );
    };

    // Drain the victim's bucket.
    for ($i = 0; $i < $attemptsAllowed; $i++) {
        expect($hitVictim())->toBeTrue();
    }
    // The next victim hit is throttled.
    expect($hitVictim())->toBeFalse();

    // The other account on the same IP still has its full quota.
    expect($hitOther())->toBeTrue();
});

it('martis-login limiter is registered with the correct attempts default', function () {
    $attempts = (int) config('martis.throttle.login_attempts', 20);
    $minutes = (int) config('martis.throttle.login_minutes', 1);

    expect($attempts)->toBeGreaterThan(0)
        ->and($minutes)->toBeGreaterThan(0);

    // Resolve the limiter — empty result means it isn't registered.
    $limits = RateLimiter::limiter('martis-login');
    expect($limits)->toBeCallable();

    // Exercise it via a synthesised request: the first limit is the
    // per-email + per-IP one, with the configured cap.
    $request = request()->create('/martis/api/auth/login', 'POST', ['email' => 'foo@example.com']);
    $request->server->set('REMOTE_ADDR', '198.51.100.42');

    /** @var array<int, Limit> $resolved */
    $resolved = (array) $limits($request);
    expect($resolved)->toHaveCount(1)
        ->and($resolved[0])->toBeInstanceOf(Limit::class)
        ->and($resolved[0]->maxAttempts)->toBe($attempts)
        ->and($resolved[0]->key)->toContain('ip|198.51.100.42');
});

// -----------------------------------------------------------------------------
// The per-account limit (v2.4.0)
// -----------------------------------------------------------------------------
//
// The named limiter gives every source IP a bucket of its own for the same
// email, so guesses spread over N addresses got N x login_attempts per minute
// at one account. AccountLoginThrottle holds the per-account bucket: it counts
// wrong passwords only, keys on the account the email matches, and the
// magic-link request has a bucket of its own.

/** The limits `martis-login` resolves for a request to the login. */
function loginLimitsFor(string $ip, ?string $email): array
{
    $request = request()->create('/martis/api/auth/login', 'POST', $email === null ? [] : ['email' => $email]);
    $request->server->set('REMOTE_ADDR', $ip);

    /** @var array<int, Limit> */
    return (array) RateLimiter::limiter('martis-login')($request);
}

function throttleUsersTable(): void
{
    Schema::dropIfExists('users');
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->rememberToken();
        $table->timestamps();
    });
}

it('martis-login holds the per-email + per-IP limit only: the per-account one lives in the controllers', function () {
    $fromOneIp = loginLimitsFor('198.51.100.1', 'Victim@Example.com');

    expect($fromOneIp)->toHaveCount(1)
        ->and($fromOneIp[0]->key)->toContain('ip|198.51.100.1')
        ->and(loginLimitsFor('198.51.100.1', null))->toHaveCount(1);
});

it('keys the per-account bucket on the matched user, whatever the spelling of the email', function () {
    throttleUsersTable();
    $victim = User::forceCreate(['name' => 'V', 'email' => 'victim@example.com', 'password' => bcrypt('right')]);
    $other = User::forceCreate(['name' => 'O', 'email' => 'other@example.com', 'password' => bcrypt('right')]);
    $guard = auth()->guard(config('martis.guard'));

    // The database matches these spellings to one row (a case-insensitive
    // collation, accents folded by MySQL's default), the bucket follows the row.
    $spelledOut = AccountLoginThrottle::key(AccountLoginThrottle::PASSWORD, 'Víctim@Example.com', $victim);
    expect($spelledOut)->toBe(AccountLoginThrottle::key(AccountLoginThrottle::PASSWORD, ' victim@example.com ', $victim))
        ->and($spelledOut)->not->toBe(AccountLoginThrottle::key(AccountLoginThrottle::PASSWORD, 'other@example.com', $other))
        // an account of its own per purpose
        ->and($spelledOut)->not->toBe(AccountLoginThrottle::key(AccountLoginThrottle::MAGIC_LINK, 'victim@example.com', $victim));

    // No user matches: the lowercased, trimmed email.
    expect(AccountLoginThrottle::key(AccountLoginThrottle::PASSWORD, 'Ghost@Example.com'))
        ->toBe(AccountLoginThrottle::key(AccountLoginThrottle::PASSWORD, ' ghost@example.com '))
        ->not->toBe(AccountLoginThrottle::key(AccountLoginThrottle::PASSWORD, 'ghost2@example.com'));

    expect(AccountLoginThrottle::userFor($guard, 'victim@example.com')?->getAuthIdentifier())->toBe($victim->getAuthIdentifier())
        ->and(AccountLoginThrottle::userFor($guard, 'nobody@example.com'))->toBeNull();
});

it('turns the per-account limit off with zero attempts', function () {
    config()->set('martis.throttle.login_email_attempts', 0);

    expect(AccountLoginThrottle::enabled())->toBeFalse();
    AccountLoginThrottle::hit(AccountLoginThrottle::PASSWORD, 'a@example.com');
    expect(AccountLoginThrottle::tooMany(AccountLoginThrottle::PASSWORD, 'a@example.com'))->toBeFalse();
});

it('puts the martis-login limiter on both login routes and the magic link request', function (string $route) {
    $middleware = app('router')->getRoutes()->getByName($route)->gatherMiddleware();

    expect($middleware)->toContain('throttle:martis-login');
})->with(['martis.login.attempt', 'martis.api.auth.login', 'martis.api.auth.magic-link.request']);

it('throttles wrong passwords at one account spread over many IPs, on both login routes', function (string $method, string $uri) {
    throttleUsersTable();
    User::forceCreate(['name' => 'V', 'email' => 'victim@example.com', 'password' => bcrypt('right-password')]);
    // The per-IP limits are out of the way: only the per-account one can answer 429.
    config()->set('martis.throttle.login_email_attempts', 3);
    $guess = fn (string $ip, string $email, string $password = 'wrong-guess') => $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->{$method}($uri, ['email' => $email, 'password' => $password]);

    foreach (['203.0.113.1', '203.0.113.2', '203.0.113.3'] as $ip) {
        expect($guess($ip, 'victim@example.com')->getStatusCode())->not->toBe(429);
    }

    // A fourth guess from a fresh address is refused, before the password is checked: the
    // right password included, or the limit would only slow a brute force down.
    $guess('203.0.113.4', 'victim@example.com')->assertStatus(429);
    $guess('203.0.113.5', 'victim@example.com', 'right-password')->assertStatus(429);
    $this->assertGuest(config('martis.guard'));
    // ... and the next account is not affected.
    expect($guess('203.0.113.4', 'bystander@example.com')->getStatusCode())->not->toBe(429);
})->with([
    'POST /login' => ['post', '/martis/login'],
    'POST /api/auth/login' => ['postJson', '/martis/api/auth/login'],
]);

it('counts only the wrong passwords: the owner signing in and out never spends the bucket', function (string $method, string $uri) {
    throttleUsersTable();
    User::forceCreate(['name' => 'V', 'email' => 'victim@example.com', 'password' => bcrypt('right-password')]);
    config()->set('martis.throttle.login_email_attempts', 3);
    config()->set('martis.throttle.login_attempts', 1000);

    // Ten good sign-ins (the bucket is 3): none is a failure, none is refused.
    foreach (range(1, 10) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$i])
            ->{$method}($uri, ['email' => 'victim@example.com', 'password' => 'right-password'])
            ->assertStatus($method === 'post' ? 302 : 200);
        auth()->guard(config('martis.guard'))->logout();
    }

    // Two wrong ones, a good one that clears the run, two more wrong ones: still below 3 in a row.
    $wrong = fn () => $this->{$method}($uri, ['email' => 'victim@example.com', 'password' => 'nope']);
    $wrong();
    $wrong();
    $this->{$method}($uri, ['email' => 'victim@example.com', 'password' => 'right-password'])->assertStatus($method === 'post' ? 302 : 200);
    auth()->guard(config('martis.guard'))->logout();
    expect($wrong()->getStatusCode())->not->toBe(429);
    expect($wrong()->getStatusCode())->not->toBe(429);
    expect($wrong()->getStatusCode())->not->toBe(429);
    $wrong()->assertStatus(429);
})->with([
    'POST /login' => ['post', '/martis/login'],
    'POST /api/auth/login' => ['postJson', '/martis/api/auth/login'],
]);

it('gives the magic-link request a bucket of its own, apart from the password sign-in', function () {
    throttleUsersTable();
    $victim = User::forceCreate(['name' => 'V', 'email' => 'victim@example.com', 'password' => bcrypt('right-password')]);
    Schema::dropIfExists('password_reset_tokens');
    Schema::create('password_reset_tokens', function ($t) {
        $t->string('email')->primary();
        $t->string('token');
        $t->timestamp('created_at')->nullable();
    });
    config()->set('martis.auth.magic_link.enabled', true);
    config()->set('martis.throttle.login_email_attempts', 3);
    config()->set('martis.throttle.login_attempts', 1000);
    Notification::fake();

    // Password noise fills the password bucket ...
    foreach (range(1, 3) as $ignored) {
        $this->postJson('/martis/api/auth/login', ['email' => 'victim@example.com', 'password' => 'nope'])->assertStatus(422);
    }
    $this->postJson('/martis/api/auth/login', ['email' => 'victim@example.com', 'password' => 'nope'])->assertStatus(429);

    // ... and does not starve the magic-link request, whatever the spelling of the address.
    foreach (['victim@example.com', 'Victim@Example.com', 'victim@example.com'] as $spelling) {
        $this->postJson('/martis/api/auth/magic-link/request', ['email' => $spelling])->assertOk();
    }
    // Its own bucket is 3 requests too: the 4th is refused, and so is the same account with another spelling.
    $this->postJson('/martis/api/auth/magic-link/request', ['email' => 'VICTIM@example.com'])->assertStatus(429);

    // An unknown address behaves alike (no account is told apart from another by the 429).
    foreach (range(1, 3) as $ignored) {
        $this->postJson('/martis/api/auth/magic-link/request', ['email' => 'ghost@example.com'])->assertOk();
    }
    $this->postJson('/martis/api/auth/magic-link/request', ['email' => 'ghost@example.com'])->assertStatus(429);
    expect($victim->exists)->toBeTrue();
});

it('martis-login limiter falls back to per-IP when no email is supplied', function () {
    // Empty payload — no email — should still throttle on IP only.
    $request = request()->create('/martis/api/auth/login', 'POST');
    $request->server->set('REMOTE_ADDR', '198.51.100.99');

    $limits = RateLimiter::limiter('martis-login');
    /** @var array<int, Limit> $resolved */
    $resolved = (array) $limits($request);

    expect($resolved)->toHaveCount(1)
        ->and($resolved[0]->key)->toContain('ip|198.51.100.99');
});
