<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Martis\Cache\MartisCache;
use Martis\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config()->set('cache.default', 'array');
    Cache::store('array')->flush();

    config()->set('martis.cache.enabled', true);
    config()->set('martis.cache.metrics', ['enabled' => true, 'ttl' => 5]);
    config()->set('martis.cache.navigation', ['enabled' => true, 'ttl' => 1]);
    config()->set('martis.cache.dashboards', ['enabled' => true, 'ttl' => null]);
    config()->set('martis.cache.schema', ['enabled' => true, 'ttl' => null]);

    $this->cache = new MartisCache(Cache::store('array'));
});

it('caches the callback result and returns it on subsequent calls', function () {
    $hits = 0;
    $cb = function () use (&$hits) {
        $hits++;

        return ['value' => 42];
    };

    expect($this->cache->remember('metrics', 'k1', $cb))->toBe(['value' => 42]);
    expect($this->cache->remember('metrics', 'k1', $cb))->toBe(['value' => 42]);

    expect($hits)->toBe(1);
});

it('skips the cache when the master switch is off', function () {
    config()->set('martis.cache.enabled', false);

    $hits = 0;
    $cb = function () use (&$hits) {
        $hits++;

        return ['v' => $hits];
    };

    expect($this->cache->remember('metrics', 'k1', $cb))->toBe(['v' => 1]);
    expect($this->cache->remember('metrics', 'k1', $cb))->toBe(['v' => 2]);
});

it('respects per-type config disabled flag', function () {
    config()->set('martis.cache.metrics', ['enabled' => false, 'ttl' => 5]);

    $hits = 0;
    $cb = function () use (&$hits) {
        $hits++;

        return $hits;
    };

    expect($this->cache->remember('metrics', 'k1', $cb))->toBe(1);
    expect($this->cache->remember('metrics', 'k1', $cb))->toBe(2);
});

it('clearing a type bumps the version key and invalidates the entry', function () {
    $hits = 0;
    $cb = function () use (&$hits) {
        $hits++;

        return $hits;
    };

    expect($this->cache->remember('schema', 'r1', $cb))->toBe(1);
    expect($this->cache->remember('schema', 'r1', $cb))->toBe(1);

    $this->cache->clear('schema');

    expect($this->cache->remember('schema', 'r1', $cb))->toBe(2);
});

it('clearing without a type wipes every layer at once', function () {
    $this->cache->remember('metrics', 'k1', fn () => 'a');
    $this->cache->remember('schema', 'k1', fn () => 'b');

    $this->cache->clear();

    $hits = 0;
    $this->cache->remember('metrics', 'k1', function () use (&$hits) {
        $hits++;

        return 'recomputed';
    });

    expect($hits)->toBe(1);
});

it('runtime disable overrides config-enabled', function () {
    $this->cache->disable('navigation');

    $hits = 0;
    $cb = function () use (&$hits) {
        $hits++;

        return $hits;
    };

    expect($this->cache->remember('navigation', 'k', $cb))->toBe(1);
    expect($this->cache->remember('navigation', 'k', $cb))->toBe(2);
});

it('runtime enable overrides config-disabled', function () {
    config()->set('martis.cache.metrics', ['enabled' => false, 'ttl' => 5]);
    $this->cache->enable('metrics');

    $hits = 0;
    $cb = function () use (&$hits) {
        $hits++;

        return $hits;
    };

    expect($this->cache->remember('metrics', 'k', $cb))->toBe(1);
    expect($this->cache->remember('metrics', 'k', $cb))->toBe(1);
});

it('clearOverride drops the runtime flag and falls back to config', function () {
    $this->cache->disable('schema');
    expect($this->cache->enabled('schema'))->toBeFalse();

    $this->cache->clearOverride('schema');
    expect($this->cache->enabled('schema'))->toBeTrue();
});

it('honours the X-Martis-No-Cache header on the active request', function () {
    $request = Request::create('/');
    $request->headers->set('X-Martis-No-Cache', '1');
    $this->app->instance('request', $request);

    $hits = 0;
    $cb = function () use (&$hits) {
        $hits++;

        return $hits;
    };

    expect($this->cache->remember('metrics', 'k', $cb))->toBe(1);
    expect($this->cache->remember('metrics', 'k', $cb))->toBe(2);
});

it('honours the ?nocache=1 query parameter', function () {
    $request = Request::create('/?nocache=1');
    $this->app->instance('request', $request);

    $hits = 0;
    $cb = function () use (&$hits) {
        $hits++;

        return $hits;
    };

    expect($this->cache->remember('metrics', 'k', $cb))->toBe(1);
    expect($this->cache->remember('metrics', 'k', $cb))->toBe(2);
});

it('accepts the legacy bare-int config shape', function () {
    config()->set('martis.cache.metrics', 7);

    expect($this->cache->enabled('metrics'))->toBeTrue();
    expect($this->cache->ttl('metrics'))->toBe(7);
});

it('accepts the legacy null-disabled config shape', function () {
    config()->set('martis.cache.dashboards', null);

    expect($this->cache->enabled('dashboards'))->toBeFalse();
    expect($this->cache->ttl('dashboards'))->toBeNull();
});

it('rejects unknown cache types loudly', function () {
    expect(fn () => $this->cache->remember('whatever', 'k', fn () => 'x'))
        ->toThrow(InvalidArgumentException::class);
});

it('status returns one row per known type with the expected shape', function () {
    $rows = $this->cache->status();

    expect($rows)->toHaveCount(4);
    expect(array_column($rows, 'type'))->toEqual(MartisCache::TYPES);

    foreach ($rows as $row) {
        expect($row)->toHaveKeys(['type', 'enabled', 'ttl', 'config_enabled', 'runtime_override', 'version', 'cleared_at']);
    }
});

// -----------------------------------------------------------------------------
// Extensibility — host-app-registered cache layers
// -----------------------------------------------------------------------------

it('extend() registers a new cache layer that surfaces in types() and status()', function () {
    MartisCache::extend('orders', enabled: true, ttl: 30);

    try {
        expect(MartisCache::types())->toContain('orders');

        $row = collect($this->cache->status())->firstWhere('type', 'orders');
        expect($row)->not->toBeNull();
        expect($row['enabled'])->toBeTrue();
        expect($row['ttl'])->toBe(30);
        expect($row['config_enabled'])->toBeTrue();
    } finally {
        MartisCache::forgetExtension('orders');
    }
});

it('extend() does not allow overriding built-in types', function () {
    MartisCache::extend('metrics', enabled: false, ttl: 999);

    // Built-in `metrics` is unaffected — extension call should be a no-op.
    expect($this->cache->ttl('metrics'))->toBe(5);
    expect($this->cache->enabled('metrics'))->toBeTrue();
});

it('a custom layer caches and clears like built-in layers', function () {
    MartisCache::extend('orders', enabled: true, ttl: 30);

    try {
        $hits = 0;
        $cb = function () use (&$hits) {
            $hits++;

            return $hits;
        };

        expect($this->cache->remember('orders', 'k', $cb))->toBe(1);
        expect($this->cache->remember('orders', 'k', $cb))->toBe(1);

        $this->cache->clear('orders');
        expect($this->cache->remember('orders', 'k', $cb))->toBe(2);
    } finally {
        MartisCache::forgetExtension('orders');
    }
});

it('a custom layer respects host-app config overrides when present', function () {
    MartisCache::extend('orders', enabled: true, ttl: 30);
    config()->set('martis.cache.orders', ['enabled' => false, 'ttl' => 5]);

    try {
        expect($this->cache->enabled('orders'))->toBeFalse();
        expect($this->cache->ttl('orders'))->toBe(5);
    } finally {
        MartisCache::forgetExtension('orders');
    }
});

it('runtime disable on a custom layer wins over the extension defaults', function () {
    MartisCache::extend('orders');

    try {
        $this->cache->disable('orders');
        expect($this->cache->enabled('orders'))->toBeFalse();

        $this->cache->clearOverride('orders');
        expect($this->cache->enabled('orders'))->toBeTrue();
    } finally {
        MartisCache::forgetExtension('orders');
    }
});

it('clear() with no argument also clears custom layers', function () {
    MartisCache::extend('orders');

    try {
        $this->cache->remember('orders', 'k', fn () => 'cached');
        $beforeRow = collect($this->cache->status())->firstWhere('type', 'orders');

        $this->cache->clear();

        $afterRow = collect($this->cache->status())->firstWhere('type', 'orders');
        expect($afterRow['version'])->toBeGreaterThan($beforeRow['version']);
    } finally {
        MartisCache::forgetExtension('orders');
    }
});
