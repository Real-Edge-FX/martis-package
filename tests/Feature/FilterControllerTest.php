<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\Text;
use Martis\Filters\BooleanFilter;
use Martis\Filters\DateFilter;
use Martis\Filters\DateRangeFilter;
use Martis\Filters\MultiSelectFilter;
use Martis\Filters\SelectFilter;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------

class FilterTestModel extends Model
{
    protected $table = 'martis_test_filter_items';

    protected $fillable = ['title', 'status', 'is_active'];
}

class FilterTestStatusFilter extends SelectFilter
{
    public function options(Request $request): array
    {
        return [
            'Active' => 'active',
            'Inactive' => 'inactive',
        ];
    }

    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query->where('status', $value);
    }
}

class FilterTestActiveFilter extends BooleanFilter
{
    public function options(Request $request): array
    {
        return [
            'Active' => 'is_active',
        ];
    }

    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        if (is_array($value)) {
            foreach ($value as $column => $enabled) {
                if ($enabled) {
                    $query->where($column, true);
                }
            }
        }

        return $query;
    }
}

class FilterTestStatusInFilter extends MultiSelectFilter
{
    public function options(Request $request): array
    {
        return ['Active' => 'active', 'Inactive' => 'inactive', 'Pending' => 'pending'];
    }

    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return empty($value) ? $query : $query->whereIn('status', (array) $value);
    }
}

// A MultiSelectFilter whose apply() calls whereIn() UNCONDITIONALLY — no
// empty($value) guard. This is the pattern the docs endorse (the request
// pipeline skips empty arrays centrally, so consumers need no guard). It
// exists here to *pin* that central skip: revert the `$value === []` clause in
// ResourceController::applyFilters and an empty selection reaches apply() as
// whereIn('status', []) => `WHERE 0 = 1` => zero rows, failing the empty-array
// test below. The self-guarded fixture above cannot detect that regression.
class FilterTestStatusInUnguardedFilter extends MultiSelectFilter
{
    public function options(Request $request): array
    {
        return ['Active' => 'active', 'Inactive' => 'inactive'];
    }

    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query->whereIn('status', (array) $value);
    }
}

class FilterTestResource extends Resource
{
    public static function model(): string
    {
        return FilterTestModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->sortable()->searchable(),
            Text::make('status'),
        ];
    }

    public function filters(Request $request): array
    {
        return [
            FilterTestStatusFilter::make('Status'),
            FilterTestActiveFilter::make('Active Users'),
            DateFilter::make('Created At')->column('created_at'),
            DateRangeFilter::make('Date Range')->column('created_at'),
            FilterTestStatusInFilter::make('Status in', 'status-in')->searchable(),
            FilterTestStatusInUnguardedFilter::make('Status in (unguarded)', 'status-in-unguarded'),
        ];
    }
}

class FilterAuthTestResource extends Resource
{
    public static function model(): string
    {
        return FilterTestModel::class;
    }

    public static function uriKey(): string
    {
        return 'filter-auth-test-models';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->sortable()->searchable(),
        ];
    }

    public function filters(Request $request): array
    {
        return [
            FilterTestStatusFilter::make('Status'),
            FilterTestStatusFilter::make('Hidden Filter', 'hidden-filter')
                ->canSee(fn () => false),
        ];
    }
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('martis_test_filter_items');
    Schema::create('martis_test_filter_items', function ($table) {
        $table->id();
        $table->string('title');
        $table->string('status')->default('active');
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(FilterTestResource::class);
    $registry->register(FilterAuthTestResource::class);

    FilterTestModel::create(['title' => 'Active User', 'status' => 'active', 'is_active' => true]);
    FilterTestModel::create(['title' => 'Inactive User', 'status' => 'inactive', 'is_active' => false]);
    FilterTestModel::create(['title' => 'Pending User', 'status' => 'active', 'is_active' => false]);
});

afterEach(function () {
    Schema::dropIfExists('martis_test_filter_items');
});

// ---------------------------------------------------------------------------
// Schema includes filters
// ---------------------------------------------------------------------------

it('schema endpoint returns filter definitions', function () {
    $response = $this->getJson('/martis/api/resources/filter-test-models/schema');

    $response->assertStatus(200);
    $response->assertJsonCount(6, 'data.filters');
    $response->assertJsonPath('data.filters.0.filterType', 'select');
    $response->assertJsonPath('data.filters.0.name', 'Status');
    $response->assertJsonPath('data.filters.0.uriKey', 'status');
    $response->assertJsonPath('data.filters.1.filterType', 'boolean');
    $response->assertJsonPath('data.filters.2.filterType', 'date');
    $response->assertJsonPath('data.filters.3.filterType', 'date-range');
    $response->assertJsonPath('data.filters.4.filterType', 'multi-select');
    $response->assertJsonPath('data.filters.5.filterType', 'multi-select');
});

it('schema includes filter options for select filters', function () {
    $response = $this->getJson('/martis/api/resources/filter-test-models/schema');

    $response->assertStatus(200);
    $options = $response->json('data.filters.0.options');

    expect($options)->toHaveCount(2)
        ->and($options[0])->toBe(['label' => 'Active', 'value' => 'active'])
        ->and($options[1])->toBe(['label' => 'Inactive', 'value' => 'inactive']);
});

// ---------------------------------------------------------------------------
// Index with select filter
// ---------------------------------------------------------------------------

it('select filter applies to index query', function () {
    $filters = json_encode(['status' => 'active']);
    $response = $this->getJson("/martis/api/resources/filter-test-models?filters={$filters}");

    $response->assertStatus(200);
    $response->assertJsonPath('meta.total', 2);

    $titles = collect($response->json('data'))->pluck('title')->all();
    expect($titles)->toContain('Active User')
        ->and($titles)->toContain('Pending User')
        ->and($titles)->not->toContain('Inactive User');
});

it('select filter with inactive value', function () {
    $filters = json_encode(['status' => 'inactive']);
    $response = $this->getJson("/martis/api/resources/filter-test-models?filters={$filters}");

    $response->assertStatus(200);
    $response->assertJsonPath('meta.total', 1);

    $titles = collect($response->json('data'))->pluck('title')->all();
    expect($titles)->toContain('Inactive User');
});

// ---------------------------------------------------------------------------
// Index without filters returns all records
// ---------------------------------------------------------------------------

it('index without filters returns all records', function () {
    $response = $this->getJson('/martis/api/resources/filter-test-models');

    $response->assertStatus(200);
    $response->assertJsonPath('meta.total', 3);
});

// ---------------------------------------------------------------------------
// Multiple filters combine with AND
// ---------------------------------------------------------------------------

it('multiple filters combine with AND logic', function () {
    $filters = json_encode([
        'status' => 'active',
        'active-users' => ['is_active' => true],
    ]);
    $response = $this->getJson("/martis/api/resources/filter-test-models?filters={$filters}");

    $response->assertStatus(200);
    $response->assertJsonPath('meta.total', 1);

    $titles = collect($response->json('data'))->pluck('title')->all();
    expect($titles)->toContain('Active User');
});

// ---------------------------------------------------------------------------
// Filters combine with search
// ---------------------------------------------------------------------------

it('filters combine with search query', function () {
    $filters = json_encode(['status' => 'active']);
    $response = $this->getJson("/martis/api/resources/filter-test-models?filters={$filters}&search=Pending");

    $response->assertStatus(200);
    $response->assertJsonPath('meta.total', 1);

    $titles = collect($response->json('data'))->pluck('title')->all();
    expect($titles)->toContain('Pending User');
});

// ---------------------------------------------------------------------------
// Empty/null filter values are ignored
// ---------------------------------------------------------------------------

it('empty filter values are ignored', function () {
    $filters = json_encode(['status' => '']);
    $response = $this->getJson("/martis/api/resources/filter-test-models?filters={$filters}");

    $response->assertStatus(200);
    $response->assertJsonPath('meta.total', 3);
});

it('null filter values are ignored', function () {
    $filters = json_encode(['status' => null]);
    $response = $this->getJson("/martis/api/resources/filter-test-models?filters={$filters}");

    $response->assertStatus(200);
    $response->assertJsonPath('meta.total', 3);
});

// ---------------------------------------------------------------------------
// Invalid filter JSON is ignored gracefully
// ---------------------------------------------------------------------------

it('invalid filter JSON is ignored gracefully', function () {
    $response = $this->getJson('/martis/api/resources/filter-test-models?filters=invalid_json');

    $response->assertStatus(200);
    $response->assertJsonPath('meta.total', 3);
});

// ---------------------------------------------------------------------------
// Unknown filter keys are ignored
// ---------------------------------------------------------------------------

it('unknown filter keys are ignored', function () {
    $filters = json_encode(['nonexistent-filter' => 'value']);
    $response = $this->getJson("/martis/api/resources/filter-test-models?filters={$filters}");

    $response->assertStatus(200);
    $response->assertJsonPath('meta.total', 3);
});

// ---------------------------------------------------------------------------
// canSee authorization — Martis extension
// ---------------------------------------------------------------------------

it('schema excludes filters hidden by canSee', function () {
    $response = $this->getJson('/martis/api/resources/filter-auth-test-models/schema');

    $response->assertStatus(200);

    $filters = $response->json('data.filters');
    $uriKeys = array_column($filters, 'uriKey');

    expect($uriKeys)->toContain('status')
        ->and($uriKeys)->not->toContain('hidden-filter');
});

it('hidden filter cannot be applied even if sent in query params', function () {
    // The "hidden-filter" is hidden via canSee(fn() => false)
    // Even if a malicious client sends it, it should be ignored
    $filters = json_encode(['hidden-filter' => 'active']);
    $response = $this->getJson("/martis/api/resources/filter-auth-test-models?filters={$filters}");

    $response->assertStatus(200);
    // All 3 records should still be returned — the hidden filter was not applied
    $response->assertJsonPath('meta.total', 3);
});

// ---------------------------------------------------------------------------
// MultiSelectFilter (v1.31.0)
// ---------------------------------------------------------------------------

it('multi-select filter ANY-matches an array of values', function () {
    // Seed: 2 rows status=active, 1 row status=inactive.
    $filters = urlencode(json_encode(['status-in' => ['inactive']]));
    $response = $this->getJson("/martis/api/resources/filter-test-models?filters={$filters}");

    $response->assertStatus(200);
    $response->assertJsonPath('meta.total', 1);
});

it('multi-select filter unions the selected values (each element contributes)', function () {
    // Seed statuses: active(2), inactive(1). Two assertions together prove a
    // genuine OR-union rather than first-element-only truncation or a silent
    // pass-through: active alone → 2 (≠ seed total 3, so it constrains), and
    // adding inactive → 3. The 3-vs-2 delta shows the SECOND value genuinely
    // contributes its own row (true union across two matching statuses).
    $one = urlencode(json_encode(['status-in' => ['active']]));
    $this->getJson("/martis/api/resources/filter-test-models?filters={$one}")
        ->assertStatus(200)
        ->assertJsonPath('meta.total', 2);

    $two = urlencode(json_encode(['status-in' => ['active', 'inactive']]));
    $this->getJson("/martis/api/resources/filter-test-models?filters={$two}")
        ->assertStatus(200)
        ->assertJsonPath('meta.total', 3);
});

it('multi-select filter with an empty array returns all records (guarded fixture)', function () {
    // The `status-in` fixture self-guards with empty($value); this documents
    // the guarded-consumer path. The central-skip guarantee is pinned
    // separately by the unguarded fixture below.
    $filters = urlencode(json_encode(['status-in' => []]));
    $response = $this->getJson("/martis/api/resources/filter-test-models?filters={$filters}");

    $response->assertStatus(200);
    $response->assertJsonPath('meta.total', 3);
});

it('an UNGUARDED multi-select whereIn still constrains on a non-empty selection', function () {
    // Sanity check that the unguarded fixture actually filters, so the
    // empty-array assertion below is meaningful (not always-3).
    $filters = urlencode(json_encode(['status-in-unguarded' => ['inactive']]));
    $response = $this->getJson("/martis/api/resources/filter-test-models?filters={$filters}");

    $response->assertStatus(200);
    $response->assertJsonPath('meta.total', 1);
});

it('the request pipeline skips an empty multi-select array before apply() runs', function () {
    // `status-in-unguarded`'s apply() is whereIn('status', (array) $value) with
    // NO empty($value) guard. If ResourceController::applyFilters did not skip
    // the empty array, apply() would compile whereIn('status', []) => WHERE 0=1
    // => zero rows. Getting all 3 rows back proves the central skip ran; revert
    // the `$value === []` clause and this assertion drops to 0 and fails.
    $filters = urlencode(json_encode(['status-in-unguarded' => []]));
    $response = $this->getJson("/martis/api/resources/filter-test-models?filters={$filters}");

    $response->assertStatus(200);
    $response->assertJsonPath('meta.total', 3);
});

it('schema serialises the multi-select filter type and searchable flag', function () {
    $response = $this->getJson('/martis/api/resources/filter-test-models/schema');

    $response->assertStatus(200);
    $filters = collect($response->json('data.filters'));
    $ms = $filters->firstWhere('uriKey', 'status-in');

    expect($ms)->not->toBeNull();
    expect($ms['filterType'])->toBe('multi-select');
    expect($ms['meta']['searchable'])->toBeTrue();
});
