<?php

namespace Martis\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Martis\Auth\GuardCatalog;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `martis.auth` middleware.
 *
 * It implements Laravel's AuthenticatesRequests marker, as Laravel's and
 * Nova's `Authenticate` do, so the router's middleware priority runs it where
 * it runs theirs: after the session starts and before the throttle, the route
 * bindings and any middleware that is not in the priority list. The throttle
 * of the protected routes then sees the user this guard signed in (per user,
 * not per IP, with a custom MARTIS_GUARD too). A middleware that has to run
 * before authentication (one that selects a tenant's database) belongs in
 * the app's middleware priority list, as it must for Laravel's `auth`.
 */
class MartisAuthenticate implements AuthenticatesRequests
{
    /** Reject unauthenticated requests: throw for JSON, redirect to login for HTML. */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var string|null $guardName */
        $guardName = config('martis.guard');

        /** @var Guard $auth */
        $auth = auth()->guard($guardName);

        if ($auth->check()) {
            // Make the Martis guard the request's default, as Laravel's own
            // `auth` middleware does: `$request->user()`, `auth()->user()`
            // and the policies then resolve the user this guard signed in,
            // not the app's default guard (null when MARTIS_GUARD differs).
            auth()->shouldUse($guardName ?? GuardCatalog::default());

            return $next($request);
        }

        if ($request->expectsJson()) {
            throw new AuthenticationException('Unauthenticated.');
        }

        return redirect()->route('martis.login');
    }
}
