<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Martis\Cache\MartisCache;
use Martis\Dashboards\Dashboard;
use Martis\Facades\Martis;
use Martis\Fields\Text;
use Martis\Filters\BooleanFilter;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Http\Requests\LensRequest;
use Martis\Lenses\Lens;
use Martis\Metrics\ValueMetric;
use Martis\Metrics\ValueResult;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// A BooleanFilter's value reaches apply() with the option values the filter
// declares and nothing else (F069, F073). The `filters` query parameter is
// client input; a filter written the way the docs and the scaffold used to
// show it (`foreach ($value as $column => $enabled) $query->where($column,
// true)`) turned a key the request invented into a column name, a boolean
// oracle on columns no field shows, or a SQL error. The package keeps the keys
// to options($request) on the index, in a lens and on a dashboard.
// ---------------------------------------------------------------------------

class BfvItem extends Model
{
    protected $table = 'bfv_items';

    protected $guarded = [];

    public $timestamps = false;
}

// The documented pattern that used to be unsafe: it uses each key as a column.
class BfvLeakyFilter extends BooleanFilter
{
    /** @var list<mixed> */
    public static array $received = [];

    public function options(Request $request): array
    {
        return ['Flagged' => 'is_flagged', 'Archived' => 'is_archived'];
    }

    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        self::$received[] = $value;

        foreach ((array) $value as $column => $enabled) {
            if ($enabled) {
                $query->where($column, true);
            }
        }

        return $query;
    }
}

class BfvGroupedFilter extends BfvLeakyFilter
{
    public function options(Request $request): array
    {
        return ['Status' => ['Flagged' => 'is_flagged'], 'Other' => ['Archived' => 'is_archived']];
    }
}

class BfvItemResource extends Resource
{
    public static function model(): string
    {
        return BfvItem::class;
    }

    public static function uriKey(): string
    {
        return 'bfv-items';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')];
    }

    public function filters(Request $request): array
    {
        return [BfvLeakyFilter::make('Flags', 'flags'), BfvGroupedFilter::make('Grouped', 'grouped')];
    }

    public function lenses(Request $request): array
    {
        return [new BfvFlagsLens];
    }
}

class BfvFlagsLens extends Lens
{
    public function query(LensRequest $request, Builder $query): Builder
    {
        return $request->withFilters($query);
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')];
    }

    public function filters(Request $request): array
    {
        return [BfvLeakyFilter::make('Flags', 'flags')];
    }
}

class BfvCountMetric extends ValueMetric
{
    public function calculate(Request $request): ValueResult
    {
        return $this->result($this->applyFilterScope(BfvItem::query())->count());
    }
}

class BfvDashboard extends Dashboard
{
    public function __construct()
    {
        parent::__construct(name: 'Flags', uriKey: 'bfv-flags');
    }

    public function cards(Request $request): array
    {
        return [BfvCountMetric::make('Items', 'items')];
    }

    public function filters(Request $request): array
    {
        return [BfvLeakyFilter::make('Flags', 'flags')];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('bfv_items');
    Schema::create('bfv_items', function ($t) {
        $t->id();
        $t->string('title');
        $t->boolean('is_flagged')->default(false);
        $t->boolean('is_archived')->default(false);
        // A column no field shows and no filter offers.
        $t->boolean('is_secret')->default(false);
    });
    BfvItem::query()->insert([
        ['title' => 'A', 'is_flagged' => true, 'is_archived' => false, 'is_secret' => true],
        ['title' => 'B', 'is_flagged' => true, 'is_archived' => true, 'is_secret' => false],
        ['title' => 'C', 'is_flagged' => false, 'is_archived' => false, 'is_secret' => true],
    ]);

    BfvLeakyFilter::$received = [];

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(BfvItemResource::class);

    Martis::dashboards([new BfvDashboard]);
    app(MartisCache::class)->clear('dashboards');
    Cache::flush();
});

afterEach(function () {
    Schema::dropIfExists('bfv_items');
    Martis::dashboards([]);
});

function bfvIndex(string $filter, array $value): TestResponse
{
    $filters = urlencode(json_encode([$filter => $value]));

    return test()->getJson("/martis/api/resources/bfv-items?filters={$filters}");
}

function bfvLens(array $value): TestResponse
{
    $filters = urlencode(json_encode(['flags' => $value]));

    return test()->getJson("/martis/api/resources/bfv-items/lenses/bfv-flags?filters={$filters}");
}

function bfvCard(array $value): TestResponse
{
    $filters = urlencode(json_encode(['flags' => $value]));

    return test()->getJson("/martis/api/dashboards/bfv-flags/cards/items?filters={$filters}");
}

// ---- the index -----------------------------------------------------------

it('never passes a key outside the options to apply() on the index', function () {
    // is_secret is a real column no filter offers: it must not narrow the index.
    $response = bfvIndex('flags', ['is_secret' => true]);

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(3)
        ->and(BfvLeakyFilter::$received)->toBe([]);
});

it('does not turn a request key into a SQL error', function (string $key) {
    $response = bfvIndex('flags', [$key => true]);

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(3)
        ->and(BfvLeakyFilter::$received)->toBe([]);
})->with(['no_such_column', 'settings->flag', 'bfv_items.is_secret', 'is_flagged; drop table bfv_items']);

it('keeps the declared keys and drops the others', function () {
    $response = bfvIndex('flags', ['is_flagged' => true, 'is_secret' => true, 'no_such_column' => true]);

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(2)
        ->and(BfvLeakyFilter::$received)->toBe([['is_flagged' => true]]);
});

it('reads the declared options of a grouped filter', function () {
    $response = bfvIndex('grouped', ['is_flagged' => true, 'is_archived' => true, 'is_secret' => true]);

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(1)
        ->and(BfvLeakyFilter::$received)->toBe([['is_flagged' => true, 'is_archived' => true]]);
});

it('hands apply() booleans', function () {
    bfvIndex('flags', ['is_flagged' => 'true', 'is_archived' => 'false'])->assertOk();

    expect(BfvLeakyFilter::$received)->toBe([['is_flagged' => true, 'is_archived' => false]]);
});

it('skips the filter when a request names no declared key', function () {
    bfvIndex('flags', ['is_secret' => true, 'other' => false])->assertOk();

    expect(BfvLeakyFilter::$received)->toBe([]);
});

it('still applies a declared key as before', function () {
    $response = bfvIndex('flags', ['is_flagged' => true]);

    expect($response->json('meta.total'))->toBe(2)
        ->and(collect($response->json('data'))->pluck('title')->all())->toBe(['A', 'B']);
});

// ---- a lens --------------------------------------------------------------

it('never passes a key outside the options to apply() in a lens', function () {
    $response = bfvLens(['is_secret' => true]);

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(3)
        ->and(BfvLeakyFilter::$received)->toBe([]);
});

it('keeps the declared keys in a lens', function () {
    $response = bfvLens(['is_flagged' => true, 'is_secret' => true, 'no_such_column' => true]);

    // The lens applies the filter to the page and to the total: every call
    // got the declared key only.
    $response->assertOk();
    expect($response->json('meta.total'))->toBe(2)
        ->and(collect(BfvLeakyFilter::$received)->unique()->values()->all())->toBe([['is_flagged' => true]]);
});

// ---- a dashboard ---------------------------------------------------------

it('never passes a key outside the options to apply() on a dashboard card', function () {
    $response = bfvCard(['is_secret' => true]);

    $response->assertOk();
    expect($response->json('data.result.value'))->toBe(3)
        ->and(BfvLeakyFilter::$received)->toBe([]);
});

it('keeps the declared keys on a dashboard card', function () {
    $response = bfvCard(['is_flagged' => true, 'is_secret' => true, 'no_such_column' => true]);

    $response->assertOk();
    expect($response->json('data.result.value'))->toBe(2)
        ->and(BfvLeakyFilter::$received)->toBe([['is_flagged' => true]]);
});
