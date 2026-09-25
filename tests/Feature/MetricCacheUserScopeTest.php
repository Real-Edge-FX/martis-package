<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Martis\Cache\MartisCache;
use Martis\Metrics\ValueMetric;
use Martis\Metrics\ValueResult;

/*
 * A metric's cached result must never be served to another user. Both cache
 * paths of Metric::resolve() (the Martis `metrics` layer, and Laravel's cache
 * when the metric overrides cacheFor()) used to key the entry on the metric,
 * range, filters and locale only, so a calculate() scoped to the user, their
 * tenant or their permissions served the first user's value to everyone for
 * the TTL. The key carries the authenticated user now; guests share one entry.
 */

class UserScopedCountMetric extends ValueMetric
{
    public static int $calls = 0;

    public function calculate(Request $request): ValueResult
    {
        self::$calls++;

        return $this->result((int) $request->user()?->getAuthIdentifier());
    }
}

class UserScopedCountMetricWithCacheFor extends UserScopedCountMetric
{
    public function cacheFor(): ?DateTimeInterface
    {
        return now()->addMinutes(5);
    }
}

function metricRequestFor(?int $userId): Request
{
    $request = Request::create('/');
    $request->setUserResolver(fn () => $userId === null ? null : (new User)->forceFill(['id' => $userId]));

    return $request;
}

beforeEach(function () {
    config()->set('cache.default', 'array');
    Cache::store('array')->flush();
    config()->set('martis.cache.enabled', true);
    config()->set('martis.cache.metrics', ['enabled' => true, 'ttl' => 5]);
    $this->app->forgetInstance(MartisCache::class);
    $this->app->singleton(MartisCache::class, fn () => new MartisCache(Cache::store('array')));
    UserScopedCountMetric::$calls = 0;
});

dataset('metric cache paths', [
    'the metrics cache layer' => [UserScopedCountMetric::class],
    'a per-class cacheFor()' => [UserScopedCountMetricWithCacheFor::class],
]);

it('serves each user the result computed for them', function (string $metricClass) {
    $metric = $metricClass::make('Count');

    expect($metric->resolve(metricRequestFor(1))['value'])->toBe(1)
        ->and($metric->resolve(metricRequestFor(2))['value'])->toBe(2)
        ->and(UserScopedCountMetric::$calls)->toBe(2);
})->with('metric cache paths');

it('still caches the result for the same user', function (string $metricClass) {
    $metric = $metricClass::make('Count');

    $metric->resolve(metricRequestFor(1));
    $metric->resolve(metricRequestFor(1));

    expect(UserScopedCountMetric::$calls)->toBe(1);
})->with('metric cache paths');

it('gives guests one shared entry', function (string $metricClass) {
    $metric = $metricClass::make('Count');

    expect($metric->resolve(metricRequestFor(null))['value'])->toBe(0);
    $metric->resolve(metricRequestFor(null));

    expect(UserScopedCountMetric::$calls)->toBe(1);
})->with('metric cache paths');
