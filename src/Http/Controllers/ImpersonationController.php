<?php

declare(strict_types=1);

namespace Martis\Http\Controllers;

use ArgumentCountError;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Martis\Impersonation\ImpersonationManager;
use Martis\Impersonation\ImpersonationRefusedException;
use Throwable;

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
     *   - 403 when the `martis-impersonate` gate denies the request, for
     *         this target (the gate receives it as its second argument,
     *         v2.4.0) or at all, or the operator's `canImpersonate()`
     *         hook says no.
     *   - 404 when the target user does not exist (and the gate would
     *         not have refused the operator anyway).
     *   - 422 when the target is the current user, is `NotImpersonable`
     *         or says so through `canBeImpersonated()`, or impersonation
     *         is already active.
     *   - 200 with the snapshot when the start succeeds.
     */
    public function start(Request $request, int|string $userId): JsonResponse
    {
        if (! $this->impersonation->enabled()) {
            return response()->json(['message' => 'Impersonation is disabled.'], 503);
        }

        $guard = $this->impersonation->guard();
        $operator = Auth::guard($guard)->user();

        if ($operator !== null && ! $this->impersonation->operatorMayImpersonate($operator)) {
            return $this->forbidden();
        }

        $target = Auth::guard($guard)->getProvider()->retrieveById($userId);

        // The gate judges the pair, so the target is loaded first. A target
        // that does not exist is answered like one the gate refuses, as long
        // as the gate refuses the operator: an operator who may not
        // impersonate must not learn which ids exist from a 404.
        if (! $this->gateAllows($target)) {
            return $this->forbidden();
        }

        if ($target === null) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        try {
            $this->impersonation->start($target);
        } catch (ImpersonationRefusedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->impersonation->snapshot());
    }

    /**
     * The `martis-impersonate` gate, with the target as its second argument
     * (`fn ($operator, $target)`, v2.4.0), so a consumer can refuse a target
     * that outranks the operator. A one-argument closure (`fn ($operator)`)
     * ignores the argument, as before.
     *
     * With no target (an id that does not exist) the gate runs as it did
     * before v2.4.0, with the operator alone: a closure that needs the
     * target cannot be called that way, which refuses the request like any
     * other denial.
     */
    private function gateAllows(?Authenticatable $target): bool
    {
        if ($target === null) {
            try {
                return Gate::allows('martis-impersonate');
            } catch (Throwable $e) {
                // PHP raises ArgumentCountError for the missing second
                // argument of a user closure; anything else is a real error.
                if ($e instanceof ArgumentCountError) {
                    return false;
                }

                throw $e;
            }
        }

        return Gate::allows('martis-impersonate', [$target]);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['message' => 'Forbidden.'], 403);
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
