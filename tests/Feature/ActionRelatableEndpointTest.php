<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Martis\Actions\Action;
use Martis\Fields\BelongsTo;
use Martis\Fields\MorphTo;
use Martis\Fields\Tag;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// The relation pickers of an Action modal (BelongsTo, MorphTo, Tag) load
// their options from GET /api/resources/{resource}/actions/{action}/relatable/{field},
// which reads the field the Action declares. They used to ask the page's
// resource, which does not declare the Action's fields (404, empty picker)
// or declares the attribute with another related resource or scope.

// ---------------------------------------------------------------------------
// Fixtures — Models
// ---------------------------------------------------------------------------

class AreTeam extends Model
{
    protected $table = 'are_teams';

    protected $guarded = [];

    public $timestamps = false;
}

class AreTag extends Model
{
    protected $table = 'are_tags';

    protected $guarded = [];

    public $timestamps = false;
}

class AreProject extends Model
{
    protected $table = 'are_projects';

    protected $guarded = [];

    public $timestamps = false;
}

// ---------------------------------------------------------------------------
// Fixtures — Resources and Actions
// ---------------------------------------------------------------------------

class AreTeamResource extends Resource
{
    public static function model(): string
    {
        return AreTeam::class;
    }

    public static function uriKey(): string
    {
        return 'are-teams';
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

class AreDeniedTeamResource extends AreTeamResource
{
    public static function uriKey(): string
    {
        return 'are-denied-teams';
    }

    public function authorizedToViewAny(Request $request): bool
    {
        return false;
    }
}

class AreTagResource extends Resource
{
    public static function model(): string
    {
        return AreTag::class;
    }

    public static function uriKey(): string
    {
        return 'are-tags';
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

class AreAssignOwner extends Action
{
    public function fields(Request $request): array
    {
        return [
            // Same attribute as the resource's own picker, declared without its closure.
            BelongsTo::make('team', 'Team')->relatedResource('are-teams')->titleAttribute('name')->nullable(),
            MorphTo::make('owner', 'Owner')->types([AreTeamResource::class, AreTagResource::class])->nullable(),
            Tag::make('labels', 'Labels')->relatedResource('are-tags')->titleAttribute('name')->nullable(),
            BelongsTo::make('reviewer', 'Reviewer')
                ->relatedResource('are-teams')
                ->titleAttribute('name')
                ->relatableQueryUsing(fn (Request $request, Builder $query) => $query->where('name', 'like', 'Beta%')),
            BelongsTo::make('auditor', 'Auditor')->relatedResource('are-denied-teams')->titleAttribute('name')->nullable(),
            Text::make('note'),
        ];
    }
}

class AreHiddenAction extends Action
{
    public function fields(Request $request): array
    {
        return [BelongsTo::make('team', 'Team')->relatedResource('are-teams')->titleAttribute('name')];
    }
}

class AreProjectResource extends Resource
{
    public static function model(): string
    {
        return AreProject::class;
    }

    public static function uriKey(): string
    {
        return 'are-projects';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            // The resource's own team picker lists the Beta teams only.
            BelongsTo::make('team', 'Team')
                ->relatedResource('are-teams')
                ->titleAttribute('name')
                ->relatableQueryUsing(fn (Request $request, Builder $query) => $query->where('name', 'like', 'Beta%')),
        ];
    }

    public function actions(Request $request): array
    {
        return [
            AreAssignOwner::make(),
            AreHiddenAction::make()->canSee(fn () => false),
        ];
    }

    // The source's narrowing hook for tag pickers.
    public static function relatableAreTags(Request $request, Builder $query): Builder
    {
        return $query->where('is_public', true);
    }
}

class AreRestrictedProjectResource extends AreProjectResource
{
    public static function uriKey(): string
    {
        return 'are-restricted-projects';
    }

    public function authorizedToViewAny(Request $request): bool
    {
        return false;
    }
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::create('are_teams', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->boolean('is_active')->default(true);
    });

    Schema::create('are_tags', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->boolean('is_public')->default(true);
    });

    Schema::create('are_projects', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->foreignId('team_id')->nullable();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    foreach ([
        AreTeamResource::class,
        AreDeniedTeamResource::class,
        AreTagResource::class,
        AreProjectResource::class,
        AreRestrictedProjectResource::class,
    ] as $resource) {
        $registry->register($resource);
    }

    AreTeam::create(['name' => 'Active Team', 'is_active' => true]);
    AreTeam::create(['name' => 'Inactive Team', 'is_active' => false]);
    AreTeam::create(['name' => 'Beta Team', 'is_active' => true]);

    AreTag::create(['name' => 'Public Tag', 'is_public' => true]);
    AreTag::create(['name' => 'Private Tag', 'is_public' => false]);

    AreProject::create(['name' => 'Apollo']);
});

afterEach(function () {
    Schema::dropIfExists('are_projects');
    Schema::dropIfExists('are_tags');
    Schema::dropIfExists('are_teams');

    app(ResourceRegistry::class)->flush();
});

function areRelatable(string $resource, string $action, string $attribute, string $query = ''): string
{
    return "/martis/api/resources/{$resource}/actions/{$action}/relatable/{$attribute}?per_page=30{$query}";
}

/** @return list<string> */
function areNames(TestResponse $response): array
{
    return collect($response->json('data'))->pluck('name')->all();
}

// ---------------------------------------------------------------------------
// The Action's declaration
// ---------------------------------------------------------------------------

// The target's relatableQuery() (active teams) and the source's
// relatableAreTags() (public tags) shape the lists, as on the forms.
it('lists the options of a relation field the Action declares', function (string $attribute, string $query, array $expected) {
    $response = $this->getJson(areRelatable('are-projects', 'are-assign-owner', $attribute, $query));

    $response->assertOk();
    expect(areNames($response))->toBe($expected);
})->with([
    'BelongsTo' => ['team_id', '', ['Active Team', 'Beta Team']],
    'MorphTo' => ['owner', '&related_resource=are-teams', ['Active Team', 'Beta Team']],
    'Tag' => ['labels', '', ['Public Tag']],
]);

it('reads the Action declaration of an attribute the resource also declares', function () {
    $action = $this->getJson(areRelatable('are-projects', 'are-assign-owner', 'team_id'));
    $resource = $this->getJson('/martis/api/resources/are-projects/_/relatable/team_id?per_page=30');

    $action->assertOk();
    $resource->assertOk();
    expect(areNames($action))->toBe(['Active Team', 'Beta Team'])
        ->and(areNames($resource))->toBe(['Beta Team']);
});

it('applies the relatableQueryUsing() closure of the Action field', function () {
    $response = $this->getJson(areRelatable('are-projects', 'are-assign-owner', 'reviewer_id'));

    $response->assertOk();
    expect(areNames($response))->toBe(['Beta Team']);
});

it('searches the options of an Action field', function () {
    $response = $this->getJson(areRelatable('are-projects', 'are-assign-owner', 'team_id', '&search=Beta'));

    $response->assertOk();
    expect(areNames($response))->toBe(['Beta Team']);
});

it('answers 404 for an attribute the Action does not declare as a relation field', function (string $attribute) {
    $this->getJson(areRelatable('are-projects', 'are-assign-owner', $attribute))->assertNotFound();
})->with([
    'a field of the resource only' => 'name',
    'a non-relation field of the Action' => 'note',
    'an undeclared attribute' => 'nope',
]);

it('answers 404 for an unknown action or resource', function () {
    $this->getJson(areRelatable('are-projects', 'nope', 'team_id'))->assertNotFound();
    $this->getJson(areRelatable('nope', 'are-assign-owner', 'team_id'))->assertNotFound();
});

// ---------------------------------------------------------------------------
// Authorisation
// ---------------------------------------------------------------------------

it('keeps the Action canSee() gate', function () {
    $this->getJson(areRelatable('are-projects', 'are-hidden-action', 'team_id'))->assertForbidden();
});

it('keeps the viewAny gate of the resource', function () {
    $this->getJson(areRelatable('are-restricted-projects', 'are-assign-owner', 'team_id'))->assertForbidden();
});

it('keeps the viewAny gate of the related resource', function () {
    $this->getJson(areRelatable('are-projects', 'are-assign-owner', 'auditor_id'))->assertForbidden();
});
