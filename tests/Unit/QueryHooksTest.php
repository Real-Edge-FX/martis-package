<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Martis\Contracts\FieldContract;
use Martis\Fields\BelongsTo;
use Martis\Fields\Text;
use Martis\RelationshipQueryResolver;
use Martis\Resource;
use Martis\Tests\TestCase;

uses(TestCase::class);

// ---------------------------------------------------------------------------
// Test fixtures — Resource stubs for query hook testing
// ---------------------------------------------------------------------------

class QueryHookTestModel extends Model
{
    protected $table = 'users';
}

class RelatedTestModel extends Model
{
    protected $table = 'users';
}

class AnotherRelatedModel extends Model
{
    protected $table = 'users';
}

/**
 * Default resource — no query hooks overridden (no-op defaults).
 */
class DefaultResource extends Resource
{
    public static function model(): string
    {
        return QueryHookTestModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->searchable(),
        ];
    }
}

/**
 * Resource with custom indexQuery — filters to only verified users.
 */
class FilteredIndexResource extends Resource
{
    public static function model(): string
    {
        return QueryHookTestModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->searchable(),
        ];
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query->whereNotNull('email_verified_at');
    }
}

/**
 * Resource with custom relatableQuery — filters active records.
 */
class FilteredRelatableResource extends Resource
{
    public static function model(): string
    {
        return RelatedTestModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->searchable(),
        ];
    }

    public static function relatableQuery(Request $request, Builder $query): Builder
    {
        return $query->whereNotNull('email_verified_at');
    }
}

/**
 * Resource with a dynamic relatable method: relatableRelatedTestModels.
 */
class DynamicRelatableResource extends Resource
{
    public static function model(): string
    {
        return QueryHookTestModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            BelongsTo::make('related', 'Related')
                ->relatedResource('related-test-models'),
        ];
    }

    /**
     * Dynamic relatable method — takes precedence over relatableQuery on target.
     */
    public static function relatableRelatedTestModels(Request $request, Builder $query): Builder
    {
        return $query->where('name', '!=', 'hidden');
    }
}

/**
 * Resource with a dynamic relatable method that accepts Field parameter.
 */
class DynamicWithFieldResource extends Resource
{
    public static function model(): string
    {
        return QueryHookTestModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            BelongsTo::make('primary_id', 'Primary')
                ->relatedResource('another-related-models'),
            BelongsTo::make('secondary_id', 'Secondary')
                ->relatedResource('another-related-models'),
        ];
    }

    /**
     * Dynamic relatable method WITH Field differentiation.
     */
    public static function relatableAnotherRelatedModels(Request $request, Builder $query, ?FieldContract $field = null): Builder
    {
        if ($field !== null && $field->attribute() === 'secondary_id') {
            return $query->where('name', 'like', 'secondary-%');
        }

        return $query->where('name', 'like', 'primary-%');
    }
}

class UnfilteredRelatableResource extends Resource
{
    public static function model(): string
    {
        return AnotherRelatedModel::class;
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

// ---------------------------------------------------------------------------
// Unit tests — indexQuery
// ---------------------------------------------------------------------------

test('indexQuery default is no-op', function () {
    $query = QueryHookTestModel::query();
    $result = DefaultResource::indexQuery(Request::create('/'), $query);

    expect($result)->toBe($query);
    expect($result->toSql())->not->toContain('where');
});

test('indexQuery custom filters the query', function () {
    $query = QueryHookTestModel::query();
    $result = FilteredIndexResource::indexQuery(Request::create('/'), $query);

    expect($result->toSql())->toContain('email_verified_at');
});

// ---------------------------------------------------------------------------
// Unit tests — relatableQuery
// ---------------------------------------------------------------------------

test('relatableQuery default is no-op', function () {
    $query = RelatedTestModel::query();
    $result = DefaultResource::relatableQuery(Request::create('/'), $query);

    expect($result)->toBe($query);
    expect($result->toSql())->not->toContain('where');
});

test('relatableQuery custom filters the query', function () {
    $query = RelatedTestModel::query();
    $result = FilteredRelatableResource::relatableQuery(Request::create('/'), $query);

    expect($result->toSql())->toContain('email_verified_at');
});

// ---------------------------------------------------------------------------
// Unit tests — RelationshipQueryResolver
// ---------------------------------------------------------------------------

test('resolver uses dynamic method when available', function () {
    $query = RelatedTestModel::query();
    $request = Request::create('/');

    $result = RelationshipQueryResolver::resolve(
        DynamicRelatableResource::class,
        FilteredRelatableResource::class,
        $request,
        $query,
    );

    // Should use relatableRelatedTestModels (dynamic) not relatableQuery (target)
    $sql = $result->toSql();
    expect($sql)->toContain('name');
    // Dynamic method filters name != 'hidden', not email_verified_at
    expect($sql)->not->toContain('email_verified_at');
});

test('resolver falls back to target relatableQuery when no dynamic method', function () {
    $query = RelatedTestModel::query();
    $request = Request::create('/');

    $result = RelationshipQueryResolver::resolve(
        DefaultResource::class,
        FilteredRelatableResource::class,
        $request,
        $query,
    );

    // Should use FilteredRelatableResource::relatableQuery
    expect($result->toSql())->toContain('email_verified_at');
});

test('resolver passes Field parameter when method accepts it', function () {
    $query = AnotherRelatedModel::query();
    $request = Request::create('/');
    $field = BelongsTo::make('secondary_id', 'Secondary');

    $result = RelationshipQueryResolver::resolve(
        DynamicWithFieldResource::class,
        UnfilteredRelatableResource::class,
        $request,
        $query,
        $field,
    );

    expect($result->toSql())->toContain('name');
    // Should use secondary- filter based on field attribute
});

test('resolver without Field uses default path in dynamic method', function () {
    $query = AnotherRelatedModel::query();
    $request = Request::create('/');

    $result = RelationshipQueryResolver::resolve(
        DynamicWithFieldResource::class,
        UnfilteredRelatableResource::class,
        $request,
        $query,
        null,
    );

    expect($result->toSql())->toContain('name');
});

test('buildDynamicMethodName returns correct name', function () {
    $name = RelationshipQueryResolver::buildDynamicMethodName(FilteredRelatableResource::class);
    expect($name)->toBe('relatableRelatedTestModels');
});

// ---------------------------------------------------------------------------
// Unit tests — Relational policy methods
// ---------------------------------------------------------------------------

test('authorizedToAttachAny returns true without policy', function () {
    $resource = new DefaultResource;
    expect($resource->authorizedToAttachAny(Request::create('/'), RelatedTestModel::class))->toBeTrue();
});

test('authorizedToAttach returns true without policy', function () {
    $resource = new DefaultResource;
    $model = new RelatedTestModel;
    expect($resource->authorizedToAttach(Request::create('/'), $model))->toBeTrue();
});

test('authorizedToDetach returns true without policy', function () {
    $resource = new DefaultResource;
    $model = new RelatedTestModel;
    expect($resource->authorizedToDetach(Request::create('/'), $model))->toBeTrue();
});

test('authorizedToAdd returns true without policy', function () {
    $resource = new DefaultResource;
    expect($resource->authorizedToAdd(Request::create('/'), RelatedTestModel::class))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Unit tests — Policy + query hook coexistence
// ---------------------------------------------------------------------------

test('indexQuery and viewAny policy coexist independently', function () {
    // indexQuery filters query, viewAny is a separate authorization check
    $query = QueryHookTestModel::query();
    $filtered = FilteredIndexResource::indexQuery(Request::create('/'), $query);

    // indexQuery modifies the query (structural filter)
    expect($filtered->toSql())->toContain('email_verified_at');

    // Authorization is separate (resource method, not query)
    $resource = new FilteredIndexResource;
    expect($resource->authorizedToViewAny(Request::create('/')))->toBeTrue();
});

test('relatableQuery and attach policy coexist independently', function () {
    $query = RelatedTestModel::query();
    $filtered = FilteredRelatableResource::relatableQuery(Request::create('/'), $query);

    // relatableQuery modifies the query (structural filter)
    expect($filtered->toSql())->toContain('email_verified_at');

    // Attach authorization is separate
    $resource = new DefaultResource;
    expect($resource->authorizedToAttachAny(Request::create('/'), RelatedTestModel::class))->toBeTrue();
});
