<?php

declare(strict_types=1);

use Martis\Cache\MartisCache;
use Martis\Dashboards\Dashboard;
use Martis\Facades\Martis;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Tools\Tool;

class LockedTestDashboard extends Dashboard
{
    public function __construct()
    {
        parent::__construct(name: 'Pro Lab', uriKey: 'pro-lab');
        $this->lockedFor(fn () => true)
            ->lockModal([
                'title' => 'Pro feature',
                'message' => 'Upgrade to unlock.',
                'cta' => ['label' => 'Upgrade', 'url' => '/billing'],
            ]);
    }
}

class UnlockedTestDashboard extends Dashboard
{
    public function __construct()
    {
        parent::__construct(name: 'Home', uriKey: 'home');
    }
}

class LockedTestTool extends Tool
{
    public function __construct()
    {
        parent::__construct(name: 'Locked Tool', uriKey: 'locked-tool');
        $this->withComponent('tool:locked-tool');
        $this->lockedFor(fn () => true)
            ->lockModal([
                'title' => 'Pro feature',
                'message' => 'Upgrade to unlock.',
                'cta' => ['label' => 'Upgrade', 'url' => '/billing'],
            ]);
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);
    Martis::dashboards([]);
    Martis::tools([]);
    app(MartisCache::class)->clear('dashboards');
});

afterEach(function () {
    Martis::dashboards([]);
    Martis::tools([]);
    app(MartisCache::class)->clear('dashboards');
});

it('GET /martis/api/dashboards/{uriKey} returns locked payload when the dashboard is locked', function () {
    Martis::dashboards([new LockedTestDashboard]);

    $response = $this->getJson('/martis/api/dashboards/pro-lab');

    $response->assertStatus(200);
    expect($response->json('data.locked'))->toBeTrue();
    expect($response->json('data.lock.modal.title'))->toBe('Pro feature');
    expect($response->json('data.lock.modal.cta.url'))->toBe('/billing');
    // Cards and filters are not in the locked payload — by design,
    // so a locked user does not even see the layout structure.
    expect($response->json('data.cards'))->toBeNull();
});

it('GET /martis/api/dashboards/{uriKey} returns the normal payload when the dashboard is unlocked', function () {
    Martis::dashboards([new UnlockedTestDashboard]);

    $response = $this->getJson('/martis/api/dashboards/home');

    $response->assertStatus(200);
    expect($response->json('data.locked'))->toBeNull();
    expect($response->json('data.dashboard.uriKey'))->toBe('home');
});

it('GET /martis/api/tools/{uriKey} returns locked payload when the tool is locked', function () {
    Martis::tools([new LockedTestTool]);

    $response = $this->getJson('/martis/api/tools/locked-tool');

    $response->assertStatus(200);
    expect($response->json('locked'))->toBeTrue();
    expect($response->json('lock.modal.title'))->toBe('Pro feature');
    expect($response->json('tool.uriKey'))->toBe('locked-tool');
});

it('the dashboards list endpoint emits the lock field on every dashboard', function () {
    Martis::dashboards([new LockedTestDashboard, new UnlockedTestDashboard]);

    $response = $this->getJson('/martis/api/dashboards');

    $response->assertStatus(200);
    $payload = $response->json('data.dashboards');
    expect($payload)->toHaveCount(2);

    $byKey = collect($payload)->keyBy('uriKey');
    expect($byKey['pro-lab']['lock'])->not->toBeNull()
        ->and($byKey['home']['lock'])->toBeNull();
});

it('the dashboards list endpoint re-evaluates lock state on every request even when the structure is cached', function () {
    // The structural cache must not freeze the lock field. We verify this by
    // registering a dashboard whose lock predicate switches between two calls.
    // First call: locked. Second call (same cache is still warm): must be unlocked.
    // Without the fix, the second call would still return the stale locked payload.
    $locked = true;

    $dynamic = new class('Dynamic', 'dynamic') extends Dashboard {};
    $dynamic->lockedFor(function () use (&$locked): bool {
        return $locked;
    })->lockModal(['title' => 'Upgrade', 'message' => 'Upgrade now.']);

    Martis::dashboards([$dynamic]);

    // First request: locked
    $first = $this->getJson('/martis/api/dashboards');
    $first->assertStatus(200);
    $firstEntry = collect($first->json('data.dashboards'))->firstWhere('uriKey', 'dynamic');
    expect($firstEntry['lock'])->not->toBeNull();

    // Simulate plan upgrade: flip the lock state
    $locked = false;

    // Second request — the structural cache is still warm, but lock must reflect
    // the new (unlocked) state.
    $second = $this->getJson('/martis/api/dashboards');
    $second->assertStatus(200);
    $secondEntry = collect($second->json('data.dashboards'))->firstWhere('uriKey', 'dynamic');
    expect($secondEntry['lock'])->toBeNull();
});
