<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Database\Eloquent\Relations\HasOne as EloquentHasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany as EloquentMorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne as EloquentMorphOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\Boolean;
use Martis\Fields\HasMany;
use Martis\Fields\HasOne;
use Martis\Fields\MorphMany;
use Martis\Fields\MorphOne;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ===========================================================================
// canSeeForModel() on every write (v1.38.0).
//
// A field hidden for a record with canSeeForModel() / canSeeUsingPolicy()
// only lost its value on the record's reads: the resource update, the
// create, the inline create and the inline forms of a relationship panel
// validated it and wrote the value the request sent, so a user who could not
// see a record's salary could set it (and an update form, which sends every
// field, emptied it). Every write now decides the field on the record it
// writes, as Nova resolves the fields of the resource instance a request
// updates: a field hidden for the stored record is neither validated nor
// written and the column keeps its value, and a create decides on the new,
// unsaved model, before any value of the request is written to it.
// ===========================================================================

class MVWTeam extends Model
{
    protected $table = 'mvw_teams';

    protected $guarded = [];

    public $timestamps = false;

    public function members(): EloquentHasMany
    {
        return $this->hasMany(MVWMember::class, 'team_id');
    }

    public function lead(): EloquentHasOne
    {
        return $this->hasOne(MVWMember::class, 'team_id');
    }

    public function notes(): EloquentMorphMany
    {
        return $this->morphMany(MVWMember::class, 'owner');
    }

    public function pinned(): EloquentMorphOne
    {
        return $this->morphOne(MVWMember::class, 'owner');
    }
}

class MVWMember extends Model
{
    protected $table = 'mvw_members';

    protected $guarded = [];

    public $timestamps = false;
}

class MVWUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}

class MVWMemberResource extends Resource
{
    public static function model(): string
    {
        return MVWMember::class;
    }

    public static function uriKey(): string
    {
        return 'mvw-members';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->nullable(),
            // Seen (and written) on an open record only.
            Text::make('salary')
                ->rules(['required', 'max:5'])
                ->canSeeForModel(fn (Request $request, Model $model): bool => (bool) $model->getAttribute('open')),
            // Seen on the record being created only.
            Text::make('bonus')
                ->nullable()
                ->canSeeForModel(fn (Request $request, Model $model): bool => ! $model->exists),
            // The Gate sugar: the `viewGrade` ability on the record.
            Text::make('grade')->nullable()->canSeeUsingPolicy('viewGrade'),
            Boolean::make('open'),
        ];
    }
}

class MVWTeamResource extends Resource
{
    public static function model(): string
    {
        return MVWTeam::class;
    }

    public static function uriKey(): string
    {
        return 'mvw-teams';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->nullable(),
            HasMany::make('Members', 'members')->relatedResource('mvw-members'),
            HasOne::make('Lead', 'lead')->relatedResource('mvw-members'),
            MorphMany::make('Notes', 'notes')->relatedResource('mvw-members'),
            MorphOne::make('Pinned', 'pinned')->relatedResource('mvw-members'),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('mvw_members');
    Schema::dropIfExists('mvw_teams');

    Schema::create('mvw_teams', function ($table) {
        $table->id();
        $table->string('name')->nullable();
    });
    Schema::create('mvw_members', function ($table) {
        $table->id();
        $table->unsignedBigInteger('team_id')->nullable();
        $table->nullableMorphs('owner');
        $table->string('name')->nullable();
        $table->string('salary')->nullable();
        $table->string('bonus')->nullable();
        $table->string('grade')->nullable();
        $table->boolean('open')->default(false);
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(MVWMemberResource::class);
    $registry->register(MVWTeamResource::class);

    Gate::define('viewGrade', fn (MVWUser $user, MVWMember $member): bool => (bool) $member->open);
});

afterEach(function () {
    Schema::dropIfExists('mvw_members');
    Schema::dropIfExists('mvw_teams');
});

/**
 * A team with one member, reachable through each of its relationships.
 *
 * @param  array<string, mixed>  $member
 * @return array{0: MVWTeam, 1: MVWMember}
 */
function mvwTeamWithMember(array $member): array
{
    $team = MVWTeam::create(['name' => 'Team']);
    $stored = MVWMember::create([
        'team_id' => $team->id,
        'owner_type' => MVWTeam::class,
        'owner_id' => $team->id,
        ...$member,
    ]);

    return [$team, $stored];
}

/** @return array<string, mixed> */
function mvwStored(MVWMember $member): array
{
    $fresh = $member->fresh();

    return [
        'name' => $fresh->name,
        'salary' => $fresh->salary,
        'bonus' => $fresh->bonus,
        'open' => (bool) $fresh->open,
    ];
}

// ---------------------------------------------------------------------------
// The resource update
// ---------------------------------------------------------------------------

it('neither validates nor writes a field hidden for the record the update writes', function () {
    $member = MVWMember::create(['name' => 'Ann', 'salary' => '100', 'open' => false]);

    // `salary` would fail max:5; `open` becomes true, but the field is
    // decided on the stored record, before the request's values are written.
    $this->putJson(cardWriteUrl("/martis/api/resources/mvw-members/{$member->id}"), [
        'name' => 'Anne',
        'salary' => 'far too long',
        'open' => true,
    ])->assertOk();

    expect(mvwStored($member))->toBe(['name' => 'Anne', 'salary' => '100', 'bonus' => null, 'open' => true]);
});

it('keeps the value of a field hidden for the record when the update form sends it empty', function () {
    $member = MVWMember::create(['name' => 'Ann', 'salary' => '100', 'open' => false]);

    // The update form sends every field of its schema, an empty one as null.
    $this->putJson(cardWriteUrl("/martis/api/resources/mvw-members/{$member->id}"), [
        'name' => 'Anne',
        'salary' => null,
        'bonus' => null,
        'open' => false,
    ])->assertOk();

    expect(mvwStored($member))->toBe(['name' => 'Anne', 'salary' => '100', 'bonus' => null, 'open' => false]);
});

it('validates and writes the field on a record it is seen on', function () {
    $member = MVWMember::create(['name' => 'Ann', 'salary' => '100', 'open' => true]);

    $invalid = $this->putJson(cardWriteUrl("/martis/api/resources/mvw-members/{$member->id}"), ['salary' => 'far too long']);

    $invalid->assertStatus(422);
    expect(collect($invalid->json('errors'))->pluck('field')->all())->toBe(['salary']);

    // `bonus` is seen on the record being created only.
    $this->putJson(cardWriteUrl("/martis/api/resources/mvw-members/{$member->id}"), ['salary' => '200', 'bonus' => 'forged'])->assertOk();

    expect(mvwStored($member))->toBe(['name' => 'Ann', 'salary' => '200', 'bonus' => null, 'open' => true]);
});

it('decides canSeeUsingPolicy() on the record the update writes', function () {
    $this->actingAs((new MVWUser)->forceFill(['id' => 1, 'name' => 'U']));

    $closed = MVWMember::create(['name' => 'Closed', 'grade' => 'A', 'open' => false]);
    $open = MVWMember::create(['name' => 'Open', 'grade' => 'A', 'salary' => '1', 'open' => true]);

    $this->putJson(cardWriteUrl("/martis/api/resources/mvw-members/{$closed->id}"), ['grade' => 'F'])->assertOk();
    $this->putJson(cardWriteUrl("/martis/api/resources/mvw-members/{$open->id}"), ['grade' => 'B'])->assertOk();

    expect($closed->fresh()->grade)->toBe('A')
        ->and($open->fresh()->grade)->toBe('B');
});

// ---------------------------------------------------------------------------
// The creates: decided on the new, unsaved model
// ---------------------------------------------------------------------------

it('decides a create on the new model before writing any value to it', function (string $uri) {
    // `salary` is required, but hidden for a record that is not open yet:
    // the request's `open` is not written when the field is decided.
    $this->postJson($uri, [
        'name' => 'New',
        'salary' => 'far too long',
        'bonus' => 'b',
        'open' => true,
    ])->assertStatus(201);

    expect(mvwStored(MVWMember::where('name', 'New')->sole()))
        ->toBe(['name' => 'New', 'salary' => null, 'bonus' => 'b', 'open' => true]);
})->with([
    'resource create' => '/martis/api/resources/mvw-members',
    'inline create' => '/martis/api/resources/mvw-members/inline-create',
]);

// ---------------------------------------------------------------------------
// The inline forms of a relationship panel
// ---------------------------------------------------------------------------

$relationships = [
    'HasMany' => ['has-many/members', true],
    'HasOne' => ['has-one/lead', false],
    'MorphMany' => ['morph-many/notes', true],
    'MorphOne' => ['morph-one/pinned', false],
];

it('neither validates nor writes a field hidden for the related record an inline update writes', function (string $path, bool $many) {
    [$team, $member] = mvwTeamWithMember(['name' => 'Ann', 'salary' => '100', 'open' => false]);

    $uri = "/martis/api/resources/mvw-teams/{$team->id}/{$path}".($many ? "/{$member->id}" : '');

    $this->putJson(cardWriteUrl($uri), ['name' => 'Anne', 'salary' => 'far too long', 'bonus' => 'forged', 'open' => true])->assertOk();

    expect(mvwStored($member))->toBe(['name' => 'Anne', 'salary' => '100', 'bonus' => null, 'open' => true]);
})->with($relationships);

it('decides an inline create on the new related model', function (string $path, bool $many) {
    $team = MVWTeam::create(['name' => 'Team']);

    $this->postJson("/martis/api/resources/mvw-teams/{$team->id}/{$path}", [
        'name' => 'New',
        'salary' => 'far too long',
        'bonus' => 'b',
        'open' => true,
    ])->assertStatus(201);

    expect(mvwStored(MVWMember::where('name', 'New')->sole()))
        ->toBe(['name' => 'New', 'salary' => null, 'bonus' => 'b', 'open' => true]);
})->with($relationships);
