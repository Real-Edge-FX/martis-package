<?php

namespace Martis;

use Closure;
use Composer\InstalledVersions;
use Illuminate\Http\Request;
use Martis\Contracts\DashboardContract;
use Martis\Contracts\ToolContract;
use Martis\Menu\Menu;
use Martis\Menu\MenuSection;
use Throwable;

class MartisManager
{
    /** @var Closure(Request, Menu): (Menu|array<int, MenuSection>|null)|null */
    protected ?Closure $mainMenuResolver = null;

    /** @var Closure(Request): string|null */
    protected ?Closure $pageTitleResolver = null;

    /** @var list<class-string<DashboardContract>|DashboardContract> */
    protected array $dashboards = [];

    /** @var list<class-string<ToolContract>|ToolContract> */
    protected array $tools = [];

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
     *   2. `config('martis.brand.page_title')` — static string or callable class
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

        $configured = config('martis.brand.page_title');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        if (is_callable($configured)) {
            $resolved = $configured($request);
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
    // Package version
    // -------------------------------------------------------------------------

    /**
     * The Martis package version surfaced in the sidebar footer.
     *
     * Resolution (highest priority first):
     *   1. `config('martis.brand.version')` — consumer override.
     *   2. `Composer\InstalledVersions` — the version Composer resolved at
     *      install time (git tag, branch name, or `dev-*` alias). This is
     *      what `composer show martis/martis` reports.
     *   3. null when the package isn't installed via Composer (rare — only
     *      in ad-hoc autoloader setups) and no override is set.
     */
    public function version(): ?string
    {
        $configured = config('martis.brand.version');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        try {
            if (class_exists(InstalledVersions::class)
                && InstalledVersions::isInstalled('martis/martis')) {
                $pretty = InstalledVersions::getPrettyVersion('martis/martis');
                if (is_string($pretty) && $pretty !== '') {
                    return ltrim($pretty, 'v');
                }
            }
        } catch (Throwable) {
            // InstalledVersions may throw when the package metadata is
            // incomplete (e.g. path repositories without a git tag). Fall
            // through to null so the footer simply hides the version chip.
        }

        return null;
    }
}
