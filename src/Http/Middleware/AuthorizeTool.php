<?php

declare(strict_types=1);

namespace Martis\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Martis\MartisManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * The gate of a Tool's routes, `martis.tool:{uriKey}`: the request passes
 * only when the tool `{uriKey}` names is registered and the user may see it
 * (`authorizedToSee()`: its policy, then its `canSee()` callback).
 * Otherwise it answers 404, exactly as `GET /api/tools/{uriKey}` does for
 * that user, so a user cannot tell which tools the app ships.
 *
 * `ToolRoutes::middleware()` puts it last on a tool's routes, after the
 * stack of the protected API routes. Nova guards a tool's routes the same
 * way with the tool's `Authorize` middleware, which answers 403 instead.
 */
class AuthorizeTool
{
    public function __construct(
        private readonly MartisManager $martis,
    ) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next, string $uriKey): Response
    {
        if ($this->martis->findTool($request, $uriKey) === null) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Tool not found.'], 404);
            }

            abort(404);
        }

        return $next($request);
    }
}
