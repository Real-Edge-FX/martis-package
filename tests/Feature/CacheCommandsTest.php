<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Martis\Cache\MartisCache;

beforeEach(function () {
    config()->set('cache.default', 'array');
    Cache::store('array')->flush();

    config()->set('martis.cache.enabled', true);
    config()->set('martis.cache.metrics', ['enabled' => true, 'ttl' => 5]);
    config()->set('martis.cache.navigation', ['enabled' => true, 'ttl' => 1]);
    config()->set('martis.cache.dashboards', ['enabled' => true, 'ttl' => null]);
    config()->set('martis.cache.schema', ['enabled' => true, 'ttl' => null]);

    $this->app->forgetInstance(MartisCache::class);
    $this->app->singleton(MartisCache::class, fn () => new MartisCache(Cache::store('array')));
});

it('martis:cache:status prints a row per type', function () {
    $this->artisan('martis:cache:status')
        ->expectsOutputToContain('Master switch')
        ->expectsOutputToContain('metrics')
        ->expectsOutputToContain('navigation')
        ->expectsOutputToContain('dashboards')
        ->expectsOutputToContain('schema')
        ->assertSuccessful();
});

it('martis:cache:clear without arguments clears every type', function () {
    $cache = $this->app->make(MartisCache::class);
    $cache->remember('schema', 'r1', fn () => 'cached');
    $before = $cache->status();

    $this->artisan('martis:cache:clear')
        ->expectsOutputToContain('All Martis caches cleared')
        ->assertSuccessful();

    $after = $this->app->make(MartisCache::class)->status();
    foreach (MartisCache::TYPES as $i => $type) {
        expect($after[$i]['version'])->toBeGreaterThan($before[$i]['version']);
    }
});

it('martis:cache:clear accepts a single type argument', function () {
    $this->artisan('martis:cache:clear', ['type' => 'schema'])
        ->expectsOutputToContain('cache "schema" cleared')
        ->assertSuccessful();
});

it('martis:cache:clear rejects an unknown type', function () {
    $this->artisan('martis:cache:clear', ['type' => 'whatever'])
        ->expectsOutputToContain('Unknown cache type')
        ->assertFailed();
});

it('martis:cache:disable persists a runtime override', function () {
    $this->artisan('martis:cache:disable', ['type' => 'metrics'])
        ->expectsOutputToContain('disabled at runtime')
        ->assertSuccessful();

    expect($this->app->make(MartisCache::class)->enabled('metrics'))->toBeFalse();
});

it('martis:cache:enable persists a runtime override', function () {
    config()->set('martis.cache.metrics', ['enabled' => false, 'ttl' => 5]);

    $this->artisan('martis:cache:enable', ['type' => 'metrics'])
        ->expectsOutputToContain('enabled at runtime')
        ->assertSuccessful();

    expect($this->app->make(MartisCache::class)->enabled('metrics'))->toBeTrue();
});
