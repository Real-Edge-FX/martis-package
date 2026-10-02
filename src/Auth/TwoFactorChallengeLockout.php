<?php

declare(strict_types=1);

namespace Martis\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The lockout of the 2FA challenge: the count of consecutive wrong codes of
 * a user, kept server-side beside the rate limiter.
 *
 * The limiter of the challenge route (`martis-2fa-challenge`) slows guessing
 * down but sets no ceiling: someone who holds a user's password can sign in
 * again and again and keep guessing, a few percent a day at 5 a minute. After
 * `martis.throttle.two_factor_lockout_attempts` consecutive wrong codes (a
 * TOTP code or a recovery code) the user is locked out for
 * `martis.throttle.two_factor_lockout_minutes`: the challenge refuses every
 * code, the right one included, and ends the session, so a new password
 * sign-in is needed once the lockout has passed.
 *
 * The count belongs to the user, not to the session: a session-bound count
 * would start over with each password sign-in. A right code clears it. It
 * lives in the cache (the rate limiter's store), so a cache flush clears it,
 * as it clears every other limiter.
 *
 * The run of wrong codes counts in a rate limiter window, which opens at the
 * first wrong code. The lockout is a key of its own, written with the full
 * `two_factor_lockout_minutes` when the failure that reaches the limit
 * lands, so it lasts that long from that failure whatever the window held.
 */
final class TwoFactorChallengeLockout
{
    /** Consecutive wrong codes that lock the user out; 0 turns the lockout off. */
    public static function maxFailures(): int
    {
        return max(0, (int) config('martis.throttle.two_factor_lockout_attempts', 5));
    }

    /** Whether the user is locked out right now. */
    public static function locked(Authenticatable $user): bool
    {
        return self::maxFailures() > 0 && Cache::has(self::lockKey($user));
    }

    /**
     * Count a wrong code.
     *
     * @return bool Whether this one locked the user out.
     */
    public static function recordFailure(Authenticatable $user): bool
    {
        $max = self::maxFailures();
        if ($max === 0) {
            return false;
        }

        $minutes = max(1, (int) config('martis.throttle.two_factor_lockout_minutes', 15));
        $failures = RateLimiter::hit(self::key($user), $minutes * 60);

        if ($failures < $max) {
            return false;
        }

        // The lockout runs from this failure, for the full time; the count
        // starts over when it ends.
        Cache::put(self::lockKey($user), true, $minutes * 60);
        RateLimiter::clear(self::key($user));

        return true;
    }

    /** A right code ends the run of wrong ones. */
    public static function clear(Authenticatable $user): void
    {
        RateLimiter::clear(self::key($user));
        Cache::forget(self::lockKey($user));
    }

    private static function lockKey(Authenticatable $user): string
    {
        return self::key($user).':locked';
    }

    private static function key(Authenticatable $user): string
    {
        return 'martis-2fa-lockout:'.GuardCatalog::martis().':'.$user->getAuthIdentifier();
    }
}
