<?php

declare(strict_types=1);

namespace Martis\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Martis\Auth\PasswordChangeRequirement;
use Symfony\Component\HttpFoundation\Response;

/**
 * The forced password change gate (`martis.password.changed`, v2.3.0): a
 * user the app flags (PasswordChangeRequirement) is held until they choose a
 * new password. A JSON request gets `409 {"password_change_required": true}`,
 * a page a redirect to the change page.
 *
 * It runs last in RouteMiddleware::verified(), after the 2FA challenge, email
 * verification and the panel gate. The change page and its endpoint run
 * RouteMiddleware::passwordChange(), the same stack without it. The 2FA
 * challenge page, which the SPA catch-all serves inside the stack, passes:
 * the challenge comes first.
 */
class EnsurePasswordIsChanged
{
    /**
     * @param  Closure(Request): mixed  $next
     */
    public function handle(Request $request, Closure $next): mixed
    {
        /** @var string|null $guard */
        $guard = config('martis.guard');

        if (! PasswordChangeRequirement::requiredFor($request, auth()->guard($guard)->user())) {
            return $next($request);
        }

        $basePath = trim((string) config('martis.path', 'martis'), '/');

        if ($request->is(($basePath !== '' ? $basePath.'/' : '').'2fa/challenge')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'password_change_required' => true,
                'message' => 'Password change required.',
            ], Response::HTTP_CONFLICT);
        }

        return redirect(PasswordChangeRequirement::pageUrl());
    }
}
