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
}
