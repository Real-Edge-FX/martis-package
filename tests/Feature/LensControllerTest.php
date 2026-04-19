<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Martis\Enums\FilterType;
use Martis\Fields\Number;
use Martis\Fields\Text;
use Martis\Filters\Filter as ResourceFilter;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Http\Requests\LensRequest;
use Martis\Lenses\Lens;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------

class LensTestModel extends Model
{
    protected $table = 'lens_test_items';

    protected $fillable = ['title', 'revenue', 'status'];

    public $timestamps = true;
}

class LensTestStatusFilter extends ResourceFilter
{
    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query->where('status', $value);
    }

    public function filterType(): FilterType
    {
        return FilterType::Select;
    }
}

class LensTestTopRevenueLens extends Lens
{
    public static int $pollingInterval = 10;

    public function query(LensRequest $request, Builder $query): Builder
    {
        return $request->withOrdering(
            $request->withFilters($query),
            fn (Builder $q) => $q->orderByDesc('revenue'),
        );
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->sortable(),
            Number::make('revenue')->sortable(),
        ];
    }

    public function filters(Request $request): array
    {
        return [
            LensTestStatusFilter::make('Status'),
        ];
    }

    public function summary(Request $request, Builder $query): array
    {
        return [
            'revenue' => ['label' => 'Total Revenue', 'value' => (int) $query->sum('revenue')],
            'count' => ['label' => 'Count', 'value' => $query->count()],
        ];
    }
}

class LensTestForbiddenLens extends Lens
{
    public function query(LensRequest $request, Builder $query): Builder
    {
        return $query;
    }
}

class LensTestItemResource extends Resource
{
    public static function model(): string
    {
        return LensTestModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->required(),
            Number::make('revenue'),
            Text::make('status'),
        ];
    }

    public function lenses(Request $request): array
    {
        return [
            new LensTestTopRevenueLens(),
            (new LensTestForbiddenLens())->canSee(fn () => false),
        ];
    }
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('lens_test_items');
    Schema::create('lens_test_items', function ($table) {
        $table->id();
        $table->string('title');
        $table->integer('revenue')->default(0);
        $table->string('status')->default('active');
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(LensTestItemResource::class);

    Cache::flush();
});

afterEach(function () {
    Schema::dropIfExists('lens_test_items');
});

// ---------------------------------------------------------------------------
// Nova 5 parity
// ---------------------------------------------------------------------------

it('returns the lens dataset ordered by default closure when no sort is supplied', function () {
    LensTestModel::create(['title' => 'Small', 'revenue' => 10]);
    LensTestModel::create(['title' => 'Large', 'revenue' => 100]);

    $response = $this->getJson('/martis/api/resources/lens-test-models/lenses/lens-test-top-revenue');

    $response->assertStatus(200);
    expect($response->json('data.0.title'))->toBe('Large');
    expect($response->json('data.1.title'))->toBe('Small');
});

it('respects explicit sort over the default closure', function () {
    LensTestModel::create(['title' => 'Zeta', 'revenue' => 50]);
    LensTestModel::create(['title' => 'Alpha', 'revenue' => 100]);

    $response = $this->getJson('/martis/api/resources/lens-test-models/lenses/lens-test-top-revenue?sort=title&direction=asc');

    $response->assertStatus(200);
    expect($response->json('data.0.title'))->toBe('Alpha');
});

it('applies filters passed via the filters query param', function () {
    LensTestModel::create(['title' => 'A', 'revenue' => 10, 'status' => 'active']);
    LensTestModel::create(['title' => 'B', 'revenue' => 20, 'status' => 'archived']);

    $filters = json_encode(['status' => 'archived']);
    $response = $this->getJson("/martis/api/resources/lens-test-models/lenses/lens-test-top-revenue?filters={$filters}");

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.title'))->toBe('B');
});

it('skips filters tagged as excludeFromLens during inheritance', function () {
    $resourceClass = new class(null) extends LensTestItemResource {
        public static function uriKey(): string
        {
            return 'exclude-from-lens-resource';
        }

        public function filters(Request $request): array
        {
            return [
                LensTestStatusFilter::make('Only Index', 'only-index')->excludeFromLens(),
                LensTestStatusFilter::make('Shared', 'shared'),
            ];
        }

        public function lenses(Request $request): array
        {
            return [
                new class extends \Martis\Lenses\Lens {
                    public function query(
                        \Martis\Http\Requests\LensRequest $request,
                        Builder $query,
                    ): Builder {
                        return $request->withFilters($query);
                    }

                    public function fields(Request $request): array
                    {
                        return [\Martis\Fields\Text::make('title')];
                    }
                },
            ];
        }
    };
    app(ResourceRegistry::class)->register($resourceClass::class);

    // Re-apply the filter column difference: "only-index" maps to `status`,
    // "shared" maps to `status`. Both exercise the same column but only the
    // "shared" one should be honoured by the lens.
    LensTestModel::create(['title' => 'A', 'status' => 'active']);
    LensTestModel::create(['title' => 'B', 'status' => 'archived']);

    $lensUri = $resourceClass->lenses(request())[0]->uriKey();

    // Excluded filter uriKey is ignored → no filtering → both rows returned.
    $resp = $this->getJson("/martis/api/resources/exclude-from-lens-resource/lenses/{$lensUri}?filters=".urlencode('{"only-index":"archived"}'));
    $resp->assertStatus(200);
    expect($resp->json('meta.total'))->toBe(2);

    // Shared filter still applies.
    $resp = $this->getJson("/martis/api/resources/exclude-from-lens-resource/lenses/{$lensUri}?filters=".urlencode('{"shared":"archived"}'));
    $resp->assertStatus(200);
    expect($resp->json('meta.total'))->toBe(1);
    expect($resp->json('data.0.title'))->toBe('B');
});

it('inherits filters from the parent resource when the lens declares none', function () {
    // LensTestTopRevenueLens declares its own filter. Build a lens that
    // does NOT, and a resource that declares ClientPlanFilter-style filter.
    $resourceClass = new class(null) extends LensTestItemResource {
        public static function uriKey(): string
        {
            return 'inherit-filters-resource';
        }

        public function filters(Request $request): array
        {
            return [LensTestStatusFilter::make('Status', 'status')];
        }

        public function lenses(Request $request): array
        {
            return [
                new class extends \Martis\Lenses\Lens {
                    public function query(
                        \Martis\Http\Requests\LensRequest $request,
                        Builder $query,
                    ): Builder {
                        return $request->withFilters($query);
                    }

                    public function fields(Request $request): array
                    {
                        return [Text::make('title')];
                    }
                },
            ];
        }
    };

    app(ResourceRegistry::class)->register($resourceClass::class);

    LensTestModel::create(['title' => 'active one', 'status' => 'active']);
    LensTestModel::create(['title' => 'archived one', 'status' => 'archived']);

    $lensUri = $resourceClass->lenses(request())[0]->uriKey();
    $filters = json_encode(['status' => 'archived']);
    $response = $this->getJson("/martis/api/resources/inherit-filters-resource/lenses/{$lensUri}?filters={$filters}");

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.title'))->toBe('archived one');
});

it('returns 404 for unknown lens uriKey', function () {
    $response = $this->getJson('/martis/api/resources/lens-test-models/lenses/not-a-lens');
    $response->assertStatus(404);
});

it('returns 403 when the lens canSee closure denies the request', function () {
    $response = $this->getJson('/martis/api/resources/lens-test-models/lenses/lens-test-forbidden');
    $response->assertStatus(403);
});

it('exposes polling metadata in the lens schema descriptor', function () {
    $lens = new LensTestTopRevenueLens();
    $payload = $lens->toArray();

    expect($payload)->toHaveKeys([
        'type', 'name', 'uriKey', 'component', 'perPageOptions',
        'polling', 'pollingInterval', 'showPollingToggle',
        'defaultFilters', 'cacheTtlSeconds', 'meta',
    ]);
    expect($payload['pollingInterval'])->toBe(10);
    expect($payload['uriKey'])->toBe('lens-test-top-revenue');
    expect($payload['name'])->toBe('Lens Test Top Revenue');
});

// ---------------------------------------------------------------------------
// Martis extension — summary row (D1)
// ---------------------------------------------------------------------------

it('embeds the summary row in the pagination meta', function () {
    LensTestModel::create(['title' => 'A', 'revenue' => 40]);
    LensTestModel::create(['title' => 'B', 'revenue' => 60]);

    $response = $this->getJson('/martis/api/resources/lens-test-models/lenses/lens-test-top-revenue');

    $response->assertStatus(200);
    expect($response->json('meta.summary.revenue.value'))->toBe(100);
    expect($response->json('meta.summary.count.value'))->toBe(2);
});

it('aggregates the summary over the lens-filtered query, not the base query', function () {
    // Build a lens that restricts status='active' in its query(). The
    // summary should ignore the archived rows entirely.
    $resourceClass = new class(null) extends LensTestItemResource {
        public static function uriKey(): string
        {
            return 'summary-filtered-items';
        }

        public function lenses(Request $request): array
        {
            return [
                new class extends \Martis\Lenses\Lens {
                    public function query(
                        \Martis\Http\Requests\LensRequest $request,
                        Builder $query,
                    ): Builder {
                        return $query->where('status', 'active');
                    }

                    public function fields(Request $request): array
                    {
                        return [\Martis\Fields\Text::make('title')];
                    }

                    public function summary(Request $request, Builder $query): array
                    {
                        return [
                            'revenue' => ['label' => 'Revenue', 'value' => (int) $query->sum('revenue')],
                            'count' => ['label' => 'Count', 'value' => (int) $query->count()],
                        ];
                    }
                },
            ];
        }
    };
    app(ResourceRegistry::class)->register($resourceClass::class);

    LensTestModel::create(['title' => 'A', 'revenue' => 10, 'status' => 'active']);
    LensTestModel::create(['title' => 'B', 'revenue' => 20, 'status' => 'active']);
    LensTestModel::create(['title' => 'C', 'revenue' => 1000, 'status' => 'archived']);

    $lensUri = $resourceClass->lenses(request())[0]->uriKey();
    $response = $this->getJson("/martis/api/resources/summary-filtered-items/lenses/{$lensUri}");

    $response->assertStatus(200);
    expect($response->json('meta.summary.count.value'))->toBe(2);
    expect($response->json('meta.summary.revenue.value'))->toBe(30);
    expect($response->json('meta.total'))->toBe(2);
});

it('exposes lens fields in the pagination meta so the UI renders the right columns', function () {
    LensTestModel::create(['title' => 'A', 'revenue' => 40]);

    $response = $this->getJson('/martis/api/resources/lens-test-models/lenses/lens-test-top-revenue');

    $response->assertStatus(200);
    $fields = $response->json('meta.fields');
    expect($fields)->toBeArray();
    expect(collect($fields)->pluck('attribute')->all())->toEqual(['title', 'revenue']);
});

it('exposes inherited actions in the pagination meta', function () {
    LensTestModel::create(['title' => 'A', 'revenue' => 40]);

    $response = $this->getJson('/martis/api/resources/lens-test-models/lenses/lens-test-top-revenue');

    $response->assertStatus(200);
    // The fixture resource does not declare actions; the lens inherits the
    // (empty) list. The key must still be present — the UI uses its mere
    // existence to differentiate lens payloads from the schema.
    expect($response->json('meta.actions'))->toBeArray();
});

it('exposes perPageOptions in the pagination meta, inheriting from the resource when the lens does not override', function () {
    LensTestModel::create(['title' => 'A', 'revenue' => 40]);

    $response = $this->getJson('/martis/api/resources/lens-test-models/lenses/lens-test-top-revenue');

    $response->assertStatus(200);
    // LensTestTopRevenueLens does not override $perPageOptions, so the
    // response inherits the Resource's default [10, 25, 50, 100].
    expect($response->json('meta.perPageOptions'))->toEqual([10, 25, 50, 100]);
});

// ---------------------------------------------------------------------------
// Martis extension — query cache (D2)
// ---------------------------------------------------------------------------

it('auto-invalidates the cache when the underlying table changes', function () {
    // Swap the lens for one that declares cacheFor(60).
    $cachingResource = new class(null) extends LensTestItemResource {
        public static function uriKey(): string
        {
            return 'cached-lens-items';
        }

        public function lenses(Request $request): array
        {
            return [
                (new LensTestTopRevenueLens())->cacheFor(60),
            ];
        }
    };

    app(ResourceRegistry::class)->register($cachingResource::class);

    LensTestModel::create(['title' => 'Before', 'revenue' => 10]);

    $first = $this->getJson('/martis/api/resources/cached-lens-items/lenses/lens-test-top-revenue');
    $first->assertStatus(200);
    expect($first->json('meta.total'))->toBe(1);

    // Inserting a new row must invalidate the cache (Martis D2 auto-invalidation):
    // the COUNT + MAX(updated_at) signature changes, so the next request
    // hits the DB again and reflects the new row.
    LensTestModel::create(['title' => 'After', 'revenue' => 99]);

    $second = $this->getJson('/martis/api/resources/cached-lens-items/lenses/lens-test-top-revenue');
    $second->assertStatus(200);
    expect($second->json('meta.total'))->toBe(2);
});

it('serves a cached response when no rows have been mutated between requests', function () {
    $cachingResource = new class(null) extends LensTestItemResource {
        public static function uriKey(): string
        {
            return 'cached-stable-items';
        }

        public function lenses(Request $request): array
        {
            return [
                (new LensTestTopRevenueLens())->cacheFor(60),
            ];
        }
    };
    app(ResourceRegistry::class)->register($cachingResource::class);

    LensTestModel::create(['title' => 'Stable', 'revenue' => 10]);

    $first = $this->getJson('/martis/api/resources/cached-stable-items/lenses/lens-test-top-revenue');
    $first->assertStatus(200);

    // No DB mutation → same cache key → identical body on the next hit.
    $second = $this->getJson('/martis/api/resources/cached-stable-items/lenses/lens-test-top-revenue');
    $second->assertStatus(200);

    expect($first->json('meta.total'))->toBe(1);
    expect($second->json('meta.total'))->toBe(1);
    expect($first->json('data'))->toEqual($second->json('data'));
});
