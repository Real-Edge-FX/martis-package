<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany as EloquentMorphToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Martis\Actions\Action;
use Martis\Fields\BelongsTo;
use Martis\Fields\BelongsToMany;
use Martis\Fields\MorphTo;
use Martis\Fields\MorphToMany;
use Martis\Fields\Tag;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Layout\Section;
use Martis\Resource;
use Martis\ResourceRegistry;

// The pickers of a pivot action modal load their options from
// {pivot actions base}/{action}/relatable/{field}, which finds the Action
// where the panel finds it (the field's actions(), then the resource's
// pivotAction() ones) and reads the Action's own declaration of the field.

// ---------------------------------------------------------------------------
// Fixtures — Models
// ---------------------------------------------------------------------------

class ParelProject extends Model
{
    protected $table = 'parel_projects';

    protected $guarded = [];

    public $timestamps = false;

    public function teams(): EloquentBelongsToMany
    {
        return $this->belongsToMany(ParelTeam::class, 'parel_project_team', 'project_id', 'team_id');
    }

    public function tags(): EloquentMorphToMany
    {
        return $this->morphToMany(ParelTag::class, 'taggable', 'parel_taggables', 'taggable_id', 'tag_id');
    }
}

class ParelTeam extends Model
{
    protected $table = 'parel_teams';

    protected $guarded = [];

    public $timestamps = false;
}

class ParelTag extends Model
{
    protected $table = 'parel_tags';

    protected $guarded = [];

    public $timestamps = false;
}

// ---------------------------------------------------------------------------
// Fixtures — Actions and Resources
// ---------------------------------------------------------------------------

/** Declared on both panels with ->actions(). */
class ParelReassignAction extends Action
{
    public function fields(Request $request): array
    {
        return [
            BelongsTo::make('team', 'Team')->relatedResource('parel-teams')->titleAttribute('name')->nullable(),
            MorphTo::make('owner', 'Owner')->types([ParelTeamResource::class, ParelTagResource::class])->nullable(),
            Tag::make('labels', 'Labels')->relatedResource('parel-tags')->titleAttribute('name')->nullable(),
            BelongsTo::make('auditor', 'Auditor')->relatedResource('parel-denied-teams')->titleAttribute('name')->nullable(),
            Text::make('note'),
        ];
    }
}

/** A resource action flagged pivotAction(): every panel offers it. */
class ParelResourcePivotAction extends Action
{
    public function fields(Request $request): array
    {
        return [
            BelongsTo::make('team', 'Team')
                ->relatedResource('parel-teams')
                ->titleAttribute('name')
                ->relatableQueryUsing(fn (Request $request, Builder $query) => $query->where('name', 'like', 'Beta%')),
        ];
    }
}

class ParelHiddenPivotAction extends Action
{
    public function fields(Request $request): array
    {
        return [BelongsTo::make('team', 'Team')->relatedResource('parel-teams')->titleAttribute('name')];
    }
}

/** A plain resource action: no panel offers it. */
class ParelPlainAction extends Action
{
    public function fields(Request $request): array
    {
        return [BelongsTo::make('team', 'Team')->relatedResource('parel-teams')->titleAttribute('name')];
    }
}

class ParelTeamResource extends Resource
{
    public static function model(): string
    {
        return ParelTeam::class;
    }

    public static function uriKey(): string
    {
        return 'parel-teams';
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

class ParelDeniedTeamResource extends ParelTeamResource
{
    public static function uriKey(): string
    {
        return 'parel-denied-teams';
    }

    public function authorizedToViewAny(Request $request): bool
    {
        return false;
    }
}

class ParelTagResource extends Resource
{
    public static function model(): string
    {
        return ParelTag::class;
    }

    public static function uriKey(): string
    {
        return 'parel-tags';
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

class ParelProjectResource extends Resource
{
    public static function model(): string
    {
        return ParelProject::class;
    }

    public static function uriKey(): string
    {
        return 'parel-projects';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            BelongsToMany::make('Teams', 'teams')
                ->relatedResource('parel-teams')
                ->actions(fn () => [ParelReassignAction::make()]),
            Section::make(null, [
                MorphToMany::make('Tags', 'tags')
                    ->relatedResource('parel-tags')
                    ->actions(fn () => [ParelReassignAction::make()]),
            ]),
        ];
    }

    public function actions(Request $request): array
    {
        return [
            ParelResourcePivotAction::make()->pivotAction(),
            ParelHiddenPivotAction::make()->pivotAction()->canSee(fn () => false),
            ParelPlainAction::make(),
        ];
    }

    // The source's narrowing hook for tag pickers.
    public static function relatableParelTags(Request $request, Builder $query): Builder
    {
        return $query->where('is_public', true);
    }
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::create('parel_projects', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });

    Schema::create('parel_teams', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->boolean('is_active')->default(true);
    });

    Schema::create('parel_tags', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->boolean('is_public')->default(true);
    });

    Schema::create('parel_project_team', function (Blueprint $table) {
        $table->foreignId('project_id');
        $table->foreignId('team_id');
    });

    Schema::create('parel_taggables', function (Blueprint $table) {
        $table->id();
        $table->foreignId('tag_id');
        $table->morphs('taggable');
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    foreach ([
        ParelProjectResource::class,
        ParelTeamResource::class,
        ParelDeniedTeamResource::class,
        ParelTagResource::class,
    ] as $resource) {
        $registry->register($resource);
    }

    ParelTeam::create(['name' => 'Active Team', 'is_active' => true]);
    ParelTeam::create(['name' => 'Inactive Team', 'is_active' => false]);
    ParelTeam::create(['name' => 'Beta Team', 'is_active' => true]);

    ParelTag::create(['name' => 'Public Tag', 'is_public' => true]);
    ParelTag::create(['name' => 'Private Tag', 'is_public' => false]);

    $this->project = ParelProject::create(['name' => 'Apollo']);
});

afterEach(function () {
    Schema::dropIfExists('parel_taggables');
    Schema::dropIfExists('parel_project_team');
    Schema::dropIfExists('parel_tags');
    Schema::dropIfExists('parel_teams');
    Schema::dropIfExists('parel_projects');

    app(ResourceRegistry::class)->flush();
});

function parelRelatable(int|string $id, string $panel, string $action, string $attribute, string $query = ''): string
{
    return "/martis/api/resources/parel-projects/{$id}/{$panel}/actions/{$action}/relatable/{$attribute}?per_page=30{$query}";
}

/** @return list<string> */
function parelNames(TestResponse $response): array
{
    return collect($response->json('data'))->pluck('name')->all();
}

// ---------------------------------------------------------------------------
// The Action's declaration
// ---------------------------------------------------------------------------

// The target's relatableQuery() (active teams) and the parent resource's
// relatableParelTags() (public tags) shape the lists, as on the forms.
it('lists the options of a relation field a panel action declares', function (string $panel, string $attribute, string $query, array $expected) {
    $response = $this->getJson(parelRelatable($this->project->id, $panel, 'parel-reassign-action', $attribute, $query));

    $response->assertOk();
    expect(parelNames($response))->toBe($expected);
})->with([
    'BelongsToMany panel, BelongsTo' => ['belongs-to-many/teams', 'team_id', '', ['Active Team', 'Beta Team']],
    'BelongsToMany panel, MorphTo' => ['belongs-to-many/teams', 'owner', '&related_resource=parel-teams', ['Active Team', 'Beta Team']],
    'BelongsToMany panel, Tag' => ['belongs-to-many/teams', 'labels', '', ['Public Tag']],
    'MorphToMany panel in a layout, BelongsTo' => ['morph-to-many/tags', 'team_id', '', ['Active Team', 'Beta Team']],
]);

it('reads a resource action flagged pivotAction() with its own scope', function (string $panel) {
    $response = $this->getJson(parelRelatable($this->project->id, $panel, 'parel-resource-pivot-action', 'team_id'));

    $response->assertOk();
    expect(parelNames($response))->toBe(['Beta Team']);
})->with(['belongs-to-many/teams', 'morph-to-many/tags']);

// ---------------------------------------------------------------------------
// What the panel does not offer
// ---------------------------------------------------------------------------

it('answers 404 for an action the panel does not offer', function () {
    $this->getJson(parelRelatable($this->project->id, 'belongs-to-many/teams', 'parel-plain-action', 'team_id'))->assertNotFound();
    $this->getJson(parelRelatable($this->project->id, 'belongs-to-many/teams', 'nope', 'team_id'))->assertNotFound();
});

it('answers 404 for an attribute the action does not declare as a relation field', function () {
    $this->getJson(parelRelatable($this->project->id, 'belongs-to-many/teams', 'parel-reassign-action', 'note'))->assertNotFound();
    $this->getJson(parelRelatable($this->project->id, 'belongs-to-many/teams', 'parel-reassign-action', 'name'))->assertNotFound();
});

it('answers 404 for a relationship the resource does not declare with the route type', function () {
    $this->getJson(parelRelatable($this->project->id, 'morph-to-many/teams', 'parel-reassign-action', 'team_id'))->assertNotFound();
    $this->getJson(parelRelatable($this->project->id, 'belongs-to-many/nope', 'parel-reassign-action', 'team_id'))->assertNotFound();
});

it('answers 404 for a parent record that does not exist', function () {
    $this->getJson(parelRelatable(999999, 'belongs-to-many/teams', 'parel-reassign-action', 'team_id'))->assertNotFound();
});

// ---------------------------------------------------------------------------
// Authorisation
// ---------------------------------------------------------------------------

it('keeps the Action canSee() gate', function () {
    $this->getJson(parelRelatable($this->project->id, 'belongs-to-many/teams', 'parel-hidden-pivot-action', 'team_id'))->assertForbidden();
});

it('keeps the viewAny gate of the related resource', function () {
    $this->getJson(parelRelatable($this->project->id, 'belongs-to-many/teams', 'parel-reassign-action', 'auditor_id'))->assertForbidden();
});
