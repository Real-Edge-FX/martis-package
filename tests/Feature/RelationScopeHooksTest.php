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
 * or PostgreSQL on 127.0.0.1:55432 (database `probe`, password `root`);
 * MARTIS_TEST_DB_HOST, MARTIS_TEST_DB_PORT and MARTIS_TEST_DB_PASSWORD name
 * another server (see rshServer()).
 */

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EBelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany as EHasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough as EHasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne as EHasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough as EHasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany as EMorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne as EMorphOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany as EMorphToMany;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Martis\Events\AfterSave;
use Martis\Fields\BelongsTo;
use Martis\Fields\BelongsToMany;
use Martis\Fields\HasMany;
use Martis\Fields\HasManyThrough;
use Martis\Fields\HasOne;
use Martis\Fields\MorphOne;
use Martis\Fields\Number;
use Martis\Fields\Tag;
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

    public function image(): EMorphOne
    {
        return $this->morphOne(RSHImage::class, 'imageable');
    }

    public function images(): EMorphMany
    {
        return $this->morphMany(RSHImage::class, 'imageable');
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

    public function tags(): EMorphToMany
    {
        return $this->morphToMany(RSHTag::class, 'taggable', 'rsh_taggables', 'taggable_id', 'tag_id');
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

    public function tags(): EMorphToMany
    {
        return $this->morphToMany(RSHTag::class, 'taggable', 'rsh_taggables', 'taggable_id', 'tag_id');
    }
}

class RSHImage extends Model
{
    protected $table = 'rsh_images';

    protected $guarded = [];

    public function tags(): EMorphToMany
    {
        return $this->morphToMany(RSHTag::class, 'taggable', 'rsh_taggables', 'taggable_id', 'tag_id');
    }
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

class RSHImageResource extends Resource
{
    public static function model(): string
    {
        return RSHImage::class;
    }

    public static function uriKey(): string
    {
        return 'rsh-images';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')];
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
            MorphOne::make('Image', 'image')->relatedResource('rsh-images'),
        ];
    }
}

const RSH_TABLES = ['rsh_likes', 'rsh_owner_tag', 'rsh_taggables', 'rsh_tags', 'rsh_items', 'rsh_groups', 'rsh_profiles', 'rsh_images', 'rsh_owners', 'rsh_categories'];

function rshSchema(): Illuminate\Database\Schema\Builder
{
    return DB::connection()->getSchemaBuilder();
}

/**
 * The server MARTIS_TEST_DB runs the file against: 127.0.0.1 on 33306
 * (MySQL) or 55432 (PostgreSQL), database `probe`, password `root`, unless
 * MARTIS_TEST_DB_HOST, MARTIS_TEST_DB_PORT or MARTIS_TEST_DB_PASSWORD say
 * otherwise.
 *
 * @return array{host: string, port: int, database: string, username: string, password: string}
 */
function rshServer(string $driver): array
{
    return [
        'host' => getenv('MARTIS_TEST_DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('MARTIS_TEST_DB_PORT') ?: ($driver === 'mysql' ? 33306 : 55432)),
        'database' => 'probe',
        'username' => $driver === 'mysql' ? 'root' : 'postgres',
        'password' => getenv('MARTIS_TEST_DB_PASSWORD') ?: 'root',
    ];
}

/** A second connection to that server, as a concurrent request opens. */
function rshPdo(string $driver): PDO
{
    $server = rshServer($driver);

    return new PDO("{$driver}:host={$server['host']};port={$server['port']};dbname={$server['database']}", $server['username'], $server['password']);
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    $driver = getenv('MARTIS_TEST_DB') ?: 'sqlite';
    if ($driver !== 'sqlite') {
        config(['database.connections.probe' => rshServer($driver) + ($driver === 'mysql'
            ? ['driver' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'engine' => null]
            : ['driver' => 'pgsql', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'disable'])]);
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
    rshSchema()->create('rsh_images', function ($table) {
        $table->id();
        $table->morphs('imageable');
        $table->string('title')->nullable();
        $table->timestamp('written_at')->nullable();
        $table->timestamps();
    });
    rshSchema()->create('rsh_taggables', function ($table) {
        $table->unsignedBigInteger('tag_id');
        $table->morphs('taggable');
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
    foreach ([RSHOwnerResource::class, RSHItemResource::class, RSHTagResource::class, RSHLikeResource::class, RSHProfileResource::class, RSHImageResource::class, RSHCategoryResource::class] as $class) {
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

// ---------------------------------------------------------------------
// Second review round
// ---------------------------------------------------------------------

it('P24a has-many panel with a hook whose first constraint is an orWhere()', function () {
    RSHItemResource::$hook = fn (Builder $q) => $q->orWhere($q->qualifyColumn('title'), 'like', 'A%')->orWhere($q->qualifyColumn('is_public'), true);
    $index = $this->getJson('/martis/api/resources/rsh-items')->assertOk();
    $r = rshPanel('has-many/items')->assertOk();
    $card = rshPanel('has-one/items')->assertOk();
    $parent = collect($this->getJson('/martis/api/resources/rsh-owners')->assertOk()->json('data'))->keyBy('name');
    expect(collect($r->json('data'))->pluck('title')->all())->toBe(['A1']);
});

it('P24b the same leading orWhere() from scopes()', function () {
    RSHItemResource::$scopeHooks = ['visible' => fn (Builder $q) => $q->orWhere($q->qualifyColumn('title'), 'like', 'A%')->orWhere($q->qualifyColumn('is_public'), true)];
    $r = rshPanel('has-many/items')->assertOk();
    expect(collect($r->json('data'))->pluck('title')->all())->toBe(['A1']);
});

it('P25 a hook that filters on a withCount alias (having)', function () {
    RSHItemResource::$hook = fn (Builder $q) => $q->withCount('likes')->having('likes_count', '>=', 0);
    $index = $this->getJson('/martis/api/resources/rsh-items');
    $hasMany = rshPanel('has-many/items');
    $through = rshPanel('has-many/groupItems');
    $parent = $this->getJson('/martis/api/resources/rsh-owners');
    $card = rshPanel('has-one/items');
    expect([$through->status(), $parent->status(), $card->status()])->toBe([$index->status(), $index->status(), $index->status()]);
});

class RSHOwnerTrashedRel extends RSHOwner
{
    public function allItems(): EHasMany
    {
        return $this->hasMany(RSHItem::class, 'owner_id')->withTrashed();
    }

    public function allTags(): EBelongsToMany
    {
        return $this->belongsToMany(RSHTag::class, 'rsh_owner_tag', 'owner_id', 'tag_id')->withTrashed();
    }
}

class RSHOwnerTrashedRelResource extends Resource
{
    public static function model(): string
    {
        return RSHOwnerTrashedRel::class;
    }

    public static function uriKey(): string
    {
        return 'rsh-owners-trashed';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            HasMany::make('All items', 'allItems')->relatedResource('rsh-items')->showOnIndex(),
            BelongsToMany::make('All tags', 'allTags')->relatedResource('rsh-tags')->showOnIndex(),
        ];
    }
}

it('P26 a relation defined withTrashed(): index count, has-many panel and pivot panel, no hooks', function () {
    app(ResourceRegistry::class)->register(RSHOwnerTrashedRelResource::class);
    $this->itemA->delete();
    RSHTag::where('title', 'A-tag')->first()->delete();
    $rows = collect($this->getJson('/martis/api/resources/rsh-owners-trashed')->assertOk()->json('data'))->keyBy('name');
    $hasMany = $this->getJson('/martis/api/resources/rsh-owners-trashed/'.$this->a->id.'/has-many/allItems')->assertOk();
    $pivot = $this->getJson('/martis/api/resources/rsh-owners-trashed/'.$this->a->id.'/belongs-to-many/allTags')->assertOk();
    expect([$rows['Owner A']['allItems'], $rows['Owner A']['allTags']])->toBe([$hasMany->json('meta.total'), $pivot->json('meta.total')]);
});

/**
 * A one-record card of owner `$ownerId` (an `$ownerClass`) the race tests
 * create on: its endpoint, a payload, the record's table, the SQL that
 * selects the owner's record there and the insert a concurrent request runs.
 *
 * @return array{path: string, payload: array<string, string>, table: string, where: string, insert: string}
 */
function rshOneRecordCard(string $kind, int $ownerId, string $ownerClass): array
{
    if ($kind === 'has-one') {
        return [
            'path' => 'has-one/profile',
            'payload' => ['bio' => 'mine'],
            'table' => 'rsh_profiles',
            'where' => "owner_id = {$ownerId}",
            'insert' => "insert into rsh_profiles (owner_id, bio) values ({$ownerId}, 'concurrent')",
        ];
    }

    $type = (new $ownerClass)->getMorphClass();

    return [
        'path' => 'morph-one/image',
        'payload' => ['title' => 'mine'],
        'table' => 'rsh_images',
        'where' => "imageable_type = '{$type}' and imageable_id = {$ownerId}",
        'insert' => "insert into rsh_images (imageable_type, imageable_id, title) values ('{$type}', {$ownerId}, 'concurrent')",
    ];
}

it('P27 two concurrent creates on a one-record card: the parent lock serializes them', function (string $kind) {
    $driver = getenv('MARTIS_TEST_DB') ?: 'sqlite';
    if ($driver === 'sqlite' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Needs a second database connection: MARTIS_TEST_DB=mysql|pgsql and pcntl.');
    }
    $ownerId = $this->a->id;
    $card = rshOneRecordCard($kind, $ownerId, RSHOwner::class);
    $pid = pcntl_fork();
    if ($pid === 0) {
        $pdo = rshPdo($driver);
        $pdo->beginTransaction();
        $pdo->query("select * from rsh_owners where id = {$ownerId} for update")->fetchAll();
        $pdo->exec($card['insert']);
        usleep(1500000);
        $pdo->commit();
        posix_kill(posix_getpid(), SIGKILL);
    }
    usleep(500000);
    $r = $this->postJson('/martis/api/resources/rsh-owners/'.$ownerId.'/'.$card['path'], $card['payload']);
    pcntl_waitpid($pid, $status);
    $count = DB::table($card['table'])->whereRaw($card['where'])->count();
    expect($count)->toBe(1);
})->with(['has-one', 'morph-one']);

class RSHOwnerOnProbe extends RSHOwner
{
    protected $connection = 'probe';

    public function profile(): EHasOne
    {
        return $this->hasOne(RSHProfileOnProbe::class, 'owner_id');
    }

    public function image(): EMorphOne
    {
        return $this->morphOne(RSHImageOnProbe::class, 'imageable');
    }
}

class RSHProfileOnProbe extends RSHProfile
{
    protected $connection = 'probe';
}

class RSHImageOnProbe extends RSHImage
{
    protected $connection = 'probe';
}

class RSHOwnerOnProbeResource extends RSHOwnerTrashedRelResource
{
    public static function model(): string
    {
        return RSHOwnerOnProbe::class;
    }

    public static function uriKey(): string
    {
        return 'rsh-owners-on-probe';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            HasOne::make('Profile', 'profile')->relatedResource('rsh-profiles-on-probe'),
            MorphOne::make('Image', 'image')->relatedResource('rsh-images-on-probe'),
        ];
    }
}

class RSHProfileOnProbeResource extends RSHProfileResource
{
    public static function model(): string
    {
        return RSHProfileOnProbe::class;
    }

    public static function uriKey(): string
    {
        return 'rsh-profiles-on-probe';
    }
}

class RSHImageOnProbeResource extends RSHImageResource
{
    public static function model(): string
    {
        return RSHImageOnProbe::class;
    }

    public static function uriKey(): string
    {
        return 'rsh-images-on-probe';
    }
}

/** The owner and record resources on the `probe` connection. */
function rshRegisterOnProbe(): void
{
    foreach ([RSHOwnerOnProbeResource::class, RSHProfileOnProbeResource::class, RSHImageOnProbeResource::class] as $class) {
        app(ResourceRegistry::class)->register($class);
    }
}

it('P28 the lock when the models use a connection other than the default one', function (string $kind) {
    $driver = getenv('MARTIS_TEST_DB') ?: 'sqlite';
    if ($driver === 'sqlite' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Needs a second database connection: MARTIS_TEST_DB=mysql|pgsql and pcntl.');
    }
    rshRegisterOnProbe();
    $ownerId = $this->a->id;
    $card = rshOneRecordCard($kind, $ownerId, RSHOwnerOnProbe::class);
    $pid = pcntl_fork();
    if ($pid === 0) {
        $pdo = rshPdo($driver);
        $pdo->beginTransaction();
        $pdo->query("select * from rsh_owners where id = {$ownerId} for update")->fetchAll();
        $pdo->exec($card['insert']);
        usleep(1500000);
        $pdo->commit();
        posix_kill(posix_getpid(), SIGKILL);
    }
    usleep(500000);
    // The app's default connection is another database (sqlite here), as in
    // a landlord/tenant setup; the models name their own connection.
    DB::setDefaultConnection('sqlite');
    $r = $this->postJson('/martis/api/resources/rsh-owners-on-probe/'.$ownerId.'/'.$card['path'], $card['payload']);
    DB::setDefaultConnection('probe');
    pcntl_waitpid($pid, $status);
    $count = DB::table($card['table'])->whereRaw($card['where'])->count();
    expect($count)->toBe(1);
})->with(['has-one', 'morph-one']);

/**
 * A second request that runs the same steps as the controller (lock the
 * parent, check, insert) exactly between the first request's in-transaction
 * check and its insert, on the card `$card` (rshOneRecordCard()).
 *
 * @param  array{path: string, payload: array<string, string>, table: string, where: string, insert: string}  $card
 */
function rshRaceBetweenCheckAndInsert(string $driver, int $ownerId, array $card, string $url): array
{
    $dir = sys_get_temp_dir().'/rsh-race-'.getmypid().'-'.uniqid();
    @mkdir($dir);
    $pid = pcntl_fork();
    if ($pid === 0) {
        $deadline = microtime(true) + 10;
        while (! file_exists("$dir/go") && microtime(true) < $deadline) {
            usleep(10000);
        }
        $pdo = rshPdo($driver);
        $pdo->beginTransaction();
        $pdo->query("select * from rsh_owners where id = {$ownerId} for update")->fetchAll();
        $exists = (int) $pdo->query("select count(*) from {$card['table']} where {$card['where']}")->fetchColumn();
        if ($exists === 0) {
            $pdo->exec($card['insert']);
        }
        $pdo->commit();
        touch("$dir/done");
        posix_kill(posix_getpid(), SIGKILL);
    }

    $seen = 0;
    DB::listen(function ($query) use (&$seen, $dir, $card) {
        if ($query->connectionName === 'probe' && str_contains(strtolower($query->sql), 'exists') && str_contains($query->sql, $card['table'])) {
            $seen++;
            if ($seen === 2) {
                touch("$dir/go");
                $deadline = microtime(true) + 1.5;
                while (! file_exists("$dir/done") && microtime(true) < $deadline) {
                    usleep(10000);
                }
            }
        }
    });

    $r = test()->postJson($url, $card['payload']);
    pcntl_waitpid($pid, $status);
    @unlink("$dir/go");
    @unlink("$dir/done");
    @rmdir($dir);

    return [$r->status(), DB::connection('probe')->table($card['table'])->whereRaw($card['where'])->count(), $seen];
}

it('P29 race between the check and the insert, models on the default connection (control)', function (string $kind) {
    $driver = getenv('MARTIS_TEST_DB') ?: 'sqlite';
    if ($driver === 'sqlite' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Needs a second database connection: MARTIS_TEST_DB=mysql|pgsql and pcntl.');
    }
    $card = rshOneRecordCard($kind, $this->a->id, RSHOwner::class);
    [$status, $count, $seen] = rshRaceBetweenCheckAndInsert($driver, $this->a->id, $card, '/martis/api/resources/rsh-owners/'.$this->a->id.'/'.$card['path']);
    expect($count)->toBe(1);
})->with(['has-one', 'morph-one']);

it('P30 race between the check and the insert, models on a connection other than the default', function (string $kind) {
    $driver = getenv('MARTIS_TEST_DB') ?: 'sqlite';
    if ($driver === 'sqlite' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Needs a second database connection: MARTIS_TEST_DB=mysql|pgsql and pcntl.');
    }
    rshRegisterOnProbe();
    $card = rshOneRecordCard($kind, $this->a->id, RSHOwnerOnProbe::class);
    DB::setDefaultConnection('sqlite');
    [$status, $count, $seen] = rshRaceBetweenCheckAndInsert($driver, $this->a->id, $card, '/martis/api/resources/rsh-owners-on-probe/'.$this->a->id.'/'.$card['path']);
    DB::setDefaultConnection('probe');
    expect($count)->toBe(1);
})->with(['has-one', 'morph-one']);

it('P31 detail page: a count through a Through relation with the documented unqualified tenant scope', function () {
    // Counted by key on a fresh query: an unqualified column the joined
    // intermediate table also has cannot be ambiguous there.
    RSHItemResource::$scopeHooks = ['tenant' => fn (Builder $q) => $q->where('tenant', 't1')];
    RSHItem::where('id', $this->itemA->id)->update(['tenant' => 't2']);

    $r = $this->getJson('/martis/api/resources/rsh-owners/'.$this->a->id)->assertOk();

    expect($r->json('data.groupItems'))->toBe(0);
});

it('P32 the one-of-many "1 of N" with the documented unqualified tenant scope and a join to the parent', function () {
    RSHItemResource::$hook = fn (Builder $q) => $q->select('rsh_items.*')
        ->join('rsh_owners', 'rsh_owners.id', '=', 'rsh_items.owner_id')
        ->where('rsh_owners.tenant', 't1');
    RSHItem::create(['owner_id' => $this->a->id, 'title' => 'A2', 'written_at' => now()]);

    expect(rshPanel('has-one/items')->assertOk()->json('meta.ofMany.totalCount'))->toBe(2);
});

it('P33 a pivot panel lists the trashed rows it asks for when the hook builds its own query', function () {
    RSHTagResource::$hook = fn (Builder $q) => RSHTag::query()->where('title', '!=', 'nothing');
    RSHTag::where('title', 'A-tag')->first()->delete();

    expect(collect(rshPanel('belongs-to-many/tags', '?trashed=only')->assertOk()->json('data'))->pluck('title')->all())->toBe(['A-tag']);
});

it('P34 the one-of-many "1 of N" with a hook that groups, as the index allows', function () {
    // A count run on the hook's own query would count one group; the keys
    // it returns count every record.
    RSHItemResource::$hook = fn (Builder $q) => $q->groupBy($q->qualifyColumn('id'));
    RSHItem::create(['owner_id' => $this->a->id, 'title' => 'A2', 'written_at' => now()]);

    expect(rshPanel('has-one/items')->assertOk()->json('meta.ofMany.totalCount'))->toBe(2);
});

// ---------------------------------------------------------------------
// Third review round: a relation that removes a global scope
// ---------------------------------------------------------------------

/** A global scope other than soft delete. */
class RSHArchivedScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('title'), '!=', 'Archived');
    }
}

/** Hides "Archived" (RSHArchivedScope) and "Draft" (`draft`) rows. */
trait RSHHidesArchivedAndDrafts
{
    protected static function booted(): void
    {
        static::addGlobalScope(new RSHArchivedScope);
        static::addGlobalScope('draft', fn (Builder $q) => $q->where($q->qualifyColumn('title'), '!=', 'Draft'));
    }
}

class RSHScopedItem extends RSHItem
{
    use RSHHidesArchivedAndDrafts;
}

class RSHScopedTag extends RSHTag
{
    use RSHHidesArchivedAndDrafts;
}

class RSHScopedImage extends RSHImage
{
    use RSHHidesArchivedAndDrafts;
}

class RSHOwnerScopedRel extends RSHOwner
{
    /** The archived items belong to these relations; the drafts do not. */
    public function allItems(): EHasMany
    {
        return $this->hasMany(RSHScopedItem::class, 'owner_id')->withoutGlobalScope(RSHArchivedScope::class);
    }

    public function allGroupItems(): EHasManyThrough
    {
        return $this->hasManyThrough(RSHScopedItem::class, RSHGroup::class, 'owner_id', 'group_id')->withoutGlobalScope(RSHArchivedScope::class);
    }

    public function allTags(): EBelongsToMany
    {
        return $this->belongsToMany(RSHScopedTag::class, 'rsh_owner_tag', 'owner_id', 'tag_id')->withoutGlobalScope(RSHArchivedScope::class);
    }

    /** Every item: the archived, the draft and the trashed ones. */
    public function everyItem(): EHasMany
    {
        return $this->hasMany(RSHScopedItem::class, 'owner_id')->withoutGlobalScopes();
    }

    /** allItems() behind a one-of-many card (a field of its own name). */
    public function cardItems(): EHasMany
    {
        return $this->allItems();
    }

    /** Eloquent one-of-many relations that keep the archived rows. */
    public function newestItem(): EHasOne
    {
        return $this->hasOne(RSHScopedItem::class, 'owner_id')->withoutGlobalScope(RSHArchivedScope::class)->latestOfMany('written_at');
    }

    public function newestGroupItem(): EHasOneThrough
    {
        return $this->hasOneThrough(RSHScopedItem::class, RSHGroup::class, 'owner_id', 'group_id')->withoutGlobalScope(RSHArchivedScope::class)->latestOfMany('written_at');
    }

    public function newestImage(): EMorphOne
    {
        return $this->morphOne(RSHScopedImage::class, 'imageable')->withoutGlobalScope(RSHArchivedScope::class)->latestOfMany('written_at');
    }
}

class RSHScopedItemResource extends RSHItemResource
{
    public static function model(): string
    {
        return RSHScopedItem::class;
    }

    public static function uriKey(): string
    {
        return 'rsh-scoped-items';
    }
}

class RSHScopedTagResource extends RSHTagResource
{
    public static function model(): string
    {
        return RSHScopedTag::class;
    }

    public static function uriKey(): string
    {
        return 'rsh-scoped-tags';
    }
}

class RSHScopedImageResource extends Resource
{
    public static function model(): string
    {
        return RSHScopedImage::class;
    }

    public static function uriKey(): string
    {
        return 'rsh-scoped-images';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')];
    }
}

class RSHOwnerScopedRelResource extends Resource
{
    public static function model(): string
    {
        return RSHOwnerScopedRel::class;
    }

    public static function uriKey(): string
    {
        return 'rsh-owners-scoped';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            HasMany::make('All items', 'allItems')->relatedResource('rsh-scoped-items')->showOnIndex(),
            HasManyThrough::make('All group items', 'allGroupItems')->relatedResource('rsh-scoped-items')->showOnIndex(),
            BelongsToMany::make('All tags', 'allTags')->relatedResource('rsh-scoped-tags')->showOnIndex(),
            HasMany::make('Every item', 'everyItem')->relatedResource('rsh-scoped-items')->showOnIndex(),
            HasOne::ofMany('Latest item', 'cardItems', RSHScopedItemResource::class)->latestByTimestamp('written_at'),
            HasOne::ofMany('Newest item', 'newestItem', RSHScopedItemResource::class),
            HasOne::ofMany('Newest group item', 'newestGroupItem', RSHScopedItemResource::class),
            MorphOne::ofMany('Newest image', 'newestImage', RSHScopedImageResource::class),
        ];
    }
}

/**
 * Owner A gets an "Archived" and a "Draft" item (in its group) and tag,
 * besides A1 and A-tag: its relations above hold A1 and "Archived".
 */
function rshArchivedAndDrafts(): void
{
    foreach ([RSHScopedItemResource::class, RSHScopedTagResource::class, RSHScopedImageResource::class, RSHOwnerScopedRelResource::class] as $class) {
        app(ResourceRegistry::class)->register($class);
    }

    $groupId = RSHGroup::where('owner_id', test()->a->id)->value('id');
    foreach (['Archived', 'Draft'] as $title) {
        RSHItem::create(['owner_id' => test()->a->id, 'group_id' => $groupId, 'title' => $title, 'written_at' => now()->subDays(2)]);
        test()->a->tags()->attach(RSHTag::create(['title' => $title])->id);
    }
}

function rshScoped(string $path = ''): TestResponse
{
    return test()->getJson('/martis/api/resources/rsh-owners-scoped/'.test()->a->id.$path)->assertOk();
}

it('P35 a relation that removes a global scope keeps it removed in the index counts (no hooks)', function () {
    rshArchivedAndDrafts();

    $rows = collect($this->getJson('/martis/api/resources/rsh-owners-scoped')->assertOk()->json('data'))->keyBy('name');

    expect([$rows['Owner A']['allItems'], $rows['Owner A']['allGroupItems'], $rows['Owner A']['allTags']])->toBe([2, 2, 2]);
});

it('P36 a relation that removes a global scope keeps it removed in the detail counts (no hooks)', function () {
    rshArchivedAndDrafts();

    $detail = rshScoped();

    expect([$detail->json('data.allItems'), $detail->json('data.allGroupItems'), $detail->json('data.allTags')])->toBe([2, 2, 2]);
});

it('P37 a relation that removes a global scope keeps it removed on its panel and its one-of-many card (no hooks)', function (string $path, string $read) {
    rshArchivedAndDrafts();

    expect(rshScoped('/'.$path)->json($read))->toBe(2);
})->with([
    'has-many (control: scoped in place)' => ['has-many/allItems', 'meta.total'],
    'has-many-through' => ['has-many/allGroupItems', 'meta.total'],
    'belongs-to-many' => ['belongs-to-many/allTags', 'meta.total'],
    'one-of-many "1 of N"' => ['has-one/cardItems', 'meta.ofMany.totalCount'],
]);

it('P38 an Eloquent one-of-many card counts in "1 of N" the rows its relation keeps', function (string $path) {
    rshArchivedAndDrafts();
    foreach (['A image' => 1, 'Archived' => 2, 'Draft' => 3] as $title => $days) {
        RSHImage::create(['imageable_type' => (new RSHOwnerScopedRel)->getMorphClass(), 'imageable_id' => $this->a->id, 'title' => $title, 'written_at' => now()->subDays($days)]);
    }

    // The relation rebuilt for the count keeps the scope it removes out.
    expect(rshScoped('/'.$path)->json('meta.ofMany.totalCount'))->toBe(2);
})->with([
    'has-one (latestOfMany)' => ['has-one/newestItem'],
    'has-one-through (latestOfMany)' => ['has-one/newestGroupItem'],
    'morph-one (latestOfMany)' => ['morph-one/newestImage'],
]);

it('P39 a relation that removes every global scope (withoutGlobalScopes()) keeps them all removed', function () {
    rshArchivedAndDrafts();
    RSHItem::create(['owner_id' => $this->a->id, 'title' => 'Trashed'])->delete();

    $rows = collect($this->getJson('/martis/api/resources/rsh-owners-scoped')->assertOk()->json('data'))->keyBy('name');

    expect([$rows['Owner A']['everyItem'], rshScoped()->json('data.everyItem'), rshScoped('/has-many/everyItem')->json('meta.total')])->toBe([4, 4, 4]);
});

it('P40 the scopes a relation keeps and the related hooks still filter it', function () {
    rshArchivedAndDrafts();
    RSHItemResource::$hook = fn (Builder $q) => $q->where($q->qualifyColumn('title'), '!=', 'A1');
    RSHTagResource::$hook = fn (Builder $q) => $q->where($q->qualifyColumn('title'), '!=', 'A-tag');

    // Left: "Archived" (the relation removes its scope). Gone: "Draft" (the
    // `draft` scope stays) and A1 / A-tag (the hooks).
    $rows = collect($this->getJson('/martis/api/resources/rsh-owners-scoped')->assertOk()->json('data'))->keyBy('name');
    $detail = rshScoped();

    expect([$rows['Owner A']['allItems'], $rows['Owner A']['allGroupItems'], $rows['Owner A']['allTags']])->toBe([1, 1, 1])
        ->and([$detail->json('data.allItems'), $detail->json('data.allGroupItems'), $detail->json('data.allTags')])->toBe([1, 1, 1])
        ->and(collect(rshScoped('/has-many/allGroupItems')->json('data'))->pluck('title')->all())->toBe(['Archived'])
        ->and(collect(rshScoped('/belongs-to-many/allTags')->json('data'))->pluck('title')->all())->toBe(['Archived']);
});

it('P41 the hooks receive the related query without the scopes the relation removes, and with the ones it keeps', function () {
    rshArchivedAndDrafts();
    $seen = [];
    RSHItemResource::$hook = function (Builder $q) use (&$seen) {
        $seen[] = (clone $q)->orderBy($q->qualifyColumn('title'))->pluck($q->qualifyColumn('title'))->all();

        return $q;
    };

    rshScoped('/has-many/allGroupItems');

    expect($seen)->toBe([['A1', 'Archived', 'B-public']]);
});

it('P42 a hook that builds its own query keeps the relation\'s removed scope removed', function () {
    rshArchivedAndDrafts();
    RSHItemResource::$hook = fn (Builder $q) => RSHScopedItem::query()->where('title', '!=', 'nothing');

    $rows = collect($this->getJson('/martis/api/resources/rsh-owners-scoped')->assertOk()->json('data'))->keyBy('name');

    expect([$rows['Owner A']['allItems'], $rows['Owner A']['allGroupItems']])->toBe([2, 2])
        ->and(rshScoped()->json('data.allGroupItems'))->toBe(2)
        ->and(rshScoped('/has-many/allItems')->json('meta.total'))->toBe(2)
        ->and(rshScoped('/has-many/allGroupItems')->json('meta.total'))->toBe(2);
});

/** A has-many that refuses its constraints without its parent's key. */
class RSHKeyedHasMany extends EHasMany
{
    public function addConstraints()
    {
        if (static::$constraints && $this->getParentKey() === null) {
            throw new LogicException('Constrained without the parent key.');
        }

        parent::addConstraints();
    }
}

class RSHOwnerLoadedRel extends RSHOwner
{
    /** Needs a loaded owner: on the listing's model, which holds none, it throws. */
    public function tenantItems(): EHasMany
    {
        return $this->hasMany(RSHItem::class, 'owner_id')->where('tenant', $this->tenant ?? throw new LogicException('A loaded owner is needed.'));
    }

    /** Resolves without a record when unconstrained, as withCount() resolves it. */
    public function keyedItems(): EHasMany
    {
        return new RSHKeyedHasMany((new RSHItem)->newQuery(), $this, 'rsh_items.owner_id', 'id');
    }
}

class RSHOwnerLoadedRelResource extends Resource
{
    public static function model(): string
    {
        return RSHOwnerLoadedRel::class;
    }

    public static function uriKey(): string
    {
        return 'rsh-owners-loaded';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name'), HasMany::make('Tenant items', 'tenantItems')->relatedResource('rsh-items')->showOnIndex()];
    }
}

class RSHOwnerKeyedRelResource extends RSHOwnerLoadedRelResource
{
    public static function uriKey(): string
    {
        return 'rsh-owners-keyed';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name'), HasMany::make('Keyed items', 'keyedItems')->relatedResource('rsh-items')->showOnIndex()];
    }
}

it('P43 an index count whose relation needs a loaded record is counted per row, and the index answers', function () {
    app(ResourceRegistry::class)->register(RSHOwnerLoadedRelResource::class);
    RSHItemResource::$hook = fn (Builder $q) => $q->where($q->qualifyColumn('title'), '!=', 'Hidden');
    RSHItem::create(['owner_id' => $this->a->id, 'title' => 'A2']);
    RSHItem::create(['owner_id' => $this->a->id, 'title' => 'Hidden']);
    RSHItem::create(['owner_id' => $this->a->id, 'title' => 'Other tenant', 'tenant' => 't2']);

    $rows = collect($this->getJson('/martis/api/resources/rsh-owners-loaded')->assertOk()->json('data'))->keyBy('name');

    expect([$rows['Owner A']['tenantItems'], $rows['Owner B']['tenantItems']])->toBe([2, 1]);
});

it('P44 a relation is read without its constraints, as withCount() reads it, so it is still counted with the page', function () {
    app(ResourceRegistry::class)->register(RSHOwnerKeyedRelResource::class);
    RSHItem::create(['owner_id' => $this->a->id, 'title' => 'A2']);

    DB::enableQueryLog();
    $rows = collect($this->getJson('/martis/api/resources/rsh-owners-keyed')->assertOk()->json('data'))->keyBy('name');
    $perRow = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'rsh_items') && str_starts_with(strtolower(trim($q['query'])), 'select count(*) as aggregate'));

    expect([$rows['Owner A']['keyedItems'], $rows['Owner B']['keyedItems']])->toBe([2, 1])
        ->and($perRow)->toBeEmpty();
});

// ---------------------------------------------------------------------
// Third review round: creating the record of a one-record card
// ---------------------------------------------------------------------

/** Related resources whose create form defers a write (a Tag field). */
class RSHProfileTaggedResource extends RSHProfileResource
{
    public static function uriKey(): string
    {
        return 'rsh-profiles-tagged';
    }

    public function fields(Request $request): array
    {
        return [Text::make('bio'), Tag::make('tags', 'Tags')->relatedResource('rsh-tags')->nullable()];
    }
}

class RSHItemTaggedResource extends RSHItemResource
{
    public static function uriKey(): string
    {
        return 'rsh-items-tagged';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title'), Tag::make('tags', 'Tags')->relatedResource('rsh-tags')->nullable()];
    }
}

class RSHImageTaggedResource extends RSHImageResource
{
    public static function uriKey(): string
    {
        return 'rsh-images-tagged';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title'), Tag::make('tags', 'Tags')->relatedResource('rsh-tags')->nullable()];
    }
}

class RSHOwnerTaggedResource extends Resource
{
    public static function model(): string
    {
        return RSHOwner::class;
    }

    public static function uriKey(): string
    {
        return 'rsh-owners-tagged';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            HasOne::make('Profile', 'profile')->relatedResource('rsh-profiles-tagged'),
            HasOne::ofMany('Latest item', 'items', RSHItemTaggedResource::class)->latestByTimestamp('written_at'),
            MorphOne::make('Image', 'image')->relatedResource('rsh-images-tagged'),
            MorphOne::ofMany('Latest image', 'images', RSHImageTaggedResource::class)->latestByTimestamp('written_at'),
        ];
    }
}

it('P45 a card create holds the parent lock for the check and the insert only: afterSave and the deferred writes run after the commit, and a one-of-many create opens no transaction', function (string $path, array $payload, string $model, int $inTransaction) {
    foreach ([RSHProfileTaggedResource::class, RSHItemTaggedResource::class, RSHImageTaggedResource::class, RSHOwnerTaggedResource::class] as $class) {
        app(ResourceRegistry::class)->register($class);
    }
    $tag = RSHTag::create(['title' => 'Tagged']);
    // The test's own transaction, if any (RefreshDatabase on SQLite).
    $outside = DB::connection()->transactionLevel();
    $levels = [];
    $model::created(function () use (&$levels) {
        $levels['created'] = DB::connection()->transactionLevel();
    });
    Event::listen(AfterSave::class, function () use (&$levels) {
        $levels['afterSave'] = DB::connection()->transactionLevel();
    });
    DB::listen(function ($query) use (&$levels) {
        if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'rsh_taggables')) {
            $levels['deferred'] = $query->connection->transactionLevel();
        }
    });

    $this->postJson('/martis/api/resources/rsh-owners-tagged/'.$this->a->id.'/'.$path, $payload + ['tags' => [$tag->id]])->assertCreated();

    expect($levels)->toBe(['created' => $outside + $inTransaction, 'afterSave' => $outside, 'deferred' => $outside]);
})->with([
    'has-one' => ['has-one/profile', ['bio' => 'Mine'], RSHProfile::class, 1],
    'morph-one' => ['morph-one/image', ['title' => 'Mine'], RSHImage::class, 1],
    'has-one of many' => ['has-one/items', ['title' => 'Mine'], RSHItem::class, 0],
    'morph-one of many' => ['morph-one/images', ['title' => 'Mine'], RSHImage::class, 0],
]);

// ---------------------------------------------------------------------
// Third review round: a hook that orders by an alias it selects
// ---------------------------------------------------------------------

it('P46 the keys drop a hook\'s order by an alias it selects (withCount then orderBy)', function (Closure $hook) {
    RSHItemResource::$hook = $hook;
    RSHItem::create(['owner_id' => $this->a->id, 'group_id' => RSHGroup::where('owner_id', $this->a->id)->value('id'), 'title' => 'A2']);

    // The index sorts by the alias, which its own select holds.
    $index = $this->getJson('/martis/api/resources/rsh-items')->assertOk();
    $rows = collect($this->getJson('/martis/api/resources/rsh-owners')->assertOk()->json('data'))->keyBy('name');

    expect(collect($index->json('data'))->pluck('title')->first())->toBe('A1')
        ->and(rshPanel('has-many/groupItems')->assertOk()->json('meta.total'))->toBe(2)
        ->and(rshPanel('has-one/items')->assertOk()->json('meta.ofMany.totalCount'))->toBe(2)
        ->and([$rows['Owner A']['items'], $rows['Owner A']['groupItems']])->toBe([2, 2]);
})->with([
    // Kept in the key subquery, the order by an alias it no longer selects
    // fails on MySQL and PostgreSQL (MARTIS_TEST_DB); SQLite reads the
    // quoted name as a string there and accepts it.
    'orderByDesc()' => [fn (Builder $q) => $q->withCount('likes')->orderByDesc('likes_count')],
    // The unquoted name fails on SQLite too.
    'orderByRaw()' => [fn (Builder $q) => $q->withCount('likes')->orderByRaw('likes_count desc')],
]);
