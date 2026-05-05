<?php

declare(strict_types=1);

use Martis\Cache\MartisCache;
use Martis\Dashboards\Dashboard;
use Martis\Facades\Martis;
use Martis\Http\Middleware\MartisAuthenticate;

/**
 * v1.10.3+ breadcrumb override coverage for `Dashboard::withBreadcrumb()`.
 *
 * The companion type lives in `resources/js/types/index.ts` (`DashboardDefinition.breadcrumb`)
 * and the consumer is `resources/js/pages/Dashboard.tsx`, where the panel shell
 * calls `useDynamicCrumb(currentDashboard?.breadcrumb ?? currentDashboard?.name)`.
 * These specs lock down the PHP surface and the descriptor JSON shape so
 * future refactors don't regress it.
 */
class BreadcrumbTestDashboard extends Dashboard
{
    public function __construct()
    {
        parent::__construct(name: 'Operations', uriKey: 'operations');
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);
    Martis::dashboards([]);
    // Dashboards endpoint is cached per user/locale (see MetricController::dashboards).
    // Clear before every spec so the previous test's payload doesn't leak in.
    app(MartisCache::class)->clear('dashboards');
});

afterEach(function () {
    Martis::dashboards([]);
    app(MartisCache::class)->clear('dashboards');
});

it('Dashboard descriptor exposes a null breadcrumb by default', function () {
    Martis::dashboards([new BreadcrumbTestDashboard]);

    $response = $this->getJson('/martis/api/dashboards');

    $response->assertStatus(200);
    $payload = $response->json('data.dashboards');

    expect($payload)->toHaveCount(1);
    expect($payload[0])->toHaveKey('breadcrumb');
    expect($payload[0]['breadcrumb'])->toBeNull();
    expect($payload[0]['name'])->toBe('Operations');
});

it('Dashboard::withBreadcrumb() propagates the override into the descriptor', function () {
    $dashboard = (new BreadcrumbTestDashboard)->withBreadcrumb('Operations · Live');
    Martis::dashboards([$dashboard]);

    $response = $this->getJson('/martis/api/dashboards');

    $response->assertStatus(200);
    $payload = $response->json('data.dashboards.0');
    expect($payload['breadcrumb'])->toBe('Operations · Live');
    expect($payload['name'])->toBe('Operations');
});

it('Dashboard::withBreadcrumb(null) clears a previously-set override', function () {
    $dashboard = (new BreadcrumbTestDashboard)
        ->withBreadcrumb('Custom')
        ->withBreadcrumb(null);
    Martis::dashboards([$dashboard]);

    $response = $this->getJson('/martis/api/dashboards');

    expect($response->json('dashboards.0.breadcrumb'))->toBeNull();
});

it('Dashboard::withBreadcrumb() returns the same instance for fluent chaining', function () {
    $dashboard = new BreadcrumbTestDashboard;
    $returned = $dashboard->withBreadcrumb('Whatever');

    expect($returned)->toBe($dashboard);
});

it('a subclass that overrides breadcrumb() is honoured by toArray()', function () {
    // Mirrors the i18n-friendly recipe in the docs:
    //
    //   public function breadcrumb(): ?string {
    //       return (string) __('app.dashboards.home.breadcrumb');
    //   }
    $dashboard = new class extends Dashboard
    {
        public function __construct()
        {
            parent::__construct(name: 'Home', uriKey: 'home');
        }

        public function breadcrumb(): ?string
        {
            return 'Per-request breadcrumb';
        }
    };

    expect($dashboard->toArray()['breadcrumb'])->toBe('Per-request breadcrumb');
});

it('Dashboard exposes null icon by default and includes it in toArray', function () {
    $d = new Dashboard('Sales');
    expect($d->icon())->toBeNull()
        ->and($d->toArray())->toHaveKey('icon')
        ->and($d->toArray()['icon'])->toBeNull();
});

it('withIcon stores the value and surfaces it in toArray (v1.11.4+)', function () {
    $d = (new Dashboard('Sales'))->withIcon('chart-line-up');
    expect($d->icon())->toBe('chart-line-up')
        ->and($d->toArray()['icon'])->toBe('chart-line-up');
});

it('withIcon(null) clears the override', function () {
    $d = (new Dashboard('Sales'))
        ->withIcon('chart-line-up')
        ->withIcon(null);
    expect($d->icon())->toBeNull();
});
