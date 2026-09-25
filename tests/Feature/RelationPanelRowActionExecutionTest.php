<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough as EloquentHasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Fields\HasMany;
use Martis\Fields\HasManyThrough;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * A row action a relationship panel (or the index) offers runs on the
 * records of the run that resolve, as Nova does, and answers an error when
 * none does or none is named: never a silent 200 that handled nothing. The
 * per-row map and the run share one predicate (the action's canRun and the
 * resource's runAction / runDestructiveAction policy), a standalone action
 * runs on no record, and with `viaResource`, `viaResourceId` and
 * `viaRelationship` (sent by the panel, as Nova does) only a record the
 * relationship reaches is run on.
 */

class RPXOwnerModel extends Model
{
    protected $table = 'rpx_owners';

    protected $fillable = ['name'];

    public function tasks(): EloquentHasMany
    {
        return $this->hasMany(RPXTaskModel::class, 'owner_id');
    }

    public function stepTasks(): EloquentHasManyThrough
    {
        return $this->hasManyThrough(RPXTaskModel::class, RPXStepModel::class, 'owner_id', 'step_id');
    }

    // Declared on the resource; its order and limit must not reach the key
    // subquery of a run through it.
    public function latestTasks(): EloquentHasMany
    {
        return $this->hasMany(RPXTaskModel::class, 'owner_id')->latest('id')->limit(1);
    }

    // A real relation no field of the resource declares.
    public function undeclaredTasks(): EloquentHasMany
    {
        return $this->hasMany(RPXTaskModel::class, 'owner_id');
    }

    // Listed by a resource with no action.
    public function plainTasks(): EloquentHasMany
    {
        return $this->hasMany(RPXTaskModel::class, 'owner_id');
    }
}

class RPXStepModel extends Model
{
    protected $table = 'rpx_steps';

    protected $fillable = ['owner_id'];
}

class RPXTaskModel extends Model
{
    use SoftDeletes;

    protected $table = 'rpx_tasks';

    protected $fillable = ['title', 'owner_id', 'step_id'];
}

class RPXRunLog
{
    /** @var list<list<int>> */
    public static array $runs = [];

    public static int $policyCalls = 0;
}

class RPXCloseAction extends Action
{
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        RPXRunLog::$runs[] = $models->map(fn (Model $m) => (int) $m->getKey())->sort()->values()->all();

        return null;
    }

    public function uriKey(): string
    {
        return 'rpx-close';
    }
}

class RPXReportAction extends RPXCloseAction
{
    public function uriKey(): string
    {
        return 'rpx-report';
    }
}

class RPXBulkAction extends RPXCloseAction
{
    public function uriKey(): string
    {
        return 'rpx-bulk';
    }
}

class RPXTaskResource extends Resource
{
    public static function model(): string
    {
        return RPXTaskModel::class;
    }

    public static function uriKey(): string
    {
        return 'rpx-tasks';
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query->where('title', '!=', 'Hidden');
    }

    // The tenant scope, as the index applies it.
    public static function scopes(Request $request): array
    {
        return [fn (Builder $query) => $query->where('title', '!=', 'Other tenant')];
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')];
    }

    public function actions(Request $request): array
    {
        return [
            (new RPXCloseAction)->showInline(),
            (new RPXReportAction)->showInline()->standalone(),
            new RPXBulkAction,
        ];
    }

    // The policy execute() checks: runAction is denied on "No policy".
    public function authorizedToRunAction(Request $request): bool
    {
        RPXRunLog::$policyCalls++;

        return $this->model?->getAttribute('title') !== 'No policy';
    }
}

// The same records under another key: its actions do not run through a
// relationship that lists `rpx-tasks`.
class RPXTaskCopyResource extends RPXTaskResource
{
    public static function uriKey(): string
    {
        return 'rpx-task-copies';
    }
}

class RPXPlainTaskResource extends Resource
{
    public static function model(): string
    {
        return RPXTaskModel::class;
    }

    public static function uriKey(): string
    {
        return 'rpx-plain-tasks';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')];
    }
}

class RPXOwnerResource extends Resource
{
    public static function model(): string
    {
        return RPXOwnerModel::class;
    }

    public static function uriKey(): string
    {
        return 'rpx-owners';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            HasMany::make('Tasks', 'tasks')->relatedResource('rpx-tasks'),
            HasManyThrough::make('Step tasks', 'stepTasks')->relatedResource('rpx-tasks'),
            HasMany::make('Latest task', 'latestTasks')->relatedResource('rpx-tasks'),
            HasMany::make('Plain tasks', 'plainTasks')->relatedResource('rpx-plain-tasks'),
        ];
    }

    public function authorizedToView(Request $request): bool
    {
        return $this->model?->getAttribute('name') !== 'Private';
    }
}

// The same owners under a resource the user may not list.
class RPXLockedOwnerResource extends RPXOwnerResource
{
    public static function uriKey(): string
    {
        return 'rpx-locked-owners';
    }

    public function authorizedToViewAny(Request $request): bool
    {
        return false;
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);
    RPXRunLog::$runs = [];
    RPXRunLog::$policyCalls = 0;

    foreach (['rpx_tasks', 'rpx_steps', 'rpx_owners'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('rpx_owners', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    Schema::create('rpx_steps', function ($table) {
        $table->id();
        $table->unsignedBigInteger('owner_id');
        $table->timestamps();
    });
    Schema::create('rpx_tasks', function ($table) {
        $table->id();
        $table->unsignedBigInteger('owner_id')->nullable();
        $table->unsignedBigInteger('step_id')->nullable();
        $table->string('title');
        $table->timestamps();
        $table->softDeletes();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    foreach ([RPXTaskResource::class, RPXTaskCopyResource::class, RPXPlainTaskResource::class, RPXOwnerResource::class, RPXLockedOwnerResource::class] as $class) {
        $registry->register($class);
    }

    $this->owner = RPXOwnerModel::create(['name' => 'Owner']);
    $this->other = RPXOwnerModel::create(['name' => 'Other']);
    // Step ids differ from task ids, so a row keyed on the step would show.
    $step = RPXStepModel::forceCreate(['id' => 40, 'owner_id' => $this->owner->id]);

    $this->open = RPXTaskModel::create(['title' => 'Open', 'owner_id' => $this->owner->id, 'step_id' => $step->id]);
    $this->denied = RPXTaskModel::create(['title' => 'No policy', 'owner_id' => $this->owner->id]);
    $this->hidden = RPXTaskModel::create(['title' => 'Hidden', 'owner_id' => $this->owner->id]);
    $this->trashed = RPXTaskModel::create(['title' => 'Trashed', 'owner_id' => $this->owner->id]);
    $this->trashed->delete();
    $this->foreign = RPXTaskModel::create(['title' => 'Foreign', 'owner_id' => $this->other->id]);
    $this->tenant = RPXTaskModel::create(['title' => 'Other tenant', 'owner_id' => $this->owner->id]);
});

afterEach(function () {
    foreach (['rpx_tasks', 'rpx_steps', 'rpx_owners'] as $table) {
        Schema::dropIfExists($table);
    }
    app(ResourceRegistry::class)->flush();
});

function rpxRun(array $body, string $action = 'rpx-close'): TestResponse
{
    return test()->postJson("/martis/api/resources/rpx-tasks/actions/{$action}", $body);
}

function rpxVia(string $relationship = 'tasks'): array
{
    return ['viaResource' => 'rpx-owners', 'viaResourceId' => test()->owner->id, 'viaRelationship' => $relationship];
}

it('marks a row action the resource policy denies as not runnable, on the panel and the index', function () {
    $panel = collect($this->getJson("/martis/api/resources/rpx-owners/{$this->owner->id}/has-many/tasks")->assertOk()->json('data'))->keyBy('title');
    $index = collect($this->getJson('/martis/api/resources/rpx-tasks')->assertOk()->json('data'))->keyBy('title');

    expect($panel['Open']['_actionAuthorization']['rpx-close'])->toBeTrue()
        ->and($panel['No policy']['_actionAuthorization']['rpx-close'])->toBeFalse()
        ->and($index['No policy']['_actionAuthorization']['rpx-close'])->toBeFalse();

    rpxRun(['resources' => [$this->denied->id]])->assertStatus(404);
    expect(RPXRunLog::$runs)->toBe([]);
});

it('maps the inline actions on a panel row, a standalone one by canRun alone', function () {
    $rows = collect($this->getJson("/martis/api/resources/rpx-owners/{$this->owner->id}/has-many/tasks")->json('data'))->keyBy('title');

    expect($rows['Open']['_actionAuthorization'])->toBe(['rpx-close' => true, 'rpx-report' => true])
        ->and($rows['No policy']['_actionAuthorization'])->toBe(['rpx-close' => false, 'rpx-report' => true]);
});

it('carries no action map on a panel whose related resource has no inline action', function () {
    $rows = $this->getJson("/martis/api/resources/rpx-owners/{$this->owner->id}/has-many/plainTasks")->assertOk()->json('data');

    expect($rows)->not->toBeEmpty();
    foreach ($rows as $row) {
        expect($row)->not->toHaveKey('_actionAuthorization');
    }
});

it('asks the run-action policy once per row, not once per action', function (string $path) {
    $rows = $this->getJson(str_replace('{owner}', (string) $this->owner->id, $path))->assertOk()->json('data');

    expect($rows)->not->toBeEmpty()
        ->and(RPXRunLog::$policyCalls)->toBe(count($rows));
})->with([
    'index' => ['/martis/api/resources/rpx-tasks'],
    'panel' => ['/martis/api/resources/rpx-owners/{owner}/has-many/tasks'],
]);

it('keys a hasManyThrough row\'s action map on the record it lists', function () {
    $rows = $this->getJson("/martis/api/resources/rpx-owners/{$this->owner->id}/has-many/stepTasks")->assertOk()->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe($this->open->id)
        ->and($rows[0]['_actionAuthorization'])->toBe(['rpx-close' => true, 'rpx-report' => true]);

    rpxRun(['resources' => [$rows[0]['id']]] + rpxVia('stepTasks'))->assertOk();
    expect(RPXRunLog::$runs)->toBe([[$this->open->id]]);
});

it('refuses a run when none of its ids resolves, and handles nothing', function (string $record) {
    rpxRun(['resources' => [$record === 'forged' ? 999 : $this->{$record}->id]])
        ->assertStatus(404)
        ->assertJsonPath('message', 'One or more selected resources could not be found.');

    expect(RPXRunLog::$runs)->toBe([]);
})->with(['a row outside the index scope' => ['hidden'], 'a forged id' => ['forged']]);

it('runs on the ids that resolve when only some do, as Nova does', function () {
    rpxRun(['resources' => [$this->open->id, $this->hidden->id, 999]])->assertOk();

    expect(RPXRunLog::$runs)->toBe([[$this->open->id]]);
});

it('runs on a trashed row, which the panel and the index list with trashed=with', function () {
    rpxRun(['resources' => [$this->trashed->id]])->assertOk();

    expect(RPXRunLog::$runs)->toBe([[$this->trashed->id]]);
});

it('runs a standalone action on no record, whatever ids are sent', function () {
    rpxRun(['resources' => [$this->open->id, 999]], 'rpx-report')->assertOk();

    expect(RPXRunLog::$runs)->toBe([[]]);
});

it('runs only on a record the relationship reaches when the panel names it', function () {
    rpxRun(['resources' => [$this->open->id]] + rpxVia())->assertOk();
    rpxRun(['resources' => [$this->foreign->id]] + rpxVia())->assertStatus(404);
    rpxRun(['resources' => [$this->foreign->id]] + rpxVia('stepTasks'))->assertStatus(404);

    expect(RPXRunLog::$runs)->toBe([[$this->open->id]]);
});

it('refuses a relationship the parent resource does not declare', function () {
    rpxRun(['resources' => [$this->open->id]] + ['viaResource' => 'rpx-owners', 'viaResourceId' => $this->owner->id, 'viaRelationship' => 'nope'])
        ->assertStatus(404);
    rpxRun(['resources' => [$this->open->id]] + ['viaResource' => 'rpx-owners', 'viaResourceId' => 999, 'viaRelationship' => 'tasks'])
        ->assertStatus(404);

    expect(RPXRunLog::$runs)->toBe([]);
});

it('refuses a run that names no record for an action that runs on records', function (array $body) {
    rpxRun($body)
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'resources');

    expect(RPXRunLog::$runs)->toBe([]);
})->with(['an empty selection' => [['resources' => []]], 'no selection' => [[]]]);

it('does not resolve a record the resource\'s scopes() keep out, as the index does not list it', function () {
    rpxRun(['resources' => [$this->tenant->id]])->assertStatus(404);

    expect(RPXRunLog::$runs)->toBe([]);
});

it('refuses a relationship name that is a method of the parent but no declared relationship, and leaves the parent', function (string $relationship) {
    rpxRun(['resources' => [$this->open->id]] + rpxVia($relationship))->assertStatus(404);

    expect(RPXOwnerModel::query()->whereKey($this->owner->id)->exists())->toBeTrue()
        ->and(RPXRunLog::$runs)->toBe([]);
})->with(['a model method' => ['delete'], 'an undeclared relation' => ['undeclaredTasks']]);

it('reaches every record of a relationship whose query orders and limits', function () {
    // latestTasks() keeps only the newest task; the run keys on the whole
    // relationship, as MySQL refuses a LIMIT in the IN subquery.
    rpxRun(['resources' => [$this->open->id]] + rpxVia('latestTasks'))->assertOk();

    expect(RPXRunLog::$runs)->toBe([[$this->open->id]]);
});

it('runs through the relationship on a trashed record it holds', function () {
    rpxRun(['resources' => [$this->trashed->id]] + rpxVia())->assertOk();

    expect(RPXRunLog::$runs)->toBe([[$this->trashed->id]]);
});

it('gates the parent a run goes through as its panel is gated', function (Closure $via, int $status) {
    rpxRun(['resources' => [$this->open->id]] + $via($this))->assertStatus($status);

    expect(RPXRunLog::$runs)->toBe([]);
})->with([
    'the parent resource denies viewAny' => [fn ($t) => ['viaResource' => 'rpx-locked-owners', 'viaResourceId' => $t->owner->id, 'viaRelationship' => 'tasks'], 403],
    'the parent record denies view' => [fn ($t) => ['viaResource' => 'rpx-owners', 'viaResourceId' => RPXOwnerModel::create(['name' => 'Private'])->id, 'viaRelationship' => 'tasks'], 403],
]);

it('refuses a relationship that lists another resource than the action\'s', function () {
    test()->postJson('/martis/api/resources/rpx-task-copies/actions/rpx-close', ['resources' => [$this->open->id]] + rpxVia())
        ->assertStatus(404);

    expect(RPXRunLog::$runs)->toBe([]);
});
