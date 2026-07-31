<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
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
