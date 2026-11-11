<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Http\Request;
use Martis\Fields\Text;
use Martis\Resource;
use Martis\SearchResolver;

/*
 * Resource::searchMode(): `contains` (default, the SQL of every earlier
 * release), `prefix` and `columns`. Asserted on the generated SQL.
 */

class SmOrder extends Model
{
    protected $table = 'sm_orders';

    protected $guarded = [];

    public $timestamps = false;

    public function lines(): EloquentHasMany
    {
        return $this->hasMany(SmLine::class, 'order_id');
    }
}

class SmLine extends Model
{
    protected $table = 'sm_lines';

    protected $guarded = [];

    public $timestamps = false;
}

class SmOrderResource extends Resource
{
    public static function model(): string
    {
        return SmOrder::class;
    }

    public static function uriKey(): string
    {
        return 'sm-orders';
    }

    public static function searchableRelations(): array
    {
        return ['lines.label'];
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')->searchable(), Text::make('code')->searchable()];
    }
}

class SmPrefixOrderResource extends SmOrderResource
{
    public static ?string $searchMode = 'prefix';
}

class SmColumnsOrderResource extends SmOrderResource
{
    public static ?string $searchMode = 'columns';
}

class SmBadOrderResource extends SmOrderResource
{
    public static ?string $searchMode = 'fulltext';
}

/** @return array{0: string, 1: list<mixed>} */
function smSql(string $resource, string $term): array
{
    $query = SearchResolver::apply(Request::create('/'), SmOrder::query(), $resource, $term);

    return [$query->toSql(), $query->getBindings()];
}

afterEach(fn () => config()->set('martis.search.match_mode', 'contains'));

it('keeps the contains SQL of earlier releases by default', function () {
    [$sql, $bindings] = smSql(SmOrderResource::class, 'acme');

    expect($sql)->toBe('select * from "sm_orders" where ("name" like ? or "code" like ? or exists (select * from "sm_lines" where "sm_orders"."id" = "sm_lines"."order_id" and "label" like ?))')
        ->and($bindings)->toBe(['%acme%', '%acme%', '%acme%']);
});

it('matches a term from the start in prefix mode, relations included', function () {
    [$sql, $bindings] = smSql(SmPrefixOrderResource::class, 'acme');

    expect($sql)->toBe('select * from "sm_orders" where ("name" like ? or "code" like ? or exists (select * from "sm_lines" where "sm_orders"."id" = "sm_lines"."order_id" and "label" like ?))')
        ->and($bindings)->toBe(['acme%', 'acme%', 'acme%']);
});

it('applies a prefix to a field:value token in prefix mode', function () {
    [, $bindings] = smSql(SmPrefixOrderResource::class, 'name:acme');

    expect($bindings)->toBe(['acme%']);
});

it('searches only the tokens in columns mode, dropping the free text', function () {
    [$sql, $bindings] = smSql(SmColumnsOrderResource::class, 'name:acme beta');

    expect($sql)->toBe('select * from "sm_orders" where ("name" like ?)')
        ->and($bindings)->toBe(['%acme%']);
});

it('falls back to contains in columns mode when no token applies', function () {
    [, $bindings] = smSql(SmColumnsOrderResource::class, 'acme');
    expect($bindings)->toBe(['%acme%', '%acme%', '%acme%']);

    [, $typo] = smSql(SmColumnsOrderResource::class, 'nope:x acme');
    expect($typo)->toBe(['%acme%', '%acme%', '%acme%']);
});

it('reads the mode from martis.search.match_mode when the resource sets none', function () {
    config()->set('martis.search.match_mode', 'prefix');

    [, $bindings] = smSql(SmOrderResource::class, 'acme');

    expect($bindings)->toBe(['acme%', 'acme%', 'acme%']);
});

it('throws naming the resource and the value for an unknown mode', function () {
    smSql(SmBadOrderResource::class, 'acme');
})->throws(InvalidArgumentException::class, 'SmBadOrderResource: unknown search mode "fulltext"');

it('throws for an unknown mode set in the config', function () {
    config()->set('martis.search.match_mode', 'nope');

    smSql(SmOrderResource::class, 'acme');
})->throws(InvalidArgumentException::class, 'unknown search mode "nope"');

it('lists the own and relation columns of a resource from the search source', function () {
    $targets = SearchResolver::searchTargets(Request::create('/'), SmOrderResource::class);

    expect($targets)->toBe([
        'own' => ['name', 'code'],
        'relations' => [['relation' => 'lines', 'attribute' => 'label']],
    ]);
});
