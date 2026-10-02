<?php

declare(strict_types=1);

namespace Martis\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The per-account limit of the sign-in (`martis.throttle.login_email_attempts`
 * per `login_email_minutes`, v2.4.0): the one limit that bounds guessing at
 * one account from many source addresses, since the `martis-login` limiter
 * gives every IP a bucket of its own.
 *
 * It is kept here, not in the `martis-login` limiter, for three reasons:
 *
 *  - **Only failures count.** A named limiter hits on every request, so the
 *    owner's own good sign-ins and the noise of a botnet spent the same
 *    bucket. The controller asks `tooMany()` before it checks the password,
 *    hits on a wrong one and clears on a right one.
 *  - **One bucket per account, not per spelling.** The key is the id of the
 *    user the email matches (the database may match `Admín@x` and `admin@x`
 *    to one row under a case- or accent-insensitive collation), falling back
 *    to the lowercased, trimmed email when no user matches.
 *  - **Each purpose has its own bucket.** The magic-link request sends a
 *    mail, never checks a password: it counts its own requests, so password
 *    noise cannot starve it and its requests cannot starve the password
 *    sign-in.
 *
 * Someone who sends that many wrong passwords for an email can still keep its
 * owner from signing in for the rest of the window (the documented cost of a
 * per-account limit); 0 attempts turns the limit off.
 */
final class AccountLoginThrottle
{
    /** The password sign-in (the web form and the SPA's API route). */
    public const PASSWORD = 'password';

    /** The request of a magic sign-in link. */
    public const MAGIC_LINK = 'magic-link';

    /** Whether the limit is on. */
    public static function enabled(): bool
    {
        return self::maxAttempts() > 0;
    }

    /**
     * Whether the account has used up its bucket for `$purpose`.
     */
    public static function tooMany(string $purpose, string $email, ?Authenticatable $user = null): bool
    {
        return self::enabled() && RateLimiter::tooManyAttempts(self::key($purpose, $email, $user), self::maxAttempts());
    }

    /**
     * The 429 the routes answer when the bucket is used up: the exception the
     * throttle middleware throws, so every client sees the one it knows.
     */
    public static function exception(string $purpose, string $email, ?Authenticatable $user = null): ThrottleRequestsException
    {
        $retryAfter = RateLimiter::availableIn(self::key($purpose, $email, $user));

        return new ThrottleRequestsException('Too Many Attempts.', headers: [
            'Retry-After' => $retryAfter,
            'X-RateLimit-Limit' => self::maxAttempts(),
            'X-RateLimit-Remaining' => 0,
        ]);
    }

    /** Count an attempt (a wrong password, a requested link). */
    public static function hit(string $purpose, string $email, ?Authenticatable $user = null): void
    {
        if (self::enabled()) {
            RateLimiter::hit(self::key($purpose, $email, $user), self::decaySeconds());
        }
    }

    /** A right password ends the run of wrong ones. */
    public static function clear(string $purpose, string $email, ?Authenticatable $user = null): void
    {
        RateLimiter::clear(self::key($purpose, $email, $user));
    }

    /**
     * The user `$email` names in the guard's own provider, or null: the
     * account the bucket belongs to.
     */
    public static function userFor(StatefulGuard $guard, string $email): ?Authenticatable
    {
        $provider = method_exists($guard, 'getProvider') ? $guard->getProvider() : null;

        return $provider instanceof UserProvider ? $provider->retrieveByCredentials(['email' => $email]) : null;
    }

    /**
     * The bucket of an account for a purpose: by the matched user's id when
     * there is one, else by the lowercased, trimmed email.
     */
    public static function key(string $purpose, string $email, ?Authenticatable $user = null): string
    {
        $account = $user !== null
            ? 'user|'.$user::class.'|'.$user->getAuthIdentifier()
            : 'email|'.sha1(strtolower(trim($email)));

        return 'martis-login|account|'.$purpose.'|'.GuardCatalog::martis().'|'.$account;
    }

    private static function maxAttempts(): int
    {
        return max(0, (int) config('martis.throttle.login_email_attempts', 100));
    }

    private static function decaySeconds(): int
    {
        return max(1, (int) config('martis.throttle.login_email_minutes', 15)) * 60;
    }
}
