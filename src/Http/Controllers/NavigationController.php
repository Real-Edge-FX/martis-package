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
     * Groups resources by their group() value. Resources without a group
     * appear under the empty-string key, serialised as null label in the JSON.
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
