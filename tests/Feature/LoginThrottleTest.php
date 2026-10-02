<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;

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
    expect($resolved)->toHaveCount(2)
        ->and($resolved[0])->toBeInstanceOf(Limit::class)
        ->and($resolved[0]->maxAttempts)->toBe($attempts)
        ->and($resolved[0]->key)->toContain('ip|198.51.100.42');
});

// -----------------------------------------------------------------------------
// The per-account limit (v2.4.0)
// -----------------------------------------------------------------------------
//
// The first limit gives every source IP a bucket of its own for the same
// email, so guesses spread over N addresses got N x login_attempts per minute
// at one account. The limiter holds a second limit keyed on the email alone.

/** The limits `martis-login` resolves for a request to the login. */
function loginLimitsFor(string $ip, ?string $email): array
{
    $request = request()->create('/martis/api/auth/login', 'POST', $email === null ? [] : ['email' => $email]);
    $request->server->set('REMOTE_ADDR', $ip);

    /** @var array<int, Limit> */
    return (array) RateLimiter::limiter('martis-login')($request);
}

it('martis-login keys its second limit on the email alone, so a botnet shares one bucket', function () {
    $fromOneIp = loginLimitsFor('198.51.100.1', 'Victim@Example.com');
    $fromAnotherIp = loginLimitsFor('198.51.100.2', ' victim@example.com ');

    expect($fromOneIp)->toHaveCount(2)
        // The first limit still tells two IPs apart ...
        ->and($fromOneIp[0]->key)->not->toBe($fromAnotherIp[0]->key)
        // ... the second does not, and holds no IP at all, whatever the case or the padding of the email.
        ->and($fromOneIp[1]->key)->toBe($fromAnotherIp[1]->key)
        ->and($fromOneIp[1]->key)->not->toContain('198.51.100')
        ->and($fromOneIp[1]->key)->not->toBe(loginLimitsFor('198.51.100.1', 'other@example.com')[1]->key);
});

it('martis-login gives the per-account limit a higher threshold than the per-IP one, from config', function () {
    [$perIp, $perAccount] = loginLimitsFor('198.51.100.1', 'victim@example.com');

    expect($perAccount->maxAttempts)->toBe(100)
        ->and($perAccount->maxAttempts)->toBeGreaterThan($perIp->maxAttempts)
        ->and($perAccount->decaySeconds)->toBe(15 * 60);

    config()->set('martis.throttle.login_email_attempts', 7);
    config()->set('martis.throttle.login_email_minutes', 3);
    [, $perAccount] = loginLimitsFor('198.51.100.1', 'victim@example.com');

    expect($perAccount->maxAttempts)->toBe(7)
        ->and($perAccount->decaySeconds)->toBe(180);
});

it('martis-login turns the per-account limit off with zero attempts', function () {
    config()->set('martis.throttle.login_email_attempts', 0);

    expect(loginLimitsFor('198.51.100.1', 'victim@example.com'))->toHaveCount(1);
});

it('martis-login limits a request without an email per IP only', function () {
    expect(loginLimitsFor('198.51.100.1', null))->toHaveCount(1);
});

it('puts the martis-login limiter on both login routes and the magic link request', function (string $route) {
    $middleware = app('router')->getRoutes()->getByName($route)->gatherMiddleware();

    expect($middleware)->toContain('throttle:martis-login');
})->with(['martis.login.attempt', 'martis.api.auth.login', 'martis.api.auth.magic-link.request']);

it('throttles guesses at one account spread over many IPs, on both login routes', function (string $method, string $uri) {
    Schema::dropIfExists('users');
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->rememberToken();
        $table->timestamps();
    });
    // The per-IP limits are out of the way: only the per-account one can answer 429.
    config()->set('martis.throttle.login_email_attempts', 3);
    $guess = fn (string $ip, string $email) => $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->{$method}($uri, ['email' => $email, 'password' => 'wrong-guess']);

    foreach (['203.0.113.1', '203.0.113.2', '203.0.113.3'] as $ip) {
        expect($guess($ip, 'victim@example.com')->getStatusCode())->not->toBe(429);
    }

    // A fourth guess from a fresh address is refused, before the password is checked ...
    $guess('203.0.113.4', 'victim@example.com')->assertStatus(429);
    // ... and the next account is not affected.
    expect($guess('203.0.113.4', 'bystander@example.com')->getStatusCode())->not->toBe(429);
})->with([
    'POST /login' => ['post', '/martis/login'],
    'POST /api/auth/login' => ['postJson', '/martis/api/auth/login'],
]);

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
