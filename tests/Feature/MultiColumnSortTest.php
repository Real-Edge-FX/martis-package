<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Martis\Enums\SortDirection;
use Martis\Fields\HasMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Http\Requests\LensRequest;
use Martis\Lenses\Lens;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Support\ListSort;
use Martis\Support\SortKey;

// ===========================================================================
// Multi-column sorting: one parser and one applier (ListSort) for the index,
// a lens and the relationship panels. Order: the requested keys, then the
// defaultSort() keys not requested, then the primary key.
// ===========================================================================

class MCSGroup extends Model
{
    protected $table = 'mcs_groups';

    protected $guarded = [];

    public $timestamps = false;

    public function items(): EloquentHasMany
    {
        return $this->hasMany(MCSItem::class, 'group_id');
    }
}

class MCSItem extends Model
{
    protected $table = 'mcs_items';

    protected $guarded = [];

    public $timestamps = true;
}

/**
 * The "label" of a list is "<category>/<position>/<locale>/<name>", the
 * order the tests read.
 *
 * @return list<Text>
 */
function mcsFields(): array
{
    return [
        Text::make('name')->sortable(),
        // Ordered by a custom rank (c, a, b), not alphabetically.
        Text::make('category')->sortUsing(fn (Builder $query, SortDirection $direction) => $query->orderByRaw(
            "case category when 'c' then 0 when 'a' then 1 else 2 end ".$direction->value
        )),
        Text::make('position')->sortable(),
        Text::make('locale')->sortable(),
        // A secret the user cannot see, with a sortUsing(): still not sortable.
        Text::make('secret')->sortUsing(fn (Builder $query, SortDirection $direction) => $query->orderBy('secret', $direction->value))->canSee(fn () => false),
        // Not sortable at all.
        Text::make('note'),
    ];
}

class MCSItemResource extends Resource
{
    public static function model(): string
    {
        return MCSItem::class;
    }

    public static function uriKey(): string
    {
        return 'mcs-items';
    }

    public static function defaultSort(): string|array|null
    {
        return ['category' => SortDirection::Asc, 'position' => 'asc', 'locale' => SortDirection::Asc];
    }

    public function fields(Request $request): array
    {
        return mcsFields();
    }

    public function lenses(Request $request): array
    {
        return [new MCSItemsLens];
    }
}

class MCSPlainItemResource extends MCSItemResource
{
    public static function uriKey(): string
    {
        return 'mcs-plain-items';
    }

    public static function defaultSort(): string|array|null
    {
        return null;
    }
}

class MCSStringDefaultItemResource extends MCSItemResource
{
    public static function uriKey(): string
    {
        return 'mcs-string-default-items';
    }

    public static function defaultSort(): string|array|null
    {
        return 'name';
    }

    public static function defaultSortDirection(): SortDirection
    {
        return SortDirection::Desc;
    }
}

class MCSBadDirectionItemResource extends MCSItemResource
{
    public static function uriKey(): string
    {
        return 'mcs-bad-direction-items';
    }

    public static function defaultSort(): string|array|null
    {
        return ['category' => 'asc', 'position' => 'up'];
    }
}

class MCSItemsLens extends Lens
{
    public function query(LensRequest $request, Builder $query): Builder
    {
        return $request->withOrdering($request->withFilters($query), fn (Builder $q): Builder => $q->orderBy('name'));
    }

    public function fields(Request $request): array
    {
        return mcsFields();
    }
}

class MCSGroupResource extends Resource
{
    public static function model(): string
    {
        return MCSGroup::class;
    }

    public static function uriKey(): string
    {
        return 'mcs-groups';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            HasMany::make('Items', 'items')->relatedResource('mcs-items'),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    foreach (['mcs_items', 'mcs_groups'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('mcs_groups', function ($table) {
        $table->id();
        $table->string('name');
    });
    Schema::create('mcs_items', function ($table) {
        $table->id();
        $table->unsignedBigInteger('group_id')->nullable();
        $table->string('name');
        $table->string('category');
        $table->integer('position');
        $table->string('locale');
        $table->string('secret')->default('s');
        $table->string('note')->nullable();
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    foreach ([MCSItemResource::class, MCSPlainItemResource::class, MCSStringDefaultItemResource::class, MCSBadDirectionItemResource::class, MCSGroupResource::class] as $resource) {
        $registry->register($resource);
    }

    $this->group = MCSGroup::create(['name' => 'G']);

    // id, name, category, position, locale. Ids follow this order.
    foreach ([
        ['n1', 'a', 2, 'pt'],
        ['n2', 'a', 1, 'pt'],
        ['n3', 'a', 1, 'en'],
        ['n4', 'b', 1, 'pt'],
        ['n5', 'c', 3, 'en'],
        ['n6', 'c', 3, 'en'],
    ] as [$name, $category, $position, $locale]) {
        MCSItem::create([
            'group_id' => $this->group->id,
            'name' => $name,
            'category' => $category,
            'position' => $position,
            'locale' => $locale,
        ]);
    }
});

afterEach(function () {
    foreach (['mcs_items', 'mcs_groups'] as $table) {
        Schema::dropIfExists($table);
    }
});

/** @return list<string> */
function mcsNames(TestResponse $response): array
{
    return array_column($response->assertOk()->json('data'), 'name');
}

$lists = [
    'resource index' => '/martis/api/resources/mcs-items',
    'HasMany panel' => '/martis/api/resources/mcs-groups/{group}/has-many/items',
    'lens' => '/martis/api/resources/mcs-items/lenses/m-c-s-items',
];

// ---------------------------------------------------------------------------
// Parser
// ---------------------------------------------------------------------------

it('parses a list of keys with directions', function () {
    $keys = ListSort::parse('category:asc,position:desc,locale:ASC');

    expect(array_map(fn (SortKey $k) => $k->attribute.':'.$k->direction->value, $keys))
        ->toBe(['category:asc', 'position:desc', 'locale:asc']);
});

it('reads a single key without a suffix with the direction parameter', function () {
    expect(ListSort::parse('title', 'desc')[0]->direction)->toBe(SortDirection::Desc)
        ->and(ListSort::parse('title', 'nonsense')[0]->direction)->toBe(SortDirection::Asc)
        ->and(ListSort::parse('title', ['desc'])[0]->direction)->toBe(SortDirection::Asc)
        ->and(ListSort::parse('title:asc', 'desc')[0]->direction)->toBe(SortDirection::Asc);
});

it('takes a missing direction as asc in a list', function () {
    $keys = ListSort::parse('a,b:desc', 'desc');

    expect($keys[0]->direction)->toBe(SortDirection::Asc)
        ->and($keys[1]->direction)->toBe(SortDirection::Desc);
});

it('caps the list at three keys and keeps the first of a duplicate', function () {
    $keys = ListSort::parse('a:desc,b,a:asc,c,d,e');

    expect(array_map(fn (SortKey $k) => $k->attribute.':'.$k->direction->value, $keys))->toBe(['a:desc', 'b:asc', 'c:asc']);
});

it('drops a key with an unknown direction suffix, an empty key and a non-string sort', function () {
    expect(ListSort::parse('a:up,,b'))->toHaveCount(1)
        ->and(ListSort::parse(''))->toBe([])
        ->and(ListSort::parse(['a']))->toBe([])
        ->and(ListSort::parse(null))->toBe([])
        ->and(ListSort::parse('meta->code:desc')[0]->attribute)->toBe('meta->code');
});

// ---------------------------------------------------------------------------
// defaultSort()
// ---------------------------------------------------------------------------

it('reads a default sort given as a string or as an ordered array', function () {
    $string = ListSort::defaults(MCSStringDefaultItemResource::class);
    $array = ListSort::defaults(MCSItemResource::class);

    expect($string)->toHaveCount(1)
        ->and($string[0]->attribute)->toBe('name')
        ->and($string[0]->direction)->toBe(SortDirection::Desc)
        ->and(array_map(fn (SortKey $k) => $k->attribute.':'.$k->direction->value, $array))->toBe(['category:asc', 'position:asc', 'locale:asc'])
        ->and(ListSort::defaults(MCSPlainItemResource::class))->toBe([]);
});

it('throws, naming the resource and the key, for a default sort direction that is not one', function () {
    ListSort::defaults(MCSBadDirectionItemResource::class);
})->throws(InvalidArgumentException::class, MCSBadDirectionItemResource::class.'::defaultSort() has an invalid direction for key [position]');

it('serializes the default sort as a list of attribute and direction, the keys the user can sort by', function () {
    $schema = $this->getJson('/martis/api/resources/mcs-items/schema')->assertOk()->json();
    $data = $schema['data'] ?? $schema;

    expect($data['defaultSort'])->toBe([
        ['attribute' => 'category', 'direction' => 'asc'],
        ['attribute' => 'position', 'direction' => 'asc'],
        ['attribute' => 'locale', 'direction' => 'asc'],
    ])->and($data)->not->toHaveKey('defaultSortDirection');

    $plain = $this->getJson('/martis/api/resources/mcs-plain-items/schema')->assertOk()->json();
    expect(($plain['data'] ?? $plain)['defaultSort'])->toBe([]);
});

// ---------------------------------------------------------------------------
// Order
// ---------------------------------------------------------------------------

it('orders the index by the default keys, then the primary key, when nothing is requested', function () {
    // category rank c(0) < a(1) < b(2), then position, locale, id.
    expect(mcsNames($this->getJson('/martis/api/resources/mcs-items')))->toBe(['n5', 'n6', 'n3', 'n2', 'n1', 'n4']);
});

it('orders by the requested keys, then the default keys not requested, then the key', function () {
    // locale first, then the default (category rank, position), then id.
    expect(mcsNames($this->getJson('/martis/api/resources/mcs-items?sort=locale')))->toBe(['n5', 'n6', 'n3', 'n2', 'n1', 'n4'])
        ->and(mcsNames($this->getJson('/martis/api/resources/mcs-items?sort=locale:desc')))->toBe(['n2', 'n1', 'n4', 'n5', 'n6', 'n3'])
        ->and(mcsNames($this->getJson('/martis/api/resources/mcs-items?sort=position:desc,name:desc')))->toBe(['n6', 'n5', 'n1', 'n4', 'n3', 'n2']);
});

it('breaks ties by the primary key ascending', function () {
    expect(mcsNames($this->getJson('/martis/api/resources/mcs-plain-items?sort=locale')))->toBe(['n3', 'n5', 'n6', 'n1', 'n2', 'n4'])
        ->and(mcsNames($this->getJson('/martis/api/resources/mcs-plain-items?sort=locale:desc')))->toBe(['n1', 'n2', 'n4', 'n3', 'n5', 'n6']);
});

it('keeps a single key with the direction parameter working', function () {
    expect(mcsNames($this->getJson('/martis/api/resources/mcs-plain-items?sort=name&direction=desc')))->toBe(['n6', 'n5', 'n4', 'n3', 'n2', 'n1']);
});

it('applies at most three keys and drops duplicates and invalid keys while the others apply', function () {
    // secret (hidden), note (not sortable) and nope (unknown) are dropped; the cap counts keys as sent.
    expect(mcsNames($this->getJson('/martis/api/resources/mcs-plain-items?sort=locale:desc,nope,secret')))->toBe(['n1', 'n2', 'n4', 'n3', 'n5', 'n6'])
        ->and(mcsNames($this->getJson('/martis/api/resources/mcs-plain-items?sort=note,position:desc,locale:asc')))->toBe(['n5', 'n6', 'n1', 'n3', 'n2', 'n4'])
        ->and(mcsNames($this->getJson('/martis/api/resources/mcs-plain-items?sort=name:desc,name:asc,id')))->toBe(['n6', 'n5', 'n4', 'n3', 'n2', 'n1']);
});

it('orders every list the same way', function (string $uri) {
    $uri = str_replace('{group}', (string) $this->group->id, $uri);

    expect(mcsNames($this->getJson("{$uri}?sort=locale:asc,position:desc,name:desc")))->toBe(['n6', 'n5', 'n3', 'n1', 'n4', 'n2']);
})->with($lists);

// ---------------------------------------------------------------------------
// sortUsing()
// ---------------------------------------------------------------------------

it('implies sortable and flags the field in the payload', function () {
    $field = Text::make('category')->sortUsing(fn () => null);

    expect($field->isSortable())->toBeTrue()
        ->and($field->sortCallback())->toBeInstanceOf(Closure::class)
        ->and($field->toArray()['sortable'])->toBeTrue();
});

it('orders through sortUsing() on the index, a lens and a HasMany panel', function (string $uri) {
    $uri = str_replace('{group}', (string) $this->group->id, $uri);

    // c(0) < a(1) < b(2) ascending, and the reverse descending.
    $categories = fn (TestResponse $response): array => array_column($response->assertOk()->json('data'), 'category');

    expect($categories($this->getJson("{$uri}?sort=category:asc")))->toBe(['c', 'c', 'a', 'a', 'a', 'b'])
        ->and($categories($this->getJson("{$uri}?sort=category:desc")))->toBe(['b', 'a', 'a', 'a', 'c', 'c']);
})->with($lists);

it('does not sort by a hidden field that has a sortUsing() closure', function (string $uri) {
    $uri = str_replace('{group}', (string) $this->group->id, $uri);
    $default = mcsNames($this->getJson($uri));

    expect(mcsNames($this->getJson("{$uri}?sort=secret:desc")))->toBe($default);
})->with($lists);
