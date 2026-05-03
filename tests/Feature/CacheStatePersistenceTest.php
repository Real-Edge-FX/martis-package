<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Martis\Cache\MartisCache;
use Martis\Models\CacheState;

/**
 * Regression suite for the v1.8.8 fix that moved cache operational
 * metadata (version counter, `cleared_at`, runtime override) out of
 * the cache backend and into the `martis_cache_state` DB table.
 *
 * Pre-1.8.8 these values lived in the same Redis / file / array store
 * that `clear()` was supposed to invalidate, so any of the following
 * wiped the admin's "V N · cleared at Y" trail:
 *
 *   • `php artisan cache:clear`
 *   • `redis-cli FLUSHDB`
 *   • A container restart without a persistent volume
 *   • Redis with `maxmemory-policy: allkeys-lru` under memory pressure
 *
 * The DB-backed path makes that metadata indestructible by cache
 * mechanics — the regression suite below proves it for the two
 * scenarios we can simulate cleanly in a test.
 */
beforeEach(function () {
    config()->set('cache.default', 'array');
    Cache::store('array')->flush();
    config()->set('martis.cache.enabled', true);
    config()->set('martis.cache.metrics', ['enabled' => true, 'ttl' => 5]);
    config()->set('martis.cache.navigation', ['enabled' => true, 'ttl' => 1]);
    config()->set('martis.cache.dashboards', ['enabled' => true, 'ttl' => null]);
    config()->set('martis.cache.schema', ['enabled' => true, 'ttl' => null]);

    CacheState::query()->delete();
});

it('the version counter survives Cache::flush()', function () {
    $cache = new MartisCache(Cache::store('array'));

    $cache->clear('navigation');
    $cache->clear('navigation');

    $row = CacheState::find('navigation');
    expect($row)->not->toBeNull();
    expect($row->version)->toBe(3);

    // The bug: pre-1.8.8 we stored version in Cache::store(), so
    // flush() reset it to its default (1). Now the table is the
    // source of truth.
    Cache::store('array')->flush();

    // A fresh MartisCache instance reads from DB, not from any
    // in-process state.
    $fresh = new MartisCache(Cache::store('array'));
    $status = collect($fresh->status())->firstWhere('type', 'navigation');
    expect($status['version'])->toBe(3);
    expect($status['cleared_at'])->not->toBeNull();
});

it('the cleared_at timestamp survives Cache::flush()', function () {
    $cache = new MartisCache(Cache::store('array'));

    $cache->clear('schema');

    $before = CacheState::find('schema')->cleared_at?->toIso8601String();
    expect($before)->not->toBeNull();

    Cache::store('array')->flush();

    $after = CacheState::find('schema')->cleared_at?->toIso8601String();
    expect($after)->toBe($before);
});

it('the runtime override survives Cache::flush()', function () {
    $cache = new MartisCache(Cache::store('array'));

    $cache->disable('metrics');
    expect($cache->enabled('metrics'))->toBeFalse();

    Cache::store('array')->flush();

    $fresh = new MartisCache(Cache::store('array'));
    expect($fresh->enabled('metrics'))->toBeFalse();

    $row = CacheState::find('metrics');
    expect($row->override)->toBeFalse();
});

it('clearOverride() leaves version + cleared_at intact', function () {
    $cache = new MartisCache(Cache::store('array'));

    $cache->clear('schema');
    $cache->disable('schema');

    $row = CacheState::find('schema');
    $version = $row->version;
    $clearedAt = $row->cleared_at?->toIso8601String();

    $cache->clearOverride('schema');

    $after = CacheState::find('schema');
    expect($after->version)->toBe($version);
    expect($after->cleared_at?->toIso8601String())->toBe($clearedAt);
    expect($after->override)->toBeNull();
});

it('reads default state (version=1, cleared_at=null, override=null) when no row exists', function () {
    $cache = new MartisCache(Cache::store('array'));

    $status = collect($cache->status())->firstWhere('type', 'navigation');
    expect($status['version'])->toBe(1);
    expect($status['cleared_at'])->toBeNull();
    expect($status['runtime_override'])->toBeNull();
});

it('survives a full Cache::store()->flush() AND in-process state reset', function () {
    $cache = new MartisCache(Cache::store('array'));

    // Operator clears navigation cache twice + force-disables metrics
    // mid-day. Then a deploy script runs `php artisan cache:clear` at
    // 02:00 (simulated by Cache::flush()).
    $cache->clear('navigation');
    $cache->clear('navigation');
    $cache->disable('metrics');
    Cache::store('array')->flush();

    // Next morning, admin opens the cache page (fresh MartisCache
    // instance because of `app->scoped()` per-request binding) and
    // expects to still see V3 + the disable override.
    $fresh = new MartisCache(Cache::store('array'));
    $statuses = collect($fresh->status())->keyBy('type');

    expect($statuses['navigation']['version'])->toBe(3);
    expect($statuses['navigation']['cleared_at'])->not->toBeNull();
    expect($statuses['metrics']['runtime_override'])->toBeFalse();
    expect($statuses['metrics']['enabled'])->toBeFalse();
});
