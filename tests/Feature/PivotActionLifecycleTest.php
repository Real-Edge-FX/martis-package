<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany as EloquentMorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Actions\DestructiveAction;
use Martis\Actions\Jobs\ExecutePivotAction;
use Martis\Fields\BelongsToMany;
use Martis\Fields\MorphToMany;
use Martis\Fields\Select;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Models\ActionEvent;
use Martis\Resource;
use Martis\ResourceRegistry;

// ===========================================================================
// Pivot actions run like resource actions (v1.38.0): a dry run answers with
// the action's preview, a ShouldQueue action is dispatched as a job that
// reloads the rows through the relationship (pivot included), and every run
// writes the action event log, with the parent record as actionable, the
// related record as target and the pivot row as model (Nova's mapping).
//
// Before, runPivotAction() always called handle() inline: a queued pivot
// action ran synchronously, the dry-run flag was ignored and no event was
// written.
// ===========================================================================

class PALParentModel extends Model
{
    protected $table = 'pal_parents';

    protected $guarded = [];

    public $timestamps = false;

    public function tags(): EloquentBelongsToMany
    {
        return $this->belongsToMany(PALTagModel::class, 'pal_parent_tag', 'parent_id', 'tag_id');
    }

    public function labels(): EloquentMorphToMany
    {
        return $this->morphToMany(PALTagModel::class, 'taggable', 'pal_taggables', null, 'tag_id');
    }
}

class PALTagModel extends Model
{
    protected $table = 'pal_tags';

    protected $guarded = [];

    public $timestamps = false;
}

class PALSetPriority extends Action
{
    public ?string $name = 'Set Priority';

    /** @param Collection<int, Model> $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        foreach ($models as $tag) {
            $tag->pivot->priority = $fields->get('priority');
            $tag->pivot->save();
        }

        return ActionResponse::message('Priority set.');
    }

    /**
     * @param  Collection<int, Model>  $models
     * @return array<string, mixed>
     */
    public function dryRun(ActionFields $fields, Collection $models): array
    {
        return [
            'preview' => "Would set {$models->count()} row(s) to {$fields->get('priority')}.",
            'current' => $models->map(fn (Model $tag): string => (string) $tag->pivot->priority)->all(),
        ];
    }

    public function fields(Request $request): array
    {
        return [Select::make('priority')->options(['low' => 'Low', 'high' => 'High'])->required()];
    }
}

class PALQueuedSetPriority extends PALSetPriority implements ShouldQueue
{
    public ?string $name = 'Queued Priority';
}

class PALSilentSetPriority extends PALSetPriority
{
    public function uriKey(): string
    {
        return 'silent-priority';
    }
}

class PALRemoveRows extends DestructiveAction
{
    public ?string $name = 'Remove Rows';

    /** @param Collection<int, Model> $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        return ActionResponse::message('Removed.');
    }
}

/** What the current user may do on the parent record, per test. */
class PALParentPolicy
{
    /** @var array<string, bool> */
    public static array $allow = [];

    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, Model $parent): bool
    {
        return true;
    }

    public function update(?User $user, Model $parent): bool
    {
        return self::$allow['update'] ?? true;
    }

    public function delete(?User $user, Model $parent): bool
    {
        return self::$allow['delete'] ?? true;
    }
}

class PALFailingAction extends Action
{
    public ?string $name = 'Failing';

    /** @param Collection<int, Model> $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        throw new RuntimeException('Pivot action exploded.');
    }
}

class PALParentResource extends Resource
{
    public static function model(): string
    {
        return PALParentModel::class;
    }

    public static function uriKey(): string
    {
        return 'pal-parents';
    }

    public function fields(Request $request): array
    {
        $actions = fn () => [
            PALSetPriority::make()->withDryRun(),
            PALQueuedSetPriority::make(),
            PALFailingAction::make(),
            PALSilentSetPriority::make()->withoutActionEvents(),
            PALRemoveRows::make(),
        ];

        $pivotFields = fn () => [Text::make('priority')->nullable()];

        return [
            Text::make('name'),
            BelongsToMany::make('Tags', 'tags')->relatedResource('pal-tags')->fields($pivotFields)->actions($actions),
            MorphToMany::make('Labels', 'labels')->relatedResource('pal-tags')->fields($pivotFields)->actions($actions),
        ];
    }
}

class PALTagResource extends Resource
{
    public static function model(): string
    {
        return PALTagModel::class;
    }

    public static function uriKey(): string
    {
        return 'pal-tags';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

function palRelation(string $endpoint): string
{
    return $endpoint === 'belongs-to-many' ? 'tags' : 'labels';
}

function palTable(string $endpoint): string
{
    return $endpoint === 'belongs-to-many' ? 'pal_parent_tag' : 'pal_taggables';
}

function palRunUrl(PALParentModel $parent, string $endpoint, string $action): string
{
    return "/martis/api/resources/pal-parents/{$parent->id}/{$endpoint}/".palRelation($endpoint)."/actions/{$action}";
}

/** @return list<string> */
function palPriorities(string $endpoint): array
{
    return DB::table(palTable($endpoint))->orderBy('tag_id')->pluck('priority')->map(fn ($p) => (string) $p)->all();
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    foreach (['pal_taggables', 'pal_parent_tag', 'pal_tags', 'pal_parents', 'martis_action_events'] as $table) {
        Schema::dropIfExists($table);
    }

    // The shape of stubs/create_martis_action_events_table.php.stub.
    Schema::create('martis_action_events', function ($table) {
        $table->id();
        $table->string('batch_id')->index();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('name');
        $table->string('actionable_type')->nullable();
        $table->string('actionable_id')->nullable();
        $table->string('target_type')->nullable();
        $table->string('target_id')->nullable();
        $table->string('model_type')->nullable();
        $table->string('model_id')->nullable();
        $table->text('fields');
        $table->string('status', 25)->default('running');
        $table->text('exception');
        $table->text('original');
        $table->text('changes');
        $table->timestamps();
    });

    Schema::create('pal_parents', function ($table) {
        $table->id();
        $table->string('name')->nullable();
    });
    Schema::create('pal_tags', function ($table) {
        $table->id();
        $table->string('name');
    });
    Schema::create('pal_parent_tag', function ($table) {
        $table->unsignedBigInteger('parent_id');
        $table->unsignedBigInteger('tag_id');
        $table->string('priority')->nullable();
    });
    Schema::create('pal_taggables', function ($table) {
        $table->unsignedBigInteger('tag_id');
        $table->morphs('taggable');
        $table->string('priority')->nullable();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(PALParentResource::class);
    $registry->register(PALTagResource::class);

    $this->parent = PALParentModel::create(['name' => 'Parent']);
    $this->first = PALTagModel::create(['name' => 'First']);
    $this->second = PALTagModel::create(['name' => 'Second']);

    foreach (['tags', 'labels'] as $relation) {
        $this->parent->{$relation}()->attach($this->first->id, ['priority' => 'low']);
        $this->parent->{$relation}()->attach($this->second->id, ['priority' => 'low']);
    }
});

afterEach(function () {
    PALParentPolicy::$allow = [];

    foreach (['pal_taggables', 'pal_parent_tag', 'pal_tags', 'pal_parents', 'martis_action_events'] as $table) {
        Schema::dropIfExists($table);
    }
});

$pivotEndpoints = ['belongs-to-many', 'morph-to-many'];

it('logs a completed event per pivot row: parent as actionable, related record as target, pivot as model', function (string $endpoint) {
    $this->postJson(palRunUrl($this->parent, $endpoint, 'p-a-l-set-priority'), [
        'resources' => [$this->first->id, $this->second->id],
        'fields' => ['priority' => 'high'],
    ])->assertOk()->assertJsonPath('data.data.message', 'Priority set.');

    expect(palPriorities($endpoint))->toBe(['high', 'high']);

    $events = ActionEvent::query()->orderBy('target_id')->get();
    expect($events)->toHaveCount(2);

    foreach ([$this->first, $this->second] as $index => $tag) {
        $event = $events[$index];
        expect($event->name)->toBe('Set Priority')
            ->and($event->status)->toBe('completed')
            ->and($event->actionable_type)->toBe(PALParentModel::class)
            ->and((string) $event->actionable_id)->toBe((string) $this->parent->id)
            ->and($event->target_type)->toBe(PALTagModel::class)
            ->and((string) $event->target_id)->toBe((string) $tag->id)
            ->and(is_a($event->model_type, Pivot::class, true))->toBeTrue()
            ->and($event->original)->toBe(['priority' => 'low'])
            ->and($event->changes)->toBe(['priority' => 'high'])
            ->and($event->fields)->toBe(['priority' => 'high']);
    }
})->with($pivotEndpoints);

it('runs a pivot action only when the parent record passes the policy a resource action checks', function (string $endpoint) {
    Gate::policy(PALParentModel::class, PALParentPolicy::class);
    $this->actingAs((new User)->forceFill(['id' => 7]));
    PALParentPolicy::$allow = ['update' => false, 'delete' => true];

    $this->postJson(palRunUrl($this->parent, $endpoint, 'p-a-l-set-priority'), [
        'resources' => [$this->first->id],
        'fields' => ['priority' => 'high'],
    ])->assertNotFound();

    expect(palPriorities($endpoint))->toBe(['low', 'low']);

    // A destructive pivot action asks for delete (runDestructiveAction), not update.
    $this->postJson(palRunUrl($this->parent, $endpoint, 'p-a-l-remove-rows'), [
        'resources' => [$this->first->id],
    ])->assertOk();

    PALParentPolicy::$allow = ['update' => true, 'delete' => false];

    $this->postJson(palRunUrl($this->parent, $endpoint, 'p-a-l-remove-rows'), [
        'resources' => [$this->first->id],
    ])->assertNotFound();

    $this->postJson(palRunUrl($this->parent, $endpoint, 'p-a-l-set-priority'), [
        'resources' => [$this->first->id],
        'fields' => ['priority' => 'high'],
    ])->assertOk();

    expect(palPriorities($endpoint))->toBe(['high', 'low']);
})->with($pivotEndpoints);

it('logs a failed event with the exception message when the pivot action throws', function (string $endpoint) {
    $this->postJson(palRunUrl($this->parent, $endpoint, 'p-a-l-failing-action'), [
        'resources' => [$this->first->id],
    ])->assertStatus(500);

    $event = ActionEvent::query()->sole();
    expect($event->status)->toBe('failed')
        ->and($event->exception)->toBe('Pivot action exploded.')
        ->and((string) $event->target_id)->toBe((string) $this->first->id);
})->with($pivotEndpoints);

it('writes no event for a pivot action that opts out, nor when the log is disabled', function (string $endpoint) {
    $this->postJson(palRunUrl($this->parent, $endpoint, 'silent-priority'), [
        'resources' => [$this->first->id],
        'fields' => ['priority' => 'high'],
    ])->assertOk();

    config()->set('martis.action_events.enabled', false);
    $this->postJson(palRunUrl($this->parent, $endpoint, 'p-a-l-set-priority'), [
        'resources' => [$this->second->id],
        'fields' => ['priority' => 'high'],
    ])->assertOk();

    expect(palPriorities($endpoint))->toBe(['high', 'high'])
        ->and(ActionEvent::query()->count())->toBe(0);
})->with($pivotEndpoints);

it('answers a dry run with the preview and runs nothing', function (string $endpoint) {
    $response = $this->postJson(palRunUrl($this->parent, $endpoint, 'p-a-l-set-priority'), [
        'resources' => [$this->first->id, $this->second->id],
        'fields' => ['priority' => 'high'],
        'dryRun' => true,
    ])->assertOk();

    expect($response->json('data.preview'))->toBe([
        'preview' => 'Would set 2 row(s) to high.',
        'current' => ['low', 'low'],
    ])
        ->and(palPriorities($endpoint))->toBe(['low', 'low'])
        ->and(ActionEvent::query()->count())->toBe(0);
})->with($pivotEndpoints);

it('dispatches a ShouldQueue pivot action as a job and logs it as queued', function (string $endpoint) {
    Queue::fake();

    $this->postJson(palRunUrl($this->parent, $endpoint, 'p-a-l-queued-set-priority'), [
        'resources' => [$this->first->id, $this->second->id],
        'fields' => ['priority' => 'high'],
    ])->assertOk()->assertJsonPath('data.data.message', 'Action has been queued for processing.');

    expect(palPriorities($endpoint))->toBe(['low', 'low']);

    Queue::assertPushed(ExecutePivotAction::class, function (ExecutePivotAction $job) use ($endpoint): bool {
        return $job->actionClass === PALQueuedSetPriority::class
            && $job->parentModelClass === PALParentModel::class
            && (string) $job->parentId === (string) $this->parent->id
            && $job->relationship === palRelation($endpoint)
            && $job->relatedIds === [$this->first->id, $this->second->id]
            && $job->fields === ['priority' => 'high'];
    });

    expect(ActionEvent::query()->where('status', 'queued')->count())->toBe(2);
})->with($pivotEndpoints);

it('runs a queued pivot action on the pivot rows and completes its events', function (string $endpoint) {
    config()->set('queue.default', 'sync');

    $this->postJson(palRunUrl($this->parent, $endpoint, 'p-a-l-queued-set-priority'), [
        'resources' => [$this->first->id, $this->second->id],
        'fields' => ['priority' => 'high'],
    ])->assertOk();

    expect(palPriorities($endpoint))->toBe(['high', 'high']);

    $events = ActionEvent::query()->orderBy('target_id')->get();
    expect($events)->toHaveCount(2)
        ->and($events->pluck('status')->unique()->all())->toBe(['completed'])
        ->and($events[0]->original)->toBe(['priority' => 'low'])
        ->and($events[0]->changes)->toBe(['priority' => 'high']);
})->with($pivotEndpoints);
