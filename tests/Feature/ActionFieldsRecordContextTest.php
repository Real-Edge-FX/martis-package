<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Fields\BelongsTo;
use Martis\Fields\BelongsToMany;
use Martis\Fields\HasMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Http\Requests\LensRequest;
use Martis\Lenses\Lens;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * An Action reads the record it runs on in `fields()` through
 * `selectedModel()`: the fields endpoint takes the record the modal is opened
 * for as `resourceId` and resolves it with exactly the run's lookup (the
 * index scopes and `indexQuery()`, the lens query, the relationship panel the
 * `via*` params name, or the relationship of a pivot panel) and the run's
 * authorization (canRun, the runAction policy): a record the run would not
 * resolve or would refuse is a 404, never served. The run sets the same
 * record when it runs on exactly one, so the fields it validates are the
 * fields the modal showed. A bulk run, a standalone action and a request that
 * names no record see no record.
 */

// ── Fixtures ────────────────────────────────────────────────────────────────

class ActionCtxItem extends Model
{
    use SoftDeletes;

    protected $table = 'action_ctx_items';

    protected $fillable = ['name', 'tenant_id', 'owner_id', 'locked'];

    public function tags(): EloquentBelongsToMany
    {
        return $this->belongsToMany(ActionCtxTag::class, 'action_ctx_item_tag', 'item_id', 'tag_id')->withPivot(['weight']);
    }
}

class ActionCtxOwner extends Model
{
    protected $table = 'action_ctx_owners';

    protected $fillable = ['name'];

    public function items(): EloquentHasMany
    {
        return $this->hasMany(ActionCtxItem::class, 'owner_id');
    }
}

class ActionCtxTag extends Model
{
    protected $table = 'action_ctx_tags';

    protected $fillable = ['name'];
}

class ActionCtxLog
{
    /** @var list<int|string|null> The key of the record each `fields()` call saw, null for none. */
    public static array $seen = [];

    /** @var list<int> The number of records each run handled. */
    public static array $ran = [];
}

/** Builds its fields from the record it runs on. */
class ActionCtxProbe extends Action
{
    /** @param Collection<int, Model> $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        ActionCtxLog::$ran[] = $models->count();

        return ActionResponse::message('Done.');
    }

    public function fields(Request $request): array
    {
        $model = $this->selectedModel();
        ActionCtxLog::$seen[] = $model?->getKey();

        return [
            Text::make('note', $model !== null ? 'Note of '.$model->getAttribute('name') : 'No record')
                ->default($model?->getAttribute('name') ?? 'no record'),
            Text::make('fixed')->default('fixed value'),
            Text::make('lazy')->default(fn () => 'lazy value'),
        ];
    }
}

/** A probe that cannot run on a locked record. */
class ActionCtxGated extends ActionCtxProbe
{
    public function __construct()
    {
        $this->canRun(fn (Request $request, Model $model): bool => ! $model->getAttribute('locked'));
    }
}

class ActionCtxHidden extends ActionCtxProbe {}

class ActionCtxStandalone extends ActionCtxProbe {}

/** A probe a many-to-many panel runs: it sees the related record with its pivot. */
class ActionCtxPivotProbe extends ActionCtxProbe
{
    public function fields(Request $request): array
    {
        $model = $this->selectedModel();
        ActionCtxLog::$seen[] = $model?->getKey();

        return [
            Text::make('weight', 'Weight')->default($model !== null ? (string) ($model->pivot->weight ?? 'no pivot') : 'no record'),
        ];
    }
}

/** A picker scoped by the record the modal runs on: only that record's tags. */
class ActionCtxPicker extends Action
{
    /** @param Collection<int, Model> $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        return ActionResponse::message('Done.');
    }

    public function fields(Request $request): array
    {
        return [
            BelongsTo::make('tag', 'Tag')
                ->relatedResource('action-ctx-tags')
                ->titleAttribute('name')
                ->relatableQueryUsing(function (Request $request, Builder $query): Builder {
                    $model = $this->selectedModel();
                    ActionCtxLog::$seen[] = $model?->getKey();

                    // No record: nothing to offer, instead of a throw on null.
                    return $model instanceof ActionCtxItem
                        ? $query->whereIn('action_ctx_tags.id', $model->tags()->pluck('action_ctx_tags.id'))
                        : $query->whereRaw('1 = 0');
                }),
        ];
    }
}

class ActionCtxPivotPicker extends ActionCtxPicker
{
    public function fields(Request $request): array
    {
        return [
            BelongsTo::make('tag', 'Tag')
                ->relatedResource('action-ctx-tags')
                ->titleAttribute('name')
                ->relatableQueryUsing(function (Request $request, Builder $query): Builder {
                    $model = $this->selectedModel();
                    ActionCtxLog::$seen[] = $model?->getKey();

                    // Only the record the modal runs on.
                    return $model instanceof ActionCtxTag
                        ? $query->where('action_ctx_tags.id', $model->getKey())
                        : $query->whereRaw('1 = 0');
                }),
        ];
    }
}

class ActionCtxLens extends Lens
{
    public function query(LensRequest $request, Builder $query): Builder
    {
        return $query->where('name', '!=', 'Unlisted');
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }

    public function actions(Request $request): array
    {
        return [ActionCtxProbe::make(), ActionCtxPicker::make(), ActionCtxHidden::make()->canSee(fn (): bool => false)];
    }
}

class ActionCtxItemResource extends Resource
{
    public static function model(): string
    {
        return ActionCtxItem::class;
    }

    public static function uriKey(): string
    {
        return 'action-ctx-items';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    // The tenant scope, as the index applies it.
    public static function scopes(Request $request): array
    {
        return ['tenant' => fn (Builder $query): Builder => $query->where('tenant_id', 1)];
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query->where('name', '!=', 'Hidden');
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            BelongsToMany::make('Tags', 'tags')
                ->relatedResource('action-ctx-tags')
                ->fields(fn () => [Text::make('weight')->nullable()])
                ->actions(fn () => [ActionCtxPivotProbe::make(), ActionCtxPivotPicker::make(), ActionCtxHidden::make()->canSee(fn (): bool => false)]),
        ];
    }

    public function actions(Request $request): array
    {
        return [
            ActionCtxProbe::make()->showInline(),
            ActionCtxGated::make(),
            ActionCtxPicker::make(),
            ActionCtxHidden::make()->canSee(fn (): bool => false),
            ActionCtxStandalone::make()->standalone(),
        ];
    }

    public function lenses(Request $request): array
    {
        return [new ActionCtxLens];
    }

    // The policy the run checks: runAction is denied on "No policy".
    public function authorizedToRunAction(Request $request): bool
    {
        return $this->model?->getAttribute('name') !== 'No policy';
    }
}

class ActionCtxTagResource extends Resource
{
    public static function model(): string
    {
        return ActionCtxTag::class;
    }

    public static function uriKey(): string
    {
        return 'action-ctx-tags';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query->where('name', '!=', 'Secret');
    }
}

class ActionCtxOwnerResource extends Resource
{
    public static function model(): string
    {
        return ActionCtxOwner::class;
    }

    public static function uriKey(): string
    {
        return 'action-ctx-owners';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            HasMany::make('Items', 'items')->relatedResource('action-ctx-items'),
        ];
    }
}

// The same records under a resource the user may not list.
class ActionCtxLockedResource extends ActionCtxItemResource
{
    public static function uriKey(): string
    {
        return 'action-ctx-locked';
    }

    public function authorizedToViewAny(Request $request): bool
    {
        return false;
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);
    ActionCtxLog::$seen = [];
    ActionCtxLog::$ran = [];

    foreach (['action_ctx_item_tag', 'action_ctx_tags', 'action_ctx_items', 'action_ctx_owners'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('action_ctx_owners', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    Schema::create('action_ctx_items', function ($table) {
        $table->id();
        $table->string('name');
        $table->unsignedInteger('tenant_id')->default(1);
        $table->unsignedBigInteger('owner_id')->nullable();
        $table->boolean('locked')->default(false);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('action_ctx_tags', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    Schema::create('action_ctx_item_tag', function ($table) {
        $table->id();
        $table->unsignedBigInteger('item_id');
        $table->unsignedBigInteger('tag_id');
        $table->string('weight')->nullable();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    foreach ([ActionCtxItemResource::class, ActionCtxTagResource::class, ActionCtxOwnerResource::class, ActionCtxLockedResource::class] as $class) {
        $registry->register($class);
    }

    $this->owner = ActionCtxOwner::create(['name' => 'Owner']);
    $this->otherOwner = ActionCtxOwner::create(['name' => 'Other owner']);

    $this->ada = ActionCtxItem::create(['name' => 'Ada', 'owner_id' => $this->owner->id]);
    $this->bob = ActionCtxItem::create(['name' => 'Bob', 'owner_id' => $this->owner->id]);
    $this->foreign = ActionCtxItem::create(['name' => 'Foreign', 'owner_id' => $this->otherOwner->id]);
    $this->hidden = ActionCtxItem::create(['name' => 'Hidden', 'owner_id' => $this->owner->id]);
    $this->otherTenant = ActionCtxItem::create(['name' => 'Other tenant', 'tenant_id' => 2, 'owner_id' => $this->owner->id]);
    $this->locked = ActionCtxItem::create(['name' => 'Locked', 'locked' => true, 'owner_id' => $this->owner->id]);
    $this->noPolicy = ActionCtxItem::create(['name' => 'No policy', 'owner_id' => $this->owner->id]);
    $this->unlisted = ActionCtxItem::create(['name' => 'Unlisted', 'owner_id' => $this->owner->id]);
    $this->trashed = ActionCtxItem::create(['name' => 'Trashed', 'owner_id' => $this->owner->id]);
    $this->trashed->delete();

    $this->tag = ActionCtxTag::create(['name' => 'Tag']);
    $this->secretTag = ActionCtxTag::create(['name' => 'Secret']);
    $this->looseTag = ActionCtxTag::create(['name' => 'Loose']);
    $this->ada->tags()->attach($this->tag->id, ['weight' => 'heavy']);
    $this->ada->tags()->attach($this->secretTag->id, ['weight' => 'x']);
    $this->noPolicy->tags()->attach($this->tag->id, ['weight' => 'z']);
    $this->bob->tags()->attach($this->looseTag->id, ['weight' => 'y']);
});

afterEach(function () {
    foreach (['action_ctx_item_tag', 'action_ctx_tags', 'action_ctx_items', 'action_ctx_owners'] as $table) {
        Schema::dropIfExists($table);
    }
    app(ResourceRegistry::class)->flush();
});

const ACTION_CTX_BASE = '/martis/api/resources/action-ctx-items';

/** @return array<string, array<string, mixed>> The fields of a fields response keyed by attribute. */
function actionCtxFields(TestResponse $response): array
{
    return collect($response->json('data.fields'))->keyBy('attribute')->all();
}

/**
 * The record each request's `fields()` calls saw, in order: a run calls it
 * more than once (validation, the event log), so the repeats of one request
 * are folded.
 *
 * @return list<int|string|null>
 */
function actionCtxSeen(): array
{
    $folded = [];
    foreach (ActionCtxLog::$seen as $key) {
        if ($folded === [] || end($folded) !== $key) {
            $folded[] = $key;
        }
    }

    return $folded;
}

// ── The fields endpoint ─────────────────────────────────────────────────────

it('hands the record the modal is opened for to fields()', function () {
    $response = $this->getJson(ACTION_CTX_BASE."/actions/action-ctx-probe/fields?resourceId={$this->ada->id}")->assertOk();
    $fields = actionCtxFields($response);

    expect(ActionCtxLog::$seen)->toBe([$this->ada->id])
        ->and($fields['note']['label'])->toBe('Note of Ada')
        ->and($fields['note']['defaultValue'])->toBe('Ada');
});

it('serves a different record to each request, so no fields are shared between records', function () {
    $ada = actionCtxFields($this->getJson(ACTION_CTX_BASE."/actions/action-ctx-probe/fields?resourceId={$this->ada->id}"));
    $bob = actionCtxFields($this->getJson(ACTION_CTX_BASE."/actions/action-ctx-probe/fields?resourceId={$this->bob->id}"));

    expect($ada['note']['defaultValue'])->toBe('Ada')
        ->and($bob['note']['defaultValue'])->toBe('Bob');
});

it('sees no record without resourceId, and the response is the one a bulk modal gets', function () {
    $response = $this->getJson(ACTION_CTX_BASE.'/actions/action-ctx-probe/fields')->assertOk();
    $fields = actionCtxFields($response);

    expect(ActionCtxLog::$seen)->toBe([null])
        ->and($fields['note']['label'])->toBe('No record')
        ->and($fields['note']['defaultValue'])->toBe('no record');
});

it('serves the static and the closure default of a field', function () {
    $fields = actionCtxFields($this->getJson(ACTION_CTX_BASE.'/actions/action-ctx-probe/fields')->assertOk());

    expect($fields['fixed']['defaultValue'])->toBe('fixed value')
        ->and($fields['lazy']['defaultValue'])->toBe('lazy value');
});

it('answers 404 for a record outside the index scope, as the run does', function () {
    // `hidden` is out of indexQuery(), `otherTenant` out of scopes().
    foreach ([$this->hidden->id, $this->otherTenant->id, 999999] as $id) {
        $this->getJson(ACTION_CTX_BASE."/actions/action-ctx-probe/fields?resourceId={$id}")->assertNotFound();
        $this->postJson(ACTION_CTX_BASE.'/actions/action-ctx-probe', ['resources' => [$id]])->assertNotFound();
    }

    // A record the index lists resolves on both.
    $this->getJson(ACTION_CTX_BASE."/actions/action-ctx-probe/fields?resourceId={$this->foreign->id}")->assertOk();
    $this->postJson(ACTION_CTX_BASE.'/actions/action-ctx-probe', ['resources' => [$this->foreign->id]])->assertOk();
});

it('serves a trashed record, as the run resolves it', function () {
    $this->getJson(ACTION_CTX_BASE."/actions/action-ctx-probe/fields?resourceId={$this->trashed->id}")->assertOk();
    $this->postJson(ACTION_CTX_BASE.'/actions/action-ctx-probe', ['resources' => [$this->trashed->id]])->assertOk();
});

it('answers 404 for a record the run refuses: the action canRun or the runAction policy', function () {
    $this->getJson(ACTION_CTX_BASE."/actions/action-ctx-gated/fields?resourceId={$this->locked->id}")->assertNotFound();
    $this->getJson(ACTION_CTX_BASE."/actions/action-ctx-probe/fields?resourceId={$this->noPolicy->id}")->assertNotFound();

    $this->postJson(ACTION_CTX_BASE.'/actions/action-ctx-gated', ['resources' => [$this->locked->id]])->assertNotFound();
    $this->postJson(ACTION_CTX_BASE.'/actions/action-ctx-probe', ['resources' => [$this->noPolicy->id]])->assertNotFound();

    // Not even `fields()` ran for a record the run refuses.
    expect(ActionCtxLog::$seen)->toBe([])
        ->and(ActionCtxLog::$ran)->toBe([]);

    // A record the run allows is served.
    $this->getJson(ACTION_CTX_BASE."/actions/action-ctx-gated/fields?resourceId={$this->ada->id}")->assertOk();
});

it('keeps the 403 for a user who may not list the resource or see the action', function () {
    $this->getJson("/martis/api/resources/action-ctx-locked/actions/action-ctx-probe/fields?resourceId={$this->ada->id}")->assertForbidden();
    $this->getJson(ACTION_CTX_BASE."/actions/action-ctx-hidden/fields?resourceId={$this->ada->id}")->assertForbidden();

    expect(ActionCtxLog::$seen)->toBe([]);
});

it('accepts one id only', function () {
    $this->getJson(ACTION_CTX_BASE.'/actions/action-ctx-probe/fields?resourceId[]='.$this->ada->id.'&resourceId[]='.$this->bob->id)->assertNotFound();
    $this->getJson(ACTION_CTX_BASE.'/actions/action-ctx-probe/fields?resourceId[a]=1')->assertNotFound();

    // A `resources` list in the query does not widen it.
    $this->getJson(ACTION_CTX_BASE."/actions/action-ctx-probe/fields?resourceId={$this->ada->id}&resources[]={$this->bob->id}")->assertOk();
    expect(ActionCtxLog::$seen)->toBe([$this->ada->id]);
});

it('ignores resourceId for a standalone action, which runs on no record', function () {
    $this->getJson(ACTION_CTX_BASE.'/actions/action-ctx-standalone/fields?resourceId=999999')->assertOk();
    $this->getJson(ACTION_CTX_BASE."/actions/action-ctx-standalone/fields?resourceId={$this->hidden->id}")->assertOk();

    expect(ActionCtxLog::$seen)->toBe([null, null]);
});

// ── The run ─────────────────────────────────────────────────────────────────

it('gives fields() the record a single-record run resolves, and none to a bulk run', function () {
    $this->postJson(ACTION_CTX_BASE."/{$this->ada->id}/actions/action-ctx-probe")->assertOk();
    $this->postJson(ACTION_CTX_BASE.'/actions/action-ctx-probe', ['resources' => [$this->ada->id, $this->bob->id]])->assertOk();

    expect(actionCtxSeen())->toBe([$this->ada->id, null])
        ->and(ActionCtxLog::$ran)->toBe([1, 2]);
});

it('sees no record in a run that names several ids and finds one, as its modal showed none', function () {
    $this->postJson(ACTION_CTX_BASE.'/actions/action-ctx-probe', ['resources' => [$this->ada->id, 999999]])->assertOk();

    expect(actionCtxSeen())->toBe([null])
        ->and(ActionCtxLog::$ran)->toBe([1]);
});

it('gives a standalone run no record, whatever the request names', function () {
    $this->postJson(ACTION_CTX_BASE.'/actions/action-ctx-standalone', ['resources' => [$this->ada->id]])->assertOk();

    expect(actionCtxSeen())->toBe([null]);
});

// ── Lens ────────────────────────────────────────────────────────────────────

const ACTION_CTX_LENS = ACTION_CTX_BASE.'/lenses/action-ctx';

it('resolves the record through the lens the fields are served for', function () {
    $fields = actionCtxFields($this->getJson(ACTION_CTX_LENS."/actions/action-ctx-probe/fields?resourceId={$this->ada->id}")->assertOk());

    expect($fields['note']['defaultValue'])->toBe('Ada')
        ->and(ActionCtxLog::$seen)->toBe([$this->ada->id]);
});

it('answers 404 for a record the lens does not list, the index hides, or the run refuses', function () {
    // The index lists it, the lens's query does not.
    $this->getJson(ACTION_CTX_LENS."/actions/action-ctx-probe/fields?resourceId={$this->unlisted->id}")->assertNotFound();
    $this->postJson(ACTION_CTX_LENS.'/actions/action-ctx-probe', ['resources' => [$this->unlisted->id]])->assertNotFound();
    // Outside the index scope the lens starts from.
    $this->getJson(ACTION_CTX_LENS."/actions/action-ctx-probe/fields?resourceId={$this->hidden->id}")->assertNotFound();
    // Refused by the run's policy.
    $this->getJson(ACTION_CTX_LENS."/actions/action-ctx-probe/fields?resourceId={$this->noPolicy->id}")->assertNotFound();

    expect(actionCtxSeen())->toBe([]);
});

it('keeps the lens 403 for an action the user may not see, and hands a lens run its record', function () {
    $this->getJson(ACTION_CTX_LENS."/actions/action-ctx-hidden/fields?resourceId={$this->ada->id}")->assertForbidden();

    $this->postJson(ACTION_CTX_LENS.'/actions/action-ctx-probe', ['resources' => [$this->ada->id]])->assertOk();
    expect(actionCtxSeen())->toBe([$this->ada->id]);
});

// ── Relationship panel (via*) ───────────────────────────────────────────────

/** @return array<string, mixed> */
function actionCtxVia(?int $ownerId = null, string $relationship = 'items'): array
{
    return [
        'viaResource' => 'action-ctx-owners',
        'viaResourceId' => $ownerId ?? test()->owner->id,
        'viaRelationship' => $relationship,
    ];
}

it('resolves the record through the relationship panel the via params name', function () {
    $query = http_build_query(['resourceId' => $this->ada->id] + actionCtxVia());
    $fields = actionCtxFields($this->getJson(ACTION_CTX_BASE."/actions/action-ctx-probe/fields?{$query}")->assertOk());

    expect($fields['note']['defaultValue'])->toBe('Ada')
        ->and(ActionCtxLog::$seen)->toBe([$this->ada->id]);
});

it('answers 404 for a record the relationship does not reach, as the panel run does', function () {
    // Inside the index scope, but another owner's.
    $query = http_build_query(['resourceId' => $this->foreign->id] + actionCtxVia());
    $this->getJson(ACTION_CTX_BASE."/actions/action-ctx-probe/fields?{$query}")->assertNotFound();
    $this->postJson(ACTION_CTX_BASE.'/actions/action-ctx-probe', ['resources' => [$this->foreign->id]] + actionCtxVia())->assertNotFound();

    // Through the owner the record belongs to, it resolves.
    $query = http_build_query(['resourceId' => $this->foreign->id] + actionCtxVia($this->otherOwner->id));
    $this->getJson(ACTION_CTX_BASE."/actions/action-ctx-probe/fields?{$query}")->assertOk();

    expect(actionCtxSeen())->toBe([$this->foreign->id]);
});

it('answers 404 for a relationship the parent does not declare', function () {
    $query = http_build_query(['resourceId' => $this->ada->id] + actionCtxVia(null, 'nope'));

    $this->getJson(ACTION_CTX_BASE."/actions/action-ctx-probe/fields?{$query}")->assertNotFound();
    expect(actionCtxSeen())->toBe([]);
});

// ── Pivot panel ─────────────────────────────────────────────────────────────

function actionCtxPivot(int $item): string
{
    return ACTION_CTX_BASE."/{$item}/belongs-to-many/tags/actions/action-ctx-pivot-probe";
}

it('hands a pivot action the related record, with its pivot, that the panel lists', function () {
    $fields = actionCtxFields($this->getJson(actionCtxPivot($this->ada->id)."/fields?resourceId={$this->tag->id}")->assertOk());

    expect($fields['weight']['defaultValue'])->toBe('heavy')
        ->and(ActionCtxLog::$seen)->toBe([$this->tag->id]);
});

it('answers 404 for a pivot record the panel does not list or the run refuses', function () {
    // Not attached to this parent.
    $this->getJson(actionCtxPivot($this->ada->id)."/fields?resourceId={$this->looseTag->id}")->assertNotFound();
    // Attached, but outside the related resource's indexQuery().
    $this->getJson(actionCtxPivot($this->ada->id)."/fields?resourceId={$this->secretTag->id}")->assertNotFound();
    $this->postJson(actionCtxPivot($this->ada->id), ['resources' => [$this->secretTag->id]])->assertNotFound();
    $this->getJson(actionCtxPivot($this->ada->id).'/fields?resourceId=999999')->assertNotFound();
    // The parent record the run refuses (runAction policy).
    $this->getJson(actionCtxPivot($this->noPolicy->id)."/fields?resourceId={$this->tag->id}")->assertNotFound();

    expect(actionCtxSeen())->toBe([]);
});

it('sees no pivot record without resourceId, and sets the record of a single-record pivot run', function () {
    $fields = actionCtxFields($this->getJson(actionCtxPivot($this->ada->id).'/fields')->assertOk());
    expect($fields['weight']['defaultValue'])->toBe('no record');

    $this->postJson(actionCtxPivot($this->ada->id), ['resources' => [$this->tag->id]])->assertOk();
    $this->postJson(actionCtxPivot($this->ada->id), ['resources' => [$this->tag->id, $this->secretTag->id]])->assertOk();

    expect(actionCtxSeen())->toBe([null, $this->tag->id, null]);
});

it('keeps the 403 of a pivot action the user may not see', function () {
    $this->getJson(ACTION_CTX_BASE."/{$this->ada->id}/belongs-to-many/tags/actions/action-ctx-hidden/fields?resourceId={$this->tag->id}")->assertForbidden();
});

// ── The relation pickers of the fields ──────────────────────────────────────

/** @return list<string> The names a relatable response lists. */
function actionCtxOptions(TestResponse $response): array
{
    return collect($response->json('data'))->pluck('name')->sort()->values()->all();
}

it('scopes a picker of an action field by the record the modal runs on', function () {
    $base = ACTION_CTX_BASE.'/actions/action-ctx-picker/relatable/tag_id?per_page=30';

    $ada = $this->getJson("{$base}&resourceId={$this->ada->id}")->assertOk();
    $bob = $this->getJson("{$base}&resourceId={$this->bob->id}")->assertOk();

    // Ada has two tags, Bob one: the closure reads selectedModel().
    expect(actionCtxOptions($ada))->toBe(['Secret', 'Tag'])
        ->and(actionCtxOptions($bob))->toBe(['Loose'])
        ->and(ActionCtxLog::$seen)->toBe([$this->ada->id, $this->bob->id]);
});

it('sees no record in the picker of a bulk modal, which names none', function () {
    $response = $this->getJson(ACTION_CTX_BASE.'/actions/action-ctx-picker/relatable/tag_id?per_page=30')->assertOk();

    expect(actionCtxOptions($response))->toBe([])
        ->and(ActionCtxLog::$seen)->toBe([null]);
});

it('answers 404 for a picker request on a record the run would not resolve or would refuse', function () {
    $base = ACTION_CTX_BASE.'/actions/action-ctx-picker/relatable/tag_id?per_page=30';

    // Out of indexQuery(), out of scopes(), missing, refused by the runAction policy.
    foreach ([$this->hidden->id, $this->otherTenant->id, 999999, $this->noPolicy->id] as $id) {
        $this->getJson("{$base}&resourceId={$id}")->assertNotFound();
    }
    $this->getJson("{$base}&resourceId[]={$this->ada->id}&resourceId[]={$this->bob->id}")->assertNotFound();

    // The closure never ran for them.
    expect(ActionCtxLog::$seen)->toBe([]);
});

it('keeps the picker 403 of an action the user may not see', function () {
    $this->getJson(ACTION_CTX_BASE."/actions/action-ctx-hidden/relatable/tag_id?resourceId={$this->ada->id}")->assertForbidden();
});

it('resolves the picker record through the lens and the relationship panel, as the fields do', function () {
    $lens = ACTION_CTX_LENS.'/actions/action-ctx-picker/relatable/tag_id?per_page=30';

    expect(actionCtxOptions($this->getJson("{$lens}&resourceId={$this->ada->id}")->assertOk()))->toBe(['Secret', 'Tag']);
    // Listed by the index, not by the lens.
    $this->getJson("{$lens}&resourceId={$this->unlisted->id}")->assertNotFound();

    $via = http_build_query(['resourceId' => $this->ada->id] + actionCtxVia());
    expect(actionCtxOptions($this->getJson(ACTION_CTX_BASE."/actions/action-ctx-picker/relatable/tag_id?per_page=30&{$via}")->assertOk()))->toBe(['Secret', 'Tag']);

    // The owner the record does not belong to.
    $other = http_build_query(['resourceId' => $this->ada->id] + actionCtxVia($this->otherOwner->id));
    $this->getJson(ACTION_CTX_BASE."/actions/action-ctx-picker/relatable/tag_id?per_page=30&{$other}")->assertNotFound();
});

it('scopes the picker of a pivot action by the related record the panel lists', function () {
    $base = ACTION_CTX_BASE."/{$this->ada->id}/belongs-to-many/tags/actions/action-ctx-pivot-picker/relatable/tag_id?per_page=30";

    expect(actionCtxOptions($this->getJson("{$base}&resourceId={$this->tag->id}")->assertOk()))->toBe(['Tag'])
        ->and(ActionCtxLog::$seen)->toBe([$this->tag->id]);

    // Not attached to this parent, outside the related resource's indexQuery(), missing.
    foreach ([$this->looseTag->id, $this->secretTag->id, 999999] as $id) {
        $this->getJson("{$base}&resourceId={$id}")->assertNotFound();
    }

    // No record named: the closure sees none.
    expect(actionCtxOptions($this->getJson($base)->assertOk()))->toBe([]);
});
