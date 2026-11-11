<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Martis\Cache\MartisCache;
use Martis\Facades\Martis;
use Martis\Metrics\ValueMetric;
use Martis\Metrics\ValueResult;

/*
 * v2.20.0: `martis.cache.metrics.default_ttl_seconds`, `Metric::$cacheSeconds`
 * (and `cacheForSeconds()`) and `Metric::live()`. Every default keeps today's
 * behaviour: the `metrics` layer in minutes, one entry per user.
 */

class McsCountingMetric extends ValueMetric
{
    public static int $calls = 0;

    public function calculate(Request $request): ValueResult
    {
        self::$calls++;

        return $this->result(self::$calls);
    }
}

class McsSharedMetric extends McsCountingMetric
{
    protected bool $cachePerUser = false;
}

class McsSecondsMetric extends McsCountingMetric
{
    protected ?int $cacheSeconds = 20;
}

class McsLiveMetric extends McsCountingMetric
{
    protected bool $live = true;
}

class McsLiveWithSecondsMetric extends McsCountingMetric
{
    protected bool $live = true;

    protected ?int $cacheSeconds = 20;
}

class McsCacheForMetric extends McsCountingMetric
{
    public function cacheFor(): ?DateTimeInterface
    {
        return now()->addMinutes(10);
    }
}

function mcsUser(int $id): User
{
    return (new User)->forceFill(['id' => $id]);
}

function mcsResolve(ValueMetric $metric, ?User $user = null): int
{
    $request = Request::create('/');
    // Binding the request installs the auth user resolver: set ours after.
    app()->instance('request', $request);
    $request->setUserResolver(fn () => $user);

    return (int) $metric->resolve($request)['value'];
}

/** @return list<string> */
function mcsLayerKeys(): array
{
    $store = Cache::store('array')->getStore();

    return array_values(array_filter(
        array_keys((fn () => $this->storage)->call($store)),
        fn (string $key): bool => str_starts_with($key, 'martis:cache:metrics'),
    ));
}

beforeEach(function () {
    config()->set('cache.default', 'array');
    Cache::store('array')->flush();
    config()->set('martis.cache.enabled', true);
    config()->set('martis.cache.metrics', ['enabled' => true, 'ttl' => 5]);

    $this->app->forgetInstance(MartisCache::class);
    $this->app->singleton(MartisCache::class, fn () => new MartisCache(Cache::store('array'), 'v2.20.0'));
    app(MartisCache::class)->flushAllForTesting();

    McsCountingMetric::$calls = 0;
    Martis::forgetCacheScope();
});

afterEach(function () {
    Martis::forgetCacheScope();
});

it('keeps the layer TTL in minutes when nothing new is set', function () {
    expect(mcsResolve(new McsCountingMetric('Plain')))->toBe(1);
    $this->travel(4)->minutes();
    expect(mcsResolve(new McsCountingMetric('Plain')))->toBe(1);

    $this->travel(2)->minutes();
    expect(mcsResolve(new McsCountingMetric('Plain')))->toBe(2);
});

it('keeps one entry per user by default', function () {
    mcsResolve(new McsCountingMetric('Plain'), mcsUser(1));
    mcsResolve(new McsCountingMetric('Plain'), mcsUser(2));

    expect(McsCountingMetric::$calls)->toBe(2);
});

it('shares one entry across users with $cachePerUser = false, with a TTL in seconds too', function () {
    config()->set('martis.cache.metrics.default_ttl_seconds', 30);

    mcsResolve(new McsSharedMetric('Shared'), mcsUser(1));
    mcsResolve(new McsSharedMetric('Shared'), mcsUser(2));

    expect(McsCountingMetric::$calls)->toBe(1)
        ->and(mcsLayerKeys())->toHaveCount(1);
});

it('applies default_ttl_seconds to a metric that sets none', function () {
    config()->set('martis.cache.metrics.default_ttl_seconds', 30);

    mcsResolve(new McsCountingMetric('Plain'));
    $this->travel(29)->seconds();
    mcsResolve(new McsCountingMetric('Plain'));
    expect(McsCountingMetric::$calls)->toBe(1);

    $this->travel(2)->seconds();
    mcsResolve(new McsCountingMetric('Plain'));
    expect(McsCountingMetric::$calls)->toBe(2);
});

it('treats an empty default_ttl_seconds like null', function () {
    config()->set('martis.cache.metrics.default_ttl_seconds', '');

    mcsResolve(new McsCountingMetric('Plain'));
    $this->travel(2)->minutes();
    mcsResolve(new McsCountingMetric('Plain'));

    expect(McsCountingMetric::$calls)->toBe(1);
});

it('accepts default_ttl_seconds as the numeric string an env var gives', function () {
    config()->set('martis.cache.metrics.default_ttl_seconds', '30');

    mcsResolve(new McsCountingMetric('Plain'));
    $this->travel(31)->seconds();
    mcsResolve(new McsCountingMetric('Plain'));

    expect(McsCountingMetric::$calls)->toBe(2);
});

it('throws, naming the key, when default_ttl_seconds is set but unusable', function (mixed $value) {
    config()->set('martis.cache.metrics.default_ttl_seconds', $value);

    expect(fn () => mcsResolve(new McsCountingMetric('Plain')))
        ->toThrow(InvalidArgumentException::class, 'martis.cache.metrics.default_ttl_seconds');
})->with([0, -1, 'soon', '1.5', 2.5, true]);

it('lets $cacheSeconds win over the layer TTL and default_ttl_seconds', function () {
    config()->set('martis.cache.metrics.default_ttl_seconds', 300);

    mcsResolve(new McsSecondsMetric('Seconds'));
    $this->travel(19)->seconds();
    mcsResolve(new McsSecondsMetric('Seconds'));
    expect(McsCountingMetric::$calls)->toBe(1);

    $this->travel(2)->seconds();
    mcsResolve(new McsSecondsMetric('Seconds'));
    expect(McsCountingMetric::$calls)->toBe(2);
});

it('sets the seconds fluently with cacheForSeconds() and refuses less than one', function () {
    $metric = (new McsCountingMetric('Fluent'))->cacheForSeconds(10);

    mcsResolve($metric);
    $this->travel(11)->seconds();
    mcsResolve($metric);
    expect(McsCountingMetric::$calls)->toBe(2);

    expect(fn () => $metric->cacheForSeconds(0))->toThrow(InvalidArgumentException::class);

    $metric->cacheForSeconds(null);
    expect($metric->cacheFor())->toBeNull();
});

it('reports $cacheSeconds as the moment cacheFor() expires', function () {
    $this->freezeTime();

    expect((new McsSecondsMetric('Seconds'))->cacheFor()?->getTimestamp())->toBe(now()->addSeconds(20)->getTimestamp())
        ->and((new McsCountingMetric('Plain'))->cacheFor())->toBeNull();
});

it('keeps the entry of $cacheSeconds in the metrics layer, so a clear drops it', function () {
    mcsResolve(new McsSecondsMetric('Seconds'));
    expect(mcsLayerKeys())->toHaveCount(1);

    $this->artisan('martis:cache:clear', ['type' => 'metrics'])->assertSuccessful();
    app(MartisCache::class)->refreshState();

    mcsResolve(new McsSecondsMetric('Seconds'));
    expect(McsCountingMetric::$calls)->toBe(2);
});

it('never caches a live metric, even with default_ttl_seconds, $cacheSeconds or a TTL set', function () {
    config()->set('martis.cache.metrics.default_ttl_seconds', 300);

    mcsResolve(new McsLiveMetric('Live'));
    mcsResolve(new McsLiveMetric('Live'));
    mcsResolve(new McsLiveWithSecondsMetric('LiveSeconds'));
    mcsResolve(new McsLiveWithSecondsMetric('LiveSeconds'));
    mcsResolve((new McsCountingMetric('Fluent'))->live());
    mcsResolve((new McsCountingMetric('Fluent'))->live());

    expect(McsCountingMetric::$calls)->toBe(6)
        ->and(mcsLayerKeys())->toBe([]);
});

it('lets live(false) turn a live metric back into a cached one', function () {
    $metric = (new McsLiveMetric('Live'))->live(false);

    mcsResolve($metric);
    mcsResolve($metric);

    expect(McsCountingMetric::$calls)->toBe(1);
});

it('never caches a live metric that overrides cacheFor() either', function () {
    mcsResolve((new McsCacheForMetric('Both'))->live());
    mcsResolve((new McsCacheForMetric('Both'))->live());

    expect(McsCountingMetric::$calls)->toBe(2);
});

it('keeps the cacheFor() path of a metric that overrides it, outside the layer', function () {
    config()->set('martis.cache.metrics.default_ttl_seconds', 5);

    mcsResolve(new McsCacheForMetric('Custom'));
    $this->travel(30)->seconds();
    mcsResolve(new McsCacheForMetric('Custom'));

    expect(McsCountingMetric::$calls)->toBe(1)
        ->and(mcsLayerKeys())->toBe([]);
});

it('puts the app cache scope into the key of a seconds entry', function () {
    Martis::cacheScopeUsing(fn (Request $request): ?string => $request->getHost());
    $metric = fn () => new McsSharedMetric('Shared');

    $resolveOn = function (string $host) use ($metric): void {
        // The scope is read from the request the application has bound.
        $request = Request::create("http://{$host}/");
        app()->instance('request', $request);
        $metric()->cacheForSeconds(30)->resolve($request);
    };
    $resolveOn('tenant-a.example');
    $resolveOn('tenant-a.example');
    $resolveOn('tenant-b.example');

    expect(McsCountingMetric::$calls)->toBe(2)
        ->and(mcsLayerKeys())->toHaveCount(2);
});

it('computes every time when the metrics layer is disabled, seconds or not', function () {
    config()->set('martis.cache.metrics', ['enabled' => false, 'ttl' => 5]);

    mcsResolve(new McsSecondsMetric('Seconds'));
    mcsResolve(new McsSecondsMetric('Seconds'));

    expect(McsCountingMetric::$calls)->toBe(2);
});

it('computes every time under the per-request bypass', function () {
    Gate::define('bypass-martis-cache', fn ($user = null) => true);
    $metric = new McsSecondsMetric('Seconds');

    foreach ([1, 2] as $_) {
        $request = Request::create('/');
        $request->headers->set('X-Martis-No-Cache', '1');
        app()->instance('request', $request);
        $metric->resolve($request);
    }

    expect(McsCountingMetric::$calls)->toBe(2);
});
