<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Laravel\Scout\Searchable;
use Martis\Fields\Text;
use Martis\Resource;
use Martis\SearchResolver;
use Martis\Tests\TestCase;

uses(TestCase::class);

/*
 * `Resource::searchQuery()` — the seam that lets a resource replace the default
 * ILIKE match predicate (e.g. PostgreSQL FTS / pg_trgm) without Scout, keeping
 * the rest of the index pipeline. When it returns a Builder, SearchResolver
 * applies none of its own matching (or empty-set guards); null keeps the default.
 */

class SearchSeamModel extends Model
{
    protected $table = 'users';
}

/** Default: no searchQuery override → the base returns null → default ILIKE. */
class SearchSeamDefaultResource extends Resource
{
    public static function model(): string
    {
        return SearchSeamModel::class;
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')->searchable()];
    }
}

/** Owns matching: searchQuery returns a distinctive predicate. */
class SearchSeamOwnedResource extends Resource
{
    public static function model(): string
    {
        return SearchSeamModel::class;
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')->searchable()];
    }

    public function searchQuery(Builder $query, string $term): ?Builder
    {
        return $query->whereRaw('searchquery_marker = ?', [$term]);
    }
}

/** Owns matching AND declares no searchable fields — must bypass the 1=0 guard. */
class SearchSeamOwnedNoFieldsResource extends Resource
{
    public static function model(): string
    {
        return SearchSeamModel::class;
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')]; // not searchable
    }

    public function searchQuery(Builder $query, string $term): ?Builder
    {
        return $query->whereRaw('searchquery_marker = ?', [$term]);
    }
}

/** Contract violation: returns a DIFFERENT builder instead of mutating in place. */
class SearchSeamDifferentBuilderResource extends Resource
{
    public static function model(): string
    {
        return SearchSeamModel::class;
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')->searchable()];
    }

    public function searchQuery(Builder $query, string $term): ?Builder
    {
        // Wrong: a fresh builder drops the indexQuery/filter scoping already on
        // $query and would be ignored by relation-picker pagination.
        return SearchSeamModel::query()->whereRaw('searchquery_marker = ?', [$term]);
    }
}

class SearchSeamScoutModel extends Model
{
    use Searchable;

    protected $table = 'users';

    /** @return array<string, mixed> */
    public function toSearchableArray(): array
    {
        return ['id' => $this->id];
    }
}

/** Scout-enabled AND defines searchQuery — Scout must win; the seam is never consulted. */
class SearchSeamScoutResource extends Resource
{
    public static bool $seamConsulted = false;

    public static function model(): string
    {
        return SearchSeamScoutModel::class;
    }

    public function fields(Request $request): array
    {
        return [];
    }

    public function searchQuery(Builder $query, string $term): ?Builder
    {
        static::$seamConsulted = true;

        return $query->whereRaw('should_not_run = 1');
    }
}

test('default searchQuery is null — SearchResolver keeps the ILIKE pipeline', function () {
    $query = SearchSeamModel::query();
    $result = SearchResolver::apply(Request::create('/'), $query, SearchSeamDefaultResource::class, 'foo');

    $sql = strtolower($result->toSql());
    expect($sql)->toContain('like');
    expect($sql)->not->toContain('searchquery_marker');
});

test('non-null searchQuery owns the WHERE and the default ILIKE is skipped', function () {
    $query = SearchSeamModel::query();
    $result = SearchResolver::apply(Request::create('/'), $query, SearchSeamOwnedResource::class, 'foo');

    $sql = strtolower($result->toSql());
    expect($sql)->toContain('searchquery_marker');
    expect($sql)->not->toContain('like');
});

test('searchQuery bypasses the empty-set guard when the resource has no searchable fields', function () {
    $query = SearchSeamModel::query();
    $result = SearchResolver::apply(Request::create('/'), $query, SearchSeamOwnedNoFieldsResource::class, 'foo');

    $sql = strtolower($result->toSql());
    expect($sql)->toContain('searchquery_marker');
    expect($sql)->not->toContain('1 = 0');
});

test('an empty search term never consults the seam', function () {
    $query = SearchSeamModel::query();
    $result = SearchResolver::apply(Request::create('/'), $query, SearchSeamOwnedResource::class, '');

    expect(strtolower($result->toSql()))->not->toContain('searchquery_marker');
});

test('searchQuery must mutate in place — returning a different builder throws', function () {
    // A fresh builder would silently drop the search on the index page and every
    // relation picker (they paginate the original builder / via the relation),
    // and would drop the indexQuery scoping. The resolver refuses it outright.
    $query = SearchSeamModel::query();

    expect(fn () => SearchResolver::apply(
        Request::create('/'),
        $query,
        SearchSeamDifferentBuilderResource::class,
        'foo',
    ))->toThrow(LogicException::class);
});

test('an in-place searchQuery composes with predicates already on the builder', function () {
    // Simulate indexQuery/filter scoping already applied before SearchResolver.
    $query = SearchSeamModel::query()->whereRaw('index_scope = 1');

    $result = SearchResolver::apply(Request::create('/'), $query, SearchSeamOwnedResource::class, 'foo');

    // Same instance back, and BOTH the upstream scope and the seam predicate
    // survive — the resource narrows, it does not replace the scoped query.
    expect($result)->toBe($query);
    $sql = strtolower($result->toSql());
    expect($sql)->toContain('index_scope')->toContain('searchquery_marker');
});

test('Scout takes precedence — a Scout resource never consults the searchQuery seam', function () {
    // Scout is checked before the seam; a resource that opts into Scout has
    // already chosen its engine. Use the null driver so the Scout path resolves
    // to an empty result set without needing a real search backend.
    config(['scout.driver' => 'null']);
    SearchSeamScoutResource::$seamConsulted = false;

    $query = SearchSeamScoutModel::query();
    SearchResolver::apply(Request::create('/'), $query, SearchSeamScoutResource::class, 'foo');

    expect(SearchSeamScoutResource::$seamConsulted)->toBeFalse();
});
