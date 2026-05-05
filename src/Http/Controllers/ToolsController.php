<?php

declare(strict_types=1);

namespace Martis\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Martis\MartisManager;

/**
 * REST surface for Tools (free-form sidebar pages).
 *
 * Pairs with the SPA catch-all route — the React app navigates to
 * `/martis/tools/{uriKey}`, calls these endpoints to learn the tool
 * metadata, then renders the registered React component (looked up
 * by the tool's `component()` key) inside the standard layout.
 */
class ToolsController extends MartisController
{
    public function __construct(
        private readonly MartisManager $martis,
    ) {}

    /**
     * List every tool the current user is authorised to see.
     *
     * Each entry is `{ type, name, uriKey, icon, component, menuSection, meta }`
     * — the same shape `Tool::toArray()` produces. The frontend uses
     * this list to build navigation entries and to validate deep
     * links into `/martis/tools/{uriKey}`.
     *
     * @response array<int, array<string, mixed>>
     */
    public function index(Request $request): JsonResponse
    {
        $tools = array_map(
            static fn ($tool) => $tool->toArray(),
            $this->martis->resolveTools($request),
        );

        return response()->json($tools);
    }

    /**
     * Return a single tool's metadata by uriKey. Returns 404 when the
     * key is unknown OR when the tool's `canSee()` callback denies
     * the user — these cases are intentionally indistinguishable so
     * an unauthorised user cannot probe which tools exist.
     */
    public function show(Request $request, string $uriKey): JsonResponse
    {
        $tool = $this->martis->findTool($request, $uriKey);

        if ($tool === null) {
            return response()->json(['message' => 'Tool not found.'], 404);
        }

        // v1.11.0+ soft-gate route guard. When the tool is locked
        // for this user, return 200 with a locked shape so the SPA
        // renders the lock modal as a full-page state instead of
        // the tool component. Anti-bypass for direct URL access.
        $lock = method_exists($tool, 'lockPayloadFor')
            ? $tool->lockPayloadFor($request)
            : null;
        if ($lock !== null) {
            return response()->json([
                'locked' => true,
                'lock' => $lock,
                'tool' => $tool->toArray(),
            ]);
        }

        return response()->json($tool->toArray());
    }
}
