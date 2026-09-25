<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany as EloquentMorphToMany;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Fields\BelongsToMany;
use Martis\Fields\MorphToMany;
use Martis\Fields\Select;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Layout\Section;
use Martis\Resource;
use Martis\ResourceRegistry;

// ── Test Fixtures ───────────────────────────────────────────────────────────

class PivotParentModel extends Model
{
    protected $table = 'pivot_test_parents';

    protected $fillable = ['name'];

    public function pivotChildren(): EloquentBelongsToMany
    {
        return $this->belongsToMany(
            PivotChildModel::class,
            'pivot_test_pivot',
            'parent_id',
            'child_id',
        )->withPivot(['priority']);
    }

    /**
     * Polymorphic tags. No withPivot() here on purpose: the pivot columns
     * must come from the pivot fields the MorphToMany field declares.
     */
    public function pivotTags(): EloquentMorphToMany
    {
        return $this->morphToMany(
            PivotTagModel::class,
            'taggable',
            'pivot_test_taggables',
            'taggable_id',
            'tag_id',
        );
    }

    /** A real relation that the resource never declares as a field. */
    public function undeclaredChildren(): EloquentBelongsToMany
    {
        return $this->belongsToMany(
            PivotChildModel::class,
            'pivot_test_pivot',
            'parent_id',
            'child_id',
        )->withPivot(['priority']);
    }
}

class PivotChildModel extends Model
{
    protected $table = 'pivot_test_children';

    protected $fillable = ['name'];
}

class PivotTagModel extends Model
{
    protected $table = 'pivot_test_tags';

    protected $fillable = ['name'];
}

class PivotTestAction extends Action
{
    public ?string $name = 'Set Priority';

    protected bool $isPivotAction = true;

    /** @param Collection<int, Model> $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        $priority = $fields->get('priority') ?? 'normal';
        foreach ($models as $child) {
            if (isset($child->pivot)) {
                $child->pivot->priority = $priority;
                $child->pivot->save();
            }
        }

        return ActionResponse::message("Priority set to '{$priority}'.");
    }

    public function fields(Request $request): array
    {
        return [
            Select::make('priority', 'Priority')
                ->options([
                    'low' => 'Low',
                    'normal' => 'Normal',
                    'high' => 'High',
                ])
                ->default('normal')
                ->required(),
        ];
    }
}

/**
 * Declared on the MorphToMany field through ->actions(), so it belongs to
 * that panel only, and not flagged ->pivotAction(). Reports the pivot
 * priorities it received, which shows whether the pivot columns were loaded.
 */
class PivotTagReportAction extends Action
{
    public ?string $name = 'Report Priorities';

    /** @param Collection<int, Model> $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        $priorities = $models
            ->map(fn (Model $tag): string => (string) ($tag->pivot->priority ?? 'missing'))
            ->sort()
            ->values()
            ->all();

        return ActionResponse::message(($fields->get('note') ?? 'Priorities').': '.implode(',', $priorities));
    }

    public function fields(Request $request): array
    {
        return [Text::make('note', 'Note')->nullable()];
    }
}

/** A resource action that is not a pivot action. */
class PivotPlainAction extends Action
{
    public ?string $name = 'Plain';

    /** @param Collection<int, Model> $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        return ActionResponse::message('plain');
    }
}

class PivotParentResource extends Resource
{
    public static function model(): string
    {
        return PivotParentModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->required(),
            BelongsToMany::make('PivotChildren', 'pivotChildren')
                ->relatedResource('pivot-child-models')
                ->fields(fn () => [
                    Select::make('priority', 'Priority')
                        ->options(['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'])
                        ->nullable(),
                ]),
            // Inside a layout on purpose: the pivot routes must still find it.
            Section::make(null, [
                MorphToMany::make('PivotTags', 'pivotTags')
                    ->relatedResource('pivot-tag-models')
                    ->fields(fn () => [
                        Select::make('priority', 'Priority')
                            ->options(['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'])
                            ->nullable(),
                    ])
                    ->actions(fn () => [PivotTagReportAction::make()]),
            ]),
        ];
    }

    public function actions(Request $request): array
    {
        return [PivotTestAction::make(), PivotPlainAction::make()];
    }
}

class PivotTagResource extends Resource
{
    public static function model(): string
    {
        return PivotTagModel::class;
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')->required()];
    }
}

class PivotChildResource extends Resource
{
    public static function model(): string
    {
        return PivotChildModel::class;
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')->required()];
    }
}

/** Parent resource the current user may NOT view — exercises the IDOR guard. */
class PivotDeniedViewResource extends PivotParentResource
{
    public static function uriKey(): string
    {
        return 'pivot-denied-view-parents';
    }

    public function authorizedToView(Request $request): bool
    {
        return false;
    }
}

/** Pivot action whose per-model canRun always denies. */
class PivotDeniedRunAction extends PivotTestAction
{
    public function uriKey(): string
    {
        return 'pivot-denied-run-action';
    }

    public function authorizedToRun(Request $request, Model $model): bool
    {
        return false;
    }
}

/** Parent resource that exposes the deny-run pivot action. */
class PivotRunGuardResource extends PivotParentResource
{
    public static function uriKey(): string
    {
        return 'pivot-runguard-parents';
    }

    public function actions(Request $request): array
    {
        return [PivotDeniedRunAction::make()];
    }
}

/** A standalone pivot action that reports how many records it received. */
class PivotStandaloneCountAction extends PivotTestAction
{
    public function uriKey(): string
    {
        return 'pivot-standalone-count';
    }

    /** @param Collection<int, Model> $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        return ActionResponse::message('records: '.$models->count());
    }

    public function fields(Request $request): array
    {
        return [];
    }
}

/** Parent resource that exposes the standalone pivot action. */
class PivotStandaloneParentResource extends PivotParentResource
{
    public static function uriKey(): string
    {
        return 'pivot-standalone-parents';
    }

    public function actions(Request $request): array
    {
        return [PivotStandaloneCountAction::make()->standalone()];
    }
}

/** A field action that reuses the URI key of the resource pivot action. */
class PivotShadowAction extends PivotTestAction
{
    public ?string $name = 'Shadowing Priority';

    public function uriKey(): string
    {
        return 'pivot-test-action';
    }
}

/** Parent resource whose BelongsToMany field declares the shadowing action. */
class PivotShadowingParentResource extends PivotParentResource
{
    public static function uriKey(): string
    {
        return 'pivot-shadowing-parents';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->required(),
            BelongsToMany::make('PivotChildren', 'pivotChildren')
                ->relatedResource('pivot-child-models')
                ->actions(fn () => [PivotShadowAction::make()]),
        ];
    }
}

/** Parent resource whose only pivot action the current user may not see. */
class PivotHiddenActionResource extends PivotParentResource
{
    public static function uriKey(): string
    {
        return 'pivot-hidden-action-parents';
    }

    public function actions(Request $request): array
    {
        return [PivotTestAction::make()->canSee(fn () => false)];
    }
}

// ── Setup ───────────────────────────────────────────────────────────────────

beforeEach(function () {
    Schema::disableForeignKeyConstraints();
    Schema::dropIfExists('pivot_test_taggables');
    Schema::dropIfExists('pivot_test_tags');
    Schema::dropIfExists('pivot_test_pivot');
    Schema::dropIfExists('pivot_test_children');
    Schema::dropIfExists('pivot_test_parents');
    Schema::enableForeignKeyConstraints();

    Schema::create('pivot_test_parents', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('pivot_test_children', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('pivot_test_pivot', function ($table) {
        $table->foreignId('parent_id')->constrained('pivot_test_parents')->onDelete('cascade');
        $table->foreignId('child_id')->constrained('pivot_test_children')->onDelete('cascade');
        $table->enum('priority', ['low', 'normal', 'high'])->default('normal');
        $table->primary(['parent_id', 'child_id']);
    });

    Schema::create('pivot_test_tags', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    // A morph pivot with its own `id`, like a typical `taggables` table: the
    // related key the actions filter on must be qualified to stay unambiguous.
    Schema::create('pivot_test_taggables', function ($table) {
        $table->id();
        $table->foreignId('tag_id')->constrained('pivot_test_tags')->onDelete('cascade');
        $table->morphs('taggable');
        $table->enum('priority', ['low', 'normal', 'high'])->default('normal');
    });

    $registry = app(ResourceRegistry::class);
    $registry->register(PivotParentResource::class);
    $registry->register(PivotChildResource::class);
    $registry->register(PivotTagResource::class);

    $this->withoutMiddleware(MartisAuthenticate::class);
});

afterEach(function () {
    Schema::disableForeignKeyConstraints();
    Schema::dropIfExists('pivot_test_taggables');
    Schema::dropIfExists('pivot_test_tags');
    Schema::dropIfExists('pivot_test_pivot');
    Schema::dropIfExists('pivot_test_children');
    Schema::dropIfExists('pivot_test_parents');
    Schema::enableForeignKeyConstraints();
});

// ── Tests ───────────────────────────────────────────────────────────────────

it('lists pivot actions for a belongs-to-many relationship', function () {
    $parent = PivotParentModel::create(['name' => 'Parent A']);

    $response = $this->getJson(
        route('martis.api.resources.belongs-to-many.actions.index', [
            'resource' => 'pivot-parent-models',
            'id' => $parent->id,
            'relationship' => 'pivotChildren',
        ])
    );

    $response->assertOk();
    $data = $response->json('data');
    expect($data['actions'])->toHaveCount(1);
    expect($data['actions'][0]['uriKey'])->toBe('pivot-test-action');
    expect($data['actions'][0]['isPivotAction'])->toBeTrue();
});

it('executes a pivot action and updates the pivot column', function () {
    $parent = PivotParentModel::create(['name' => 'Parent B']);
    $child1 = PivotChildModel::create(['name' => 'Child 1']);
    $child2 = PivotChildModel::create(['name' => 'Child 2']);

    $parent->pivotChildren()->attach([$child1->id, $child2->id], ['priority' => 'normal']);

    $response = $this->postJson(
        route('martis.api.resources.belongs-to-many.actions.execute', [
            'resource' => 'pivot-parent-models',
            'id' => $parent->id,
            'relationship' => 'pivotChildren',
            'action' => 'pivot-test-action',
        ]),
        ['resources' => [$child1->id, $child2->id], 'fields' => ['priority' => 'high']],
    );

    $response->assertOk();
    $data = $response->json('data');
    expect($data['type'])->toBe('message');

    // Verify the pivot was actually updated
    $updated = $parent->pivotChildren()->withPivot(['priority'])->get();
    foreach ($updated as $child) {
        expect($child->pivot->priority)->toBe('high');
    }
});

it('refuses a pivot action when none of its ids is attached, and runs on the attached ones otherwise', function () {
    $parent = PivotParentModel::create(['name' => 'Parent C']);
    $attached = PivotChildModel::create(['name' => 'Attached']);
    $loose = PivotChildModel::create(['name' => 'Not attached']);
    $parent->pivotChildren()->attach($attached->id, ['priority' => 'normal']);
    $url = route('martis.api.resources.belongs-to-many.actions.execute', [
        'resource' => 'pivot-parent-models',
        'id' => $parent->id,
        'relationship' => 'pivotChildren',
        'action' => 'pivot-test-action',
    ]);

    foreach ([[$loose->id], [999]] as $ids) {
        $this->postJson($url, ['resources' => $ids, 'fields' => ['priority' => 'high']])
            ->assertStatus(404)
            ->assertJsonPath('message', 'One or more selected resources could not be found.');
    }
    expect($parent->pivotChildren()->withPivot(['priority'])->first()->pivot->priority)->toBe('normal');

    $this->postJson($url, ['resources' => [$attached->id, $loose->id], 'fields' => ['priority' => 'high']])->assertOk();
    expect($parent->pivotChildren()->withPivot(['priority'])->first()->pivot->priority)->toBe('high');
});

it('runs a standalone pivot action on no record, whatever ids are sent, as on the resource endpoint', function () {
    app(ResourceRegistry::class)->register(PivotStandaloneParentResource::class);
    $parent = PivotParentModel::create(['name' => 'Parent S']);
    $child = PivotChildModel::create(['name' => 'Attached']);
    $parent->pivotChildren()->attach($child->id, ['priority' => 'normal']);

    $this->postJson(
        route('martis.api.resources.belongs-to-many.actions.execute', [
            'resource' => 'pivot-standalone-parents',
            'id' => $parent->id,
            'relationship' => 'pivotChildren',
            'action' => 'pivot-standalone-count',
        ]),
        ['resources' => [$child->id, 999]],
    )->assertOk()->assertJsonPath('data.data.message', 'records: 0');
});

it('forbids executing a pivot action on a parent the user cannot view (IDOR)', function () {
    app(ResourceRegistry::class)->register(PivotDeniedViewResource::class);

    $parent = PivotParentModel::create(['name' => 'Secret']);
    $child = PivotChildModel::create(['name' => 'Child']);
    $parent->pivotChildren()->attach($child->id, ['priority' => 'normal']);

    $response = $this->postJson(
        route('martis.api.resources.belongs-to-many.actions.execute', [
            'resource' => 'pivot-denied-view-parents',
            'id' => $parent->id,
            'relationship' => 'pivotChildren',
            'action' => 'pivot-test-action',
        ]),
        ['resources' => [$child->id], 'fields' => ['priority' => 'high']],
    );

    $response->assertStatus(403);
    expect($parent->pivotChildren()->withPivot(['priority'])->first()->pivot->priority)->toBe('normal');
});

it('denies a pivot action when per-model authorizedToRun returns false', function () {
    app(ResourceRegistry::class)->register(PivotRunGuardResource::class);

    $parent = PivotParentModel::create(['name' => 'Parent']);
    $child = PivotChildModel::create(['name' => 'Child']);
    $parent->pivotChildren()->attach($child->id, ['priority' => 'normal']);

    $response = $this->postJson(
        route('martis.api.resources.belongs-to-many.actions.execute', [
            'resource' => 'pivot-runguard-parents',
            'id' => $parent->id,
            'relationship' => 'pivotChildren',
            'action' => 'pivot-denied-run-action',
        ]),
        ['resources' => [$child->id], 'fields' => ['priority' => 'high']],
    );

    $response->assertStatus(404);
    expect($parent->pivotChildren()->withPivot(['priority'])->first()->pivot->priority)->toBe('normal');
});

it('returns 404 when the parent resource does not exist', function () {
    $response = $this->postJson(
        route('martis.api.resources.belongs-to-many.actions.execute', [
            'resource' => 'pivot-parent-models',
            'id' => 99999,
            'relationship' => 'pivotChildren',
            'action' => 'pivot-test-action',
        ]),
        ['resources' => [1], 'fields' => ['priority' => 'high']],
    );

    $response->assertStatus(404);
});

it('returns 404 when executing a non-pivot action via pivot route', function () {
    $parent = PivotParentModel::create(['name' => 'Parent C']);

    // Register a non-pivot action on the resource
    // (We test by using a non-existent action key)
    $response = $this->postJson(
        route('martis.api.resources.belongs-to-many.actions.execute', [
            'resource' => 'pivot-parent-models',
            'id' => $parent->id,
            'relationship' => 'pivotChildren',
            'action' => 'non-existent-action',
        ]),
        ['resources' => [1], 'fields' => []],
    );

    $response->assertStatus(404);
});

it('returns validation error when no resources selected', function () {
    $parent = PivotParentModel::create(['name' => 'Parent D']);

    $response = $this->postJson(
        route('martis.api.resources.belongs-to-many.actions.execute', [
            'resource' => 'pivot-parent-models',
            'id' => $parent->id,
            'relationship' => 'pivotChildren',
            'action' => 'pivot-test-action',
        ]),
        ['resources' => [], 'fields' => ['priority' => 'high']],
    );

    $response->assertStatus(422);
});

// ── IDOR via indexQuery scope (whole-branch review finding) ──────────────────

class PivotScopedParentResource extends PivotParentResource
{
    public static function uriKey(): string
    {
        return 'pivot-scoped-parents';
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query->where('name', '!=', 'HIDDEN');
    }
}

it('a pivot action cannot reach a parent outside the resource indexQuery scope (uniform 404)', function () {
    app(ResourceRegistry::class)->register(PivotScopedParentResource::class);

    $parent = PivotParentModel::create(['name' => 'HIDDEN']); // excluded by indexQuery
    $child = PivotChildModel::create(['name' => 'Child']);
    $parent->pivotChildren()->attach($child->id, ['priority' => 'normal']);

    $response = $this->postJson(
        route('martis.api.resources.belongs-to-many.actions.execute', [
            'resource' => 'pivot-scoped-parents',
            'id' => $parent->id,
            'relationship' => 'pivotChildren',
            'action' => 'pivot-test-action',
        ]),
        ['resources' => [$child->id], 'fields' => ['priority' => 'high']],
    );

    // The parent is scoped out → resolved as not-found (404, no existence
    // oracle), and the pivot is never touched.
    $response->assertNotFound();
    expect($parent->pivotChildren()->withPivot(['priority'])->first()->pivot->priority)->toBe('normal');
});

// ── MorphToMany: the same pivot action routes ────────────────────────────────

it('lists pivot actions for a morph-to-many relationship, field actions first', function () {
    $parent = PivotParentModel::create(['name' => 'Parent M']);

    $response = $this->getJson(
        route('martis.api.resources.morph-to-many.actions.index', [
            'resource' => 'pivot-parent-models',
            'id' => $parent->id,
            'relationship' => 'pivotTags',
        ])
    );

    $response->assertOk();
    $actions = collect($response->json('data.actions'));
    expect($actions->pluck('uriKey')->all())->toBe(['pivot-tag-report-action', 'pivot-test-action']);
    // The field makes its actions pivot actions; no ->pivotAction() needed.
    expect($actions->pluck('isPivotAction')->all())->toBe([true, true]);
});

it('executes a pivot action on a morph-to-many relationship and updates the morph pivot rows', function () {
    $parent = PivotParentModel::create(['name' => 'Parent N']);
    $other = PivotParentModel::create(['name' => 'Other parent']);
    $urgent = PivotTagModel::create(['name' => 'urgent']);
    $backend = PivotTagModel::create(['name' => 'backend']);
    $parent->pivotTags()->attach([$urgent->id, $backend->id], ['priority' => 'normal']);
    $other->pivotTags()->attach($urgent->id, ['priority' => 'low']);

    $response = $this->postJson(
        route('martis.api.resources.morph-to-many.actions.execute', [
            'resource' => 'pivot-parent-models',
            'id' => $parent->id,
            'relationship' => 'pivotTags',
            'action' => 'pivot-test-action',
        ]),
        ['resources' => [$urgent->id, $backend->id], 'fields' => ['priority' => 'high']],
    );

    $response->assertOk();
    expect($response->json('data.type'))->toBe('message');
    expect($parent->pivotTags()->withPivot(['priority'])->get()->pluck('pivot.priority')->all())
        ->toBe(['high', 'high']);
    // The other parent's pivot row for the same tag is left alone.
    expect($other->pivotTags()->withPivot(['priority'])->first()->pivot->priority)->toBe('low');
});

it('loads the pivot columns the morph-to-many field declares before running the action', function () {
    $parent = PivotParentModel::create(['name' => 'Parent O']);
    $a = PivotTagModel::create(['name' => 'a']);
    $b = PivotTagModel::create(['name' => 'b']);
    $parent->pivotTags()->attach($a->id, ['priority' => 'high']);
    $parent->pivotTags()->attach($b->id, ['priority' => 'low']);

    $response = $this->postJson(
        route('martis.api.resources.morph-to-many.actions.execute', [
            'resource' => 'pivot-parent-models',
            'id' => $parent->id,
            'relationship' => 'pivotTags',
            'action' => 'pivot-tag-report-action',
        ]),
        ['resources' => [$a->id, $b->id], 'fields' => []],
    );

    $response->assertOk();
    expect($response->json('data.data.message'))->toBe('Priorities: high,low');
});

it('returns the fields of a pivot action through the pivot routes', function (string $prefix, string $relationship, string $action, array $expected) {
    $parent = PivotParentModel::create(['name' => 'Parent P']);

    $response = $this->getJson(
        route("martis.api.resources.{$prefix}.actions.fields", [
            'resource' => 'pivot-parent-models',
            'id' => $parent->id,
            'relationship' => $relationship,
            'action' => $action,
        ])
    );

    $response->assertOk();
    expect(collect($response->json('data.fields'))->pluck('attribute')->all())->toBe($expected);
})->with([
    'resource pivot action on a belongs-to-many' => ['belongs-to-many', 'pivotChildren', 'pivot-test-action', ['priority']],
    'resource pivot action on a morph-to-many' => ['morph-to-many', 'pivotTags', 'pivot-test-action', ['priority']],
    'field action on its morph-to-many' => ['morph-to-many', 'pivotTags', 'pivot-tag-report-action', ['note']],
]);

// ── Actions declared on the field (->actions()) ──────────────────────────────

it('lists an action declared on a many-to-many field only on that relationship', function () {
    $parent = PivotParentModel::create(['name' => 'Parent Q']);

    $response = $this->getJson(
        route('martis.api.resources.belongs-to-many.actions.index', [
            'resource' => 'pivot-parent-models',
            'id' => $parent->id,
            'relationship' => 'pivotChildren',
        ])
    );

    $response->assertOk();
    expect(collect($response->json('data.actions'))->pluck('uriKey')->all())->toBe(['pivot-test-action']);
});

it('neither runs nor describes a field action through another relationship', function () {
    $parent = PivotParentModel::create(['name' => 'Parent R']);
    $child = PivotChildModel::create(['name' => 'Child']);
    $parent->pivotChildren()->attach($child->id, ['priority' => 'normal']);

    $params = [
        'resource' => 'pivot-parent-models',
        'id' => $parent->id,
        'relationship' => 'pivotChildren',
        'action' => 'pivot-tag-report-action',
    ];

    $this->postJson(route('martis.api.resources.belongs-to-many.actions.execute', $params), ['resources' => [$child->id]])
        ->assertNotFound();
    $this->getJson(route('martis.api.resources.belongs-to-many.actions.fields', $params))
        ->assertNotFound();
});

it('does not run a resource action that is not a pivot action through a pivot route', function (string $prefix, string $relationship) {
    $parent = PivotParentModel::create(['name' => 'Parent T']);

    $response = $this->postJson(
        route("martis.api.resources.{$prefix}.actions.execute", [
            'resource' => 'pivot-parent-models',
            'id' => $parent->id,
            'relationship' => $relationship,
            'action' => 'pivot-plain-action',
        ]),
        ['resources' => [1], 'fields' => []],
    );

    $response->assertNotFound();
})->with([
    'belongs-to-many' => ['belongs-to-many', 'pivotChildren'],
    'morph-to-many' => ['morph-to-many', 'pivotTags'],
]);

it('lets a field action take over a resource pivot action with the same uri key', function () {
    app(ResourceRegistry::class)->register(PivotShadowingParentResource::class);
    $parent = PivotParentModel::create(['name' => 'Parent S']);

    $response = $this->getJson(
        route('martis.api.resources.belongs-to-many.actions.index', [
            'resource' => 'pivot-shadowing-parents',
            'id' => $parent->id,
            'relationship' => 'pivotChildren',
        ])
    );

    $response->assertOk();
    expect($response->json('data.actions'))->toHaveCount(1);
    expect($response->json('data.actions.0.name'))->toBe('Shadowing Priority');
});

// ── The {relationship} segment names a declared field, never a model method ──

it('never calls a parent model method that is not a declared relationship', function (string $prefix) {
    $parent = PivotParentModel::create(['name' => 'Keep me']);

    $response = $this->postJson(
        route("martis.api.resources.{$prefix}.actions.execute", [
            'resource' => 'pivot-parent-models',
            'id' => $parent->id,
            'relationship' => 'delete',
            'action' => 'pivot-test-action',
        ]),
        ['resources' => [1], 'fields' => ['priority' => 'high']],
    );

    $response->assertNotFound();
    expect(PivotParentModel::query()->whereKey($parent->id)->exists())->toBeTrue();
})->with(['belongs-to-many', 'morph-to-many']);

it('does not run a pivot action through a relation the resource does not declare', function () {
    $parent = PivotParentModel::create(['name' => 'Parent U']);
    $child = PivotChildModel::create(['name' => 'Child']);
    $parent->pivotChildren()->attach($child->id, ['priority' => 'normal']);

    $response = $this->postJson(
        route('martis.api.resources.belongs-to-many.actions.execute', [
            'resource' => 'pivot-parent-models',
            'id' => $parent->id,
            'relationship' => 'undeclaredChildren',
            'action' => 'pivot-test-action',
        ]),
        ['resources' => [$child->id], 'fields' => ['priority' => 'high']],
    );

    $response->assertNotFound();
    expect($parent->pivotChildren()->first()->pivot->priority)->toBe('normal');
});

it('404s the pivot actions of a relationship not declared as that many-to-many type', function (string $prefix, string $relationship) {
    $parent = PivotParentModel::create(['name' => 'Parent V']);

    $this->getJson(
        route("martis.api.resources.{$prefix}.actions.index", [
            'resource' => 'pivot-parent-models',
            'id' => $parent->id,
            'relationship' => $relationship,
        ])
    )->assertNotFound();
})->with([
    'unknown relationship' => ['belongs-to-many', 'nope'],
    'morph-to-many relation on the belongs-to-many route' => ['belongs-to-many', 'pivotTags'],
    'belongs-to-many relation on the morph-to-many route' => ['morph-to-many', 'pivotChildren'],
]);

it('forbids listing pivot actions and fields of a parent the user cannot view', function () {
    app(ResourceRegistry::class)->register(PivotDeniedViewResource::class);
    $parent = PivotParentModel::create(['name' => 'Secret']);

    $params = [
        'resource' => 'pivot-denied-view-parents',
        'id' => $parent->id,
        'relationship' => 'pivotTags',
    ];

    $this->getJson(route('martis.api.resources.morph-to-many.actions.index', $params))->assertForbidden();
    $this->getJson(route('martis.api.resources.morph-to-many.actions.fields', [...$params, 'action' => 'pivot-test-action']))
        ->assertForbidden();
});

it('forbids the fields and the run of a pivot action the user cannot see', function () {
    app(ResourceRegistry::class)->register(PivotHiddenActionResource::class);
    $parent = PivotParentModel::create(['name' => 'Parent W']);
    $child = PivotChildModel::create(['name' => 'Child']);
    $parent->pivotChildren()->attach($child->id, ['priority' => 'normal']);

    $params = [
        'resource' => 'pivot-hidden-action-parents',
        'id' => $parent->id,
        'relationship' => 'pivotChildren',
    ];

    $listing = $this->getJson(route('martis.api.resources.belongs-to-many.actions.index', $params));
    $listing->assertOk();
    expect($listing->json('data.actions'))->toBe([]);

    $withAction = [...$params, 'action' => 'pivot-test-action'];
    $this->getJson(route('martis.api.resources.belongs-to-many.actions.fields', $withAction))->assertForbidden();
    $this->postJson(
        route('martis.api.resources.belongs-to-many.actions.execute', $withAction),
        ['resources' => [$child->id], 'fields' => ['priority' => 'high']],
    )->assertForbidden();
    expect($parent->pivotChildren()->first()->pivot->priority)->toBe('normal');
});
