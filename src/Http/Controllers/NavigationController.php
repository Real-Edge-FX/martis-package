<?php

namespace Martis\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Martis\MartisManager;
use Martis\Menu\MenuSection;
use Martis\ResourceRegistry;

class NavigationController extends MartisController
{
    /** Create the controller and inject the resource registry. */
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly MartisManager $martis,
    ) {}

    /**
     * Return navigation groups for the React panel.
     *
     * Returns the navigation structure used by the sidebar, dashboard cards,
     * top navigation, and quick navigation search.
     *
     * Each section exposes a declarative `items` array containing resource and
     * link items. Consumers should render this response directly.
     *
     * **Example response:**
     * ```json
     * [
     *   {
     *     "label": "Quick Links",
     *     "items": [
     *       { "type": "link", "label": "Overview", "url": "/overview", "external": false }
     *     ]
     *   },
     *   {
     *     "label": "Settings",
     *     "items": [
     *       { "type": "resource", "uriKey": "users", "label": "Users", "url": "/resources/users", ... }
     *     ]
     *   }
     * ]
     * ```
     *
     * @response array<int, array{label: string|null, icon: string|null, collapsable: bool, items: array<int, array<string, mixed>>}>
     */
    public function index(Request $request): JsonResponse
    {
        /** @var array<string, list<\Martis\Menu\MenuItem>> $grouped */
        $grouped = [];

        foreach ($this->registry->list() as $resourceClass) {
            $instance = new $resourceClass;

            if (! $resourceClass::displayInNavigation()) {
                continue;
            }

            if (! $instance->authorizedToViewAny($request)) {
                continue;
            }

            $key = $instance->group() ?? '';

            if (! array_key_exists($key, $grouped)) {
                $grouped[$key] = [];
            }

            $grouped[$key][] = $instance->menuItem($request);
        }

        $sections = [];
        foreach ($grouped as $key => $resources) {
            $sections[] = MenuSection::make($key === '' ? null : $key, $resources);
        }

        return response()->json($this->martis->resolveMainMenu($request, $sections));
    }
}
