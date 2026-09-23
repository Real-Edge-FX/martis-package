<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany as EloquentMorphToMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\BelongsTo;
use Martis\Fields\BelongsToMany;
use Martis\Fields\Boolean;
use Martis\Fields\Field;
use Martis\Fields\MorphToMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ===========================================================================
// canSeeForModel() on the pivot fields and the records of a BelongsToMany /
// MorphToMany (v1.38.0).
//
// A pivot field's canSeeForModel() was never asked: the pivot values listed
// it, and the attach and the pivot update validated it and wrote the value
// the request sent. A pivot field is now decided on the pivot row that
// holds its value (an instance of the relationship's pivot class): the
// stored row when the pivot values are read and updated, a new row on
// attach. Hidden for that row, it is written like a pivot field the user
// cannot see: not validated, the attach stores its default(), the pivot
// update leaves the column alone, and the pivot values leave it out. The
// attached records' own fields, and the relationship field itself on the
// parent record, follow the rules of every other read.
// ===========================================================================

class PMVProject extends Model
{
    protected $table = 'pmv_projects';

    protected $guarded = [];

    public $timestamps = false;

    public function people(): EloquentBelongsToMany
    {
        return $this->belongsToMany(PMVPerson::class, 'pmv_project_person', 'project_id', 'person_id');
    }

    public function staff(): EloquentMorphToMany
    {
        return $this->morphToMany(PMVPerson::class, 'assignable', 'pmv_assignables', null, 'person_id');
    }
}

class PMVPerson extends Model
{
    protected $table = 'pmv_people';

    protected $guarded = [];

    public $timestamps = false;
}

/**
 * A role, a rate and an approver seen on a shared pivot row only, the flag
 * itself, and a mentor seen on every row.
 *
 * @return list<Field>
 */
function pmvPivotFields(): array
{
    $shared = fn (Request $request, Model $pivot): bool => (bool) $pivot->getAttribute('shared');

    return [
        Text::make('role')->nullable(),
        Text::make('rate')->rules(['required', 'max:5'])->default('std')->canSeeForModel($shared),
        BelongsTo::make('approver', 'Approver')->relatedResource('pmv-people')->nullable()->canSeeForModel($shared),
        Boolean::make('shared'),
        BelongsTo::make('mentor', 'Mentor')->relatedResource('pmv-people')->nullable(),
    ];
}

class PMVProjectResource extends Resource
{
    public static function model(): string
    {
        return PMVProject::class;
    }

    public static function uriKey(): string
    {
        return 'pmv-projects';
    }

    public function fields(Request $request): array
    {
        // Both panels are seen on a project that is not locked only.
        $unlocked = fn (Request $request, Model $project): bool => ! $project->getAttribute('locked');

        return [
            Text::make('name'),
            BelongsToMany::make('People', 'people')->relatedResource('pmv-people')->fields(fn () => pmvPivotFields())->canSeeForModel($unlocked),
            MorphToMany::make('Staff', 'staff')->relatedResource('pmv-people')->fields(fn () => pmvPivotFields())->canSeeForModel($unlocked),
        ];
    }
}

class PMVPersonResource extends Resource
{
    public static function model(): string
    {
        return PMVPerson::class;
    }

    public static function uriKey(): string
    {
        return 'pmv-people';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            Text::make('salary')->canSeeForModel(fn (Request $request, Model $model): bool => (bool) $model->getAttribute('open')),
        ];
    }
}

function pmvDropTables(): void
{
    foreach (['pmv_assignables', 'pmv_project_person', 'pmv_people', 'pmv_projects'] as $table) {
        Schema::dropIfExists($table);
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    pmvDropTables();

    $pivotColumns = function ($table): void {
        $table->string('role')->nullable();
        $table->string('rate')->nullable();
        $table->unsignedBigInteger('approver_id')->nullable();
        $table->boolean('shared')->default(false);
        $table->unsignedBigInteger('mentor_id')->nullable();
    };

    Schema::create('pmv_projects', function ($table) {
        $table->id();
        $table->string('name');
        $table->boolean('locked')->default(false);
    });
    Schema::create('pmv_people', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('salary')->nullable();
        $table->boolean('open')->default(false);
    });
    Schema::create('pmv_project_person', function ($table) use ($pivotColumns) {
        $table->unsignedBigInteger('project_id');
        $table->unsignedBigInteger('person_id');
        $pivotColumns($table);
    });
    Schema::create('pmv_assignables', function ($table) use ($pivotColumns) {
        $table->unsignedBigInteger('person_id');
        $table->morphs('assignable');
        $pivotColumns($table);
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(PMVProjectResource::class);
    $registry->register(PMVPersonResource::class);

    $this->project = PMVProject::create(['name' => 'Project']);
    $this->person = PMVPerson::create(['name' => 'Ann', 'salary' => '100']);
});

afterEach(function () {
    pmvDropTables();
});

$endpoints = [
    'BelongsToMany' => ['belongs-to-many', 'people', 'pmv_project_person'],
    'MorphToMany' => ['morph-to-many', 'staff', 'pmv_assignables'],
];

/** @return array<string, mixed> */
function pmvStoredRow(string $table): array
{
    $row = (array) DB::table($table)->sole(['role', 'rate', 'shared']);

    return ['role' => $row['role'], 'rate' => $row['rate'], 'shared' => (bool) $row['shared']];
}

it('decides an attach on the new pivot row: a pivot field hidden for it is not validated and stores its default', function (string $endpoint, string $relation, string $table) {
    // `rate` would fail max:5; `shared` is written, but the field is decided
    // on the new row before any value of the request is written to it.
    $this->postJson("/martis/api/resources/pmv-projects/{$this->project->id}/{$endpoint}/{$relation}/attach", [
        'related_id' => $this->person->id,
        'role' => 'Lead',
        'rate' => 'far too long',
        'shared' => true,
    ])->assertStatus(201);

    expect(pmvStoredRow($table))->toBe(['role' => 'Lead', 'rate' => 'std', 'shared' => true]);
})->with($endpoints);

it('names the pivot fields a new row hides in the attachable list, for the attach modal to leave out', function (string $endpoint, string $relation) {
    $this->getJson("/martis/api/resources/pmv-projects/{$this->project->id}/{$endpoint}/{$relation}/attachable")
        ->assertOk()
        ->assertJsonPath('meta.hiddenPivotFields', ['rate', 'approver_id']);
})->with(array_map(fn (array $e): array => [$e[0], $e[1]], $endpoints));

it('leaves a pivot field hidden for the stored pivot row alone on pivot update', function (string $endpoint, string $relation, string $table) {
    $this->project->{$relation}()->attach($this->person->id, ['role' => 'Dev', 'rate' => '10', 'shared' => false]);

    $response = $this->putJson("/martis/api/resources/pmv-projects/{$this->project->id}/{$endpoint}/{$relation}/{$this->person->id}/pivot", [
        'role' => 'Lead',
        'rate' => 'far too long',
        'shared' => true,
    ]);

    $response->assertOk();
    expect(pmvStoredRow($table))->toBe(['role' => 'Lead', 'rate' => '10', 'shared' => true])
        ->and($response->json('data.pivot'))->not->toHaveKey('rate');
})->with($endpoints);

it('validates and writes a pivot field on a pivot row it is seen on', function (string $endpoint, string $relation, string $table) {
    $this->project->{$relation}()->attach($this->person->id, ['role' => 'Dev', 'rate' => '10', 'shared' => true]);
    $uri = "/martis/api/resources/pmv-projects/{$this->project->id}/{$endpoint}/{$relation}/{$this->person->id}/pivot";

    $invalid = $this->putJson($uri, ['rate' => 'far too long']);

    $invalid->assertStatus(422);
    expect(collect($invalid->json('errors'))->pluck('field')->all())->toBe(['rate']);

    $this->putJson($uri, ['rate' => '20'])->assertOk();
    expect(pmvStoredRow($table))->toBe(['role' => 'Dev', 'rate' => '20', 'shared' => true]);
})->with($endpoints);

it('lists the attached records without the values hidden for their pivot row or their record', function (string $endpoint, string $relation) {
    $other = PMVPerson::create(['name' => 'Bob', 'salary' => '200', 'open' => true]);
    $this->project->{$relation}()->attach($this->person->id, ['role' => 'Dev', 'rate' => '10', 'shared' => false]);
    $this->project->{$relation}()->attach($other->id, ['role' => 'Lead', 'rate' => '20', 'shared' => true]);

    $rows = collect($this->getJson("/martis/api/resources/pmv-projects/{$this->project->id}/{$endpoint}/{$relation}")->assertOk()->json('data'))
        ->keyBy('name');

    // The flag comes back as the column stores it (0 / 1 on SQLite). The
    // pivot values list the fields hidden for their row under `_hidden`
    // (v1.38.0), as a record lists its own.
    expect($rows['Ann']['_pivot'])->toEqual(['role' => 'Dev', 'shared' => false, 'mentor_id' => null, '_hidden' => ['rate', 'approver_id']])
        ->and(array_keys($rows['Ann']['_pivot']))->toBe(['role', 'shared', 'mentor_id', '_hidden'])
        ->and($rows['Ann'])->not->toHaveKey('salary')
        ->and($rows['Ann']['_hidden'])->toBe(['salary'])
        ->and($rows['Bob']['_pivot'])->toEqual(['role' => 'Lead', 'rate' => '20', 'approver_id' => null, 'shared' => true, 'mentor_id' => null])
        ->and($rows['Bob']['salary'])->toBe('200');
})->with($endpoints);

it('answers the picker of a pivot field hidden for the pivot row like an undeclared one', function (string $endpoint, string $relation) {
    $shared = PMVPerson::create(['name' => 'Bob']);
    $this->project->{$relation}()->attach($this->person->id, ['shared' => false]);
    $this->project->{$relation}()->attach($shared->id, ['shared' => true]);
    $base = "/martis/api/resources/pmv-projects/{$this->project->id}/{$endpoint}/{$relation}/pivot-fields";

    // The attach modal: a new pivot row, which the field is hidden for.
    $this->getJson("{$base}/relatable/approver_id")->assertNotFound();
    $this->getJson("{$base}/relatable/mentor_id")->assertOk();
    // The pivot edit modal of each attached record.
    $this->getJson("{$base}/{$this->person->id}/relatable/approver_id")->assertNotFound();
    expect($this->getJson("{$base}/{$shared->id}/relatable/approver_id")->assertOk()->json('data.*.name'))->toBe(['Ann', 'Bob']);
})->with($endpoints);

it('answers 404 for a many-to-many relationship field hidden for the parent record', function (string $endpoint, string $relation) {
    $this->project->update(['locked' => true]);
    $this->project->{$relation}()->attach($this->person->id, ['role' => 'Dev']);
    $base = "/martis/api/resources/pmv-projects/{$this->project->id}/{$endpoint}/{$relation}";

    $this->getJson($base)->assertNotFound();
    $this->getJson("{$base}/attachable")->assertNotFound();
    $this->postJson("{$base}/attach", ['related_id' => $this->person->id])->assertNotFound();
    $this->putJson("{$base}/{$this->person->id}/pivot", ['role' => 'Lead'])->assertNotFound();
    $this->deleteJson("{$base}/{$this->person->id}/detach")->assertNotFound();
    $this->getJson("{$base}/actions")->assertNotFound();
    $this->getJson("{$base}/pivot-fields/relatable/mentor_id")->assertNotFound();

    expect($this->project->{$relation}()->count())->toBe(1);
})->with($endpoints);
