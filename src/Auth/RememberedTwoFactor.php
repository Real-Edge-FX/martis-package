<?php

declare(strict_types=1);

namespace Martis\Auth;

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
 * challenge while the browser holds a remember-me cookie of the same user
 * leaves a second cookie, this marker: an HMAC (app key) of the user, their
 * remember token and their 2FA secret. A later sign-in through the
 * remember-me cookie that also carries a matching marker proves both factors
 * and keeps the 2FA pass, so an expired session does not ask for the code.
 *
 * The marker stops matching when the remember token rotates (sign-out, which
 * also ends the remember-me cookie) or the 2FA secret changes (set up again,
 * turned off). A remember-me cookie issued at the password step without a
 * passed challenge never gets one. `martis.profile.two_factor.trust_remember_me`
 * false turns the marker off: every new session meets the challenge.
 */
final class RememberedTwoFactor
{
    /** The marker cookie. */
    public const COOKIE = 'martis_two_factor_remembered';

    /**
     * Minutes the marker lives: Laravel's default remember-me lifetime (400
     * days; SessionGuard keeps its own duration protected). The marker only
     * counts on a sign-in through a valid remember-me cookie, so outliving
     * that cookie grants nothing.
     */
    private const LIFETIME_MINUTES = 576000;

    /** Whether a remember-me sign-in may keep the 2FA pass (default on). */
    public static function enabled(): bool
    {
        return (bool) config('martis.profile.two_factor.trust_remember_me', true);
    }

    /**
     * After the challenge (or the 2FA setup) passed: leave the marker when
     * the request carries this user's valid remember-me cookie, for as long
     * as that cookie lives.
     */
    public static function remember(Request $request, Authenticatable $user): void
    {
        if (! self::enabled()) {
            return;
        }

        $guard = auth()->guard(GuardCatalog::martis());
        if (! $guard instanceof SessionGuard || ! self::holdsRememberCookie($request, $guard, $user)) {
            return;
        }

        $signature = self::signature($user);
        if ($signature !== null) {
            Cookie::queue(self::COOKIE, $signature, self::LIFETIME_MINUTES);
        }
    }

    /** Whether the request carries a marker that matches this user now. */
    public static function trusts(Request $request, Authenticatable $user): bool
    {
        if (! self::enabled()) {
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
     * EncryptCookies) names this user and their current remember token.
     */
    private static function holdsRememberCookie(Request $request, SessionGuard $guard, Authenticatable $user): bool
    {
        $value = $request->cookies->get($guard->getRecallerName());
        if (! is_string($value)) {
            return false;
        }

        $parts = explode('|', $value, 3);
        $token = $user->getRememberToken();

        return count($parts) === 3
            && $parts[0] === (string) $user->getAuthIdentifier()
            && is_string($token) && $token !== ''
            && hash_equals($token, $parts[1]);
    }

    /** The marker of this user, or null when there is nothing to bind it to. */
    private static function signature(Authenticatable $user): ?string
    {
        $token = $user->getRememberToken();
        /** @var mixed $secret */
        $secret = $user->two_factor_secret ?? null;

        if (! is_string($token) || $token === '' || ! is_string($secret) || $secret === '') {
            return null;
        }

        $key = (string) config('app.key');

        return hash_hmac('sha256', implode('|', ['martis-2fa-remembered', (string) $user->getAuthIdentifier(), $token, $secret]), $key);
    }
}
