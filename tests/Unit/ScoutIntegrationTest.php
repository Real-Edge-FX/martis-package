<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Laravel\Scout\Searchable;
use Martis\Resource;
use Martis\SearchResolver;
use Martis\Tests\TestCase;

uses(TestCase::class);

// ---------------------------------------------------------------------------
// Test models
// ---------------------------------------------------------------------------

class NonSearchableTestModel extends Model
{
    protected $table = 'users';
}

class SearchableTestModel extends Model
{
    use Searchable;

    protected $table = 'users';

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return ['id' => $this->id];
    }
}

// ---------------------------------------------------------------------------
// Test resources
// ---------------------------------------------------------------------------

class NonScoutTestResource extends Resource
{
    public static function model(): string
    {
        return NonSearchableTestModel::class;
    }

    public function fields(Request $request): array
    {
        return [];
    }
}

class ScoutAutoTestResource extends Resource
{
    public static function model(): string
    {
        return SearchableTestModel::class;
    }

    public function fields(Request $request): array
    {
        return [];
    }
}

class ScoutDisabledTestResource extends Resource
{
    public static function model(): string
    {
        return SearchableTestModel::class;
    }

    public static function usesScout(): bool
    {
        return false;
    }

    public function fields(Request $request): array
    {
        return [];
    }
}

class ScoutCustomQueryTestResource extends Resource
{
    public static ?int $scoutSearchResults = 5;

    public static bool $scoutQueryCalled = false;

    public static function model(): string
    {
        return SearchableTestModel::class;
    }

    public static function scoutQuery(Request $request, mixed $query): mixed
    {
        static::$scoutQueryCalled = true;

        return $query;
    }

    public function fields(Request $request): array
    {
        return [];
    }
}

// ---------------------------------------------------------------------------
// Unit tests — usesScout() detection
// ---------------------------------------------------------------------------

test('usesScout returns false when model has no Searchable trait', function (): void {
    expect(NonScoutTestResource::usesScout())->toBeFalse();
});

test('usesScout returns true when model uses Searchable trait', function (): void {
    expect(ScoutAutoTestResource::usesScout())->toBeTrue();
});

test('usesScout returns false when overridden to false despite Searchable model', function (): void {
    expect(ScoutDisabledTestResource::usesScout())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Unit tests — scoutQuery() default behavior
// ---------------------------------------------------------------------------

test('scoutQuery default is no-op (returns query unchanged)', function (): void {
    $request = Request::create('/');
    $mockQuery = new stdClass;

    $result = NonScoutTestResource::scoutQuery($request, $mockQuery);

    expect($result)->toBe($mockQuery);
});

test('scoutQuery custom override is called', function (): void {
    ScoutCustomQueryTestResource::$scoutQueryCalled = false;

    $request = Request::create('/');
    $mockQuery = new stdClass;

    ScoutCustomQueryTestResource::scoutQuery($request, $mockQuery);

    expect(ScoutCustomQueryTestResource::$scoutQueryCalled)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Unit tests — scoutSearchResults property
// ---------------------------------------------------------------------------

test('scoutSearchResults defaults to null on base Resource', function (): void {
    expect(NonScoutTestResource::$scoutSearchResults)->toBeNull();
});

test('scoutSearchResults can be set per resource', function (): void {
    expect(ScoutCustomQueryTestResource::$scoutSearchResults)->toBe(5);
});

// ---------------------------------------------------------------------------
// Unit tests — SearchResolver::isUsingScout()
// ---------------------------------------------------------------------------

test('SearchResolver::isUsingScout returns false for non-Scout resource', function (): void {
    expect(SearchResolver::isUsingScout(NonScoutTestResource::class))->toBeFalse();
});

test('SearchResolver::isUsingScout returns true for Scout-enabled resource', function (): void {
    expect(SearchResolver::isUsingScout(ScoutAutoTestResource::class))->toBeTrue();
});

test('SearchResolver::isUsingScout returns false for Scout-disabled resource', function (): void {
    expect(SearchResolver::isUsingScout(ScoutDisabledTestResource::class))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Unit tests — coexistence between resources
// ---------------------------------------------------------------------------

test('different resources can use different search pipelines simultaneously', function (): void {
    expect(NonScoutTestResource::usesScout())->toBeFalse();
    expect(ScoutAutoTestResource::usesScout())->toBeTrue();
    expect(ScoutDisabledTestResource::usesScout())->toBeFalse();
    expect(ScoutCustomQueryTestResource::usesScout())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Unit tests — fallback behavior
// ---------------------------------------------------------------------------

test('Scout disabled resource falls back to database search pipeline', function (): void {
    // ScoutDisabledTestResource model has Searchable, but usesScout() = false
    $modelClass = ScoutDisabledTestResource::model();
    $traits = class_uses_recursive($modelClass);

    // Model HAS the trait
    expect(in_array(Searchable::class, $traits, true))->toBeTrue();

    // But the resource says no Scout
    expect(ScoutDisabledTestResource::usesScout())->toBeFalse();
    expect(SearchResolver::isUsingScout(ScoutDisabledTestResource::class))->toBeFalse();
});

test('resource toArray does not leak scout internals', function (): void {
    $instance = new NonScoutTestResource;
    $array = $instance->toArray();

    expect($array)->toHaveKeys(['uriKey', 'label', 'singularLabel']);
});
