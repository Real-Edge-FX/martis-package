<?php

declare(strict_types=1);

namespace Martis\Sso;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;

/**
 * The SSO origin of a session: which provider signed the user in (v2.3.0).
 *
 * The SSO callback signs in with remember-me, so the session it opens can
 * end (SESSION_LIFETIME) while the remember cookie signs the same user back
 * in, into a new session. The origin is therefore kept twice: in the session
 * (`martis_sso_provider`) and in a cookie that lives as long as the remember
 * cookie, holding the user's id and the provider, encrypted by Martis so a
 * browser cannot mint one. The forced password change gate never holds an
 * SSO session, and the sign-out goes through the provider's federated logout.
 * Every other sign-in, and the sign-out, drops both.
 */
final class SsoSession
{
    /** The session key the SSO callback sets. */
    public const SESSION_KEY = 'martis_sso_provider';

    /** The cookie that carries the origin across a remember-me re-login. */
    public const COOKIE = 'martis_sso_provider';

    /** Laravel's remember cookie lifetime when the guard sets none (`SessionGuard::$rememberDuration`). */
    private const REMEMBER_MINUTES = 576000;

    /** Record that $user signed in through $provider, under the guard $guard. */
    public static function start(Request $request, Authenticatable $user, string $provider, ?string $guard): void
    {
        $request->session()->put(self::SESSION_KEY, $provider);

        $minutes = config('auth.guards.'.($guard ?? config('auth.defaults.guard')).'.remember');

        Cookie::queue(Cookie::make(
            self::COOKIE,
            Crypt::encryptString($user->getAuthIdentifier().'|'.$provider),
            is_numeric($minutes) && (int) $minutes > 0 ? (int) $minutes : self::REMEMBER_MINUTES,
        ));
    }

    /** Drop the origin: a sign-in that is not an SSO one, or a sign-out. */
    public static function forget(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }

        if ($request->cookies->has(self::COOKIE)) {
            Cookie::queue(Cookie::forget(self::COOKIE));
        }
    }

    /**
     * The provider the session of $user was opened through, or null: the
     * session's marker, else the cookie when it names this same user (a
     * remember-me re-login), which is then written back to the session.
     */
    public static function provider(Request $request, ?Authenticatable $user): ?string
    {
        if ($request->hasSession()) {
            $provider = $request->session()->get(self::SESSION_KEY);

            if (is_string($provider) && $provider !== '') {
                return $provider;
            }
        }

        $provider = $user === null ? null : self::fromCookie($request, $user);

        if ($provider !== null && $request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $provider);
        }

        return $provider;
    }

    private static function fromCookie(Request $request, Authenticatable $user): ?string
    {
        $value = $request->cookies->get(self::COOKIE);

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $decrypted = Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }

        [$id, $provider] = array_pad(explode('|', $decrypted, 2), 2, '');

        if ($provider === '' || $id !== (string) $user->getAuthIdentifier()) {
            return null;
        }

        return $provider;
    }
}
