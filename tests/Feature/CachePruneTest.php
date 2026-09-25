<?php

use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Martis\Cache\MartisCache;

/*
 * Every upgrade (and every `clear()`) leaves the previous keys behind. Redis
 * and memcached drop an expired key on their own, but the database and file
 * stores only delete an expired entry when that key is read again, which an
 * orphan never is. `martis:cache:prune` removes them where the store allows.
 */

beforeEach(function () {
    config()->set('martis.cache.enabled', true);
    config()->set('martis.cache.schema', ['enabled' => true, 'ttl' => null]);
    config()->set('martis.cache.metrics', ['enabled' => true, 'ttl' => 5]);
});

function pruneDatabaseStore(): Repository
{
    Schema::dropIfExists('prune_cache');
    Schema::create('prune_cache', function ($table) {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration');
    });
    config()->set('cache.stores.prune_db', ['driver' => 'database', 'table' => 'prune_cache', 'connection' => null, 'lock_connection' => null]);
    config()->set('cache.prefix', 'app_');

    return Cache::store('prune_db');
}

it('deletes the database entries of an older version, an older counter and expired ones, and nothing else', function () {
    $store = pruneDatabaseStore();

    (new MartisCache($store, 'v1.39.1'))->remember('schema', 'posts', fn () => 'old version');
    $current = new MartisCache($store, 'v2.0.0');
    $current->remember('schema', 'posts', fn () => 'before clear');
    $current->clear('schema');
    $current->remember('schema', 'posts', fn () => 'current');
    $current->remember('metrics', 'expired', fn () => 'expired', ttlMinutesOverride: 1);
    DB::table('prune_cache')->where('key', 'like', '%metrics%')->update(['expiration' => time() - 10]);
    $store->forever('app-own-key', 'the app\'s');

    $result = $current->prune();

    expect($result)->toMatchArray(['driver' => 'database', 'supported' => true, 'deleted' => 3])
        ->and(DB::table('prune_cache')->orderBy('key')->pluck('key')->all())->toBe([
            'app_app-own-key',
            'app_'.$current->buildKey('schema', 'posts'),
        ])
        ->and($current->remember('schema', 'posts', fn () => 'recomputed'))->toBe('current');
});

it('deletes the expired files of the file store and keeps the live ones', function () {
    $dir = sys_get_temp_dir().'/martis-prune-'.uniqid();
    config()->set('cache.stores.prune_file', ['driver' => 'file', 'path' => $dir]);
    $store = Cache::store('prune_file');
    $cache = new MartisCache($store, 'v2.0.0');

    $cache->remember('metrics', 'live', fn () => 'live', ttlMinutesOverride: 60);
    $store->put('expired-entry', 'gone', 1);
    $expiredPath = (fn (string $key) => $this->path($key))->call($store->getStore(), 'expired-entry');
    file_put_contents($expiredPath, (time() - 10).substr((string) file_get_contents($expiredPath), 10));

    expect($cache->prune())->toMatchArray(['driver' => 'file', 'supported' => true, 'deleted' => 1])
        ->and(file_exists($expiredPath))->toBeFalse()
        ->and($cache->remember('metrics', 'live', fn () => 'recomputed'))->toBe('live');

    rmtree($dir);
});

it('reports a store that expires keys on its own as needing no prune', function () {
    expect((new MartisCache(Cache::store('array'), 'v2.0.0'))->prune())
        ->toMatchArray(['driver' => 'array', 'supported' => false, 'deleted' => 0]);
});

it('runs from martis:cache:prune', function () {
    $store = pruneDatabaseStore();
    config()->set('cache.default', 'prune_db');
    (new MartisCache($store, 'v0.0.1'))->remember('schema', 'posts', fn () => 'old');

    $this->artisan('martis:cache:prune')
        ->expectsOutputToContain('database')
        ->assertSuccessful();

    expect(DB::table('prune_cache')->count())->toBe(0);
});

it('counts the store prefix in the 191-character key limit', function () {
    $cache = new MartisCache(pruneDatabaseStore(), 'v2.0.0');
    $base = 'martis:cache:schema@v2.0.0:v1:';
    $fits = str_repeat('a', 191 - strlen('app_') - strlen($base));

    expect($cache->buildKey('schema', $fits))->toBe($base.$fits)
        ->and($cache->buildKey('schema', $fits.'a'))->toStartWith('martis:cache:schema:h:');
});
