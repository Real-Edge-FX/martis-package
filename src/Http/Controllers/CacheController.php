<?php

declare(strict_types=1);

namespace Martis\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Martis\Cache\MartisCache;
use Martis\Http\Resources\JsonErrorResponse;

/**
 * REST endpoints powering the "Sistema → Cache" admin page (Task 17).
 *
 * Authorization: every endpoint goes through Gate `manage-martis-cache`.
 * The default Gate definition (registered in `MartisServiceProvider`)
 * allows any authenticated user — host applications should tighten it
 * in their own service provider:
 *
 *     Gate::define('manage-martis-cache', fn ($u) => $u->is_admin);
 */
class CacheController extends Controller
{
    public function __construct(private readonly MartisCache $cache) {}

    /** GET /api/cache — full status snapshot for the admin page. */
    public function status(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return JsonErrorResponse::forbidden(__('martis::messages.unauthorized'))->toResponse();
        }

        return new JsonResponse([
            'data' => $this->cache->status(),
            'meta' => [
                'master_enabled' => $this->cache->masterEnabled(),
                'types' => MartisCache::types(),
            ],
        ]);
    }

    /** POST /api/cache/clear — invalidate one or every cache type. */
    public function clear(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return JsonErrorResponse::forbidden(__('martis::messages.unauthorized'))->toResponse();
        }

        $type = $request->input('type');
        if ($type !== null && ! in_array($type, MartisCache::types(), true)) {
            return JsonErrorResponse::validation(['type' => [__('martis::messages.cache_unknown_type')]])->toResponse();
        }

        $this->cache->clear($type);

        return new JsonResponse(['data' => $this->cache->status()]);
    }

    /** POST /api/cache/disable — runtime disable a single type. */
    public function disable(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return JsonErrorResponse::forbidden(__('martis::messages.unauthorized'))->toResponse();
        }

        $type = (string) $request->input('type', '');
        if (! in_array($type, MartisCache::types(), true)) {
            return JsonErrorResponse::validation(['type' => [__('martis::messages.cache_unknown_type')]])->toResponse();
        }

        $this->cache->disable($type);

        return new JsonResponse(['data' => $this->cache->status()]);
    }

    /** POST /api/cache/enable — runtime re-enable a single type. */
    public function enable(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return JsonErrorResponse::forbidden(__('martis::messages.unauthorized'))->toResponse();
        }

        $type = (string) $request->input('type', '');
        if (! in_array($type, MartisCache::types(), true)) {
            return JsonErrorResponse::validation(['type' => [__('martis::messages.cache_unknown_type')]])->toResponse();
        }

        $this->cache->enable($type);

        return new JsonResponse(['data' => $this->cache->status()]);
    }

    /** POST /api/cache/reset-override — fall back to config for a type. */
    public function resetOverride(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return JsonErrorResponse::forbidden(__('martis::messages.unauthorized'))->toResponse();
        }

        $type = (string) $request->input('type', '');
        if (! in_array($type, MartisCache::types(), true)) {
            return JsonErrorResponse::validation(['type' => [__('martis::messages.cache_unknown_type')]])->toResponse();
        }

        $this->cache->clearOverride($type);

        return new JsonResponse(['data' => $this->cache->status()]);
    }

    protected function authorized(Request $request): bool
    {
        $user = $request->user();
        if ($user === null) {
            return false;
        }

        return Gate::forUser($user)->allows('manage-martis-cache');
    }
}
