<?php

declare(strict_types=1);

namespace Martis\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the URL of a page request as the session's intended URL when nobody is
 * signed in, then sends the visitor to the login page (v2.21.0).
 *
 * `martis.auth` redirects an anonymous visitor to the login page without
 * remembering where they were going. The SSO issue route
 * (`/{martis}/sso/issue/{audience}`) runs this first, so a sign-in that ends in
 * `redirect()->intended()` (the form login, an SSO callback) brings the person
 * back to it. A request that expects JSON, or is not a GET, passes on and is
 * answered by `martis.auth` as before.
 *
 * It implements Laravel's AuthenticatesRequests marker, as `martis.auth` does,
 * so the router's middleware priority keeps it ahead of `martis.auth` (an
 * unmarked middleware would be sorted after the authentication it must
 * precede).
 */
class RememberIntendedUrl implements AuthenticatesRequests
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var string|null $guard */
        $guard = config('martis.guard');

        if (auth()->guard($guard)->check() || ! $request->isMethod('GET') || $request->expectsJson()) {
            return $next($request);
        }

        return redirect()->guest(route('martis.login'));
    }
}
