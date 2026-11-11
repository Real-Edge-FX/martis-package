<?php

declare(strict_types=1);

namespace Martis\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Martis\Auth\RememberedTwoFactor;
use Martis\Impersonation\ImpersonationManager;
use Martis\Sso\Contracts\RevalidatesSessions;
use Martis\Sso\SsoManager;
use Martis\Sso\SsoSession;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The `martis.sso.session` middleware (v2.21.0): ends a session that SSO
 * opened when it is too old, was revoked, or the provider says the person is
 * gone. A session that SSO did not open (password, magic link, invitation) is
 * never touched.
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
 *     kept, and the provider is asked again on the next request instead of
 *     after an interval: an IdP outage must not sign every user out, and
 *     `max_session_minutes` is the hard bound.
 *
 * Ending a session mirrors a local sign-out (guard logout, session invalidate
 * and a new CSRF token, the SSO origin, the remembered 2FA device), without
 * the federated logout: the IdP's session is the one that is gone, or the
 * person is about to sign in at it again. A JSON request then gets
 * `401 {message, sso_reauth_url}` and the SPA navigates to the URL; any other
 * request is redirected to it: `/{martis}/sso/{name}/redirect`.
 *
 * It sits right after `martis.auth` in RouteMiddleware::authenticated(), ahead
 * of every gate (2FA, email verification, the forced password change), so a
 * session that must end does so before a gate answers for it. While an
 * impersonation is active the session belongs to the operator who signed in
 * through SSO, so the checks run against the operator.
 */
class EnforceSsoSession
{
    public function __construct(
        private readonly SsoManager $manager,
        private readonly ImpersonationManager $impersonation,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var string|null $guardName */
        $guardName = config('martis.guard');

        /** @var StatefulGuard $auth */
        $auth = auth()->guard($guardName);
        $user = $auth->user();

        if ($user === null || ! $request->hasSession()) {
            return $next($request);
        }

        $subject = $this->impersonation->originalUser() ?? $user;
        $provider = SsoSession::provider($request, $subject);

        if ($provider === null || ! $this->mustEnd($request, $subject, $provider)) {
            return $next($request);
        }

        return $this->end($request, $auth, $provider);
    }

    private function mustEnd(Request $request, Authenticatable $subject, string $provider): bool
    {
        $prefix = "martis.auth.sso.providers.{$provider}.";

        // Read (and so validate) the config first: a value that is set and
        // unusable throws on every SSO request, not only when it would matter.
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

        // The sign-in is the first verification; a re-login by the remember
        // cookie counts from the sign-in it restored. A session with neither
        // is asked right away.
        $lastChecked = SsoSession::checkedAt($request) ?? $signedInAt ?? 0;

        if ($now - $lastChecked < $every * 60) {
            return false;
        }

        try {
            $valid = $revalidator->stillValid($subject, SsoSession::externalId($request));
        } catch (Throwable $e) {
            // Keep the session and leave the clock where it was: the next request asks again.
            report($e);

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

    private function end(Request $request, StatefulGuard $auth, string $provider): Response
    {
        // What AuthController::logout() does locally, without the federated logout.
        $auth->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        SsoSession::forget($request);
        RememberedTwoFactor::forget();

        $base = '/'.trim((string) config('martis.path', 'martis'), '/');
        $url = $this->manager->isEnabled($provider)
            ? $base.'/sso/'.rawurlencode($provider).'/redirect'
            : $base.'/login';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('martis::messages.sso_session_ended'),
                'sso_reauth_url' => $url,
            ], Response::HTTP_UNAUTHORIZED);
        }

        return redirect($url);
    }
}
