<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Martis\Contracts\FieldContract;
use Martis\Resource;

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------

class SearchIndexUrlTestModel extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

// Routable, no override — the backward-compat anchor.
class ResourceWithDefaultSearchIndex extends Resource
{
    public static function model(): string
    {
        return SearchIndexUrlTestModel::class;
    }

    /** @return list<FieldContract> */
    public function fields(Request $request): array
    {
        return [];
    }
}

// Non-routable, no override — no listing destination exists.
class NonRoutableWithDefaultSearchIndex extends Resource
{
    public static function model(): string
    {
        return SearchIndexUrlTestModel::class;
    }

    public static function routable(): bool
    {
        return false;
    }

    /** @return list<FieldContract> */
    public function fields(Request $request): array
    {
        return [];
    }
}

// Non-routable, declares a searchIndexUrl() with a {search} placeholder.
class NonRoutableWithSearchIndexTemplate extends Resource
{
    public static function model(): string
    {
        return SearchIndexUrlTestModel::class;
    }

    public static function routable(): bool
    {
        return false;
    }

    public static function searchIndexUrl(): ?string
    {
        return '/tools/normas?search={search}';
    }

    /** @return list<FieldContract> */
    public function fields(Request $request): array
    {
        return [];
    }
}

// Non-routable, declares a fixed searchIndexUrl() with no placeholder.
class NonRoutableWithFixedSearchIndex extends Resource
{
    public static function model(): string
    {
        return SearchIndexUrlTestModel::class;
    }

    public static function routable(): bool
    {
        return false;
    }

    public static function searchIndexUrl(): ?string
    {
        return '/tools/normas';
    }

    /** @return list<FieldContract> */
    public function fields(Request $request): array
    {
        return [];
    }
}

// ---------------------------------------------------------------------------
// searchIndexUrl() / searchIndexHref()
// ---------------------------------------------------------------------------

it('searchIndexUrl returns null by default', function () {
    expect(ResourceWithDefaultSearchIndex::searchIndexUrl())->toBeNull();
});

it('searchIndexHref falls back to the default /resources search page for a routable resource', function () {
    expect(ResourceWithDefaultSearchIndex::searchIndexHref('a b'))
        ->toBe('/resources/'.ResourceWithDefaultSearchIndex::uriKey().'?search=a%20b');
});

it('searchIndexHref returns null for a non-routable resource with no template', function () {
    expect(NonRoutableWithDefaultSearchIndex::searchIndexHref('anything'))->toBeNull();
});

it('searchIndexHref interpolates the {search} placeholder, URL-encoding the term', function () {
    expect(NonRoutableWithSearchIndexTemplate::searchIndexHref('a b'))
        ->toBe('/tools/normas?search=a%20b');
});

it('searchIndexHref returns a fixed template unchanged when it has no {search} placeholder', function () {
    expect(NonRoutableWithFixedSearchIndex::searchIndexHref('ignored'))
        ->toBe('/tools/normas');
});
