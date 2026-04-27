<?php

declare(strict_types=1);

namespace Martis\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Martis\Impersonation\ImpersonationManager;
use RuntimeException;

/**
 * REST surface for the v0.10 impersonation subsystem.
 *
 * Endpoints are guarded by two layers:
 *
 *   1. The `martis.impersonation.enabled` master switch — when off
 *      every endpoint returns 503 (the feature is disabled). This
 *      lets a deploy globally turn impersonation off without code
 *      changes.
 *   2. The `martis-impersonate` Gate — consumers define this gate
 *      themselves; the package ships no default. When the gate
 *      denies the request the endpoints return 403.
 *
 * Both checks are intentional: the master switch protects against
 * accidentally-enabled gates, the gate protects against unprivileged
 * users who happen to know the URL.
 */
class ImpersonationController extends MartisController
{
    public function __construct(
        private readonly ImpersonationManager $impersonation,
    ) {}

    /**
     * Snapshot of the impersonation state — used by the React banner
     * to decide whether to render the "you are impersonating X" bar.
     */
    public function status(Request $request): JsonResponse
    {
        if (! $this->impersonation->enabled()) {
            return response()->json([
                'active' => false,
                'enabled' => false,
                'original' => null,
                'target' => null,
                'started_at' => null,
            ]);
        }

        return response()->json($this->impersonation->snapshot());
    }

    /**
     * Begin impersonating the user with the given id.
     *
     * Returns:
     *   - 503 when impersonation is disabled by config.
     *   - 403 when the `martis-impersonate` gate denies the request.
     *   - 404 when the target user does not exist.
     *   - 422 when the target is the current user, or impersonation
     *         is already active.
     *   - 200 with the snapshot when the start succeeds.
     */
    public function start(Request $request, int|string $userId): JsonResponse
    {
        if (! $this->impersonation->enabled()) {
            return response()->json(['message' => 'Impersonation is disabled.'], 503);
        }

        if (! Gate::allows('martis-impersonate')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $guard = $this->impersonation->guard();
        $target = Auth::guard($guard)->getProvider()->retrieveById($userId);

        if ($target === null) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        try {
            $this->impersonation->start($target);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->impersonation->snapshot());
    }

    /**
     * Stop the current impersonation session and restore the operator.
     * Idempotent — calling this when no impersonation is active is a
     * no-op that returns the (inactive) snapshot.
     */
    public function stop(Request $request): JsonResponse
    {
        if (! $this->impersonation->enabled()) {
            return response()->json(['message' => 'Impersonation is disabled.'], 503);
        }

        $this->impersonation->stop();

        return response()->json($this->impersonation->snapshot());
    }
}
