<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo as BelongsToRelation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\BelongsTo;
use Martis\Fields\Slug;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ===========================================================================
// The per-field form endpoints bind a record only for a user who may edit
// it (v1.38.0).
//
// The relatable options and the slug check read the update form of the
// record their URL names, so closures on its fields see that record
// (`relatableQueryUsing()` reading `$this->model`, a slug's reserved list).
// They used to bind any record the id named after a viewAny check, so a user
// who could not view or edit a record read options derived from it. A record
// the user may not update is now answered from the create form, exactly
// like an id that names no record.
// ===========================================================================

class FRATeam extends Model
{
    protected $table = 'fra_teams';

    protected $guarded = [];

    public $timestamps = false;
}

class FRAProject extends Model
{
    protected $table = 'fra_projects';

    protected $guarded = [];

    public $timestamps = false;

    public function team(): BelongsToRelation
    {
        return $this->belongsTo(FRATeam::class, 'team_id');
    }
}

class FRATeamResource extends Resource
{
    public static function model(): string
    {
        return FRATeam::class;
    }

    public static function uriKey(): string
    {
        return 'fra-teams';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

class FRAProjectResource extends Resource
{
    public static function model(): string
    {
        return FRAProject::class;
    }

    public static function uriKey(): string
    {
        return 'fra-projects';
    }

    /** Only project 1 may be edited by the current user. */
    public function authorizedToUpdate(Request $request): bool
    {
        return (int) $this->model?->getKey() === 1;
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }

    public function fieldsForCreate(Request $request): array
    {
        return [
            Text::make('name'),
            BelongsTo::make('team', 'Team')->relatedResource('fra-teams'),
            Slug::make('slug')->from('name'),
        ];
    }

    public function fieldsForUpdate(Request $request): array
    {
        return [
            Text::make('name'),
            BelongsTo::make('team', 'Team')->relatedResource('fra-teams')
                ->relatableQueryUsing(fn (Request $request, Builder $query) => $query->whereKey($this->model?->getAttribute('team_id'))),
            Slug::make('slug')->from('name')->reserved(['update-form-only']),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('fra_projects');
    Schema::dropIfExists('fra_teams');
    Schema::create('fra_teams', function ($table) {
        $table->id();
        $table->string('name');
    });
    Schema::create('fra_projects', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('slug')->nullable();
        $table->unsignedBigInteger('team_id')->nullable();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(FRATeamResource::class);
    $registry->register(FRAProjectResource::class);

    FRATeam::create(['id' => 10, 'name' => 'Own team']);
    FRATeam::create(['id' => 20, 'name' => 'Foreign team']);
    FRAProject::create(['id' => 1, 'name' => 'Mine', 'slug' => 'mine', 'team_id' => 10]);
    FRAProject::create(['id' => 2, 'name' => 'Foreign', 'slug' => 'foreign-secret', 'team_id' => 20]);
});

afterEach(function () {
    Schema::dropIfExists('fra_projects');
    Schema::dropIfExists('fra_teams');
});

/** @return list<string> */
function fraTeamTitles($response): array
{
    return array_values(array_map(fn (array $row): string => (string) $row['_title'], $response->json('data') ?? []));
}

it('answers the relatable options of an editable record from its update form', function () {
    $response = $this->getJson('/martis/api/resources/fra-projects/1/relatable/team_id')->assertOk();

    expect(fraTeamTitles($response))->toBe(['Own team']);
});

it('answers a record the user may not edit from the create form, like a missing record', function () {
    $foreign = $this->getJson('/martis/api/resources/fra-projects/2/relatable/team_id')->assertOk();
    $missing = $this->getJson('/martis/api/resources/fra-projects/999/relatable/team_id')->assertOk();

    // Nothing derived from project 2 (its team) leaks, and the answer is the
    // same as for an id that names no record.
    expect(fraTeamTitles($foreign))->toBe(['Own team', 'Foreign team'])
        ->and(fraTeamTitles($foreign))->toBe(fraTeamTitles($missing));
});

it('checks a slug against the update form only for an editable record', function () {
    $editable = $this->getJson('/martis/api/resources/fra-projects/slug-check/slug?value=update-form-only&id=1')->assertOk();
    $foreign = $this->getJson('/martis/api/resources/fra-projects/slug-check/slug?value=update-form-only&id=2')->assertOk();

    expect($editable->json('data.reserved'))->toBeTrue()
        ->and($foreign->json('data.reserved'))->toBeFalse();
});

it('leaves only an editable record out of the slug uniqueness probe', function () {
    // The record being edited keeps its own slug.
    $this->getJson('/martis/api/resources/fra-projects/slug-check/slug?value=mine&id=1')
        ->assertOk()->assertJsonPath('data.available', true);

    // A record the user may not update stays in the probe, so the answer
    // with its id matches the one without, and its slug is not told apart.
    $withId = $this->getJson('/martis/api/resources/fra-projects/slug-check/slug?value=foreign-secret&id=2')->assertOk();
    $withoutId = $this->getJson('/martis/api/resources/fra-projects/slug-check/slug?value=foreign-secret')->assertOk();

    expect($withId->json('data.available'))->toBeFalse()
        ->and($withId->json('data'))->toBe($withoutId->json('data'));
});
