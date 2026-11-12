<?php

declare(strict_types=1);

namespace Martis\Sso;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Martis\Auth\RememberedTwoFactor;
use Martis\Impersonation\ImpersonationManager;
use Martis\Sso\Contracts\RevalidatesSessions;
use Throwable;

/**
 * The decision of the `martis.sso.session` middleware and the act of ending a
 * session (v2.21.0), in one place so that every surface that must answer for a
 * session the middleware would end (the middleware itself, and
 * `/api/auth/user`, which runs without it) uses the same logic.
 *
 * Three independent checks, each off unless asked for, in this order:
 *
 *  1. Revocation ({@see SsoManager::revokeFor()}): the session's sign-in is not
 *     later than the user's revocation time. Needs no config.
 *  2. Absolute age (`martis.auth.sso.providers.{name}.max_session_minutes`):
 *     the session's sign-in is that old or older. A session with the SSO origin
 *     and no sign-in time (opened before v2.21.0) counts as expired.
 *  3. Revalidation (`revalidate_every_minutes`): the provider's
 *     {@see RevalidatesSessions::stillValid()} answers false. Asked at most once
 *     per interval per session. A throwable is reported and the session is
 *     kept, and the provider is asked again no sooner than {@see RETRY_AFTER_SECONDS}
 *     later (kept in the session): an IdP outage must not sign every user out,
 *     nor turn every request into a synchronous IdP call, and
 *     `max_session_minutes` is the hard bound.
 *
 * A session that SSO did not open (password, magic link, invitation) is never
 * touched. While an impersonation is active the session belongs to the operator
 * who signed in through SSO, so the checks run against the operator.
 *
 * Ending a session mirrors a local sign-out on this device only: the guard's
 * current-device logout (the remember token of the user's other devices is NOT
 * cycled, the user did not ask for this; {@see SsoManager::revokeFor()} cycles it
 * on purpose), the session invalidated with a new CSRF token, the SSO origin and
 * the remembered 2FA device dropped. No federated logout: the IdP's session is
 * the one that is gone, or the person is about to sign in at it again.
 *
 * For {@see SsoSession::ENDED_SECONDS} seconds afterwards the browser's other
 * unauthenticated JSON requests are answered with the same way back
 * ({@see pendingReauthUrl()}): a page fires several API requests at once and
 * only the first one meets the session; the rest would answer a bare 401 and
 * the SPA would send the person to the login page, past the provider.
 */
final class SsoSessionEnforcer
{
    /** How long an outage of the provider is left alone before it is asked again. */
    public const RETRY_AFTER_SECONDS = 60;

    public function __construct(
        private readonly SsoManager $manager,
        private readonly ImpersonationManager $impersonation,
    ) {}

    /**
     * End the SSO session of the request when it must end.
     *
     * @return string|null the provider the session was opened through when it was ended, else null
     *
     * @throws InvalidArgumentException when the provider's SSO settings are set but unusable
     */
    public function endIfRequired(Request $request, StatefulGuard $auth): ?string
    {
        $user = $auth->user();

        if ($user === null || ! $request->hasSession()) {
            return null;
        }

        $subject = $this->impersonation->originalUser() ?? $user;
        $provider = SsoSession::provider($request, $subject);

        if ($provider === null || ! $this->mustEnd($request, $subject, $provider)) {
            return null;
        }

        $this->end($request, $auth, $provider);

        return $provider;
    }

    /**
     * Where the person goes to sign in again after the session of $provider
     * ended: its redirect route while the provider is enabled, else the login page.
     */
    public function reauthUrl(string $provider): string
    {
        $base = '/'.trim((string) config('martis.path', 'martis'), '/');

        return $this->manager->isEnabled($provider)
            ? $base.'/sso/'.rawurlencode($provider).'/redirect'
            : $base.'/login';
    }

    /**
     * Where an unauthenticated JSON request goes to sign in again when the SSO
     * session of its browser ended less than {@see SsoSession::ENDED_SECONDS}
     * seconds ago, else null (a plain 401). Asked by `martis.auth`, so the
     * concurrent requests of a page whose first request ended the session
     * answer the same way as that first request did.
     */
    public function pendingReauthUrl(Request $request): ?string
    {
        $provider = SsoSession::endedProvider($request);

        return $provider === null ? null : $this->reauthUrl($provider);
    }

    private function mustEnd(Request $request, Authenticatable $subject, string $provider): bool
    {
        $prefix = "martis.auth.sso.providers.{$provider}.";

        // Read (and so validate) the config first: a value that is set and
        // unusable throws on every SSO request, not only when it would matter.
        SsoSession::assertLimitsFitRemember($provider);
        $max = $this->minutes($prefix.'max_session_minutes');
        $every = $this->minutes($prefix.'revalidate_every_minutes');
        $revalidator = $every === null ? null : $this->revalidator($provider, $prefix.'revalidate_every_minutes');

        $now = now()->getTimestamp();
        $signedInAt = SsoSession::signedInAt($request);

        if (SsoSession::isRevoked((string) $subject->getAuthIdentifier(), $signedInAt)) {
            return true;
        }

        if ($max !== null && ($signedInAt === null || $now - $signedInAt >= $max * 60)) {
            return true;
        }

        if ($every === null || $revalidator === null) {
            return false;
        }

        // The sign-in is the first verification. A session with no check and
        // no sign-in time is asked right away.
        $lastChecked = SsoSession::checkedAt($request) ?? $signedInAt ?? 0;

        if ($now - $lastChecked < $every * 60) {
            return false;
        }

        // The provider threw not long ago: leave it alone for a while.
        $retryAt = SsoSession::retryAt($request);
        if ($retryAt !== null && $now < $retryAt) {
            return false;
        }

        try {
            $valid = $revalidator->stillValid($subject, SsoSession::externalId($request));
        } catch (Throwable $e) {
            // Keep the session, and ask again after the back-off.
            report($e);
            SsoSession::deferRevalidation($request, self::RETRY_AFTER_SECONDS);

            return false;
        }

        if ($valid) {
            SsoSession::markChecked($request);
        }

        return ! $valid;
    }

    /**
     * A positive number of minutes from config, or null when it is unset (or an
     * empty environment value). Anything else is a configuration error.
     */
    private function minutes(string $key): ?int
    {
        $value = config($key);

        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException(sprintf(
                'The [%s] config value must be a positive whole number of minutes, or null to turn it off, got %s.',
                $key,
                is_scalar($value) ? var_export($value, true) : get_debug_type($value),
            ));
        }

        return $value;
    }

    private function revalidator(string $provider, string $key): RevalidatesSessions
    {
        $class = $this->manager->providerClass($provider);
        $driver = $class === null ? null : app($class);

        if (! $driver instanceof RevalidatesSessions) {
            throw new InvalidArgumentException(sprintf(
                'The [%s] config value is set, but the SSO provider [%s] does not implement %s.',
                $key,
                $provider,
                RevalidatesSessions::class,
            ));
        }

        return $driver;
    }

    private function end(Request $request, StatefulGuard $auth, string $provider): void
    {
        $endedSessionId = $request->session()->getId();

        // This device only: Laravel's logout() would cycle the remember token
        // and so sign the user out of every other device too.
        method_exists($auth, 'logoutCurrentDevice') ? $auth->logoutCurrentDevice() : $auth->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        SsoSession::forget($request);
        RememberedTwoFactor::forget();

        // The requests of the same page that are still on their way (or sent
        // before the browser learns the new session id) are plain
        // unauthenticated requests: leave them the way back. After forget(),
        // which clears a marker.
        SsoSession::markEnded($request, $provider, $endedSessionId);
    }
}
