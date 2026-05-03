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
     * Lightweight count map for sidebar badges.
     *
     * Returns `{ "users": 1284, "invoices": 7, ... }` keyed by resource
     * `uriKey`. Skips menu structure, icons, system section, host-app
     * mainMenu resolver and per-section MenuItem rendering — only the
     * `menuCount()` calls run. Polling this from the SPA instead of
     * `/api/navigation` cuts the per-tick payload by 10× or more on a
     * typical sidebar.
     *
     * Cache scope mirrors `index()` (same user + locale key) but lives
     * under a separate `badges:` prefix so navigation cache and badge
     * cache invalidate independently. Both share the `navigation`
     * cache layer, so `php artisan martis:cache:clear navigation`
     * still wipes both.
     */
    public function badges(Request $request): JsonResponse
    {
        $userKey = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');
        $locale = app()->getLocale();
        $cacheKey = 'badges:'.$userKey.':'.$locale;

        $payload = $this->cache->remember('navigation', $cacheKey, function () use ($request) {
            return $this->buildBadges($request);
        });

        return response()->json($payload);
    }

    /**
     * Walk the registered resources and resolve only the count badge
     * for each one that opts in. Per-resource auth is enforced first,
     * matching the visibility rules of the full navigation payload.
     *
     * Failures inside a single resource's `menuCount()` are swallowed
     * (mirroring the behaviour in `MenuItem::resolveMenuCount`) so a
     * broken counter never poisons the response.
     *
     * @return array<string, int>
     */
    protected function buildBadges(Request $request): array
    {
        if (! (bool) config('martis.navigation.counts.enabled', true)) {
            return [];
        }

        $counts = [];

        foreach ($this->registry->list() as $resourceClass) {
            if (! $resourceClass::displayInNavigation()) {
                continue;
            }

            if (! $resourceClass::showMenuCount()) {
                continue;
            }

            $instance = new $resourceClass;
            if (! $instance->authorizedToViewAny($request)) {
                continue;
            }

            try {
                $count = $resourceClass::menuCount($request);
            } catch (\Throwable) {
                continue;
            }

            if ($count === null) {
                continue;
            }

            $counts[$resourceClass::uriKey()] = (int) $count;
        }

        return $counts;
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

        // Suppress auto-injection for system-section resources the host-app
        // already pulled into a custom `Martis::mainMenu(...)` payload. A
        // resource that opts into `belongsToSystemSection()` would otherwise
        // appear twice — once where the host placed it, and once in the
        // bundled "System" section — which surprised people building dense
        // sidebars (Real-Edge-FX/martis-package#NN).
        $referencedUriKeys = $this->collectResourceUriKeys($resolved);
        if ($referencedUriKeys !== []) {
            $systemResources = array_values(array_filter(
                $systemResources,
                function (MenuItem $item) use ($referencedUriKeys) {
                    $resourceClass = $item->resourceClass();
                    if ($resourceClass === null) {
                        return true;
                    }

                    return ! in_array($resourceClass::uriKey(), $referencedUriKeys, true);
                },
            ));
        }

        // Append the System section AFTER the host-app resolver runs so
        // a custom `Martis::mainMenu(...)` callback that replaces the
        // entire section list still sees the Cache admin entry. The
        // section appears whenever there is at least one item visible
        // to this user (cache admin link + any system-grouped resources).
        return $this->appendSystemSection($resolved, $request, $systemResources);
    }

    /**
     * Walk a resolved navigation payload and collect every resource
     * `uriKey` referenced inside it. Used to suppress double-rendering
     * of `belongsToSystemSection()` resources that a host already placed
     * via `Martis::mainMenu(...)`.
     *
     * Handles the heterogeneous shape emitted by `MenuSection::resolve()`:
     * top-level sections, nested `MenuGroup` containers (`type === 'group'`),
     * and leaf items of any factory type. Only `type === 'resource'`
     * leaves contribute a uriKey.
     *
     * @param  list<array<string, mixed>>  $sections
     * @return list<string>
     */
    protected function collectResourceUriKeys(array $sections): array
    {
        $found = [];

        $walk = function (array $items) use (&$walk, &$found): void {
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $type = $item['type'] ?? null;

                if ($type === 'group' && isset($item['items']) && is_array($item['items'])) {
                    $walk($item['items']);

                    continue;
                }

                if ($type === 'resource' && isset($item['uriKey']) && is_string($item['uriKey'])) {
                    $found[] = $item['uriKey'];
                }
            }
        };

        foreach ($sections as $section) {
            if (isset($section['items']) && is_array($section['items'])) {
                $walk($section['items']);
            }
        }

        return array_values(array_unique($found));
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
