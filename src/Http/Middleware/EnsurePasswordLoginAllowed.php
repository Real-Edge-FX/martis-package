<?php

declare(strict_types=1);

namespace Martis\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Martis\Auth\PasswordLogin;
use Martis\Support\TranslatedLine;
use Symfony\Component\HttpFoundation\Response;

/**
 * `martis.password_login` (v2.21.0): while `martis.auth.password_login.enabled`
 * is false, the password sign-in routes refuse with a 403 before their
 * controller reads any credential, the same answer for every address. An
 * address of `martis.auth.password_login.break_glass_emails` passes to the
 * normal flow. The throttles of the route run before this.
 */
class EnsurePasswordLoginAllowed
{
    /**
     * @param  Closure(Request): mixed  $next
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if (PasswordLogin::enabled() || PasswordLogin::isBreakGlass($request->input('email'))) {
            return $next($request);
        }

        $message = TranslatedLine::get('martis::auth.password_login_disabled');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
        }

        abort(Response::HTTP_FORBIDDEN, $message);
    }
}
