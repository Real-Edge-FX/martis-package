<?php

declare(strict_types=1);

namespace Martis\Sso;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Martis\Auth\GuardCatalog;
use Throwable;

/**
 * The SSO origin of a session: which provider signed the user in (v2.3.0).
 *
 * A provider that opts in to remember-me (`remember`, default false) signs
 * the user in with the remember cookie, so the session it opens can end
 * (SESSION_LIFETIME) while the remember cookie signs the same user back
 * in, into a new session. The origin is therefore kept twice: in the session
 * (`martis_sso_provider`) and in a cookie that lives as long as the remember
 * cookie. The forced password change gate never holds an SSO session, and
 * the sign-out goes through the provider's federated logout. Every other
 * sign-in, and the sign-out, drops both.
 *
 * The cookie is encrypted by Martis, so a browser cannot mint one, and bound
 * to server-side state, so a copy of an old one is worth nothing (v2.4.0): it
 * carries the user's id, the provider, the time it was issued and a random
 * nonce, and counts only while it is younger than the remember lifetime and
 * the nonce is one the server still holds for that user (the cache, for the
 * remember lifetime). A password, magic-link or invitation sign-in drops
 * every nonce of the user, so a user who kept the cookie of an earlier SSO
 * sign-in and re-attaches it after signing in with a password is not exempt
 * from the forced password change. A user who signs in through SSO on two
 * devices keeps both nonces; the sign-out drops only the nonce of the
 * browser it ends.
 */
final class SsoSession
{
    /** The session key the SSO callback sets. */
    public const SESSION_KEY = 'martis_sso_provider';

    /** The cookie that carries the origin across a remember-me re-login. */
    public const COOKIE = 'martis_sso_provider';

    /** Laravel's remember cookie lifetime when the guard sets none (`SessionGuard::$rememberDuration`). */
    private const REMEMBER_MINUTES = 576000;

    /** The nonces kept per user: one per browser signed in through SSO, the oldest dropped first. */
    private const MAX_NONCES = 20;

    /**
     * Record that $user signed in through $provider, under the guard $guard.
     *
     * The cookie is written only when the sign-in was a remembered one
     * ($remember): without a remember cookie nothing signs the user back in
     * after the session ends, so there is no origin to carry across.
     */
    public static function start(Request $request, Authenticatable $user, string $provider, ?string $guard, bool $remember = true): void
    {
        $request->session()->put(self::SESSION_KEY, $provider);

        if (! $remember) {
            return;
        }

        $minutes = self::lifetimeMinutes($guard);
        $nonce = Str::random(40);

        // Without the server-side half the cookie would never validate:
        // leave it out rather than send a dead one.
        if (! self::remember($user, $nonce, $minutes)) {
            return;
        }

        Cookie::queue(Cookie::make(
            self::COOKIE,
            Crypt::encryptString((string) json_encode([
                'u' => (string) $user->getAuthIdentifier(),
                'p' => $provider,
                'n' => $nonce,
                'i' => time(),
            ])),
            $minutes,
        ));
    }

    /**
     * Drop the origin: a sign-in that is not an SSO one, or a sign-out.
     *
     * A sign-in passes the signed-in $user, and every nonce of that user is
     * dropped, whichever browser holds the cookie: a copy kept from an
     * earlier SSO sign-in stops counting. The sign-out passes none, and
     * drops only the nonce of the cookie this request carries.
     */
    public static function forget(Request $request, ?Authenticatable $user = null): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }

        if ($user !== null) {
            self::revokeAll((string) $user->getAuthIdentifier());
        }

        $claims = self::claims($request);

        if ($claims !== null && ($user === null || $claims['u'] !== (string) $user->getAuthIdentifier())) {
            self::revoke($claims['u'], $claims['n']);
        }

        if ($request->cookies->has(self::COOKIE)) {
            Cookie::queue(Cookie::forget(self::COOKIE));
        }
    }

    /**
     * The provider the session of $user was opened through, or null: the
     * session's marker, else the cookie when it names this same user, is
     * younger than the remember lifetime and carries a nonce the server
     * still holds (a remember-me re-login), which is then written back to
     * the session.
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
        $claims = self::claims($request);

        if ($claims === null || $claims['u'] !== (string) $user->getAuthIdentifier()) {
            return null;
        }

        $age = time() - $claims['i'];

        // Older than a remember cookie can be, or issued in the future.
        if ($age < -60 || $age > self::lifetimeMinutes(null) * 60) {
            return null;
        }

        return self::holds($claims['u'], $claims['n']) ? $claims['p'] : null;
    }

    /**
     * What the cookie of the request says, when it decrypts and has the
     * shape Martis writes: the user's id (`u`), the provider (`p`), the
     * nonce (`n`) and the issue time (`i`).
     *
     * @return array{u: string, p: string, n: string, i: int}|null
     */
    private static function claims(Request $request): ?array
    {
        $value = $request->cookies->get(self::COOKIE);

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $claims = json_decode(Crypt::decryptString($value), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($claims)
            || ! is_string($claims['u'] ?? null) || $claims['u'] === ''
            || ! is_string($claims['p'] ?? null) || $claims['p'] === ''
            || ! is_string($claims['n'] ?? null) || $claims['n'] === ''
            || ! is_int($claims['i'] ?? null)) {
            return null;
        }

        return ['u' => $claims['u'], 'p' => $claims['p'], 'n' => $claims['n'], 'i' => $claims['i']];
    }

    /** The remember lifetime of the guard, in minutes. */
    private static function lifetimeMinutes(?string $guard): int
    {
        $minutes = config('auth.guards.'.($guard ?? GuardCatalog::martis()).'.remember');

        return is_numeric($minutes) && (int) $minutes > 0 ? (int) $minutes : self::REMEMBER_MINUTES;
    }

    private static function key(string $userId): string
    {
        return 'martis.sso-origin.'.sha1(GuardCatalog::martis().'|'.$userId);
    }

    /** Keep the nonce for the user, for $minutes. False when the cache could not take it. */
    private static function remember(Authenticatable $user, string $nonce, int $minutes): bool
    {
        try {
            $key = self::key((string) $user->getAuthIdentifier());
            $held = Cache::get($key, []);
            $held = is_array($held) ? array_values(array_filter($held, 'is_string')) : [];
            $held[] = hash('sha256', $nonce);

            return Cache::put($key, array_slice($held, -self::MAX_NONCES), now()->addMinutes($minutes));
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    private static function holds(string $userId, string $nonce): bool
    {
        try {
            $held = Cache::get(self::key($userId), []);
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return is_array($held) && in_array(hash('sha256', $nonce), $held, true);
    }

    private static function revoke(string $userId, string $nonce): void
    {
        try {
            $key = self::key($userId);
            $held = Cache::get($key, []);

            if (! is_array($held)) {
                return;
            }

            $left = array_values(array_diff($held, [hash('sha256', $nonce)]));
            $left === [] ? Cache::forget($key) : Cache::put($key, $left, now()->addMinutes(self::lifetimeMinutes(null)));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function revokeAll(string $userId): void
    {
        try {
            Cache::forget(self::key($userId));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
