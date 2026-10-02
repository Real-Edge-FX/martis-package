<?php

declare(strict_types=1);

namespace Martis\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Martis\Gates\SoftGate;
use Martis\MartisManager;
use Martis\Support\TranslatedLine;
use Symfony\Component\HttpFoundation\Response;

/**
 * The gate of a Tool's routes, `martis.tool:{uriKey}`: the request passes
 * only when the tool `{uriKey}` names is registered and the user may see it
 * (`authorizedToSee()`: its policy, then its `canSee()` callback).
 * Otherwise it answers 404, exactly as `GET /api/tools/{uriKey}` does for
 * that user, so a user cannot tell which tools the app ships.
 *
 * A tool soft-locked for the user (`lockedFor()`, `requirePlan()`) is refused
 * too (v2.4.0): its page answers `200` with the lock payload, and its
 * routes answer `403` with the same payload (a plain `403` for a request that
 * does not expect JSON), so the data a lock withholds from the page is not
 * served through the tool's own URLs. The lock is told only to a user who
 * may see the tool: `canSee()` and the policy win.
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
        $tool = $this->martis->findTool($request, $uriKey);

        if ($tool === null) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Tool not found.'], 404);
            }

            abort(404);
        }

        $lock = SoftGate::lockOf($tool, $request);

        if ($lock !== null) {
            if ($request->expectsJson()) {
                return SoftGate::refusal($lock);
            }

            abort(403, TranslatedLine::get('martis::messages.feature_locked'));
        }

        return $next($request);
    }
}
