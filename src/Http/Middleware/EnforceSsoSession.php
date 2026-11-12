<?php

declare(strict_types=1);

namespace Martis\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Martis\Sso\SsoManager;
use Martis\Sso\SsoSessionEnforcer;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `martis.sso.session` middleware (v2.21.0): ends a session that SSO
 * opened when it is too old, was revoked, or the provider says the person is
 * gone. A session that SSO did not open (password, magic link, invitation) is
 * never touched.
 *
 * The decision and the ending live in {@see SsoSessionEnforcer}, which also
 * answers for `/api/auth/user` (a route that runs without this middleware): see
 * there for the three checks, their order and what ending a session does. A
 * JSON request of an ended session gets `401 {message, sso_reauth_url}` and the
 * SPA navigates to the URL; any other request is redirected to it:
 * `/{martis}/sso/{name}/redirect`.
 *
 * It sits right after `martis.auth` in RouteMiddleware::authenticated(), ahead
 * of every gate (2FA, email verification, the forced password change), so a
 * session that must end does so before a gate answers for it. It is also on the
 * two routes that run `martis.auth` alone (the email verification notice and the
 * resend of the verification mail).
 */
class EnforceSsoSession
{
    public function __construct(private readonly SsoSessionEnforcer $enforcer) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var string|null $guardName */
        $guardName = config('martis.guard');

        /** @var StatefulGuard $auth */
        $auth = auth()->guard($guardName);

        $ended = $this->enforcer->endIfRequired($request, $auth);

        if ($ended === null) {
            return $next($request);
        }

        $url = $this->enforcer->reauthUrl($ended);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('martis::messages.sso_session_ended'),
                'sso_reauth_url' => $url,
            ], Response::HTTP_UNAUTHORIZED);
        }

        return redirect($url);
    }
}
