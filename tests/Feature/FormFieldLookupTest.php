<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Martis\Contracts\FieldContract;
use Martis\Fields\BelongsTo;
use Martis\Fields\MorphTo;
use Martis\Fields\Select;
use Martis\Fields\Slug;
use Martis\Fields\Tag;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Layout\Panel;
use Martis\Layout\Section;
use Martis\Resource;
use Martis\ResourceRegistry;

// Endpoints that answer for one field of a form (relatable options, slug
// check, dependsOn sync, remote select options) must find a field that a
// resource declares on a form definition only (fieldsForCreate(),
// fieldsForUpdate(), fieldsForInlineCreate()), not just in fields().

// ---------------------------------------------------------------------------
// Fixtures — Models
// ---------------------------------------------------------------------------

class FflTeam extends Model
{
    protected $table = 'ffl_teams';

    protected $guarded = [];

    public $timestamps = false;
}

class FflTag extends Model
{
    protected $table = 'ffl_tags';

    protected $guarded = [];

    public $timestamps = false;
}

class FflProject extends Model
{
    protected $table = 'ffl_projects';

    protected $guarded = [];

    public $timestamps = false;
}

// ---------------------------------------------------------------------------
// Fixtures — Resources
// ---------------------------------------------------------------------------

/** @return list<FieldContract> */
function fflRelationFields(): array
{
    return [
        BelongsTo::make('team', 'Team')->relatedResource('ffl-teams')->titleAttribute('name')->nullable(),
        MorphTo::make('owner', 'Owner')->types([FflTeamResource::class, FflTagResource::class])->nullable(),
        Tag::make('tags', 'Tags')->relatedResource('ffl-tags')->titleAttribute('name')->nullable(),
    ];
}

class FflTeamResource extends Resource
{
    public static function model(): string
    {
        return FflTeam::class;
    }

    public static function uriKey(): string
    {
        return 'ffl-teams';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')->searchable()];
    }

    // The target's fence: every picker that reaches teams lists active ones.
    public static function relatableQuery(Request $request, Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}

class FflDeniedTeamResource extends FflTeamResource
{
    public static function uriKey(): string
    {
        return 'ffl-denied-teams';
    }

    public function authorizedToViewAny(Request $request): bool
    {
        return false;
    }
}

class FflTagResource extends Resource
{
    public static function model(): string
    {
        return FflTag::class;
    }

    public static function uriKey(): string
    {
        return 'ffl-tags';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')->searchable()];
    }
}

abstract class FflProjectResource extends Resource
{
    public static function model(): string
    {
        return FflProject::class;
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }

    // The source's narrowing hook for tag pickers.
    public static function relatableFflTags(Request $request, Builder $query): Builder
    {
        return $query->where('is_public', true);
    }
}

/** Relation fields on the create form only. */
class FflCreateFormProjectResource extends FflProjectResource
{
    public static function uriKey(): string
    {
        return 'ffl-create-form-projects';
    }

    public function fieldsForCreate(Request $request): array
    {
        return [
            Text::make('name'),
            Panel::make('Links', [
                ...fflRelationFields(),
                BelongsTo::make('auditor', 'Auditor')->relatedResource('ffl-denied-teams')->titleAttribute('name')->nullable(),
            ]),
        ];
    }
}

/** Relation fields on the update form only. */
class FflUpdateFormProjectResource extends FflProjectResource
{
    public static function uriKey(): string
    {
        return 'ffl-update-form-projects';
    }

    public function fieldsForUpdate(Request $request): array
    {
        return [
            Text::make('name'),
            Section::make('Links', fflRelationFields()),
        ];
    }
}

/** A team picker on the update form that reads the record under edit. */
class FflBoundUpdateFormProjectResource extends FflProjectResource
{
    public static function uriKey(): string
    {
        return 'ffl-bound-update-form-projects';
    }

    public function fieldsForUpdate(Request $request): array
    {
        return [
            Text::make('name'),
            BelongsTo::make('team', 'Team')
                ->relatedResource('ffl-teams')
                ->titleAttribute('name')
                ->relatableQueryUsing(fn (Request $request, Builder $query) => $query->whereKeyNot($this->model?->getAttribute('team_id'))),
        ];
    }
}

/** fields() and the update form declare the same picker differently. */
class FflOverriddenFormProjectResource extends FflProjectResource
{
    public static function uriKey(): string
    {
        return 'ffl-overridden-form-projects';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            BelongsTo::make('team', 'Team')->relatedResource('ffl-teams')->titleAttribute('name'),
        ];
    }

    public function fieldsForUpdate(Request $request): array
    {
        return [
            Text::make('name'),
            BelongsTo::make('team', 'Team')
                ->relatedResource('ffl-teams')
                ->titleAttribute('name')
                ->relatableQueryUsing(fn (Request $request, Builder $query) => $query->where('name', 'like', 'Beta%')),
        ];
    }
}

/** The picker lives in fields(); both forms leave it out or reuse its attribute. */
class FflFieldsOnlyProjectResource extends FflProjectResource
{
    public static function uriKey(): string
    {
        return 'ffl-fields-only-projects';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            BelongsTo::make('team', 'Team')->relatedResource('ffl-teams')->titleAttribute('name'),
        ];
    }

    public function fieldsForCreate(Request $request): array
    {
        return [Text::make('name')];
    }

    public function fieldsForUpdate(Request $request): array
    {
        // A read-only text under the picker's attribute is not a picker.
        return [Text::make('name'), Text::make('team_id', 'Team')->readonly()];
    }
}

/** Fields on the inline-create form only. */
class FflInlineFormProjectResource extends FflProjectResource
{
    public static function uriKey(): string
    {
        return 'ffl-inline-form-projects';
    }

    public function fieldsForInlineCreate(Request $request): array
    {
        return [
            Text::make('name'),
            BelongsTo::make('team', 'Team')->relatedResource('ffl-teams')->titleAttribute('name')->nullable(),
            Select::make('stage')->searchOptionsUsing(fn (string $term) => ['draft' => 'Draft', 'live' => 'Live']),
            Text::make('code')->dependsOn(['name'], function (array $form, Request $request, Text $field) {
                $field->placeholder('Code for '.($form['name'] ?? ''));
            }),
            Slug::make('slug')->from('name'),
        ];
    }
}

/** fields() and the update form declare the same slug differently. */
class FflSlugFormProjectResource extends FflProjectResource
{
    public static function uriKey(): string
    {
        return 'ffl-slug-form-projects';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name'), Slug::make('slug')->from('name')->reserved(['admin'])];
    }

    public function fieldsForUpdate(Request $request): array
    {
        return [Text::make('name'), Slug::make('slug')->from('name')->reserved(['archive'])];
    }
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::create('ffl_teams', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->boolean('is_active')->default(true);
    });

    Schema::create('ffl_tags', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->boolean('is_public')->default(true);
    });

    Schema::create('ffl_projects', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->foreignId('team_id')->nullable();
        $table->string('slug')->nullable();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    foreach ([
        FflTeamResource::class,
        FflDeniedTeamResource::class,
        FflTagResource::class,
        FflCreateFormProjectResource::class,
        FflUpdateFormProjectResource::class,
        FflBoundUpdateFormProjectResource::class,
        FflOverriddenFormProjectResource::class,
        FflFieldsOnlyProjectResource::class,
        FflInlineFormProjectResource::class,
        FflSlugFormProjectResource::class,
    ] as $resource) {
        $registry->register($resource);
    }

    $this->activeTeam = FflTeam::create(['name' => 'Active Team', 'is_active' => true]);
    FflTeam::create(['name' => 'Inactive Team', 'is_active' => false]);
    FflTeam::create(['name' => 'Beta Team', 'is_active' => true]);

    FflTag::create(['name' => 'Public Tag', 'is_public' => true]);
    FflTag::create(['name' => 'Private Tag', 'is_public' => false]);

    $this->project = FflProject::create(['name' => 'Apollo', 'team_id' => $this->activeTeam->id, 'slug' => 'apollo']);
});

afterEach(function () {
    Schema::dropIfExists('ffl_projects');
    Schema::dropIfExists('ffl_tags');
    Schema::dropIfExists('ffl_teams');

    app(ResourceRegistry::class)->flush();
});

function fflRelatable(string $resource, int|string $id, string $attribute, string $query = ''): string
{
    return "/martis/api/resources/{$resource}/{$id}/relatable/{$attribute}?per_page=30{$query}";
}

/** @return list<string> */
function fflNames(TestResponse $response): array
{
    return collect($response->json('data'))->pluck('name')->all();
}

// The target's relatableQuery() (active teams) and the source's
// relatableFflTags() (public tags) still shape every list.
dataset('ffl relation fields', [
    'BelongsTo' => ['team_id', '', ['Active Team', 'Beta Team']],
    'MorphTo' => ['owner', '&related_resource=ffl-teams', ['Active Team', 'Beta Team']],
    'Tag' => ['tags', '', ['Public Tag']],
]);

// ---------------------------------------------------------------------------
// Relatable — the form definition of the request context
// ---------------------------------------------------------------------------

it('lists the options of a relation field declared only in fieldsForCreate()', function (string $attribute, string $query, array $expected) {
    $response = $this->getJson(fflRelatable('ffl-create-form-projects', '_', $attribute, $query));

    $response->assertOk();
    expect(fflNames($response))->toBe($expected);
})->with('ffl relation fields');

it('lists the options of a relation field declared only in fieldsForUpdate()', function (string $attribute, string $query, array $expected) {
    $response = $this->getJson(fflRelatable('ffl-update-form-projects', $this->project->id, $attribute, $query));

    $response->assertOk();
    expect(fflNames($response))->toBe($expected);
})->with('ffl relation fields');

it('reads the create form for an id that names no record', function (string $id) {
    $response = $this->getJson(fflRelatable('ffl-create-form-projects', $id, 'team_id'));

    $response->assertOk();
    expect(fflNames($response))->toBe(['Active Team', 'Beta Team']);
})->with(['missing key' => '999999', 'not a key' => 'not-a-key']);

it('reads fieldsForInlineCreate() in the create context', function () {
    $response = $this->getJson(fflRelatable('ffl-inline-form-projects', '_', 'team_id'));

    $response->assertOk();
    expect(fflNames($response))->toBe(['Active Team', 'Beta Team']);
});

it('prefers the form declaration of a field over the one in fields()', function () {
    $update = $this->getJson(fflRelatable('ffl-overridden-form-projects', $this->project->id, 'team_id'));
    $create = $this->getJson(fflRelatable('ffl-overridden-form-projects', '_', 'team_id'));

    $update->assertOk();
    $create->assertOk();
    expect(fflNames($update))->toBe(['Beta Team'])
        ->and(fflNames($create))->toBe(['Active Team', 'Beta Team']);
});

it('binds the record under edit to the update form', function () {
    $response = $this->getJson(fflRelatable('ffl-bound-update-form-projects', $this->project->id, 'team_id'));

    $response->assertOk();
    // The closure left out the team the record already has.
    expect(fflNames($response))->toBe(['Beta Team']);
});

it('falls back to fields() when the form does not declare the picker', function (string $id) {
    $response = $this->getJson(fflRelatable('ffl-fields-only-projects', $id === 'record' ? $this->project->id : $id, 'team_id'));

    $response->assertOk();
    expect(fflNames($response))->toBe(['Active Team', 'Beta Team']);
})->with(['create' => '_', 'update' => 'record']);

it('keeps the related resource viewAny gate for a form-only field', function () {
    $this->getJson(fflRelatable('ffl-create-form-projects', '_', 'auditor_id'))->assertForbidden();
});

it('still answers 404 for a field no definition declares', function () {
    $this->getJson(fflRelatable('ffl-update-form-projects', '_', 'team_id'))->assertNotFound();
    $this->getJson(fflRelatable('ffl-create-form-projects', $this->project->id, 'nope'))->assertNotFound();
});

// ---------------------------------------------------------------------------
// The other per-field form endpoints
// ---------------------------------------------------------------------------

it('answers the options of a remote select declared only in fieldsForInlineCreate()', function () {
    $response = $this->getJson('/martis/api/resources/ffl-inline-form-projects/fields/stage/options?context=create&search=');

    $response->assertOk();
    expect(collect($response->json('data.options'))->pluck('value')->all())->toBe(['draft', 'live']);
});

it('syncs a dependsOn field declared only in fieldsForInlineCreate()', function () {
    $response = $this->postJson('/martis/api/resources/ffl-inline-form-projects/sync-field', [
        'field' => 'code',
        'context' => 'create',
        'formData' => ['name' => 'Apollo'],
    ]);

    $response->assertOk();
    expect($response->json('data.placeholder'))->toBe('Code for Apollo');
});

it('checks a slug declared only in fieldsForInlineCreate()', function () {
    $response = $this->getJson('/martis/api/resources/ffl-inline-form-projects/slug-check/slug?value=apollo');

    $response->assertOk();
    expect($response->json('data.available'))->toBeFalse()
        ->and($response->json('data.suggestion'))->toBe('apollo-2');
});

it('checks a slug against the declaration of the form the id names', function () {
    $update = $this->getJson('/martis/api/resources/ffl-slug-form-projects/slug-check/slug?value=archive&id='.$this->project->id);
    $create = $this->getJson('/martis/api/resources/ffl-slug-form-projects/slug-check/slug?value=archive');

    expect($update->json('data.reserved'))->toBeTrue()
        ->and($create->json('data.reserved'))->toBeFalse()
        ->and($create->json('data.available'))->toBeTrue();
});
