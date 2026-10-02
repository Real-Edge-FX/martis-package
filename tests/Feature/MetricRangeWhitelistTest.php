<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Martis\Cache\MartisCache;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Metrics\Metric;
use Martis\Metrics\PartitionMetric;
use Martis\Metrics\PartitionResult;
use Martis\Metrics\TrendMetric;
use Martis\Metrics\TrendResult;
use Martis\Metrics\ValueMetric;
use Martis\Metrics\ValueResult;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// A metric accepts only the ranges() it declares (F045). `?range=` is client
// input: a trend opens one bucket per step of its window and reads every row
// in it, and the metric cache keeps an entry per value, so one request for
// `range=10000000` loaded the whole table, built millions of buckets and
// cached the result under a key of its own.
// ---------------------------------------------------------------------------

class MrwItem extends Model
{
    protected $table = 'mrw_items';

    protected $guarded = [];

    public $timestamps = false;
}

class MrwTrend extends TrendMetric
{
    public function calculate(Request $request): TrendResult
    {
        return $this->countByDays($request, MrwItem::class);
    }

    public function cacheKey(Request $request): ?string
    {
        return $this->resultCacheKey($request);
    }

    public function range(Request $request): string
    {
        return $this->requestedRange($request);
    }
}

class MrwWeeklyTrend extends MrwTrend
{
    public function calculate(Request $request): TrendResult
    {
        return $this->countByWeeks($request, MrwItem::class);
    }
}

// Declares its own ranges.
class MrwCustomTrend extends MrwTrend
{
    public function ranges(): array
    {
        return [7 => 'A week', 14 => 'Two weeks'];
    }
}

// Declares a window beyond the ceiling.
class MrwHugeTrend extends MrwTrend
{
    public function ranges(): array
    {
        return [30 => '30 days', 1000000 => 'Everything'];
    }
}

class MrwValue extends ValueMetric
{
    public function calculate(Request $request): ValueResult
    {
        return $this->count($request, MrwItem::class);
    }

    public function cacheKey(Request $request): ?string
    {
        return $this->resultCacheKey($request);
    }

    public function window(string|int $range): array
    {
        return $this->calculateDateRange($range);
    }
}

// Hides the range selector.
class MrwNoRangesValue extends MrwValue
{
    public function ranges(): array
    {
        return [];
    }
}

class MrwPartition extends PartitionMetric
{
    public function calculate(Request $request): PartitionResult
    {
        return $this->count($request, MrwItem::class, 'kind');
    }

    public function cacheKey(Request $request): ?string
    {
        return $this->resultCacheKey($request);
    }
}

class MrwItemResource extends Resource
{
    public static function model(): string
    {
        return MrwItem::class;
    }

    public static function uriKey(): string
    {
        return 'mrw-items';
    }

    public function fields(Request $request): array
    {
        return [Text::make('kind')];
    }

    public function cards(Request $request): array
    {
        return [MrwTrend::make('Trend', 'trend'), MrwValue::make('Value', 'value')];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('mrw_items');
    Schema::create('mrw_items', function ($t) {
        $t->id();
        $t->string('kind')->nullable();
        $t->dateTime('created_at')->nullable();
    });

    $now = CarbonImmutable::now();
    MrwItem::query()->insert([
        ['kind' => 'a', 'created_at' => $now->subDays(2)],
        ['kind' => 'a', 'created_at' => $now->subDays(100)],
        ['kind' => 'b', 'created_at' => $now->subDays(400)],
    ]);

    config()->set('martis.cache.enabled', false);
    Cache::flush();

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(MrwItemResource::class);
});

afterEach(function () {
    Schema::dropIfExists('mrw_items');
    app(ResourceRegistry::class)->flush();
});

function mrwRequest(mixed $range = null): Request
{
    // A query string carries text: what an HTTP request reads back is a
    // string (or an array for `?range[]=`), never an int.
    return Request::create('/', 'GET', $range === null ? [] : ['range' => is_scalar($range) ? (string) $range : $range]);
}

function mrwLabels(Metric $metric, mixed $range = null): int
{
    $result = $metric->calculate(mrwRequest($range));

    return count($result->toArray()['labels']);
}

// ---- the trend -----------------------------------------------------------

it('opens the default window for a range the metric does not declare', function (mixed $range) {
    // Declared default 30: one bucket per day from 30 days ago to today.
    expect(mrwLabels(MrwTrend::make('Trend'), $range))->toBe(31);
})->with([
    'an arbitrary number' => 10000000,
    'a huge number' => '99999999999999999999',
    'a negative number' => -5,
    'text' => 'forever',
    'a padded value' => '030',
    'a decimal' => '30.0',
    'an array' => [['10000000']],
    'a named range the trend does not declare' => 'LAST_DECADE',
]);

it('opens the window of a range the metric declares', function () {
    expect(mrwLabels(MrwTrend::make('Trend'), 60))->toBe(61)
        ->and(mrwLabels(MrwTrend::make('Trend'), '365'))->toBe(366)
        ->and(mrwLabels(MrwTrend::make('Trend')))->toBe(31);
});

it('keeps a weekly trend to the declared window too', function () {
    // 30 is a number of weeks here: one bucket per week.
    expect(mrwLabels(MrwWeeklyTrend::make('Trend'), 10000000))->toBe(31);
});

it('falls back to the first declared range when 30 is not one of them', function () {
    expect(mrwLabels(MrwCustomTrend::make('Trend'), 10000000))->toBe(8)
        ->and(mrwLabels(MrwCustomTrend::make('Trend'), 14))->toBe(15)
        ->and(mrwLabels(MrwCustomTrend::make('Trend'), 30))->toBe(8);
});

it('holds a declared window to the ceiling', function () {
    expect(mrwLabels(MrwHugeTrend::make('Trend'), 1000000))->toBe(Metric::MAX_RANGE + 1);
});

it('answers the request for a huge range without reading more than the default window', function () {
    $response = $this->getJson('/martis/api/resources/mrw-items/cards/trend?range=10000000');

    $response->assertOk();
    expect($response->json('data.result.labels'))->toHaveCount(31);
});

// ---- the value metric ------------------------------------------------------

it('counts the default window for a range the metric does not declare', function () {
    $value = fn (mixed $range) => (int) MrwValue::make('Value')->calculate(mrwRequest($range))->toArray()['value'];

    // 30 days: the row two days old. 365: the row 100 days old as well.
    expect($value(null))->toBe(1)
        ->and($value(365))->toBe(2)
        ->and($value(10000000))->toBe(1)
        ->and($value('forever'))->toBe(1);
});

it('holds a numeric window to the ceiling', function () {
    [$start] = MrwValue::make('Value')->window(99999999);

    expect(abs($start->diffInDays(CarbonImmutable::now(), false) - Metric::MAX_RANGE))->toBeLessThan(1);
});

it('still resolves the named ranges', function () {
    [$start] = MrwValue::make('Value')->window('MTD');

    expect($start->toDateString())->toBe(CarbonImmutable::now()->startOfMonth()->toDateString());
});

it('reads 30 for a metric that declares no ranges', function () {
    $metric = MrwNoRangesValue::make('Value');

    expect((int) $metric->calculate(mrwRequest(365))->toArray()['value'])->toBe(1)
        ->and((int) $metric->calculate(mrwRequest(10000000))->toArray()['value'])->toBe(1);
});

// ---- the cache key -------------------------------------------------------

it('keys the cache on the declared range, not on the value the request sends', function () {
    $metric = MrwTrend::make('Trend');

    $defaultKey = $metric->cacheKey(mrwRequest());

    expect($metric->cacheKey(mrwRequest('10000000')))->toBe($defaultKey)
        ->and($metric->cacheKey(mrwRequest('9999999999')))->toBe($defaultKey)
        ->and($metric->cacheKey(mrwRequest('forever')))->toBe($defaultKey)
        ->and($metric->cacheKey(mrwRequest(30)))->toBe($defaultKey)
        ->and($metric->cacheKey(mrwRequest(60)))->not->toBe($defaultKey)
        ->and($metric->cacheKey(mrwRequest('MTD')))->not->toBe($defaultKey);
});

it('gives a metric with no declared ranges one key whatever the range', function () {
    $partition = MrwPartition::make('Kinds');

    expect($partition->cacheKey(mrwRequest(1)))->toBe($partition->cacheKey(mrwRequest(99999)))
        ->and($partition->cacheKey(mrwRequest(1)))->toBe($partition->cacheKey(mrwRequest()));
});

it('does not grow the cache with the range values a request makes up', function () {
    config()->set('martis.cache.enabled', true);
    config()->set('martis.cache.metrics', ['enabled' => true, 'ttl' => 5]);
    config()->set('cache.default', 'array');
    $this->app->forgetInstance(MartisCache::class);
    $this->app->singleton(MartisCache::class, fn () => new MartisCache(Cache::store('array')));

    $metric = MrwTrend::make('Trend');

    foreach ([1000, 2000, 3000, 'a', 'b'] as $range) {
        $metric->resolve(mrwRequest($range));
    }

    $keys = collect((fn () => array_keys($this->storage))->call(Cache::store('array')->getStore()))
        ->filter(fn (string $key) => str_contains($key, 'metrics'));

    expect($keys)->toHaveCount(1);
});

it('reads the range through requestedRange()', function () {
    $metric = MrwTrend::make('Trend');

    expect($metric->range(mrwRequest(60)))->toBe('60')
        ->and($metric->range(mrwRequest('MTD')))->toBe('MTD')
        ->and($metric->range(mrwRequest(61)))->toBe('30')
        ->and($metric->range(mrwRequest()))->toBe('30')
        ->and(MrwCustomTrend::make('Trend')->range(mrwRequest(30)))->toBe('7');
});
