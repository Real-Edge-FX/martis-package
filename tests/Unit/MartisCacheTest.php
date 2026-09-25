<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Martis\Cache\MartisCache;
use Martis\Support\InstalledVersion;
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

// The installed Martis version is part of every key, so an upgrade (a new
// Composer version) rebuilds every layer; the `schema` layer has no expiry
// and would otherwise keep serving the previous version's payload. Without
// a known version the key keeps the per-type counter only.

it('puts the installed Martis version in every key', function () {
    $cache = new MartisCache(Cache::store('array'), 'v2.0.0');

    expect($cache->buildKey('schema', 'posts'))->toBe('martis:cache:schema@v2.0.0:v1:posts');
});

it('rebuilds a cached entry once the installed version changes', function () {
    $hits = 0;
    $cb = function () use (&$hits) {
        $hits++;

        return $hits;
    };

    $before = new MartisCache(Cache::store('array'), 'v1.39.1');
    $after = new MartisCache(Cache::store('array'), 'v2.0.0');

    expect($before->remember('schema', 'posts', $cb))->toBe(1)
        ->and($before->remember('schema', 'posts', $cb))->toBe(1)
        ->and($after->remember('schema', 'posts', $cb))->toBe(2)
        ->and($after->remember('schema', 'posts', $cb))->toBe(2);
});

it('keeps the key without a version when the installed version is unknown', function () {
    expect($this->cache->buildKey('schema', 'posts'))->toBe('martis:cache:schema:v1:posts');
});

it('binds the cache service with the version Composer installed', function () {
    $installed = InstalledVersion::fingerprint('martis/martis');

    expect($installed)->toBeString()->not->toBe('')
        ->and(app(MartisCache::class)->buildKey('schema', 'posts'))->toBe("martis:cache:schema@{$installed}:v1:posts");
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

it('honours the X-Martis-No-Cache header when the bypass gate allows', function () {
    Gate::define('bypass-martis-cache', fn ($user = null) => true);

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

it('honours the ?nocache=1 query parameter when the bypass gate allows', function () {
    Gate::define('bypass-martis-cache', fn ($user = null) => true);

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

// -----------------------------------------------------------------------------
// Per-request bypass authorization (bypass-martis-cache Gate)
// -----------------------------------------------------------------------------

it('blocks the X-Martis-No-Cache header when the bypass gate denies', function () {
    // Default gate (deny-all) is registered by the service provider.
    // This test asserts the default behaviour without overriding the gate.
    Gate::define('bypass-martis-cache', fn () => false);

    $request = Request::create('/');
    $request->headers->set('X-Martis-No-Cache', '1');
    $this->app->instance('request', $request);

    $hits = 0;
    $cb = function () use (&$hits) {
        $hits++;

        return $hits;
    };

    // Both calls should hit the cache (gate denies the bypass signal).
    expect($this->cache->remember('metrics', 'k', $cb))->toBe(1);
    expect($this->cache->remember('metrics', 'k', $cb))->toBe(1);
    expect($hits)->toBe(1);
});

it('blocks the ?nocache=1 query parameter when the bypass gate denies', function () {
    Gate::define('bypass-martis-cache', fn () => false);

    $request = Request::create('/?nocache=1');
    $this->app->instance('request', $request);

    $hits = 0;
    $cb = function () use (&$hits) {
        $hits++;

        return $hits;
    };

    expect($this->cache->remember('metrics', 'k', $cb))->toBe(1);
    expect($this->cache->remember('metrics', 'k', $cb))->toBe(1);
    expect($hits)->toBe(1);
});

it('blocks the ?nocache=true variant when the bypass gate denies', function () {
    Gate::define('bypass-martis-cache', fn () => false);

    $request = Request::create('/?nocache=true');
    $this->app->instance('request', $request);

    $hits = 0;
    $cb = function () use (&$hits) {
        $hits++;

        return $hits;
    };

    expect($this->cache->remember('metrics', 'k', $cb))->toBe(1);
    expect($this->cache->remember('metrics', 'k', $cb))->toBe(1);
    expect($hits)->toBe(1);
});

it('bypassed() returns false when no bypass signal is present regardless of the gate', function () {
    Gate::define('bypass-martis-cache', fn ($user = null) => true);

    $request = Request::create('/');
    $this->app->instance('request', $request);

    expect($this->cache->bypassed($request))->toBeFalse();
});

it('bypassed() returns true when both signal and gate allow', function () {
    Gate::define('bypass-martis-cache', fn ($user = null) => true);

    $request = Request::create('/?nocache=1');

    expect($this->cache->bypassed($request))->toBeTrue();
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

it('normalizedConfig() normalizes a zero TTL on an extension to null (no expiry)', function () {
    // Passing ttl: 0 to extend() must result in no-expiry semantics
    // (null TTL) rather than addMinutes(0) = immediate expiry.
    MartisCache::extend('orders', enabled: true, ttl: 0);

    try {
        expect($this->cache->ttl('orders'))->toBeNull();
    } finally {
        MartisCache::forgetExtension('orders');
    }
});

it('normalizedConfig() normalizes a negative TTL on an extension to null', function () {
    MartisCache::extend('orders', enabled: true, ttl: -5);

    try {
        expect($this->cache->ttl('orders'))->toBeNull();
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

// A versioned key leaves the previous version's entries behind; on a store
// without eviction (file, database) a layer with no expiry would keep them
// forever, so the schema layer ships a finite TTL.

it('ships a finite default TTL for the schema layer', function () {
    $shipped = require __DIR__.'/../../config/martis.php';

    expect($shipped['cache']['schema']['ttl'])->toBeInt()->toBeGreaterThan(0);
});

// Cache stores bound key length (database: a 255-character column, 191 once
// indexed under utf8mb4; memcached: 250 bytes), so a key over 191 characters
// is hashed, whole, which keeps the version and the counter in it.

it('keeps a key of 191 characters or less readable', function () {
    $cache = new MartisCache(Cache::store('array'), 'v2.0.0');
    $key = $cache->buildKey('schema', str_repeat('a', 191 - strlen('martis:cache:schema@v2.0.0:v1:')));

    expect(strlen($key))->toBe(191)->and($key)->toStartWith('martis:cache:schema@v2.0.0:v1:');
});

it('hashes a key over 191 characters, keeping distinct keys distinct', function () {
    $cache = new MartisCache(Cache::store('array'), 'v2.0.0');
    $a = $cache->buildKey('schema', str_repeat('a', 300));
    $b = $cache->buildKey('schema', str_repeat('a', 299).'b');

    expect(strlen($a))->toBeLessThanOrEqual(191)
        ->and($a)->toStartWith('martis:cache:schema:h:')
        ->and($a)->not->toBe($b)
        ->and((new MartisCache(Cache::store('array'), 'v2.0.1'))->buildKey('schema', str_repeat('a', 300)))->not->toBe($a);
});

it('rebuilds a long-keyed entry after clear()', function () {
    $cache = new MartisCache(Cache::store('array'), 'v2.0.0');
    $hits = 0;
    $cb = function () use (&$hits) {
        return ++$hits;
    };
    $key = str_repeat('k', 400);

    expect($cache->remember('schema', $key, $cb))->toBe(1)
        ->and($cache->remember('schema', $key, $cb))->toBe(1);
    $cache->clear('schema');
    expect($cache->remember('schema', $key, $cb))->toBe(2);
});
