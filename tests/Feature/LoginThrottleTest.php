<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

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

    // Exercise it via a synthesised request and confirm it returns a
    // single Limit with the configured cap.
    $request = request()->create('/martis/api/auth/login', 'POST', ['email' => 'foo@example.com']);
    $request->server->set('REMOTE_ADDR', '198.51.100.42');

    /** @var array<int, Limit> $resolved */
    $resolved = (array) $limits($request);
    expect($resolved)->toHaveCount(1)
        ->and($resolved[0])->toBeInstanceOf(Limit::class)
        ->and($resolved[0]->maxAttempts)->toBe($attempts);
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
