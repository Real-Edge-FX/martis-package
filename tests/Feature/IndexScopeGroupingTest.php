<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo as EloquentBelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany as EloquentMorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Martis\Enums\FilterType;
use Martis\Fields\BelongsTo;
use Martis\Fields\BelongsToMany;
use Martis\Fields\MorphToMany;
use Martis\Fields\Text;
use Martis\Filters\Filter;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Http\Requests\LensRequest;
use Martis\Lenses\Lens;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * A user hook written with a top-level `orWhere()` (a scope, `indexQuery()`,
 * a filter, `searchQuery()`, a lens query, `relatableQuery()`, a
 * `relatable{Models}()` method, a field's `relatableQueryUsing()`) runs as
 * Eloquent runs a local scope: what it adds is one group, and what the query
 * held before is another. Before v2.0.1 the index composed them raw, so
 * `tenant = 1 or shared = 1` followed by a filter `F` read
 * `tenant = 1 or (shared = 1 and F)`: every record of the tenant showed
 * whatever the filter, the search term or the picker's fence said.
 */

// ── Fixtures ────────────────────────────────────────────────────────────────

final class IsgContext
{
    /** @var (Closure(Builder): Builder)|null Replaces the resource's default tenant scope. */
    public static ?Closure $scope = null;

    /** @var (Closure(Builder, string): (Builder|null))|null The resource's searchQuery(). */
    public static ?Closure $searchQuery = null;

    /** @var (Closure(Builder): Builder)|null Replaces the owners' relatableQuery() fence. */
    public static ?Closure $fence = null;

    /** @var (Closure(Builder): mixed)|null The owner fields' relatableQueryUsing(). */
    public static ?Closure $fieldClosure = null;

    /** @var (Closure(Builder): Builder)|null The tasks' relatableIsgOwners(). */
    public static ?Closure $sourceHook = null;

    public static function reset(): void
    {
        self::$scope = self::$searchQuery = self::$fence = self::$fieldClosure = self::$sourceHook = null;
    }
}

class IsgProject extends Model
{
    use SoftDeletes;

    protected $table = 'isg_projects';

    protected $guarded = [];
}

class IsgOwner extends Model
{
    protected $table = 'isg_owners';

    protected $guarded = [];

    public $timestamps = false;
}

class IsgTask extends Model
{
    protected $table = 'isg_tasks';

    protected $guarded = [];

    public $timestamps = false;

    public function owner(): EloquentBelongsTo
    {
        return $this->belongsTo(IsgOwner::class, 'owner_id');
    }
}

class IsgTeam extends Model
{
    protected $table = 'isg_teams';

    protected $guarded = [];

    public $timestamps = false;

    public function members(): EloquentBelongsToMany
    {
        return $this->belongsToMany(IsgOwner::class, 'isg_team_owner', 'team_id', 'owner_id');
    }

    public function labels(): EloquentMorphToMany
    {
        return $this->morphToMany(IsgOwner::class, 'ownable', 'isg_ownables', null, 'owner_id');
    }
}

/** `status = value`. */
class IsgStatusFilter extends Filter
{
    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query->where('status', $value);
    }

    public function filterType(): FilterType
    {
        return FilterType::Select;
    }
}

/** "Open or urgent", written as a filter often is: a top-level orWhere(). */
class IsgOpenOrUrgentFilter extends Filter
{
    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query->where('status', 'open')->orWhere('status', 'urgent');
    }

    public function filterType(): FilterType
    {
        return FilterType::Select;
    }
}

/** `tenant_id = value`. */
class IsgTenantFilter extends Filter
{
    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query->where('tenant_id', $value);
    }

    public function filterType(): FilterType
    {
        return FilterType::Select;
    }
}

/** The open projects and the shared ones, with a top-level orWhere(). */
class IsgOpenOrSharedLens extends Lens
{
    public function query(LensRequest $request, Builder $query): Builder
    {
        return $request->withFilters($query->where('status', 'open')->orWhere('shared', true));
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }

    public function filters(Request $request): array
    {
        return [IsgTenantFilter::make('Tenant')];
    }
}

class IsgProjectResource extends Resource
{
    public static function model(): string
    {
        return IsgProject::class;
    }

    public static function uriKey(): string
    {
        return 'isg-projects';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public static function scopes(Request $request): array
    {
        return ['tenant' => fn (Builder $query): Builder => IsgContext::$scope !== null
            ? (IsgContext::$scope)($query)
            : $query->where('tenant_id', 1)];
    }

    public function searchQuery(Builder $query, string $search): ?Builder
    {
        return IsgContext::$searchQuery !== null ? (IsgContext::$searchQuery)($query, $search) : null;
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')->searchable()];
    }

    public function filters(Request $request): array
    {
        return [IsgStatusFilter::make('Status'), IsgOpenOrUrgentFilter::make('Open or urgent')];
    }

    public function lenses(Request $request): array
    {
        return [new IsgOpenOrSharedLens];
    }
}

/** The picker target: fenced to tenant 1 by relatableQuery(). */
class IsgOwnerResource extends Resource
{
    public static function model(): string
    {
        return IsgOwner::class;
    }

    public static function uriKey(): string
    {
        return 'isg-owners';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')->searchable()];
    }

    public static function relatableQuery(Request $request, Builder $query): Builder
    {
        return IsgContext::$fence !== null ? (IsgContext::$fence)($query) : $query->where('tenant_id', 1);
    }
}

/** The owners' field closure, when the test sets one. */
function isgFieldClosure(): Closure
{
    return function (Request $request, Builder $query): mixed {
        return IsgContext::$fieldClosure !== null ? (IsgContext::$fieldClosure)($query) : $query;
    };
}

class IsgTaskResource extends Resource
{
    public static function model(): string
    {
        return IsgTask::class;
    }

    public static function uriKey(): string
    {
        return 'isg-tasks';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title'),
            BelongsTo::make('owner', 'Owner')->relatedResource('isg-owners')->titleAttribute('name')
                ->relatableQueryUsing(isgFieldClosure()),
        ];
    }

    public static function relatableIsgOwners(Request $request, Builder $query): Builder
    {
        return IsgContext::$sourceHook !== null ? (IsgContext::$sourceHook)($query) : $query;
    }
}

class IsgTeamResource extends Resource
{
    public static function model(): string
    {
        return IsgTeam::class;
    }

    public static function uriKey(): string
    {
        return 'isg-teams';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            BelongsToMany::make('Members', 'members')->relatedResource('isg-owners')->relatableQueryUsing(isgFieldClosure()),
            MorphToMany::make('Labels', 'labels')->relatedResource('isg-owners')->relatableQueryUsing(isgFieldClosure()),
        ];
    }
}

// ── Setup ───────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);
    IsgContext::reset();

    Schema::create('isg_projects', function ($table) {
        $table->id();
        $table->unsignedInteger('tenant_id');
        $table->string('name');
        $table->string('status')->default('open');
        $table->boolean('shared')->default(false);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('isg_owners', function ($table) {
        $table->id();
        $table->unsignedInteger('tenant_id');
        $table->string('name');
        $table->boolean('active')->default(true);
    });
    Schema::create('isg_tasks', function ($table) {
        $table->id();
        $table->string('title');
        $table->unsignedBigInteger('owner_id')->nullable();
    });
    Schema::create('isg_teams', function ($table) {
        $table->id();
        $table->string('name');
    });
    Schema::create('isg_team_owner', function ($table) {
        $table->unsignedBigInteger('team_id');
        $table->unsignedBigInteger('owner_id');
    });
    Schema::create('isg_ownables', function ($table) {
        $table->unsignedBigInteger('owner_id');
        $table->morphs('ownable');
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    foreach ([IsgProjectResource::class, IsgOwnerResource::class, IsgTaskResource::class, IsgTeamResource::class] as $class) {
        $registry->register($class);
    }
});

/** Tenant 1 or the shared records, with a top-level orWhere(). */
function isgTenantOrShared(): void
{
    IsgContext::$scope = fn (Builder $query): Builder => $query->where('tenant_id', 1)->orWhere('shared', true);
}

/** @return list<string> */
function isgNames(TestResponse $response): array
{
    return collect($response->assertOk()->json('data'))->pluck('name')->sort()->values()->all();
}

function isgIndex(string $query = ''): TestResponse
{
    return test()->getJson('/martis/api/resources/isg-projects'.$query);
}

// ── Index: the resource's hooks ─────────────────────────────────────────────

it('applies the filters to every record a scopes() orWhere() keeps', function () {
    isgTenantOrShared();
    IsgProject::create(['tenant_id' => 1, 'name' => 'Mine open', 'status' => 'open']);
    IsgProject::create(['tenant_id' => 1, 'name' => 'Mine closed', 'status' => 'closed']);
    IsgProject::create(['tenant_id' => 2, 'name' => 'Shared closed', 'status' => 'closed', 'shared' => true]);
    IsgProject::create(['tenant_id' => 2, 'name' => 'Theirs closed', 'status' => 'closed']);

    $filters = urlencode(json_encode(['status' => 'open']));

    expect(isgNames(isgIndex("?filters={$filters}")))->toBe(['Mine open'])
        ->and(isgIndex("?filters={$filters}")->json('meta.total'))->toBe(1);
});

it('applies the search term to every record a scopes() orWhere() keeps', function () {
    isgTenantOrShared();
    IsgProject::create(['tenant_id' => 1, 'name' => 'Apollo mine']);
    IsgProject::create(['tenant_id' => 1, 'name' => 'Zeus mine']);
    IsgProject::create(['tenant_id' => 2, 'name' => 'Apollo shared', 'shared' => true]);
    IsgProject::create(['tenant_id' => 2, 'name' => 'Apollo theirs']);

    expect(isgNames(isgIndex('?search=Apollo')))->toBe(['Apollo mine', 'Apollo shared']);
});

it('keeps a scopes() hook that starts with orWhere() inside the trashed filter', function () {
    // On a fresh query a leading orWhere() reads as `and`: the trashed
    // filter's own clause must not turn it into `deleted or tenant = 1`.
    IsgContext::$scope = fn (Builder $query): Builder => $query->orWhere('tenant_id', 1);
    IsgProject::create(['tenant_id' => 1, 'name' => 'Mine active']);
    IsgProject::create(['tenant_id' => 1, 'name' => 'Mine trashed'])->delete();
    IsgProject::create(['tenant_id' => 2, 'name' => 'Theirs trashed'])->delete();

    expect(isgNames(isgIndex('?trashed=only')))->toBe(['Mine trashed'])
        ->and(isgNames(isgIndex()))->toBe(['Mine active']);
});

// ── Index: a filter's and searchQuery()'s own orWhere() ─────────────────────

it('keeps a filter written with orWhere() inside the resource scopes()', function () {
    IsgProject::create(['tenant_id' => 1, 'name' => 'Mine open', 'status' => 'open']);
    IsgProject::create(['tenant_id' => 1, 'name' => 'Mine closed', 'status' => 'closed']);
    IsgProject::create(['tenant_id' => 2, 'name' => 'Theirs urgent', 'status' => 'urgent']);

    $filters = urlencode(json_encode(['open-or-urgent' => 'yes']));

    expect(isgNames(isgIndex("?filters={$filters}")))->toBe(['Mine open']);
});

it('keeps a searchQuery() written with orWhere() inside the resource scopes()', function () {
    IsgContext::$searchQuery = fn (Builder $query, string $search): Builder => $query
        ->where('name', 'like', "%{$search}%")->orWhere('status', $search);
    IsgProject::create(['tenant_id' => 1, 'name' => 'Apollo mine']);
    IsgProject::create(['tenant_id' => 2, 'name' => 'Theirs', 'status' => 'Apollo']);

    expect(isgNames(isgIndex('?search=Apollo')))->toBe(['Apollo mine']);
});

// ── Menu badge ──────────────────────────────────────────────────────────────

it('counts on the menu badge the records the index lists under a scopes() orWhere()', function () {
    isgTenantOrShared();
    IsgProject::create(['tenant_id' => 1, 'name' => 'Mine']);
    IsgProject::create(['tenant_id' => 2, 'name' => 'Shared', 'shared' => true]);
    IsgProject::create(['tenant_id' => 2, 'name' => 'Shared trashed', 'shared' => true])->delete();
    IsgProject::create(['tenant_id' => 2, 'name' => 'Theirs']);

    expect(IsgProjectResource::menuCount(request()))->toBe(2)
        ->and(isgIndex()->json('meta.total'))->toBe(2);
});

// ── Lens ────────────────────────────────────────────────────────────────────

it('applies a lens filter to every record the lens query orWhere() keeps', function () {
    IsgProject::create(['tenant_id' => 1, 'name' => 'Open one', 'status' => 'open']);
    IsgProject::create(['tenant_id' => 1, 'name' => 'Shared one', 'status' => 'closed', 'shared' => true]);
    IsgProject::create(['tenant_id' => 2, 'name' => 'Open two', 'status' => 'open']);
    IsgProject::create(['tenant_id' => 2, 'name' => 'Closed two', 'status' => 'closed']);

    $filters = urlencode(json_encode(['tenant' => 1]));

    expect(isgNames($this->getJson("/martis/api/resources/isg-projects/lenses/isg-open-or-shared?filters={$filters}")))
        ->toBe(['Open one', 'Shared one']);
});

// ── Relationship pickers ────────────────────────────────────────────────────

function isgOwners(): void
{
    IsgOwner::query()->insert([
        ['id' => 1, 'tenant_id' => 1, 'name' => 'Ana', 'active' => true],
        ['id' => 2, 'tenant_id' => 1, 'name' => 'Bruno', 'active' => false],
        ['id' => 3, 'tenant_id' => 2, 'name' => 'Carla', 'active' => true],
        ['id' => 4, 'tenant_id' => 2, 'name' => 'Vera', 'active' => false],
    ]);
}

function isgPicker(string $query = ''): TestResponse
{
    return test()->getJson('/martis/api/resources/isg-tasks/_/relatable/owner_id'.$query);
}

it('keeps a source relatable{Models}() orWhere() inside the target relatableQuery() fence', function () {
    isgOwners();
    IsgContext::$sourceHook = fn (Builder $query): Builder => $query->where('active', true)->orWhere('name', 'like', 'V%');

    expect(isgNames(isgPicker()))->toBe(['Ana']);
});

it('keeps a field relatableQueryUsing() orWhere() inside the resource-level fences', function () {
    isgOwners();
    IsgContext::$fieldClosure = fn (Builder $query): Builder => $query->where('active', true)->orWhere('name', 'like', 'V%');

    expect(isgNames(isgPicker()))->toBe(['Ana']);
});

it('applies the picker search term to every record a relatableQuery() orWhere() keeps', function () {
    isgOwners();
    IsgContext::$fence = fn (Builder $query): Builder => $query->where('tenant_id', 1)->orWhere('name', 'Vera');

    expect(isgNames(isgPicker('?search=a')))->toBe(['Ana', 'Vera'])
        ->and(isgNames(isgPicker('?search=Bru')))->toBe(['Bruno']);
});

it('keeps an attachable picker closure orWhere() inside the target relatableQuery() fence', function (string $type, string $relationship) {
    isgOwners();
    $team = IsgTeam::create(['name' => 'Core']);
    IsgContext::$fieldClosure = fn (Builder $query): Builder => $query->where('active', true)->orWhere('name', 'like', 'V%');

    $names = isgNames($this->getJson("/martis/api/resources/isg-teams/{$team->id}/{$type}/{$relationship}/attachable"));

    expect($names)->toBe(['Ana']);
})->with([
    'belongs-to-many' => ['belongs-to-many', 'members'],
    'morph-to-many' => ['morph-to-many', 'labels'],
]);
