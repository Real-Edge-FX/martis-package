<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Database\Eloquent\Relations\HasOne as EloquentHasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany as EloquentMorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne as EloquentMorphOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany as EloquentMorphToMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\BelongsToMany;
use Martis\Fields\HasMany;
use Martis\Fields\HasOne;
use Martis\Fields\MorphMany;
use Martis\Fields\MorphOne;
use Martis\Fields\MorphToMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Http\Requests\LensRequest;
use Martis\Layout\Panel;
use Martis\Layout\Section;
use Martis\Lenses\Lens;
use Martis\Resource;
use Martis\ResourceRegistry;

// ===========================================================================
// The fields a record hides, on the wire (v1.38.0).
//
// A field canSeeForModel() hides for a record is left out of that record's
// values, but the schema describes the resource, so the pages rendered it
// empty: the detail page showed "No" for a hidden Boolean, the update
// form an empty input. Every serialised record now lists the attributes of
// the fields it hides under `_hidden`, and the create form's field lists
// leave out the fields hidden for the new model a create fills.
// ===========================================================================

class RHFEmployee extends Model
{
    protected $table = 'rhf_employees';

    protected $guarded = [];
}

class RHFTeam extends Model
{
    protected $table = 'rhf_teams';

    protected $guarded = [];

    public $timestamps = false;

    public function members(): EloquentHasMany
    {
        return $this->hasMany(RHFEmployee::class, 'team_id');
    }

    public function lead(): EloquentHasOne
    {
        return $this->hasOne(RHFEmployee::class, 'team_id');
    }

    public function notes(): EloquentMorphMany
    {
        return $this->morphMany(RHFEmployee::class, 'owner');
    }

    public function pinned(): EloquentMorphOne
    {
        return $this->morphOne(RHFEmployee::class, 'owner');
    }

    public function crew(): EloquentBelongsToMany
    {
        return $this->belongsToMany(RHFEmployee::class, 'rhf_crew', 'team_id', 'employee_id');
    }

    public function guests(): EloquentMorphToMany
    {
        return $this->morphToMany(RHFEmployee::class, 'host', 'rhf_guests', null, 'employee_id');
    }
}

/**
 * A name; a salary and a bonus seen on an open employee only; a grade seen
 * on an open employee and on the new one a create fills.
 *
 * @return list<Text|Panel|Section>
 */
function rhfEmployeeFields(): array
{
    $open = fn (Request $request, Model $model): bool => (bool) $model->getAttribute('open');

    return [
        Text::make('name')->nullable(),
        Text::make('salary')->nullable()->canSeeForModel($open),
        Panel::make('Private', [
            Text::make('bonus')->nullable()->canSeeForModel($open),
        ]),
        Section::make('Career', [
            Text::make('grade')->nullable()
                ->canSeeForModel(fn (Request $request, Model $model): bool => ! $model->exists || $open($request, $model)),
            Text::make('bonus_note')->nullable()->canSeeForModel($open),
        ]),
    ];
}

class RHFAllEmployeesLens extends Lens
{
    public function query(LensRequest $request, Builder $query): Builder
    {
        return $query->orderBy('id');
    }

    public function fields(Request $request): array
    {
        return rhfEmployeeFields();
    }
}

class RHFEmployeeResource extends Resource
{
    public static function model(): string
    {
        return RHFEmployee::class;
    }

    public static function uriKey(): string
    {
        return 'rhf-employees';
    }

    public function fields(Request $request): array
    {
        return rhfEmployeeFields();
    }

    public function lenses(Request $request): array
    {
        return [new RHFAllEmployeesLens];
    }
}

class RHFTeamResource extends Resource
{
    public static function model(): string
    {
        return RHFTeam::class;
    }

    public static function uriKey(): string
    {
        return 'rhf-teams';
    }

    public function fields(Request $request): array
    {
        // The members' panel is seen on a team that is not locked only.
        $unlocked = fn (Request $request, Model $team): bool => ! $team->getAttribute('locked');

        return [
            Text::make('name'),
            HasMany::make('Members', 'members')->relatedResource('rhf-employees')->canSeeForModel($unlocked),
            HasOne::make('Lead', 'lead')->relatedResource('rhf-employees'),
            MorphMany::make('Notes', 'notes')->relatedResource('rhf-employees'),
            MorphOne::make('Pinned', 'pinned')->relatedResource('rhf-employees'),
            BelongsToMany::make('Crew', 'crew')->relatedResource('rhf-employees'),
            MorphToMany::make('Guests', 'guests')->relatedResource('rhf-employees'),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    foreach (['rhf_guests', 'rhf_crew', 'rhf_employees', 'rhf_teams'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('rhf_teams', function ($table) {
        $table->id();
        $table->string('name')->nullable();
        $table->boolean('locked')->default(false);
    });
    Schema::create('rhf_employees', function ($table) {
        $table->id();
        $table->unsignedBigInteger('team_id')->nullable();
        $table->nullableMorphs('owner');
        $table->string('name')->nullable();
        $table->string('salary')->nullable();
        $table->string('bonus')->nullable();
        $table->string('grade')->nullable();
        $table->string('bonus_note')->nullable();
        $table->boolean('open')->default(false);
        $table->timestamps();
    });
    Schema::create('rhf_crew', function ($table) {
        $table->unsignedBigInteger('team_id');
        $table->unsignedBigInteger('employee_id');
    });
    Schema::create('rhf_guests', function ($table) {
        $table->unsignedBigInteger('employee_id');
        $table->morphs('host');
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(RHFEmployeeResource::class);
    $registry->register(RHFTeamResource::class);
});

afterEach(function () {
    foreach (['rhf_guests', 'rhf_crew', 'rhf_employees', 'rhf_teams'] as $table) {
        Schema::dropIfExists($table);
    }
});

/**
 * A team with one employee, reachable through each of its relationships.
 *
 * @param  array<string, mixed>  $team
 * @param  array<string, mixed>  $employee
 * @return array{0: RHFTeam, 1: RHFEmployee}
 */
function rhfTeamWithEmployee(array $team, array $employee): array
{
    $stored = RHFTeam::create(['name' => 'Team', ...$team]);
    $related = RHFEmployee::create([
        'team_id' => $stored->id,
        'owner_type' => RHFTeam::class,
        'owner_id' => $stored->id,
        'name' => 'Ann',
        'salary' => '100',
        'bonus' => '10',
        'grade' => 'B',
        'bonus_note' => 'Q3',
        ...$employee,
    ]);
    $stored->crew()->attach($related->id);
    $stored->guests()->attach($related->id);

    return [$stored, $related];
}

const RHF_HIDDEN = ['salary', 'bonus', 'grade', 'bonus_note'];

it('lists the fields a record hides under _hidden', function (string $uri) {
    $closed = RHFEmployee::create(['name' => 'Closed', 'salary' => '100', 'bonus' => '10', 'grade' => 'B', 'open' => false]);
    $open = RHFEmployee::create(['name' => 'Open', 'salary' => '200', 'bonus' => '20', 'grade' => 'A', 'open' => true]);

    $record = fn (RHFEmployee $employee): array => str_contains($uri, '{id}')
        ? $this->getJson(str_replace('{id}', (string) $employee->id, $uri))->assertOk()->json('data')
        : collect($this->getJson($uri)->assertOk()->json('data'))->firstWhere('id', $employee->id);

    expect($record($closed)['_hidden'])->toEqualCanonicalizing(RHF_HIDDEN)
        ->and($record($closed))->not->toHaveKeys(RHF_HIDDEN)
        ->and($record($open))->not->toHaveKey('_hidden')
        ->and($record($open)['salary'])->toBe('200');
})->with([
    'index' => '/martis/api/resources/rhf-employees',
    'detail' => '/martis/api/resources/rhf-employees/{id}',
    'update form' => '/martis/api/resources/rhf-employees/{id}?context=update',
    'lens' => '/martis/api/resources/rhf-employees/lenses/r-h-f-all-employees',
]);

it('lists the fields the saved record hides in the response of a create and an update', function () {
    $created = $this->postJson('/martis/api/resources/rhf-employees', ['name' => 'New'])->assertCreated()->json('data');
    $updated = $this->putJson("/martis/api/resources/rhf-employees/{$created['id']}", ['name' => 'Renamed'])->assertOk()->json('data');

    expect($created['_hidden'])->toEqualCanonicalizing(RHF_HIDDEN)
        ->and($updated['name'])->toBe('Renamed')
        ->and($updated['_hidden'])->toEqualCanonicalizing(RHF_HIDDEN);
});

it('lists the fields a related record hides in a relationship panel', function (string $path, string $row) {
    [$team] = rhfTeamWithEmployee([], ['open' => false]);

    $record = $this->getJson("/martis/api/resources/rhf-teams/{$team->id}/{$path}")->assertOk()->json($row);

    expect($record['_hidden'])->toEqualCanonicalizing(RHF_HIDDEN)
        ->and($record)->not->toHaveKeys(RHF_HIDDEN);
})->with([
    'HasMany' => ['has-many/members', 'data.0'],
    'HasOne' => ['has-one/lead', 'data'],
    'MorphMany' => ['morph-many/notes', 'data.0'],
    'MorphOne' => ['morph-one/pinned', 'data'],
    'BelongsToMany' => ['belongs-to-many/crew', 'data.0'],
    'MorphToMany' => ['morph-to-many/guests', 'data.0'],
]);

it('sends no _hidden list for a related record that hides no field', function (string $path, string $row) {
    [$team] = rhfTeamWithEmployee([], ['open' => true]);

    expect($this->getJson("/martis/api/resources/rhf-teams/{$team->id}/{$path}")->assertOk()->json($row))->not->toHaveKey('_hidden');
})->with([
    'HasMany' => ['has-many/members', 'data.0'],
    'HasOne' => ['has-one/lead', 'data'],
    'BelongsToMany' => ['belongs-to-many/crew', 'data.0'],
]);

it('lists a relationship field hidden for the parent record', function () {
    [$locked] = rhfTeamWithEmployee(['locked' => true], []);
    [$unlocked] = rhfTeamWithEmployee([], []);

    expect($this->getJson("/martis/api/resources/rhf-teams/{$locked->id}")->assertOk()->json('data._hidden'))->toBe(['members'])
        ->and($this->getJson("/martis/api/resources/rhf-teams/{$unlocked->id}")->assertOk()->json('data'))->not->toHaveKey('_hidden');
});

it('leaves the fields hidden for the new model out of the create forms', function () {
    $schema = $this->getJson('/martis/api/resources/rhf-employees/schema')->assertOk()->json('data');
    $inline = $this->getJson('/martis/api/resources/rhf-employees/inline-create-schema')->assertOk()->json('data.fields');

    // The Panel holds no field left and goes; the Section keeps `grade`,
    // seen on the new model.
    expect(array_map(fn (array $item): string => $item['attribute'] ?? $item['type'], $schema['fieldsForCreate']))->toBe(['name', 'section'])
        ->and(array_column($schema['fieldsForCreate'][1]['fields'], 'attribute'))->toBe(['grade'])
        ->and(array_column($schema['fieldsForInlineCreate'], 'attribute'))->toBe(['name', 'grade'])
        ->and(array_column($inline, 'attribute'))->toBe(['name', 'grade'])
        // The other lists describe the resource: a record decides.
        ->and(array_column($schema['fields'], 'attribute'))->toBe(['name', 'salary', 'bonus', 'grade', 'bonus_note'])
        ->and(array_column($schema['fieldsForIndex'], 'attribute'))->toBe(['name', 'salary', 'bonus', 'grade', 'bonus_note']);
});
