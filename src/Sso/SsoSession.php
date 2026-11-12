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
use InvalidArgumentException;
use Martis\Auth\GuardCatalog;
use RuntimeException;
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
 *
 * Since v2.21.0 the session also keeps when the SSO sign-in happened
 * (`martis_sso_at`, unix time) and the external id the IdP issued
 * (`martis_sso_external_id`), which the `martis.sso.session` middleware reads
 * to end a session by age, by revocation ({@see revokeUser()}) or when the
 * provider says the person is gone. A remember-me re-login restores both from
 * the cookie, so the sign-in time is the cookie's issue time, never the time
 * of the re-login.
 */
final class SsoSession
{
    /** The session key the SSO callback sets. */
    public const SESSION_KEY = 'martis_sso_provider';

    /** The session key of the unix time the SSO sign-in happened (v2.21.0). */
    public const SESSION_AT = 'martis_sso_at';

    /** The session key of the external id the IdP issued for the signed-in identity (v2.21.0). */
    public const SESSION_EXTERNAL_ID = 'martis_sso_external_id';

    /** The session key of the unix time the provider last confirmed the session (v2.21.0). */
    public const SESSION_CHECKED_AT = 'martis_sso_checked_at';

    /** The session key of the unix time before which the provider is not asked again after it threw (v2.21.0). */
    public const SESSION_RETRY_AT = 'martis_sso_retry_at';

    /** The session key of the marker an ended SSO session leaves in the new anonymous session (v2.21.0). */
    public const SESSION_ENDED = 'martis_sso_ended';

    /** How long the marker of an ended session answers, in seconds (v2.21.0). */
    public const ENDED_SECONDS = 60;

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
    public static function start(Request $request, Authenticatable $user, string $provider, ?string $guard, bool $remember = true, ?string $externalId = null): void
    {
        $now = self::now();

        $request->session()->put(self::SESSION_KEY, $provider);
        $request->session()->forget(self::SESSION_ENDED);
        self::writeSignIn($request, $now, $externalId);

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
                'i' => $now,
                ...($externalId !== null ? ['e' => $externalId] : []),
            ])),
            $minutes,
        ));
    }

    /**
     * A provider that remembers its users cannot also limit their sessions
     * (v2.21.0): `remember` together with `max_session_minutes` or
     * `revalidate_every_minutes` is a configuration error.
     *
     * After the session ends, a remember-me re-login is recognised as an SSO
     * one only through the origin cookie and its nonce in the cache. The server
     * cannot rely on either (the holder deletes the cookie, a cache flush drops
     * every nonce), and a re-login that is not recognised is a session no limit
     * applies to. Revocation does not depend on the origin: `revokeFor()` cycles
     * the remember token, so it still covers remembered sessions.
     *
     * @throws InvalidArgumentException
     */
    public static function assertLimitsFitRemember(string $provider): void
    {
        $prefix = "martis.auth.sso.providers.{$provider}.";

        if (! (bool) config($prefix.'remember', false)) {
            return;
        }

        foreach (['max_session_minutes', 'revalidate_every_minutes'] as $limit) {
            $value = config($prefix.$limit);

            if ($value !== null && $value !== '') {
                throw new InvalidArgumentException("The [{$prefix}remember] config value is true together with [{$prefix}{$limit}]: a remembered re-login is recognised as an SSO session only through a cookie and a cache entry the server cannot rely on, so the limit could not be enforced. Turn remember off, or unset [{$prefix}{$limit}].");
            }
        }
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
            $request->session()->forget([self::SESSION_KEY, self::SESSION_AT, self::SESSION_EXTERNAL_ID, self::SESSION_CHECKED_AT, self::SESSION_RETRY_AT, self::SESSION_ENDED]);
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

        $claims = $user === null ? null : self::fromCookie($request, $user);

        if ($claims === null) {
            return null;
        }

        // A remember-me re-login: the origin comes back with the time of the
        // sign-in it began with (the cookie's issue time, never now) and the
        // external id of that sign-in.
        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $claims['p']);
            self::writeSignIn($request, $claims['i'], $claims['e'] ?? null);
        }

        return $claims['p'];
    }

    /**
     * When the SSO sign-in of the session happened (unix time), or null for a
     * session that has the origin and no time (opened before v2.21.0).
     */
    public static function signedInAt(Request $request): ?int
    {
        $at = $request->hasSession() ? $request->session()->get(self::SESSION_AT) : null;

        return is_int($at) ? $at : null;
    }

    /** The external id the IdP issued for the signed-in identity, when the session recorded it. */
    public static function externalId(Request $request): ?string
    {
        $id = $request->hasSession() ? $request->session()->get(self::SESSION_EXTERNAL_ID) : null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** When the provider last confirmed the session (unix time), or null when it never did. */
    public static function checkedAt(Request $request): ?int
    {
        $at = $request->hasSession() ? $request->session()->get(self::SESSION_CHECKED_AT) : null;

        return is_int($at) ? $at : null;
    }

    /** Record that the provider confirmed the session now (and drop any back-off). */
    public static function markChecked(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_CHECKED_AT, self::now());
            $request->session()->forget(self::SESSION_RETRY_AT);
        }
    }

    /** The unix time before which the provider is not asked again after it threw, or null when there is no back-off. */
    public static function retryAt(Request $request): ?int
    {
        $at = $request->hasSession() ? $request->session()->get(self::SESSION_RETRY_AT) : null;

        return is_int($at) ? $at : null;
    }

    /** Do not ask the provider again for $seconds: it threw (an outage), and every request must not wait on it. */
    public static function deferRevalidation(Request $request, int $seconds): void
    {
        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_RETRY_AT, self::now() + $seconds);
        }
    }

    /**
     * Leave the marker of an SSO session that just ended (v2.21.0), for
     * {@see ENDED_SECONDS} seconds: the provider's name and the time, nothing of
     * the user.
     *
     * A page fires several API requests at once and the first one ends the
     * session, so the others reach the server as plain unauthenticated
     * requests. The marker lets them be answered with the way back through the
     * provider instead of a bare 401. It is kept in the new anonymous session
     * (what the browser sends next) and in the cache under a hash of the id of
     * the session that ended (what the requests already in flight still send,
     * the old session being gone). A cache that cannot take it is reported and
     * the session half still answers.
     *
     * Call it after the session was invalidated: that clears the marker with it.
     */
    public static function markEnded(Request $request, string $provider, ?string $endedSessionId): void
    {
        $marker = ['p' => $provider, 'at' => self::now()];

        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_ENDED, $marker);
        }

        if ($endedSessionId === null || $endedSessionId === '') {
            return;
        }

        try {
            Cache::put(self::endedKey($endedSessionId), $marker, now()->addSeconds(self::ENDED_SECONDS));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The provider whose SSO session ended less than {@see ENDED_SECONDS}
     * seconds ago in this browser, or null: the marker of the session, else
     * the cache entry of the session id the request carries.
     */
    public static function endedProvider(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $session = $request->session();
        $marker = self::liveMarker($session->get(self::SESSION_ENDED));

        if ($marker === null) {
            // Past its minute (or never valid): drop what is left.
            $session->forget(self::SESSION_ENDED);

            try {
                $marker = self::liveMarker(Cache::get(self::endedKey($session->getId())));
            } catch (Throwable $e) {
                report($e);

                return null;
            }
        }

        return $marker;
    }

    /**
     * Drop the marker of an ended session: the person is on their way to the
     * provider (its redirect route was visited), or signed in.
     */
    public static function forgetEnded(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $session = $request->session();
        $session->forget(self::SESSION_ENDED);

        try {
            Cache::forget(self::endedKey($session->getId()));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** The provider of a marker that is well formed and younger than {@see ENDED_SECONDS}, else null. */
    private static function liveMarker(mixed $marker): ?string
    {
        if (! is_array($marker) || ! is_string($marker['p'] ?? null) || $marker['p'] === '' || ! is_int($marker['at'] ?? null)) {
            return null;
        }

        $age = self::now() - $marker['at'];

        return $age >= 0 && $age < self::ENDED_SECONDS ? $marker['p'] : null;
    }

    /** The cache key of the marker of an ended session: a hash, never the session id itself. */
    private static function endedKey(string $sessionId): string
    {
        return 'martis.sso-ended.'.sha1($sessionId);
    }

    /**
     * Revoke every SSO session of the user (v2.21.0): remember the time, for
     * the remember lifetime, and drop every SSO-origin nonce of the user, so no
     * cookie of an earlier sign-in gives an origin back. {@see isRevoked()}
     * compares the sign-in time of a session with that time.
     *
     * The time is written first, and a cache that cannot take it throws: a
     * revocation that did not stick must not look as if it had.
     *
     * @throws RuntimeException
     */
    public static function revokeUser(string $userId): void
    {
        try {
            $stored = Cache::put(self::revokedKey($userId), self::now(), now()->addMinutes(self::lifetimeMinutes(null)));
        } catch (Throwable $e) {
            throw new RuntimeException("Martis could not record the SSO revocation of user [{$userId}]: the cache refused the write.", 0, $e);
        }

        if (! $stored) {
            throw new RuntimeException("Martis could not record the SSO revocation of user [{$userId}]: the cache refused the write.");
        }

        self::revokeAll($userId);
    }

    /**
     * Whether a session of the user that signed in through SSO at
     * `$signedInAt` was revoked: the user was revoked at or after it. A
     * session with no sign-in time counts as older than any revocation. A cache
     * that cannot be read is reported and counts as revoked (fail closed).
     */
    public static function isRevoked(string $userId, ?int $signedInAt): bool
    {
        try {
            $revokedAt = Cache::get(self::revokedKey($userId));
        } catch (Throwable $e) {
            report($e);

            return true;
        }

        if ($revokedAt === null) {
            return false;
        }

        return ! is_int($revokedAt) || $signedInAt === null || $signedInAt <= $revokedAt;
    }

    /**
     * The claims of the origin cookie when it counts: it names this user, is
     * younger than the remember lifetime and carries a nonce the server still
     * holds.
     *
     * @return array{u: string, p: string, n: string, i: int, e?: string}|null
     */
    private static function fromCookie(Request $request, Authenticatable $user): ?array
    {
        $claims = self::claims($request);

        if ($claims === null || $claims['u'] !== (string) $user->getAuthIdentifier()) {
            return null;
        }

        $age = self::now() - $claims['i'];

        // Older than a remember cookie can be, or issued in the future.
        if ($age < -60 || $age > self::lifetimeMinutes(null) * 60) {
            return null;
        }

        return self::holds($claims['u'], $claims['n']) ? $claims : null;
    }

    /**
     * What the cookie of the request says, when it decrypts and has the
     * shape Martis writes: the user's id (`u`), the provider (`p`), the
     * nonce (`n`), the issue time (`i`) and, when the sign-in recorded one,
     * the external id of the identity (`e`, v2.21.0).
     *
     * @return array{u: string, p: string, n: string, i: int, e?: string}|null
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

        $out = ['u' => $claims['u'], 'p' => $claims['p'], 'n' => $claims['n'], 'i' => $claims['i']];

        if (is_string($claims['e'] ?? null) && $claims['e'] !== '') {
            $out['e'] = $claims['e'];
        }

        return $out;
    }

    /** Write the sign-in time and external id of the session, and start its revalidation clock over. */
    private static function writeSignIn(Request $request, int $at, ?string $externalId): void
    {
        $session = $request->session();
        $session->put(self::SESSION_AT, $at);
        $session->forget([self::SESSION_CHECKED_AT, self::SESSION_RETRY_AT]);

        $externalId !== null && $externalId !== ''
            ? $session->put(self::SESSION_EXTERNAL_ID, $externalId)
            : $session->forget(self::SESSION_EXTERNAL_ID);
    }

    /** The current unix time, through Carbon so a test can travel. */
    private static function now(): int
    {
        return now()->getTimestamp();
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

    /** The cache key of the time the user was revoked, scoped by guard like {@see key()}. */
    private static function revokedKey(string $userId): string
    {
        return 'martis.sso-revoked.'.sha1(GuardCatalog::martis().'|'.$userId);
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
