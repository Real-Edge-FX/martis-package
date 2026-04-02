<?php

use Illuminate\Support\Facades\Route;

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
    expect(config('martis.guard'))->toBe('web');
});
