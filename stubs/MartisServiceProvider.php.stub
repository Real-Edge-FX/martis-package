<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Martis\Cache\MartisCache;
use Martis\Facades\Martis;
use Martis\Menu\MenuItem;
use Martis\Menu\MenuSection;

/**
 * Application-side Martis registrations.
 *
 * This provider is the canonical place to wire up everything Martis
 * exposes through code (closures, classes, callables) — things that
 * cannot live in `config/martis.php` because Laravel's config:cache
 * cannot serialize closures.
 *
 * `config/martis.php` keeps every static setting (paths, throttle,
 * theme, profile, cache TTLs, drawer widths, …). This provider keeps
 * the dynamic registrations that depend on the host application's
 * own classes (resources, dashboards, gates, callables).
 *
 * Sections below are commented-out by default. Uncomment what you
 * need; everything you don't touch keeps Martis on its built-in
 * defaults.
 *
 * @see https://martis.dev/docs/configuration
 */
class MartisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerMainMenu();
        $this->registerDashboards();
        $this->registerCacheLayers();
        $this->registerGates();
    }

    /**
     * Sidebar / top-nav main menu.
     *
     * Layout-agnostic: the same resolver feeds every layout preset
     * (sidebar, topnav, minimal). The React shell renders the result
     * differently per preset; the data shape stays the same.
     *
     * The closure receives the current Request and the default Menu
     * (sections derived automatically from registered resources). You
     * can either return a `list<MenuSection>`, mutate the Menu in
     * place, or replace it entirely.
     *
     * @see https://martis.dev/docs/menus
     */
    protected function registerMainMenu(): void
    {
        // Martis::mainMenu(function ($request, $menu) {
        //     return $menu->sections([
        //         MenuSection::make('Operations', [
        //             MenuItem::resource(\App\Martis\Resources\ClientResource::class),
        //             MenuItem::resource(\App\Martis\Resources\ProjectResource::class),
        //         ])->icon('briefcase'),
        //
        //         MenuSection::make('Reports', [
        //             MenuItem::link('Weekly summary', '/reports/weekly')->icon('chart-line'),
        //         ])->icon('file-text'),
        //     ]);
        // });
    }

    /**
     * Dashboards available in the admin panel. Each class extends
     * `Martis\Dashboards\Dashboard` and declares its cards/filters.
     *
     * @see https://martis.dev/docs/dashboards
     */
    protected function registerDashboards(): void
    {
        // Martis::dashboards([
        //     \App\Martis\Dashboards\OperationsDashboard::class,
        //     \App\Martis\Dashboards\RevenueDashboard::class,
        // ]);
    }

    /**
     * Custom cache layers — surface in "System → Cache", in
     * `martis:cache:status` / `:clear` / `:enable` / `:disable`, and
     * accept runtime overrides exactly like the four built-ins
     * (metrics, navigation, dashboards, schema).
     *
     * Built-in names are protected — calls to extend() with those
     * names are silently ignored.
     *
     * @see https://martis.dev/docs/cache#adding-your-own-cache-layer
     */
    protected function registerCacheLayers(): void
    {
        // MartisCache::extend('orders',  enabled: true, ttl: 30);
        // MartisCache::extend('reports', enabled: true, ttl: null); // no expiration
    }

    /**
     * Gate definitions — tighten the permissive Martis defaults.
     *
     * Out of the box every authenticated user passes
     * `manage-martis-cache` so the System → Cache page is reachable.
     * Production deployments typically restrict this to admins.
     */
    protected function registerGates(): void
    {
        // Gate::define('manage-martis-cache', fn ($user) => $user->is_admin);
    }
}
