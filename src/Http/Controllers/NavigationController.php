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
        /** @var array<string, list<MenuItem>> $grouped */
        $grouped = [];

        /** @var list<MenuItem> $systemResources */
        $systemResources = [];

        foreach ($this->registry->list() as $resourceClass) {
            $instance = new $resourceClass;

            if (! $resourceClass::displayInNavigation()) {
                continue;
            }

            if (! $instance->authorizedToViewAny($request)) {
                continue;
            }

            // System-section resources skip the normal grouping loop and
            // are rendered alongside the Cache admin link in
            // `appendSystemSection`. Per-resource auth is already
            // enforced above, so the section reflects exactly what
            // this user is authorized to see.
            if ($instance->belongsToSystemSection()) {
                $systemResources[] = $instance->menuItem($request);

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
        // section appears whenever there is at least one item visible
        // to this user (cache admin link + any system-grouped resources).
        return $this->appendSystemSection($resolved, $request, $systemResources);
    }

    /**
     * Render the "System" sidebar section.
     *
     * Two distinct sources of items live here:
     *   1. The bundled Cache admin link (gated by
     *      `martis.cache.admin_ui` + the `manage-martis-cache` Gate).
     *   2. Resources marked with `belongsToSystemSection() === true`
     *      (e.g. ActionEventResource, plus host-app User/Role/Permission
     *      resources scaffolded by `martis:roles`). Per-resource auth
     *      is already enforced upstream in `buildNavigation`.
     *
     * The section appears whenever at least one of those sources
     * produces a visible item for the current user. It collapses
     * cleanly when the user is unauthorized for everything.
     *
     * @param  list<array<string, mixed>>  $sections
     * @param  list<MenuItem>  $systemResources
     * @return list<array<string, mixed>>
     */
    protected function appendSystemSection(array $sections, Request $request, array $systemResources = []): array
    {
        $items = [];

        // Resources first — host-app concerns (users, roles) typically
        // outweigh the Cache admin in the admin's mental model.
        foreach ($systemResources as $resourceItem) {
            $items[] = $resourceItem;
        }

        $cacheUiEnabled = (bool) config('martis.cache.admin_ui', true);
        $user = $request->user();

        if ($cacheUiEnabled && $user !== null && Gate::forUser($user)->allows('manage-martis-cache')) {
            $items[] = MenuItem::link(__('martis::messages.cache_admin_title'), '/system/cache')
                ->icon('database');
        }

        if ($items === []) {
            return $sections;
        }

        $section = MenuSection::make(__('martis::messages.system'), $items)
            ->icon('gear')
            ->collapsable(true)
            ->resolve($request);

        if ($section !== null) {
            $sections[] = $section;
        }

        return $sections;
    }
}
