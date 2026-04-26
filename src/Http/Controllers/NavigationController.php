<?php

namespace Martis\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Martis\Cache\MartisCache;
use Martis\MartisManager;
use Martis\Menu\MenuItem;
use Martis\Menu\MenuSection;
use Martis\ResourceRegistry;

class NavigationController extends MartisController
{
    /** Create the controller and inject the resource registry. */
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly MartisManager $martis,
        private readonly MartisCache $cache,
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
        // Scope cache by user — `displayInNavigation()` and per-resource
        // `authorizedToViewAny()` checks depend on policies, so different
        // users will see different sections.
        $userKey = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');
        $locale = app()->getLocale();
        $cacheKey = $userKey.':'.$locale;

        $payload = $this->cache->remember('navigation', $cacheKey, function () use ($request) {
            return $this->buildNavigation($request);
        });

        return response()->json($payload);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function buildNavigation(Request $request): array
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

        $resolved = $this->martis->resolveMainMenu($request, $sections);

        // Append the System section AFTER the host-app resolver runs so
        // a custom `Martis::mainMenu(...)` callback that replaces the
        // entire section list still sees the Cache admin entry. The
        // section appears only when admin UI is enabled and the user
        // passes the `manage-martis-cache` Gate.
        return $this->appendSystemSection($resolved, $request);
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    protected function appendSystemSection(array $sections, Request $request): array
    {
        if (! config('martis.cache.admin_ui', true)) {
            return $sections;
        }

        $user = $request->user();
        if ($user === null) {
            return $sections;
        }

        if (! Gate::forUser($user)->allows('manage-martis-cache')) {
            return $sections;
        }

        $section = MenuSection::make(__('martis::messages.system'), [
            MenuItem::link(__('martis::messages.cache_admin_title'), '/system/cache')
                ->icon('database'),
        ])
            ->icon('gear')
            ->collapsable(true)
            ->resolve($request);

        if ($section !== null) {
            $sections[] = $section;
        }

        return $sections;
    }
}
