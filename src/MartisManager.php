<?php

namespace Martis;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Martis\Contracts\DashboardContract;
use Martis\Contracts\ToolContract;
use Martis\Menu\Menu;
use Martis\Menu\MenuGroup;
use Martis\Menu\MenuItem;
use Martis\Menu\MenuSection;
use Martis\Support\ConfigCallable;
use Martis\Support\InstalledVersion;
use Martis\Tools\ToolRoutes;
use Throwable;

class MartisManager
{
    /** @var Closure(Request, Menu): (Menu|array<int, MenuSection>|null)|null */
    protected ?Closure $mainMenuResolver = null;

    /** @var Closure(Request): string|null */
    protected ?Closure $pageTitleResolver = null;

    /** @var list<Closure(Request): mixed> checked at resolve time: an iterable of entries */
    protected array $commandPaletteResolvers = [];

    /** @var (Closure(Builder<Model>, Request): mixed)|null */
    protected ?Closure $notificationScope = null;

    /** @var list<class-string<DashboardContract>|DashboardContract> */
    protected array $dashboards = [];

    /** @var list<class-string<ToolContract>|ToolContract> */
    protected array $tools = [];

    /** Tracks whether `bootTools()` already ran for this request lifecycle. */
    protected bool $bootedTools = false;

    /**
     * Register a custom main menu builder.
     *
     * @param  Closure(Request, Menu): (Menu|array<int, MenuSection>|null)  $resolver
     */
    public function mainMenu(Closure $resolver): static
    {
        $this->mainMenuResolver = $resolver;

        return $this;
    }

    // -------------------------------------------------------------------------
    // Dashboards
    // -------------------------------------------------------------------------

    /**
     * Register dashboards for the application.
     *
     * @param  list<class-string<DashboardContract>|DashboardContract>  $dashboards
     */
    public function dashboards(array $dashboards): static
    {
        $this->dashboards = $dashboards;

        return $this;
    }

    /**
     * Resolve all registered dashboards.
     *
     * @return list<DashboardContract>
     */
    public function resolveDashboards(Request $request): array
    {
        $resolved = [];

        foreach ($this->dashboards as $dashboard) {
            $instance = is_string($dashboard) ? new $dashboard : $dashboard;

            if ($instance instanceof DashboardContract && $instance->authorizedToSee($request)) {
                $resolved[] = $instance;
            }
        }

        return $resolved;
    }

    // -------------------------------------------------------------------------
    // Tools — free-form sidebar pages (v0.10)
    // -------------------------------------------------------------------------

    /**
     * Register Tools (free-form sidebar pages) for the application.
     *
     * Tools are non-resource, non-dashboard, non-lens admin pages —
     * import wizards, system status, ad-hoc reports, third-party
     * embeds. They get an automatic route at `/martis/tools/{uriKey}`
     * and surface in the menu under `menuSection()` (or a default
     * "Tools" section).
     *
     * Pass either a class-string or an instance. Class-strings are
     * lazily instantiated per-request; instances are kept verbatim.
     *
     * @param  list<class-string<ToolContract>|ToolContract>  $tools
     */
    public function tools(array $tools): static
    {
        $this->tools = $tools;
        // Re-arm the boot lifecycle: registering a new tool list
        // means the host has new tools to initialise. Without this,
        // tools registered after the first request (in tests, or
        // in a deferred service provider) would never have their
        // `boot()` hook fired.
        $this->bootedTools = false;

        return $this;
    }

    /**
     * Append Tools to the registered list with dedup by class-string.
     *
     * Used by `ToolDiscovery` (auto-registration) so manual
     * `Martis::tools([...])` calls and discovery can coexist without
     * stomping each other. Pass either class-strings or instances —
     * dedup is keyed on `is_string($t) ? $t : $t::class`.
     *
     * @param  list<class-string<ToolContract>|ToolContract>  $tools
     */
    public function mergeTools(array $tools): static
    {
        $known = [];
        foreach ($this->tools as $existing) {
            $known[is_string($existing) ? $existing : $existing::class] = true;
        }

        foreach ($tools as $candidate) {
            $key = is_string($candidate) ? $candidate : $candidate::class;
            if (isset($known[$key])) {
                continue;
            }
            $this->tools[] = $candidate;
            $known[$key] = true;
        }

        $this->bootedTools = false;

        return $this;
    }

    /**
     * Resolve all registered tools the current user is authorised to see.
     *
     * @return list<ToolContract>
     */
    public function resolveTools(Request $request): array
    {
        $resolved = [];

        foreach ($this->tools as $tool) {
            $instance = is_string($tool) ? new $tool : $tool;

            if ($instance instanceof ToolContract && $instance->authorizedToSee($request)) {
                $resolved[] = $instance;
            }
        }

        return $resolved;
    }

    /**
     * Look up a single registered tool by its uriKey, applying the
     * same authorisation gate as `resolveTools()`. Returns null when
     * the key is unknown or the user is not allowed to see it.
     */
    public function findTool(Request $request, string $uriKey): ?ToolContract
    {
        foreach ($this->resolveTools($request) as $tool) {
            if ($tool->uriKey() === $uriKey) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * Run each registered tool's `boot()` hook exactly once. Called
     * from `MartisServiceProvider::boot()` after Martis has loaded
     * its own routes / views / config so the tool-owned routes and
     * publishables register on top of an initialised package.
     *
     * Tools registered as class-strings are instantiated lazily into
     * a long-lived instance held in `$this->tools` so `resolveTools()`
     * sees the same object the boot ran against (otherwise a tool
     * that mutated internal state during boot would lose it on the
     * first request).
     *
     * Idempotent — a re-run is a no-op once `$booted` flips on.
     */
    public function bootTools(): void
    {
        if ($this->bootedTools) {
            return;
        }

        $this->bootedTools = true;

        foreach ($this->tools as $i => $tool) {
            $instance = is_string($tool) ? new $tool : $tool;

            if (! $instance instanceof ToolContract) {
                continue;
            }

            // Materialise the instance back into the registry so
            // later resolveTools() / findTool() calls reuse the same
            // booted object.
            $this->tools[$i] = $instance;

            try {
                $instance->boot();
            } catch (Throwable $e) {
                // A broken tool must not bring down the whole admin
                // panel boot. Surface the error through the standard
                // logger and keep going.
                if (function_exists('logger')) {
                    logger()->error('[martis] Tool boot() threw — registration kept, hook skipped.', [
                        'tool' => $instance::class,
                        'uriKey' => $instance->uriKey(),
                        'exception' => $e,
                    ]);
                }
            }
        }

        // A tool that registers its routes itself, with the v1.x
        // ['web', 'martis.auth'] the docs showed, skips the 2FA challenge:
        // say so once. Never let the check break the boot.
        try {
            ToolRoutes::warnAboutRegisteredRoutes($this->tools);
        } catch (Throwable $e) {
            report($e);
        }
    }

    // -------------------------------------------------------------------------
    // Menus
    // -------------------------------------------------------------------------

    public function forgetMainMenu(): static
    {
        $this->mainMenuResolver = null;

        return $this;
    }

    /**
     * @param  list<MenuSection>  $defaultSections
     * @return list<array<string, mixed>>
     */
    public function resolveMainMenu(Request $request, array $defaultSections): array
    {
        $menu = Menu::make($defaultSections);

        if ($this->mainMenuResolver instanceof Closure) {
            $resolved = call_user_func($this->mainMenuResolver, $request, $menu);

            if ($resolved instanceof Menu) {
                $menu = $resolved;
            } elseif (is_array($resolved)) {
                $menu = Menu::make($resolved);
            }
        }

        $sections = [];
        foreach ($menu->all() as $section) {
            $resolvedSection = $section->resolve($request);

            if ($resolvedSection !== null) {
                $sections[] = $resolvedSection;
            }
        }

        return array_values($sections);
    }

    // -------------------------------------------------------------------------
    // Page title
    // -------------------------------------------------------------------------

    /**
     * Register a resolver that computes the `<title>` for each admin request.
     * The closure receives the Request so the title can depend on the route,
     * the authenticated user, a resource being viewed, or any query parameter.
     *
     * A closure registered here wins over `config('martis.brand.page_title')`
     * and the bundled translation fallback.
     *
     * @param  Closure(Request): (string|null)  $resolver
     */
    public function pageTitleUsing(Closure $resolver): static
    {
        $this->pageTitleResolver = $resolver;

        return $this;
    }

    public function forgetPageTitle(): static
    {
        $this->pageTitleResolver = null;

        return $this;
    }

    /**
     * Resolve the effective page title for the current request.
     *
     * Resolution order (highest priority first):
     *   1. Closure registered via `Martis::pageTitleUsing(...)`
     *   2. `config('martis.brand.page_title')` — literal string, invokable class
     *      name or `[Class::class, 'staticMethod']` array (see {@see ConfigCallable})
     *   3. Automatic inference from the request path (resource label, profile, etc.)
     *   4. `__('martis::navigation.page_title_default', ['brand' => ...])` — i18n fallback
     */
    public function resolvePageTitle(Request $request): string
    {
        if ($this->pageTitleResolver instanceof Closure) {
            $resolved = call_user_func($this->pageTitleResolver, $request);
            if (is_string($resolved) && $resolved !== '') {
                return $resolved;
            }
        }

        // A string is the literal title unless it names an invokable
        // class. It is never tried as a callable: "Mail", "Date" or "Link"
        // name PHP functions.
        $configured = config('martis.brand.page_title');
        if (is_string($configured) && $configured !== '' && ! ConfigCallable::isInvokableClass($configured)) {
            return $configured;
        }

        $resolver = ConfigCallable::resolve($configured, 'martis.brand.page_title');
        if ($resolver !== null) {
            $resolved = $resolver($request);
            if (is_string($resolved) && $resolved !== '') {
                return $resolved;
            }
        }

        /** @var string $brand */
        $brand = config('martis.brand.name', 'Martis');

        $inferred = $this->inferTitleFromPath($request, $brand);
        if ($inferred !== null) {
            return $inferred;
        }

        /** @var string $default */
        $default = trans('martis::navigation.page_title_default', ['brand' => $brand]);

        return $default !== 'martis::navigation.page_title_default' ? $default : "{$brand} Admin";
    }

    /**
     * Infer a page title from the request path by mapping known Martis
     * routes to human-readable labels. Returns null when the path does
     * not match a known pattern — the caller falls back to the translation.
     */
    protected function inferTitleFromPath(Request $request, string $brand): ?string
    {
        $basePath = trim((string) config('martis.path', 'martis'), '/');
        $path = trim($request->path(), '/');

        // Strip the configured base path prefix so pattern matching
        // works regardless of the mount point.
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $remainder = ltrim(substr($path, strlen($basePath)), '/');
        } else {
            $remainder = $path;
        }

        if ($remainder === '' || $remainder === 'login') {
            return null;
        }

        // Profile page.
        if ($remainder === 'profile') {
            $label = trans('martis::navigation.profile');
            if ($label === 'martis::navigation.profile') {
                $label = 'Profile';
            }

            return "{$label} · {$brand}";
        }

        // /resources/{uriKey}[/...]
        if (preg_match('#^resources/([^/]+)(?:/(.*))?$#', $remainder, $matches) === 1) {
            $uriKey = $matches[1];
            $tail = $matches[2] ?? '';

            $registry = $this->resolveResourceRegistry();
            if ($registry === null || ! $registry->has($uriKey)) {
                return null;
            }

            /** @var class-string<\Martis\Resource> $class */
            $class = $registry->get($uriKey);

            /** @var string $label */
            $label = $class::label();

            /** @var string $singular */
            $singular = $class::singularLabel();

            // Index: /resources/{uriKey}
            if ($tail === '') {
                return "{$label} · {$brand}";
            }

            // Create: /resources/{uriKey}/new
            if ($tail === 'new') {
                $action = trans('martis::navigation.create');
                $action = $action === 'martis::navigation.create' ? 'Create' : $action;

                return "{$action} {$singular} · {$brand}";
            }

            // Edit: /resources/{uriKey}/{id}/edit
            if (preg_match('#^[^/]+/edit$#', $tail) === 1) {
                $action = trans('martis::navigation.edit');
                $action = $action === 'martis::navigation.edit' ? 'Edit' : $action;

                return "{$action} {$singular} · {$brand}";
            }

            // Detail: /resources/{uriKey}/{id}
            return "{$singular} · {$brand}";
        }

        // /dashboards/{key}
        if (preg_match('#^dashboards/([^/]+)$#', $remainder, $matches) === 1) {
            return ucfirst(str_replace('-', ' ', $matches[1]))." · {$brand}";
        }

        return null;
    }

    protected function resolveResourceRegistry(): ?ResourceRegistry
    {
        if (! app()->bound(ResourceRegistry::class)) {
            return null;
        }

        $registry = app(ResourceRegistry::class);

        return $registry instanceof ResourceRegistry ? $registry : null;
    }

    // -------------------------------------------------------------------------
    // Command palette
    // -------------------------------------------------------------------------

    /**
     * Add entries to the command palette (⌘K). The resolver returns a list
     * of `MenuItem`s and `MenuGroup`s, resolved per request as the menu
     * resolves them: `canSee()` and a tool's `authorizedToSee()` hide an
     * entry; a soft-gate lock keeps it listed and points it at the lock
     * page, as in the menu. Calls accumulate, so several tools or packages
     * can each add theirs.
     *
     * The resolver may return an array or any other iterable (a
     * Collection); anything else throws an InvalidArgumentException.
     *
     * @param  Closure(Request): mixed  $resolver  an iterable of MenuItem/MenuGroup
     */
    public function commandPalette(Closure $resolver): static
    {
        $this->commandPaletteResolvers[] = $resolver;

        return $this;
    }

    public function forgetCommandPalette(): static
    {
        $this->commandPaletteResolvers = [];

        return $this;
    }

    /**
     * The registered palette entries the request may see, in registration
     * order. A `MenuGroup` gives its visible items its label as their group.
     *
     * @return list<array{key: string, label: string, url: string, external: bool, icon: string|null, group: string|null}>
     */
    public function resolveCommandPalette(Request $request): array
    {
        $entries = [];

        foreach ($this->commandPaletteResolvers as $resolver) {
            $items = $resolver($request);

            if (! is_iterable($items)) {
                throw new InvalidArgumentException(sprintf(
                    'Martis::commandPalette() resolvers must return an iterable of MenuItem/MenuGroup, %s given.',
                    get_debug_type($items),
                ));
            }

            foreach ($items as $item) {
                if ($item instanceof MenuItem) {
                    $resolved = $item->resolve($request);
                    if ($resolved !== null) {
                        $entries[] = $this->paletteEntry(count($entries), $resolved, null);
                    }

                    continue;
                }

                if ($item instanceof MenuGroup) {
                    $group = $item->resolve($request);
                    $groupItems = is_array($group['items'] ?? null) ? $group['items'] : [];
                    $label = is_string($group['label'] ?? null) ? $group['label'] : null;

                    foreach ($groupItems as $resolved) {
                        if (is_array($resolved)) {
                            $entries[] = $this->paletteEntry(count($entries), $resolved, $label);
                        }
                    }

                    continue;
                }

                throw new InvalidArgumentException(sprintf(
                    'Martis::commandPalette() entries must be %s or %s instances, %s given.',
                    MenuItem::class,
                    MenuGroup::class,
                    get_debug_type($item),
                ));
            }
        }

        return $entries;
    }

    /**
     * @param  array<array-key, mixed>  $resolved  a resolved MenuItem
     * @return array{key: string, label: string, url: string, external: bool, icon: string|null, group: string|null}
     */
    protected function paletteEntry(int $index, array $resolved, ?string $group): array
    {
        return [
            'key' => 'command:'.$index,
            'label' => is_string($resolved['label'] ?? null) ? $resolved['label'] : '',
            'url' => is_string($resolved['url'] ?? null) ? $resolved['url'] : '',
            'external' => ($resolved['external'] ?? false) === true,
            'icon' => is_string($resolved['icon'] ?? null) ? $resolved['icon'] : null,
            'group' => $group,
        ];
    }

    // -------------------------------------------------------------------------
    // Notifications
    // -------------------------------------------------------------------------

    /**
     * Narrow the notification centre: every endpoint (the list, both unread
     * counts, mark-read, mark-all-read, delete and clear-all) calls
     * `$scope($query, $request)` with an Eloquent query builder over the
     * user's notifications. The closure adds its constraints to that
     * builder; they land inside one parenthesised group joined to the
     * user constraint with AND, so an `orWhere` never reaches another
     * user's notifications. Its return value is ignored; `null` removes
     * the scope. There is one scope: a second call replaces the first
     * (unlike commandPalette(), which accumulates), so an app and a
     * package that both narrow the centre combine their conditions in one
     * closure. The package knows nothing about what the scope filters
     * on (a tenant, a workspace, a product area).
     *
     * @param  (Closure(Builder<Model>, Request): mixed)|null  $scope
     */
    public function scopeNotificationsUsing(?Closure $scope): static
    {
        $this->notificationScope = $scope;

        return $this;
    }

    public function forgetNotificationScope(): static
    {
        return $this->scopeNotificationsUsing(null);
    }

    /**
     * Whether an app registered a notification scope. The shell tells the
     * bell, which then refetches the scoped count on a real-time
     * `martis:notification-received` instead of adding one: the pushed
     * notification may fall outside the scope.
     */
    public function hasNotificationScope(): bool
    {
        return $this->notificationScope instanceof Closure;
    }

    /**
     * Apply the registered notification scope, if any, to `$query` (the
     * user's `notifications()` relation or a builder over it). The scope
     * runs on a nested builder, so its constraints stay in one group
     * AND-ed with the constraints `$query` already carries.
     *
     * @param  Builder<Model>|Relation<Model, Model, mixed>  $query
     */
    public function applyNotificationScope(Builder|Relation $query, Request $request): void
    {
        $scope = $this->notificationScope;

        if ($scope instanceof Closure) {
            $query->where(function (Builder $nested) use ($scope, $request): void {
                $scope($nested, $request);
            });
        }
    }

    // -------------------------------------------------------------------------
    // Package version
    // -------------------------------------------------------------------------

    /**
     * The Martis package version surfaced in the sidebar footer.
     *
     * Resolution (highest priority first):
     *   1. `config('martis.brand.version')` — consumer override.
     *   2. The version Composer resolved at install time
     *      (`InstalledVersion::of()`: git tag, branch name, or `dev-*`
     *      alias), without its leading `v`. This is what
     *      `composer show martis/martis` reports.
     *   3. null when the package isn't installed via Composer (rare — only
     *      in ad-hoc autoloader setups) and no override is set.
     */
    public function version(): ?string
    {
        $configured = config('martis.brand.version');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        // Null (no Composer record, or unreadable metadata) simply hides
        // the version chip in the footer.
        $installed = InstalledVersion::of('martis/martis');

        return $installed === null ? null : ltrim($installed, 'v');
    }

    // -------------------------------------------------------------------------
    // Record URL map
    // -------------------------------------------------------------------------

    /** @return array<string,string> uriKey => recordUrl template, for resources that declare one. */
    public function recordUrlMap(): array
    {
        $map = [];
        foreach (app(ResourceRegistry::class)->list() as $resourceClass) {
            $t = $resourceClass::recordUrl();
            if ($t !== null) {
                $map[$resourceClass::uriKey()] = $t;
            }
        }

        return $map;
    }
}
