<?php

declare(strict_types=1);

namespace Martis\Auth;

use Illuminate\Auth\Recaller;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * "Keep me signed in" that survives the 2FA challenge, as in Nova (Fortify).
 *
 * The remember-me cookie is issued at the password step, before the
 * challenge, so on its own it proves one factor: a sign-in through it meets
 * the challenge again ({@see Listeners\ResetTwoFactorPass}). Passing the
 * challenge while the browser holds a usable remember-me cookie of the same
 * user leaves a second cookie, this marker: an HMAC (app key) of the user,
 * their remember token, their password hash and their 2FA secret. A later
 * sign-in through that remember-me cookie that also carries a matching
 * marker proves both factors and keeps the 2FA pass, so an expired session
 * does not ask for the code.
 *
 * The marker stops matching when the remember token rotates (sign-out), the
 * password changes (a change, a reset, "log out other devices") or the 2FA
 * secret changes (set up again, turned off). A remember-me cookie issued at
 * the password step without a passed challenge never gets one. It is a
 * bearer secret as sensitive as the remember-me cookie, and one browser
 * holds one marker: a second user passing the challenge in the same browser
 * replaces it. `martis.profile.two_factor.trust_remember_me` false turns the
 * marker off: every new session meets the challenge.
 */
final class RememberedTwoFactor
{
    /** The marker cookie. */
    public const COOKIE = 'martis_two_factor_remembered';

    /** Laravel's remember cookie lifetime when the guard sets none (`SessionGuard::$rememberDuration`). */
    private const REMEMBER_MINUTES = 576000;

    /** Whether a remember-me sign-in may keep the 2FA pass (default on). */
    public static function enabled(): bool
    {
        return (bool) config('martis.profile.two_factor.trust_remember_me', true);
    }

    /**
     * After the challenge (or the 2FA setup) passed: leave the marker when
     * the request carries this user's usable remember-me cookie, for as long
     * as the guard's remember-me cookie lives.
     */
    public static function remember(Request $request, Authenticatable $user): void
    {
        if (! self::enabled() || ! self::holdsRememberCookie($request, $user)) {
            return;
        }

        $signature = self::signature($user);
        if ($signature !== null) {
            Cookie::queue(self::COOKIE, $signature, self::lifetimeMinutes());
        }
    }

    /**
     * Whether this sign-in came through the user's usable remember-me cookie
     * and the request carries a marker that matches them now. Checks the
     * cookie itself rather than the guard's viaRemember() flag, which stays
     * true for the rest of a request whose remember-me cookie was stale.
     */
    public static function trusts(Request $request, Authenticatable $user): bool
    {
        if (! self::enabled() || ! self::holdsRememberCookie($request, $user)) {
            return false;
        }

        $marker = $request->cookies->get(self::COOKIE);
        $signature = self::signature($user);

        return is_string($marker) && $marker !== '' && $signature !== null && hash_equals($signature, $marker);
    }

    /** Drop the marker (sign-out, 2FA turned off). */
    public static function forget(): void
    {
        Cookie::queue(Cookie::forget(self::COOKIE));
    }

    /**
     * Whether the request's remember-me cookie (decrypted by the app's
     * EncryptCookies) would sign this user in: their id, their current
     * remember token and their current password hash, checked as
     * SessionGuard does.
     */
    private static function holdsRememberCookie(Request $request, Authenticatable $user): bool
    {
        $guard = auth()->guard(GuardCatalog::martis());
        if (! $guard instanceof SessionGuard) {
            return false;
        }

        $value = $request->cookies->get($guard->getRecallerName());
        if (! is_string($value)) {
            return false;
        }

        $recaller = new Recaller($value);
        $token = $user->getRememberToken();
        $password = $user->getAuthPassword();

        return $recaller->valid()
            && hash_equals((string) $user->getAuthIdentifier(), (string) $recaller->id())
            && is_string($token) && $token !== '' && hash_equals($token, $recaller->token())
            && $password !== ''
            && (hash_equals($guard->hashPasswordForCookie($password), $recaller->hash()) || hash_equals($password, $recaller->hash()));
    }

    /** The marker of this user, or null when there is nothing to bind it to. */
    private static function signature(Authenticatable $user): ?string
    {
        $token = $user->getRememberToken();
        $password = $user->getAuthPassword();
        /** @var mixed $secret */
        $secret = $user->two_factor_secret ?? null;

        if (! is_string($token) || $token === '' || $password === '' || ! is_string($secret) || $secret === '') {
            return null;
        }

        return hash_hmac(
            'sha256',
            implode('|', ['martis-2fa-remembered', (string) $user->getAuthIdentifier(), $token, $password, $secret]),
            (string) config('app.key'),
        );
    }

    private static function lifetimeMinutes(): int
    {
        $minutes = config('auth.guards.'.GuardCatalog::martis().'.remember');

        return is_numeric($minutes) && (int) $minutes > 0 ? (int) $minutes : self::REMEMBER_MINUTES;
    }
}
