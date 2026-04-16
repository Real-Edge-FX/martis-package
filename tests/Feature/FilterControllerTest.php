<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\Text;
use Martis\Filters\BooleanFilter;
use Martis\Filters\DateFilter;
use Martis\Filters\DateRangeFilter;
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
    config([
        'database.default' => 'mysql',
        'database.connections.mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3308'),
            'database' => env('DB_DATABASE', 'martis_playground'),
            'username' => env('DB_USERNAME', 'laravel'),
            'password' => env('DB_PASSWORD', 'laravel'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => false,
        ],
    ]);

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
    $response->assertJsonCount(4, 'data.filters');
    $response->assertJsonPath('data.filters.0.filterType', 'select');
    $response->assertJsonPath('data.filters.0.name', 'Status');
    $response->assertJsonPath('data.filters.0.uriKey', 'status');
    $response->assertJsonPath('data.filters.1.filterType', 'boolean');
    $response->assertJsonPath('data.filters.2.filterType', 'date');
    $response->assertJsonPath('data.filters.3.filterType', 'date-range');
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
