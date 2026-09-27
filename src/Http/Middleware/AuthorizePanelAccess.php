<?php

declare(strict_types=1);

namespace Martis\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Martis\Auth\PanelAccess;
use Martis\Http\Resources\JsonErrorResponse;
use Martis\Support\TranslatedLine;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `martis.authorize` middleware: the `viewMartis` gate (PanelAccess).
 *
 * `RouteMiddleware::verified()` runs it after authentication, the 2FA
 * challenge and email verification, so it guards the SPA shell, the
 * protected API, the Tool routes that run the Martis API stack
 * (ToolRoutes::middleware) and any app route on the `martis.api` group.
 * The sign-in routes, logout, the 2FA challenge and the email
 * verification routes stay outside it, so a refused user can sign out.
 */
class AuthorizePanelAccess
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (PanelAccess::allows($request->user())) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return JsonErrorResponse::forbidden(TranslatedLine::get('martis::messages.panel_forbidden'))->toResponse();
        }

        return response()->view('martis::app', ['panelForbidden' => true], 403);
    }
}
