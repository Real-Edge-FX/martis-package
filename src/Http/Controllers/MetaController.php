<?php

declare(strict_types=1);

namespace Martis\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Martis\Auth\GuardCatalog;

/**
 * Meta endpoints — shipped reference data that powers admin dropdowns.
 *
 * Currently exposes `GET /martis/api/_meta/guards` so consumers can
 * render their own auth-guard selectors (e.g. on a custom permission
 * form) without re-implementing the lookup. The bundled `GuardSelect`
 * field uses `GuardCatalog` directly server-side, so it does not
 * depend on this endpoint at runtime.
 *
 * All endpoints sit behind the `martis.auth` middleware group — guests
 * never see them, even if they discover the URL.
 *
 * v1.8.0.
 */
class MetaController extends Controller
{
    /**
     * List the auth guards configured in `config/auth.guards`, plus the
     * app's default guard. Returns `{guards: ["api","web"], default: "web"}`.
     */
    public function guards(): JsonResponse
    {
        return response()->json([
            'guards' => GuardCatalog::available(),
            'default' => GuardCatalog::default(),
        ]);
    }
}
