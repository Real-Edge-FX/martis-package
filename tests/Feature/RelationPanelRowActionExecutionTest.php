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
 * none does: never a silent 200 that handled nothing. The per-row map and the run share one predicate (the
 * action's canRun and the resource's runAction / runDestructiveAction
 * policy), a standalone action runs on no record, and with `viaResource`,
 * `viaResourceId` and `viaRelationship` (sent by the panel, as Nova does)
 * only a record the relationship reaches is run on.
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
        return $this->model?->getAttribute('title') !== 'No policy';
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
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);
    RPXRunLog::$runs = [];

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
    $registry->register(RPXTaskResource::class);
    $registry->register(RPXOwnerResource::class);

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

it('maps only the inline actions that run on a record on a panel row', function () {
    $row = collect($this->getJson("/martis/api/resources/rpx-owners/{$this->owner->id}/has-many/tasks")->json('data'))->firstWhere('title', 'Open');

    expect(array_keys($row['_actionAuthorization']))->toBe(['rpx-close']);
});

it('keys a hasManyThrough row\'s action map on the record it lists', function () {
    $rows = $this->getJson("/martis/api/resources/rpx-owners/{$this->owner->id}/has-many/stepTasks")->assertOk()->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe($this->open->id)
        ->and($rows[0]['_actionAuthorization'])->toBe(['rpx-close' => true]);

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
