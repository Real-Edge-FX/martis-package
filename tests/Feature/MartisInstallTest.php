<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\MartisServiceProvider;

it('registers the martis.index route', function () {
    $routeNames = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($r) => $r->getName())
        ->filter()
        ->values()
        ->toArray();

    expect($routeNames)->toContain('martis.index');
});

it('registers the martis.spa catch-all route', function () {
    $routeNames = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($r) => $r->getName())
        ->filter()
        ->values()
        ->toArray();

    expect($routeNames)->toContain('martis.spa');
});

it('martis routes use the configured path prefix', function () {
    $martisRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->getName() ?? '', 'martis.'))
        ->values();

    expect($martisRoutes)->not->toBeEmpty();

    $firstUri = $martisRoutes->first()->uri();
    expect($firstUri)->toContain(config('martis.path', 'martis'));
});

it('loads the martis configuration', function () {
    expect(config('martis'))->toBeArray();
    expect(config('martis.path'))->toBe('martis');
    expect(config('martis.guard'))->toBeNull();
    expect(config('martis.middleware'))->toBe(['web']);
    expect(config('martis.auth_middleware'))->toBe(['martis.auth']);
    expect(config('martis.brand.name'))->toBe('Martis');
    expect(config('martis.pagination.default_per_page'))->toBe(25);
});

it('registers the martis.login route', function () {
    $routeNames = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($r) => $r->getName())
        ->filter()
        ->values()
        ->toArray();

    expect($routeNames)->toContain('martis.login');
});

it('registers the martis.auth middleware alias', function () {
    $middleware = app('router')->getMiddleware();
    expect($middleware)->toHaveKey('martis.auth');
    expect($middleware['martis.auth'])->toBe(MartisAuthenticate::class);
});

it('vendor publish tag martis-config is registered', function () {
    $paths = ServiceProvider::pathsToPublish(
        MartisServiceProvider::class,
        'martis-config'
    );

    expect($paths)->not->toBeEmpty();
});
