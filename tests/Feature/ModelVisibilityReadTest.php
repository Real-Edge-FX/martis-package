<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Database\Eloquent\Relations\HasOne as EloquentHasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany as EloquentMorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne as EloquentMorphOne;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\HasMany;
use Martis\Fields\HasOne;
use Martis\Fields\MorphMany;
use Martis\Fields\MorphOne;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Http\Requests\LensRequest;
use Martis\Lenses\Lens;
use Martis\Resource;
use Martis\ResourceRegistry;

// ===========================================================================
// canSeeForModel() on every read (v1.38.0).
//
// The resource's own endpoints (index, detail, the update form's values,
// the replicate form, the pickers) left a field hidden for a record out of
// its values, but the other reads of a record sent it: the rows and the
// record of a HasMany / HasOne / MorphMany / MorphOne panel (and the
// responses of their inline create and update), the rows of a lens and the
// peek card. A relationship field hidden for the parent record still served
// its panel. They now all leave the field out, and a relationship field
// hidden for the parent record answers 404 like an undeclared one.
// ===========================================================================

class MVRTeam extends Model
{
    protected $table = 'mvr_teams';

    protected $guarded = [];

    public $timestamps = false;

    public function members(): EloquentHasMany
    {
        return $this->hasMany(MVRMember::class, 'team_id');
    }

    public function lead(): EloquentHasOne
    {
        return $this->hasOne(MVRMember::class, 'team_id');
    }

    public function notes(): EloquentMorphMany
    {
        return $this->morphMany(MVRMember::class, 'owner');
    }

    public function pinned(): EloquentMorphOne
    {
        return $this->morphOne(MVRMember::class, 'owner');
    }
}

class MVRMember extends Model
{
    protected $table = 'mvr_members';

    protected $guarded = [];

    public $timestamps = false;
}

/** Every member, closed ones included. */
class MVRAllMembersLens extends Lens
{
    public function query(LensRequest $request, Builder $query): Builder
    {
        return $query->orderBy('id');
    }

    public function fields(Request $request): array
    {
        return mvrMemberFields();
    }
}

/**
 * A name, and a salary seen on an open member only.
 *
 * @return list<Text>
 */
function mvrMemberFields(): array
{
    return [
        Text::make('name'),
        Text::make('salary')->canSeeForModel(fn (Request $request, Model $model): bool => (bool) $model->getAttribute('open')),
    ];
}

class MVRMemberResource extends Resource
{
    public static function model(): string
    {
        return MVRMember::class;
    }

    public static function uriKey(): string
    {
        return 'mvr-members';
    }

    public function fields(Request $request): array
    {
        return mvrMemberFields();
    }

    public function lenses(Request $request): array
    {
        return [new MVRAllMembersLens];
    }
}

class MVRTeamResource extends Resource
{
    public static function model(): string
    {
        return MVRTeam::class;
    }

    public static function uriKey(): string
    {
        return 'mvr-teams';
    }

    public function fields(Request $request): array
    {
        // Every panel is seen on a team that is not locked only.
        $unlocked = fn (Request $request, Model $team): bool => ! $team->getAttribute('locked');

        return [
            Text::make('name'),
            HasMany::make('Members', 'members')->relatedResource('mvr-members')->canSeeForModel($unlocked),
            HasOne::make('Lead', 'lead')->relatedResource('mvr-members')->canSeeForModel($unlocked),
            MorphMany::make('Notes', 'notes')->relatedResource('mvr-members')->canSeeForModel($unlocked),
            MorphOne::make('Pinned', 'pinned')->relatedResource('mvr-members')->canSeeForModel($unlocked),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('mvr_members');
    Schema::dropIfExists('mvr_teams');

    Schema::create('mvr_teams', function ($table) {
        $table->id();
        $table->string('name')->nullable();
        $table->boolean('locked')->default(false);
    });
    Schema::create('mvr_members', function ($table) {
        $table->id();
        $table->unsignedBigInteger('team_id')->nullable();
        $table->nullableMorphs('owner');
        $table->string('name')->nullable();
        $table->string('salary')->nullable();
        $table->boolean('open')->default(false);
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(MVRMemberResource::class);
    $registry->register(MVRTeamResource::class);
});

afterEach(function () {
    Schema::dropIfExists('mvr_members');
    Schema::dropIfExists('mvr_teams');
});

/**
 * A team with one member, reachable through each of its relationships.
 *
 * @param  array<string, mixed>  $team
 * @param  array<string, mixed>  $member
 * @return array{0: MVRTeam, 1: MVRMember}
 */
function mvrTeamWithMember(array $team, array $member): array
{
    $stored = MVRTeam::create(['name' => 'Team', ...$team]);
    $related = MVRMember::create([
        'team_id' => $stored->id,
        'owner_type' => MVRTeam::class,
        'owner_id' => $stored->id,
        'name' => 'Ann',
        'salary' => '100',
        ...$member,
    ]);

    return [$stored, $related];
}

$panels = [
    'HasMany' => ['has-many/members', 'data.0'],
    'HasOne' => ['has-one/lead', 'data'],
    'MorphMany' => ['morph-many/notes', 'data.0'],
    'MorphOne' => ['morph-one/pinned', 'data'],
];

it('leaves a field hidden for a related record out of a relationship panel', function (string $path, string $row) {
    [$team] = mvrTeamWithMember([], ['open' => false]);

    $record = $this->getJson("/martis/api/resources/mvr-teams/{$team->id}/{$path}")->assertOk()->json($row);

    expect($record)->toHaveKey('name')
        ->and($record)->not->toHaveKey('salary');
})->with($panels);

it('sends a field of a relationship panel on a related record it is seen on', function (string $path, string $row) {
    [$team] = mvrTeamWithMember([], ['open' => true]);

    expect($this->getJson("/martis/api/resources/mvr-teams/{$team->id}/{$path}")->assertOk()->json("{$row}.salary"))->toBe('100');
})->with($panels);

it('leaves a field hidden for the related record out of an inline update response', function () {
    [$team, $member] = mvrTeamWithMember([], ['open' => false]);

    $data = $this->putJson("/martis/api/resources/mvr-teams/{$team->id}/has-many/members/{$member->id}", ['name' => 'Anne'])
        ->assertOk()
        ->json('data');

    expect($data['name'])->toBe('Anne')
        ->and($data)->not->toHaveKey('salary');
});

it('answers 404 for a relationship field hidden for the parent record', function (string $path) {
    [$team] = mvrTeamWithMember(['locked' => true], ['open' => true]);

    $this->getJson("/martis/api/resources/mvr-teams/{$team->id}/{$path}")->assertNotFound();
    $this->postJson("/martis/api/resources/mvr-teams/{$team->id}/{$path}", ['name' => 'New'])->assertNotFound();

    expect(MVRMember::count())->toBe(1);
})->with(['has-many/members', 'has-one/lead', 'morph-many/notes', 'morph-one/pinned']);

it('leaves a field hidden for a record out of the rows of a lens', function () {
    MVRMember::create(['name' => 'Closed', 'salary' => '100', 'open' => false]);
    MVRMember::create(['name' => 'Open', 'salary' => '200', 'open' => true]);

    $rows = $this->getJson('/martis/api/resources/mvr-members/lenses/m-v-r-all-members')->assertOk()->json('data');

    expect($rows[0])->not->toHaveKey('salary')
        ->and($rows[1]['salary'])->toBe('200');
});

it('leaves a field hidden for a record out of its peek card', function () {
    $closed = MVRMember::create(['name' => 'Closed', 'salary' => '100', 'open' => false]);
    $open = MVRMember::create(['name' => 'Open', 'salary' => '200', 'open' => true]);

    $labels = fn (MVRMember $member): array => collect(
        $this->getJson("/martis/api/resources/mvr-members/{$member->id}/peek")->assertOk()->json('data.attributes'),
    )->pluck('label')->all();

    expect($labels($closed))->toBe(['Name'])
        ->and($labels($open))->toBe(['Name', 'Salary']);
});
