<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany as EloquentMorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany as EloquentMorphToMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Martis\Enums\SortDirection;
use Martis\Fields\BelongsToMany;
use Martis\Fields\HasMany;
use Martis\Fields\MorphMany;
use Martis\Fields\MorphToMany;
use Martis\Fields\Number;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Http\Requests\LensRequest;
use Martis\Layout\Panel;
use Martis\Lenses\Lens;
use Martis\Resource;
use Martis\ResourceRegistry;

// ===========================================================================
// Sorting by a field the user cannot see (v1.38.0).
//
// A `?sort=` naming a sortable field whose canSee() denies ordered the
// resource index, the relationship panels and a lens by that field, so the
// order of the rows told the order of values the user may not read. A lens
// ordered by any column `?sort=` named, one no field exposes included (and a
// column that does not exist failed the query on MySQL / PostgreSQL). Only
// a sortable field the user can see orders a list now; any other `?sort=`
// is ignored like an unknown attribute, and the list keeps its default
// order.
// ===========================================================================

class HSQTeam extends Model
{
    protected $table = 'hsq_teams';

    protected $guarded = [];

    public $timestamps = false;

    public function members(): EloquentHasMany
    {
        return $this->hasMany(HSQMember::class, 'team_id');
    }

    public function notes(): EloquentMorphMany
    {
        return $this->morphMany(HSQMember::class, 'owner');
    }

    public function crew(): EloquentBelongsToMany
    {
        return $this->belongsToMany(HSQMember::class, 'hsq_crew', 'team_id', 'member_id');
    }

    public function guests(): EloquentMorphToMany
    {
        return $this->morphToMany(HSQMember::class, 'host', 'hsq_guests', null, 'member_id');
    }
}

class HSQMember extends Model
{
    protected $table = 'hsq_members';

    protected $guarded = [];

    public $timestamps = false;
}

/**
 * A name, a salary the user cannot see and a rank, all three sortable. The
 * salary sits in a Panel, as a layout container does not change the rule.
 *
 * @return list<Text|Number|Panel>
 */
function hsqMemberFields(): array
{
    return [
        Text::make('name')->sortable(),
        Panel::make('Pay', [
            Number::make('salary')->sortable()->canSee(fn () => false),
        ]),
        Number::make('rank')->sortable(),
    ];
}

/** Every member, in the order of their ids unless the request sorts them. */
class HSQRosterLens extends Lens
{
    public function query(LensRequest $request, Builder $query): Builder
    {
        return $request->withOrdering($request->withFilters($query), fn (Builder $q): Builder => $q->orderBy('id'));
    }

    public function fields(Request $request): array
    {
        return hsqMemberFields();
    }
}

/** Orders by the column `?sort=` names itself, without withOrdering(). */
class HSQRawSortLens extends Lens
{
    public function query(LensRequest $request, Builder $query): Builder
    {
        return $request->sortColumn !== null
            ? $query->orderBy($request->sortColumn, $request->sortDirection->value)
            : $query->orderBy('id');
    }

    public function fields(Request $request): array
    {
        return hsqMemberFields();
    }
}

class HSQMemberResource extends Resource
{
    public static function model(): string
    {
        return HSQMember::class;
    }

    public static function uriKey(): string
    {
        return 'hsq-members';
    }

    public function fields(Request $request): array
    {
        return hsqMemberFields();
    }

    public function lenses(Request $request): array
    {
        return [new HSQRosterLens, new HSQRawSortLens];
    }
}

/** The members' resource, loading its index sorted by the salary. */
class HSQSalarySortedMemberResource extends HSQMemberResource
{
    public static function uriKey(): string
    {
        return 'hsq-salary-sorted-members';
    }

    public static function defaultSort(): ?string
    {
        return 'salary';
    }

    public static function defaultSortDirection(): SortDirection
    {
        return SortDirection::Asc;
    }
}

class HSQTeamResource extends Resource
{
    public static function model(): string
    {
        return HSQTeam::class;
    }

    public static function uriKey(): string
    {
        return 'hsq-teams';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            HasMany::make('Members', 'members')->relatedResource('hsq-members'),
            MorphMany::make('Notes', 'notes')->relatedResource('hsq-members'),
            BelongsToMany::make('Crew', 'crew')->relatedResource('hsq-members'),
            MorphToMany::make('Guests', 'guests')->relatedResource('hsq-members'),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    foreach (['hsq_guests', 'hsq_crew', 'hsq_members', 'hsq_teams'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('hsq_teams', function ($table) {
        $table->id();
        $table->string('name')->nullable();
    });
    Schema::create('hsq_members', function ($table) {
        $table->id();
        $table->unsignedBigInteger('team_id')->nullable();
        $table->nullableMorphs('owner');
        $table->string('name');
        $table->integer('salary');
        $table->integer('rank');
        // A column no field exposes.
        $table->string('code');
        // A lens caches on the table's COUNT(*) and MAX(updated_at).
        $table->timestamps();
    });
    Schema::create('hsq_crew', function ($table) {
        $table->unsignedBigInteger('team_id');
        $table->unsignedBigInteger('member_id');
    });
    Schema::create('hsq_guests', function ($table) {
        $table->unsignedBigInteger('member_id');
        $table->morphs('host');
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(HSQMemberResource::class);
    $registry->register(HSQSalarySortedMemberResource::class);
    $registry->register(HSQTeamResource::class);

    // Stored in this order, so the default (id) order is Cara, Abe, Bo; the
    // salary orders them Abe, Bo, Cara, the rank Bo, Cara, Abe and the code
    // Bo, Cara, Abe.
    $this->team = HSQTeam::create(['name' => 'Team']);
    foreach ([['Cara', 300, 2, 'b'], ['Abe', 100, 3, 'c'], ['Bo', 200, 1, 'a']] as [$name, $salary, $rank, $code]) {
        $member = HSQMember::create([
            'team_id' => $this->team->id,
            'owner_type' => HSQTeam::class,
            'owner_id' => $this->team->id,
            'name' => $name,
            'salary' => $salary,
            'rank' => $rank,
            'code' => $code,
        ]);
        $this->team->crew()->attach($member->id);
        $this->team->guests()->attach($member->id);
    }
});

afterEach(function () {
    foreach (['hsq_guests', 'hsq_crew', 'hsq_members', 'hsq_teams'] as $table) {
        Schema::dropIfExists($table);
    }
});

/** @return list<string> */
function hsqNames(TestResponse $response): array
{
    return array_column($response->assertOk()->json('data'), 'name');
}

$lists = [
    'resource index' => '/martis/api/resources/hsq-members',
    'HasMany panel' => '/martis/api/resources/hsq-teams/{team}/has-many/members',
    'MorphMany panel' => '/martis/api/resources/hsq-teams/{team}/morph-many/notes',
    'BelongsToMany panel' => '/martis/api/resources/hsq-teams/{team}/belongs-to-many/crew',
    'MorphToMany panel' => '/martis/api/resources/hsq-teams/{team}/morph-to-many/guests',
    'lens' => '/martis/api/resources/hsq-members/lenses/h-s-q-roster',
    'lens reading sortColumn' => '/martis/api/resources/hsq-members/lenses/h-s-q-raw-sort',
];

it('ignores a sort by a sortable field the user cannot see', function (string $uri) {
    $uri = str_replace('{team}', (string) $this->team->id, $uri);

    expect(hsqNames($this->getJson("{$uri}?sort=salary&direction=asc")))->toBe(['Cara', 'Abe', 'Bo'])
        ->and(hsqNames($this->getJson("{$uri}?sort=salary&direction=desc")))->toBe(['Cara', 'Abe', 'Bo']);
})->with($lists);

it('sorts by a sortable field the user can see', function (string $uri) {
    $uri = str_replace('{team}', (string) $this->team->id, $uri);

    expect(hsqNames($this->getJson("{$uri}?sort=rank&direction=asc")))->toBe(['Bo', 'Cara', 'Abe'])
        ->and(hsqNames($this->getJson("{$uri}?sort=name&direction=desc")))->toBe(['Cara', 'Bo', 'Abe']);
})->with($lists);

it('ignores a default sort by a field the user cannot see', function () {
    expect(hsqNames($this->getJson('/martis/api/resources/hsq-salary-sorted-members')))->toBe(['Cara', 'Abe', 'Bo']);
});

it('ignores a sort by a column no field exposes', function (string $uri) {
    $uri = str_replace('{team}', (string) $this->team->id, $uri);

    expect(hsqNames($this->getJson("{$uri}?sort=code&direction=asc")))->toBe(['Cara', 'Abe', 'Bo'])
        ->and(hsqNames($this->getJson("{$uri}?sort=code&direction=desc")))->toBe(['Cara', 'Abe', 'Bo']);
})->with($lists);

it('keeps a lens sort by a column that does not exist out of the query', function (string $lens) {
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    expect(hsqNames($this->getJson("/martis/api/resources/hsq-members/lenses/{$lens}?sort=no_such_column&direction=desc")))->toBe(['Cara', 'Abe', 'Bo'])
        ->and(implode("\n", $queries))->not->toContain('no_such_column');
})->with(['h-s-q-roster', 'h-s-q-raw-sort']);

it('ignores a sort direction that is not a string', function (string $uri) {
    $uri = str_replace('{team}', (string) $this->team->id, $uri);

    expect(hsqNames($this->getJson("{$uri}?sort=rank&direction[]=desc")))->toBe(['Bo', 'Cara', 'Abe']);
})->with($lists);
