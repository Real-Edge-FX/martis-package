<?php

namespace Martis\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Martis\ResourceRegistry;

class NavigationController extends MartisController
{
    public function __construct(
        private readonly ResourceRegistry $registry,
    ) {}

    /**
     * Return navigation groups for the React sidebar.
     *
     * Returns the navigation structure used by the React panel.
     * Resources are grouped by their `group()` property.
     * Resources without a group appear with `label: null`.
     * Only resources the authenticated user is authorised to view are included.
     *
     * **Example response:**
     * ```json
     * [
     *   {
     *     "label": null,
     *     "resources": [
     *       { "uriKey": "users", "label": "Users", "icon": "Users", ... }
     *     ]
     *   },
     *   {
     *     "label": "Settings",
     *     "resources": [
     *       { "uriKey": "settings", "label": "Settings", "icon": "Gear", ... }
     *     ]
     *   }
     * ]
     * ```
     *
     * @response array<int, array{label: string|null, resources: array<int, array{uriKey: string, label: string, singularLabel: string, icon: string, group: string|null}>}>
     */
    public function index(Request $request): JsonResponse
    {
        /** @var array<string, list<array<string, mixed>>> $grouped */
        $grouped = [];

        foreach ($this->registry->list() as $resourceClass) {
            $instance = new $resourceClass;

            if (! $instance->authorizedToViewAny($request)) {
                continue;
            }

            $key = $instance->group() ?? '';

            if (! array_key_exists($key, $grouped)) {
                $grouped[$key] = [];
            }

            $grouped[$key][] = $instance->toArray();
        }

        $result = [];
        foreach ($grouped as $key => $resources) {
            $result[] = [
                'label' => $key === '' ? null : $key,
                'resources' => $resources,
            ];
        }

        return response()->json($result);
    }
}
