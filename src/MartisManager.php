<?php

namespace Martis;

use Closure;
use Illuminate\Http\Request;
use Martis\Contracts\DashboardContract;
use Martis\Menu\Menu;
use Martis\Menu\MenuSection;

class MartisManager
{
    /** @var Closure(Request, Menu): (Menu|array<int, MenuSection>|null)|null */
    protected ?Closure $mainMenuResolver = null;

    /** @var Closure(Request): string|null */
    protected ?Closure $pageTitleResolver = null;

    /** @var list<class-string<DashboardContract>|DashboardContract> */
    protected array $dashboards = [];

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
     *   3. `__('martis::navigation.page_title_default', ['brand' => ...])` — i18n fallback
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

        /** @var string $default */
        $default = trans('martis::navigation.page_title_default', ['brand' => $brand]);

        return $default !== 'martis::navigation.page_title_default' ? $default : "{$brand} Admin";
    }
}
