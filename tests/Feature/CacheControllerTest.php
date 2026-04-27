<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Martis\Cache\MartisCache;

class CacheTestUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

beforeEach(function () {
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }

    config()->set('cache.default', 'array');
    Cache::store('array')->flush();

    config()->set('martis.cache.enabled', true);
    config()->set('martis.cache.metrics', ['enabled' => true, 'ttl' => 5]);
    config()->set('martis.cache.navigation', ['enabled' => true, 'ttl' => 1]);
    config()->set('martis.cache.dashboards', ['enabled' => true, 'ttl' => null]);
    config()->set('martis.cache.schema', ['enabled' => true, 'ttl' => null]);
    config()->set('martis.cache.admin_ui', true);

    // Re-bind the singleton so it picks up the array store flush.
    $this->app->forgetInstance(MartisCache::class);
    $this->app->singleton(MartisCache::class, fn () => new MartisCache(Cache::store('array')));

    $this->user = CacheTestUser::query()->create([
        'name' => 'Cache Admin',
        'email' => 'cache-admin@martis.test',
        'password' => bcrypt('secret'),
    ]);
});

it('returns the full cache snapshot through GET /api/cache', function () {
    $this->actingAs($this->user, 'web');

    $response = $this->getJson('/martis/api/cache');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            ['type', 'enabled', 'ttl', 'config_enabled', 'runtime_override', 'version', 'cleared_at'],
        ],
        'meta' => ['master_enabled', 'types'],
    ]);
    expect($response->json('meta.master_enabled'))->toBeTrue();
    expect($response->json('meta.types'))->toEqual(MartisCache::TYPES);
});

it('clears every cache type when no type is provided', function () {
    $this->actingAs($this->user, 'web');

    $cache = $this->app->make(MartisCache::class);
    $cache->remember('schema', 'r1', fn () => 'cached');
    $beforeVersion = $cache->status()[0]['version'];

    $response = $this->postJson('/martis/api/cache/clear', []);

    $response->assertOk();

    $afterVersion = $this->app->make(MartisCache::class)->status()[0]['version'];
    expect($afterVersion)->toBeGreaterThan($beforeVersion);
});

it('clears a single cache type when explicit type is given', function () {
    $this->actingAs($this->user, 'web');

    $response = $this->postJson('/martis/api/cache/clear', ['type' => 'schema']);

    $response->assertOk();
    $row = collect($response->json('data'))->firstWhere('type', 'schema');
    expect($row['cleared_at'])->not->toBeNull();
});

it('rejects an unknown cache type with 422', function () {
    $this->actingAs($this->user, 'web');

    $response = $this->postJson('/martis/api/cache/clear', ['type' => 'whatever']);

    $response->assertStatus(422);
});

it('disables and re-enables a cache type at runtime via the API', function () {
    $this->actingAs($this->user, 'web');

    $this->postJson('/martis/api/cache/disable', ['type' => 'navigation'])->assertOk();

    $cache = $this->app->make(MartisCache::class);
    expect($cache->enabled('navigation'))->toBeFalse();

    $this->postJson('/martis/api/cache/enable', ['type' => 'navigation'])->assertOk();
    expect($cache->enabled('navigation'))->toBeTrue();
});

it('reset-override drops the runtime flag and falls back to config', function () {
    $this->actingAs($this->user, 'web');

    $this->postJson('/martis/api/cache/disable', ['type' => 'metrics'])->assertOk();
    expect($this->app->make(MartisCache::class)->enabled('metrics'))->toBeFalse();

    $this->postJson('/martis/api/cache/reset-override', ['type' => 'metrics'])->assertOk();
    expect($this->app->make(MartisCache::class)->enabled('metrics'))->toBeTrue();
});

it('returns 403 when the manage-martis-cache gate denies the user', function () {
    Gate::define('manage-martis-cache', fn (?Authenticatable $u) => false);

    $this->actingAs($this->user, 'web');

    $this->getJson('/martis/api/cache')->assertStatus(403);
    $this->postJson('/martis/api/cache/clear')->assertStatus(403);
});

it('does not register admin routes when cache.admin_ui is disabled', function () {
    // Force a cold boot of the package routes with the flag turned off
    // so we can prove the `if (config(...))` gate suppresses every
    // admin endpoint at registration time.
    config()->set('martis.cache.admin_ui', false);

    /** @var Router $router */
    $router = $this->app['router'];
    $fresh = new RouteCollection;
    $router->setRoutes($fresh);
    require __DIR__.'/../../routes/martis.php';

    $names = collect($router->getRoutes()->getRoutesByName())
        ->keys()
        ->filter(fn (string $n) => str_starts_with($n, 'martis.api.cache'))
        ->values();

    expect($names)->toBeEmpty();
});

it('every admin endpoint is registered when cache.admin_ui is enabled', function () {
    /** @var Router $router */
    $router = $this->app['router'];

    $names = collect($router->getRoutes()->getRoutesByName())
        ->keys()
        ->filter(fn (string $n) => str_contains($n, 'cache.'))
        ->values();

    expect($names)->toContain('martis.api.cache.status');
    expect($names)->toContain('martis.api.cache.clear');
    expect($names)->toContain('martis.api.cache.enable');
    expect($names)->toContain('martis.api.cache.disable');
    expect($names)->toContain('martis.api.cache.reset-override');
});

it('returns 401/403 when the user is anonymous', function () {
    // No actingAs — the auth middleware kicks in.
    $this->getJson('/martis/api/cache')->assertStatus(401);
});
