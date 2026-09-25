<?php

/*
 * A related resource's `scopes()` / `indexQuery()` hooks are written for its
 * own index query, and relationship panels, their counts and the one-record
 * cards reuse them (see Martis\Support\RelationScope). Hooks shaped as real
 * apps write them must keep working there: an `orWhere()` (nova-issues
 * #3655), a `select()`, a join to the parent, the pivot (nova-issues #337)
 * or the intermediate table, an unqualified column the joined table also
 * has, and one that returns its own query. Born from the review of #256.
 *
 * MARTIS_TEST_DB=mysql|pgsql runs the file against MySQL 8 on 127.0.0.1:33306
 * or PostgreSQL on 127.0.0.1:55432 (database `probe`, password `root`).
 */

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EBelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany as EHasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough as EHasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne as EHasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Martis\Fields\BelongsTo;
use Martis\Fields\BelongsToMany;
use Martis\Fields\HasMany;
use Martis\Fields\HasManyThrough;
use Martis\Fields\HasOne;
use Martis\Fields\Number;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

class RSHOwner extends Model
{
    protected $table = 'rsh_owners';

    protected $guarded = [];

    public function items(): EHasMany
    {
        return $this->hasMany(RSHItem::class, 'owner_id');
    }

    public function groupItems(): EHasManyThrough
    {
        return $this->hasManyThrough(RSHItem::class, RSHGroup::class, 'owner_id', 'group_id');
    }

    public function tags(): EBelongsToMany
    {
        return $this->belongsToMany(RSHTag::class, 'rsh_owner_tag', 'owner_id', 'tag_id')->withPivot('note')->withTimestamps();
    }

    public function profile(): EHasOne
    {
        return $this->hasOne(RSHProfile::class, 'owner_id');
    }

    public function category()
    {
        return $this->belongsTo(RSHCategory::class, 'category_id');
    }
}

class RSHGroup extends Model
{
    protected $table = 'rsh_groups';

    protected $guarded = [];
}

class RSHItem extends Model
{
    use SoftDeletes;

    protected $table = 'rsh_items';

    protected $guarded = [];

    protected $casts = ['meta' => 'array'];

    public function likes(): EHasMany
    {
        return $this->hasMany(RSHLike::class, 'item_id');
    }
}

class RSHTag extends Model
{
    use SoftDeletes;

    protected $table = 'rsh_tags';

    protected $guarded = [];

    public function owners(): EBelongsToMany
    {
        return $this->belongsToMany(RSHOwner::class, 'rsh_owner_tag', 'tag_id', 'owner_id');
    }

    public function likes(): EHasMany
    {
        return $this->hasMany(RSHLike::class, 'tag_id');
    }
}

class RSHLike extends Model
{
    protected $table = 'rsh_likes';

    protected $guarded = [];
}

class RSHProfile extends Model
{
    protected $table = 'rsh_profiles';

    protected $guarded = [];
}

class RSHCategory extends Model
{
    protected $table = 'rsh_categories';

    protected $guarded = [];
}

class RSHItemResource extends Resource
{
    public static ?Closure $hook = null;

    public static array $scopeHooks = [];

    public static ?Closure $view = null;

    public static function model(): string
    {
        return RSHItem::class;
    }

    public static function uriKey(): string
    {
        return 'rsh-items';
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return static::$hook ? (static::$hook)($query, $request) : $query;
    }

    public static function scopes(Request $request): array
    {
        return static::$scopeHooks;
    }

    public function authorizedToView(Request $request): bool
    {
        return static::$view ? (static::$view)($this->model) : true;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->searchable()->sortable(),
            Text::make('meta->prio', 'Prio')->sortable(),
            HasMany::make('Likes', 'likes')->relatedResource('rsh-likes')->showOnIndex(),
        ];
    }
}

class RSHTagResource extends Resource
{
    public static ?Closure $hook = null;

    public static array $scopeHooks = [];

    public static function model(): string
    {
        return RSHTag::class;
    }

    public static function uriKey(): string
    {
        return 'rsh-tags';
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return static::$hook ? (static::$hook)($query, $request) : $query;
    }

    public static function scopes(Request $request): array
    {
        return static::$scopeHooks;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->searchable()->sortable(),
            Number::make('owners_count', 'Owners')->sortable(),
            HasMany::make('Likes', 'likes')->relatedResource('rsh-likes')->showOnIndex(),
        ];
    }
}

class RSHLikeResource extends Resource
{
    public static ?Closure $hook = null;

    public static function model(): string
    {
        return RSHLike::class;
    }

    public static function uriKey(): string
    {
        return 'rsh-likes';
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return static::$hook ? (static::$hook)($query, $request) : $query;
    }

    public function fields(Request $request): array
    {
        return [Text::make('kind')];
    }
}

class RSHProfileResource extends Resource
{
    public static function model(): string
    {
        return RSHProfile::class;
    }

    public static function uriKey(): string
    {
        return 'rsh-profiles';
    }

    public function fields(Request $request): array
    {
        return [Text::make('bio')];
    }
}

class RSHCategoryResource extends Resource
{
    public static bool $viewAny = true;

    public static function model(): string
    {
        return RSHCategory::class;
    }

    public static function uriKey(): string
    {
        return 'rsh-categories';
    }

    public function authorizedToViewAny(Request $request): bool
    {
        return static::$viewAny;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            Text::make('slug')->dependsOn(['name'], fn ($field, $request, $formData) => $field),
        ];
    }
}

class RSHOwnerResource extends Resource
{
    public static function model(): string
    {
        return RSHOwner::class;
    }

    public static function uriKey(): string
    {
        return 'rsh-owners';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            BelongsTo::make('category', 'Category', RSHCategoryResource::class)->nullable()->showCreateRelationButton(),
            HasMany::make('Items', 'items')->relatedResource('rsh-items')->showOnIndex(),
            HasManyThrough::make('Group items', 'groupItems')->relatedResource('rsh-items')->showOnIndex(),
            BelongsToMany::make('Tags', 'tags')->relatedResource('rsh-tags')->showOnIndex()
                ->fields(fn () => [Text::make('note', 'Note')]),
            HasOne::ofMany('Latest item', 'items', RSHItemResource::class)->latestByTimestamp('written_at'),
            HasOne::make('Profile', 'profile')->relatedResource('rsh-profiles'),
        ];
    }
}

const RSH_TABLES = ['rsh_likes', 'rsh_owner_tag', 'rsh_tags', 'rsh_items', 'rsh_groups', 'rsh_profiles', 'rsh_owners', 'rsh_categories'];

function rshSchema(): Illuminate\Database\Schema\Builder
{
    return DB::connection()->getSchemaBuilder();
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    $driver = getenv('MARTIS_TEST_DB') ?: 'sqlite';
    if ($driver !== 'sqlite') {
        config(['database.connections.probe' => $driver === 'mysql'
            ? ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 33306, 'database' => 'probe', 'username' => 'root', 'password' => 'root', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'engine' => null]
            : ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 55432, 'database' => 'probe', 'username' => 'postgres', 'password' => 'root', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'disable']]);
        DB::purge('probe');
        DB::setDefaultConnection('probe');
        if (! rshSchema()->hasTable('martis_cache_state')) {
            rshSchema()->create('martis_cache_state', function ($table) {
                $table->string('type')->primary();
                $table->unsignedInteger('version')->default(1);
                $table->timestamp('cleared_at')->nullable();
                $table->boolean('override')->nullable();
                $table->timestamps();
            });
        }
    }

    foreach (RSH_TABLES as $table) {
        rshSchema()->dropIfExists($table);
    }
    rshSchema()->create('rsh_categories', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('slug')->nullable();
        $table->timestamps();
    });
    rshSchema()->create('rsh_owners', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('tenant')->default('t1');
        $table->unsignedBigInteger('category_id')->nullable();
        $table->timestamps();
    });
    rshSchema()->create('rsh_groups', function ($table) {
        $table->id();
        $table->unsignedBigInteger('owner_id');
        $table->string('title')->default('Group');
        $table->string('tenant')->default('t1');
        $table->timestamps();
    });
    rshSchema()->create('rsh_items', function ($table) {
        $table->id();
        $table->unsignedBigInteger('owner_id');
        $table->unsignedBigInteger('group_id')->nullable();
        $table->string('title');
        $table->string('tenant')->default('t1');
        $table->boolean('is_public')->default(false);
        $table->json('meta')->nullable();
        $table->timestamp('written_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    rshSchema()->create('rsh_tags', function ($table) {
        $table->id();
        $table->string('title');
        $table->string('tenant')->default('t1');
        $table->boolean('is_public')->default(false);
        $table->timestamps();
        $table->softDeletes();
    });
    rshSchema()->create('rsh_owner_tag', function ($table) {
        $table->unsignedBigInteger('owner_id');
        $table->unsignedBigInteger('tag_id');
        $table->string('note')->nullable();
        $table->string('tenant')->default('t1');
        $table->timestamps();
    });
    rshSchema()->create('rsh_likes', function ($table) {
        $table->id();
        $table->unsignedBigInteger('item_id')->nullable();
        $table->unsignedBigInteger('tag_id')->nullable();
        $table->string('kind')->default('like');
        $table->timestamps();
    });
    rshSchema()->create('rsh_profiles', function ($table) {
        $table->id();
        $table->unsignedBigInteger('owner_id');
        $table->string('bio')->nullable();
        $table->timestamps();
    });

    RSHItemResource::$hook = null;
    RSHItemResource::$scopeHooks = [];
    RSHItemResource::$view = null;
    RSHTagResource::$hook = null;
    RSHTagResource::$scopeHooks = [];
    RSHLikeResource::$hook = null;
    RSHCategoryResource::$viewAny = true;

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    foreach ([RSHOwnerResource::class, RSHItemResource::class, RSHTagResource::class, RSHLikeResource::class, RSHProfileResource::class, RSHCategoryResource::class] as $class) {
        $registry->register($class);
    }

    $this->a = RSHOwner::create(['name' => 'Owner A']);
    $this->b = RSHOwner::create(['name' => 'Owner B']);
    $groupA = RSHGroup::create(['owner_id' => $this->a->id]);
    $groupB = RSHGroup::create(['owner_id' => $this->b->id]);

    $this->itemA = RSHItem::create(['owner_id' => $this->a->id, 'group_id' => $groupA->id, 'title' => 'A1', 'meta' => ['prio' => 2], 'written_at' => now()->subDay()]);
    $this->itemB = RSHItem::create(['owner_id' => $this->b->id, 'group_id' => $groupB->id, 'title' => 'B-public', 'is_public' => true, 'meta' => ['prio' => 1], 'written_at' => now()]);

    $tagA = RSHTag::create(['title' => 'A-tag']);
    $tagPublic = RSHTag::create(['title' => 'Public-tag', 'is_public' => true]);
    $this->a->tags()->attach($tagA->id, ['note' => 'note of A']);
    $this->b->tags()->attach($tagPublic->id, ['note' => 'secret note of B']);

    RSHLike::create(['item_id' => $this->itemA->id]);
    RSHLike::create(['tag_id' => $tagA->id]);
});

afterEach(function () {
    foreach (RSH_TABLES as $table) {
        rshSchema()->dropIfExists($table);
    }
    app(ResourceRegistry::class)->flush();
    if ((getenv('MARTIS_TEST_DB') ?: 'sqlite') !== 'sqlite') {
        DB::setDefaultConnection('sqlite');
    }
});

function rshPanel(string $path, string $query = ''): TestResponse
{
    return test()->getJson('/martis/api/resources/rsh-owners/'.test()->a->id.'/'.$path.$query);
}

// ---------------------------------------------------------------------
// 1. OR precedence: a hook with a top-level orWhere() ("own or public")
// ---------------------------------------------------------------------

$ownOrPublic = fn (Builder $q) => $q->where($q->qualifyColumn('title'), 'like', 'A%')->orWhere($q->qualifyColumn('is_public'), true);

it('P01 has-many panel keeps other parents\' rows out with an own-or-public indexQuery', function () use ($ownOrPublic) {
    RSHItemResource::$hook = $ownOrPublic;
    $r = rshPanel('has-many/items')->assertOk();
    expect(collect($r->json('data'))->pluck('title')->all())->toBe(['A1'])
        ->and($r->json('meta.total'))->toBe(1);
});

it('P02 belongs-to-many panel keeps other parents\' pivot rows out with an own-or-public indexQuery', function () use ($ownOrPublic) {
    RSHTagResource::$hook = $ownOrPublic;
    $r = rshPanel('belongs-to-many/tags')->assertOk();
    expect(collect($r->json('data'))->pluck('title')->all())->toBe(['A-tag']);
});

it('P03 has-many-through panel keeps other parents\' rows out with an own-or-public indexQuery', function () use ($ownOrPublic) {
    RSHItemResource::$hook = $ownOrPublic;
    $r = rshPanel('has-many/groupItems')->assertOk();
    expect(collect($r->json('data'))->pluck('title')->all())->toBe(['A1']);
});

it('P04 one-of-many "1 of N" counts only this parent\'s rows with an own-or-public indexQuery', function () use ($ownOrPublic) {
    RSHItemResource::$hook = $ownOrPublic;
    $r = rshPanel('has-one/items')->assertOk();
    expect($r->json('meta.ofMany.totalCount'))->toBe(1);
});

it('P04b index counts group an own-or-public hook (withCount runs it through callScope)', function () use ($ownOrPublic) {
    RSHItemResource::$hook = $ownOrPublic;
    $rows = collect($this->getJson('/martis/api/resources/rsh-owners')->assertOk()->json('data'))->keyBy('name');
    expect($rows['Owner A']['items'])->toBe(1)->and($rows['Owner B']['items'])->toBe(1);
});

// ---------------------------------------------------------------------
// 2. Index counts: a hook that selects or joins
// ---------------------------------------------------------------------

it('P05 parent index still answers when the related indexQuery() selects its own columns', function () {
    RSHItemResource::$hook = fn (Builder $q) => $q->select($q->qualifyColumn('*'));
    $r = $this->getJson('/martis/api/resources/rsh-owners');
    $r->assertOk();
});

it('P06 parent index counts stay per parent when the related indexQuery() joins the parent table', function () {
    RSHItemResource::$hook = fn (Builder $q) => $q->join('rsh_owners', 'rsh_owners.id', '=', 'rsh_items.owner_id')
        ->addSelect('rsh_owners.name as owner_name')
        ->where('rsh_owners.tenant', 't1');
    $rows = collect($this->getJson('/martis/api/resources/rsh-owners')->assertOk()->json('data'))->keyBy('name');
    expect($rows['Owner A']['items'])->toBe(1)->and($rows['Owner B']['items'])->toBe(1);
});

it('P06b the same join hook on the has-many panel lists the right rows', function () {
    // The index needs the hook to select its table's columns too (an
    // addSelect() alone selects only the alias there as well).
    RSHItemResource::$hook = fn (Builder $q) => $q->select('rsh_items.*')
        ->join('rsh_owners', 'rsh_owners.id', '=', 'rsh_items.owner_id')
        ->addSelect('rsh_owners.name as owner_name')
        ->where('rsh_owners.tenant', 't1');
    $r = rshPanel('has-many/items');
    $r->assertOk();
    expect(collect($r->json('data'))->pluck('id')->all())->toBe([$this->itemA->id]);
});

it('P05b a panel still answers when its related rows count a relation whose indexQuery() selects', function () {
    RSHLikeResource::$hook = fn (Builder $q) => $q->select($q->qualifyColumn('*'));
    $r = rshPanel('has-many/items');
    $r->assertOk();
});

// ---------------------------------------------------------------------
// 3. Unqualified columns (the documented examples) and latest()
// ---------------------------------------------------------------------

it('P07a through panel answers with the documented unqualified tenant scope', function () {
    RSHItemResource::$scopeHooks = ['tenant' => fn (Builder $q) => $q->where('tenant', 't1')];
    $r = rshPanel('has-many/groupItems');
    $r->assertOk();
});

it('P07b pivot panel answers with the documented unqualified tenant scope', function () {
    RSHTagResource::$scopeHooks = ['tenant' => fn (Builder $q) => $q->where('tenant', 't1')];
    $r = rshPanel('belongs-to-many/tags');
    $r->assertOk();
});

it('P07c parent index answers with the documented unqualified tenant scope on a counted Through/pivot field', function () {
    RSHItemResource::$scopeHooks = ['tenant' => fn (Builder $q) => $q->where('tenant', 't1')];
    RSHTagResource::$scopeHooks = ['tenant' => fn (Builder $q) => $q->where('tenant', 't1')];
    $r = $this->getJson('/martis/api/resources/rsh-owners');
    $r->assertOk();
});

it('P08a pivot panel answers when the related indexQuery() orders with latest()', function () {
    RSHTagResource::$hook = fn (Builder $q) => $q->latest();
    $r = rshPanel('belongs-to-many/tags');
    $r->assertOk();
});

it('P08b through panel answers when the related indexQuery() orders with latest()', function () {
    RSHItemResource::$hook = fn (Builder $q) => $q->latest();
    $r = rshPanel('has-many/groupItems');
    $r->assertOk();
});

it('P08c has-many panel: the indexQuery() order comes first, as on the index', function () {
    RSHItemResource::$hook = fn (Builder $q) => $q->orderBy($q->qualifyColumn('title'), 'desc');
    RSHItem::create(['owner_id' => $this->a->id, 'title' => 'A2', 'written_at' => now()->subDays(2)]);
    $r = rshPanel('has-many/items', '?sort=title&direction=asc')->assertOk();
    expect(collect($r->json('data'))->pluck('title')->all())->toBe(['A2', 'A1']);
});

// ---------------------------------------------------------------------
// 4. Sorting by an alias and by a JSON path on the panels
// ---------------------------------------------------------------------

it('P10 panels sort by a JSON path', function (string $path) {
    RSHItem::create(['owner_id' => $this->a->id, 'group_id' => RSHGroup::where('owner_id', $this->a->id)->value('id'), 'title' => 'A0', 'meta' => ['prio' => 1]]);
    $r = rshPanel($path, '?sort=meta->prio&direction=asc');
    $r->assertOk();
    expect(collect($r->json('data'))->pluck('title')->all())->toBe(['A0', 'A1']);
})->with(['has-many/items', 'has-many/groupItems']);

// ---------------------------------------------------------------------
// 5. #249 ids and the hydrated pivot, with and without the fallback
// ---------------------------------------------------------------------

it('P11 through panel lists the related ids and the pivot panel hydrates _pivot under the hooks', function () {
    RSHItemResource::$hook = fn (Builder $q) => RSHItem::query()->where('title', '!=', 'nothing');
    RSHTagResource::$hook = fn (Builder $q) => $q->where($q->qualifyColumn('title'), '!=', 'nothing');
    // Make the group id differ from the item id.
    RSHGroup::create(['owner_id' => 999]);
    RSHGroup::create(['owner_id' => 999]);
    $g = RSHGroup::create(['owner_id' => $this->a->id]);
    $item = RSHItem::create(['owner_id' => $this->a->id, 'group_id' => $g->id, 'title' => 'A-through']);

    $through = rshPanel('has-many/groupItems')->assertOk();
    $pivot = rshPanel('belongs-to-many/tags')->assertOk();
    expect(collect($through->json('data'))->pluck('id', 'title')->get('A-through'))->toBe($item->id)
        ->and($pivot->json('data.0._pivot.note'))->toBe('note of A');
});

// ---------------------------------------------------------------------
// 6. Counts: panels aggregate, values, alias collisions
// ---------------------------------------------------------------------

it('P12 a pivot panel counts its rows\' relations in one query, scoped', function () {
    RSHLikeResource::$hook = fn (Builder $q) => $q->where($q->qualifyColumn('kind'), '!=', 'hidden');
    RSHLike::create(['tag_id' => RSHTag::where('title', 'A-tag')->value('id'), 'kind' => 'hidden']);
    DB::enableQueryLog();
    $r = rshPanel('belongs-to-many/tags')->assertOk();
    $perRow = collect(DB::getQueryLog())->filter(fn ($q) => str_contains(strtolower($q['query']), 'from "rsh_likes"') || str_contains(strtolower($q['query']), 'from `rsh_likes`'))
        ->filter(fn ($q) => str_starts_with(strtolower(trim($q['query'])), 'select count(*) as aggregate'));
    expect($r->json('data.0.likes'))->toBe(1)->and($perRow)->toBeEmpty();
});

// ---------------------------------------------------------------------
// 7. Cards
// ---------------------------------------------------------------------

it('P14 second has-one record: 422 shape', function () {
    RSHProfile::create(['owner_id' => $this->a->id, 'bio' => 'first']);
    $r = $this->postJson('/martis/api/resources/rsh-owners/'.$this->a->id.'/has-one/profile', ['bio' => 'second']);
    $r->assertStatus(422);
    // The UI shows the top-level message in a toast (field errors go to inputs; no input is named "profile").
    expect($r->json('message'))->toContain('already been filled');
});

it('P15 second has-one record: a concurrent create between the check and the insert', function () {
    $fired = false;
    DB::listen(function ($query) use (&$fired) {
        if (! $fired && str_contains(strtolower($query->sql), 'exists') && str_contains($query->sql, 'rsh_profiles')) {
            $fired = true;
            DB::table('rsh_profiles')->insert(['owner_id' => test()->a->id, 'bio' => 'concurrent']);
        }
    });
    $r = $this->postJson('/martis/api/resources/rsh-owners/'.$this->a->id.'/has-one/profile', ['bio' => 'mine']);
    expect(RSHProfile::where('owner_id', $this->a->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------
// 8. viewAny on create: the adjacent surfaces
// ---------------------------------------------------------------------

it('P16 a BelongsTo does not offer an inline create the endpoint refuses', function () {
    RSHCategoryResource::$viewAny = false;
    $schema = $this->getJson('/martis/api/resources/rsh-owners/schema')->assertOk()->json();
    $flag = null;
    array_walk_recursive($schema, function ($v, $k) use (&$flag) {
        if ($k === 'showCreateRelationButton' && $flag === null) {
            $flag = $v;
        }
    });
    $inline = $this->getJson('/martis/api/resources/rsh-categories/inline-create-schema');
    expect($flag === true && $inline->status() === 403)->toBeFalse();
});

it('P17 the create form\'s dependsOn sync needs viewAny like the create', function () {
    RSHCategoryResource::$viewAny = false;
    $r = $this->postJson('/martis/api/resources/rsh-categories/sync-field', ['field' => 'slug', 'context' => 'create', 'formData' => ['name' => 'x']]);
    $r->assertStatus(403);
});

// ---------------------------------------------------------------------
// 9. Fallback by key and the trashed filter
// ---------------------------------------------------------------------

it('P18 the key fallback keeps the trashed filter of the panel', function () {
    RSHItemResource::$hook = fn (Builder $q) => RSHItem::query()->where('title', '!=', 'nothing');
    $this->itemA->delete();
    $panel = rshPanel('has-many/items', '?trashed=only')->assertOk();
    $index = $this->getJson('/martis/api/resources/rsh-items?trashed=only')->assertOk();
    expect(collect($panel->json('data'))->pluck('title')->all())->toBe(['A1']);
});

// ---------------------------------------------------------------------
// 10. A hook that joins the table the relation already joins
// ---------------------------------------------------------------------

it('P19 through panel answers when the related indexQuery() joins the intermediate table (index works)', function () {
    RSHItemResource::$hook = fn (Builder $q) => $q->select('rsh_items.*')
        ->join('rsh_groups', 'rsh_groups.id', '=', 'rsh_items.group_id')
        ->where('rsh_groups.tenant', 't1');
    $index = $this->getJson('/martis/api/resources/rsh-items');
    $r = rshPanel('has-many/groupItems');
    $r->assertOk();
});

it('P20 pivot panel and parent index answer with the Nova #337 hook (indexQuery joins the pivot)', function () {
    RSHTagResource::$hook = fn (Builder $q) => $q->select('rsh_tags.*')
        ->join('rsh_owner_tag', 'rsh_tags.id', '=', 'rsh_owner_tag.tag_id')
        ->where('rsh_owner_tag.tenant', 't1');
    $index = $this->getJson('/martis/api/resources/rsh-tags');
    $panel = rshPanel('belongs-to-many/tags');
    $parent = $this->getJson('/martis/api/resources/rsh-owners');
    $panel->assertOk();
    $parent->assertOk();
});

// ---------------------------------------------------------------------
// 11. Self-referential counts (withCount aliases the related table)
// ---------------------------------------------------------------------

class RSHNode extends Model
{
    protected $table = 'rsh_nodes';

    protected $guarded = [];

    public function children(): EHasMany
    {
        return $this->hasMany(RSHNode::class, 'parent_id');
    }
}

class RSHNodeResource extends Resource
{
    public static function model(): string
    {
        return RSHNode::class;
    }

    public static function uriKey(): string
    {
        return 'rsh-nodes';
    }

    // The qualified form the PR's docs recommend.
    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('title'), '!=', 'Hidden');
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title'),
            HasMany::make('Children', 'children')->relatedResource('rsh-nodes')->showOnIndex(),
        ];
    }
}

it('P22 self-referential index count applies the qualified hook to the child rows', function () {
    rshSchema()->dropIfExists('rsh_nodes');
    rshSchema()->create('rsh_nodes', function ($table) {
        $table->id();
        $table->unsignedBigInteger('parent_id')->nullable();
        $table->string('title');
        $table->timestamps();
    });
    app(ResourceRegistry::class)->register(RSHNodeResource::class);
    $root = RSHNode::create(['title' => 'Root']);
    RSHNode::create(['title' => 'Visible child', 'parent_id' => $root->id]);
    RSHNode::create(['title' => 'Hidden', 'parent_id' => $root->id]);

    $rows = collect($this->getJson('/martis/api/resources/rsh-nodes')->assertOk()->json('data'))->keyBy('title');
    $panel = $this->getJson('/martis/api/resources/rsh-nodes/'.$root->id.'/has-many/children')->assertOk();
    rshSchema()->dropIfExists('rsh_nodes');
    expect($rows['Root']['children'])->toBe(1);
});

it('P23 detail page: a count read outside the listing still applies the hooks', function () {
    // The detail page reads the counts field by field (no listing withCount).
    RSHItemResource::$hook = fn (Builder $q) => $q->where($q->qualifyColumn('title'), '!=', 'A1');
    RSHTagResource::$hook = fn (Builder $q) => $q->where($q->qualifyColumn('title'), '!=', 'A-tag');
    $r = $this->getJson('/martis/api/resources/rsh-owners/'.$this->a->id)->assertOk();
    expect($r->json('data.groupItems'))->toBe(0)->and($r->json('data.tags'))->toBe(0);
});

it('P24 a has-many panel counts its rows\' relations in one query, scoped', function () {
    RSHLikeResource::$hook = fn (Builder $q) => $q->where($q->qualifyColumn('kind'), '!=', 'hidden');
    RSHLike::create(['item_id' => $this->itemA->id, 'kind' => 'hidden']);
    DB::enableQueryLog();
    $r = rshPanel('has-many/items')->assertOk();
    $perRow = collect(DB::getQueryLog())->filter(fn ($q) => str_contains(strtolower($q['query']), 'rsh_likes') && str_starts_with(strtolower(trim($q['query'])), 'select count(*) as aggregate'));
    expect($r->json('data.0.likes'))->toBe(1)->and($perRow)->toBeEmpty();
});

it('P25 the one-of-many card still reads its newest record, and a hidden-by-view one hides the card', function () {
    RSHItemResource::$view = fn ($model) => $model->title !== 'A-newest-private';
    RSHItem::create(['owner_id' => $this->a->id, 'title' => 'A-newest-private', 'written_at' => now()->addDay()]);
    rshPanel('has-one/items')->assertOk()->assertJsonPath('meta.hidden', true);
});
