<?php

namespace Martis\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MartisAuthenticate
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
            if (is_string($guardName) && $guardName !== '') {
                auth()->shouldUse($guardName);
            }

            return $next($request);
        }

        if ($request->expectsJson()) {
            throw new AuthenticationException('Unauthenticated.');
        }

        return redirect()->route('martis.login');
    }
}
