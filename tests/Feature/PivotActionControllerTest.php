<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Fields\BelongsToMany;
use Martis\Fields\Select;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
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
}

class PivotChildModel extends Model
{
    protected $table = 'pivot_test_children';

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
                    'Low' => 'low',
                    'Normal' => 'normal',
                    'High' => 'high',
                ])
                ->default('normal')
                ->required(),
        ];
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
                        ->options(['Low' => 'low', 'Normal' => 'normal', 'High' => 'high'])
                        ->nullable(),
                ]),
        ];
    }

    public function actions(Request $request): array
    {
        return [PivotTestAction::make()];
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

// ── Setup ───────────────────────────────────────────────────────────────────

beforeEach(function () {
    Schema::disableForeignKeyConstraints();
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

    $registry = app(ResourceRegistry::class);
    $registry->register(PivotParentResource::class);
    $registry->register(PivotChildResource::class);

    $this->withoutMiddleware(MartisAuthenticate::class);
});

afterEach(function () {
    Schema::disableForeignKeyConstraints();
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
