<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough as EloquentHasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOneThrough as EloquentHasOneThrough;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\HasMany;
use Martis\Fields\HasManyThrough;
use Martis\Fields\HasOne;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * A create through a Through relationship writes the parent's key into the
 * related record's key to the intermediate model (the project's `client_id`
 * here), which files the record under whichever intermediate has that id.
 * The has-many and has-one stores refuse it (403), except through a
 * HasManyThrough field that opted in with canCreate(true), the 1.x escape
 * hatch for an app that sets that key itself.
 */

class TRCTeamModel extends Model
{
    protected $table = 'trc_teams';

    protected $guarded = [];

    public function plainProjects(): EloquentHasManyThrough
    {
        return $this->hasManyThrough(TRCProjectModel::class, TRCClientModel::class, 'team_id', 'client_id');
    }

    public function throughProjects(): EloquentHasManyThrough
    {
        return $this->hasManyThrough(TRCProjectModel::class, TRCClientModel::class, 'team_id', 'client_id');
    }

    public function optInProjects(): EloquentHasManyThrough
    {
        return $this->hasManyThrough(TRCProjectModel::class, TRCClientModel::class, 'team_id', 'client_id');
    }

    public function firstProject(): EloquentHasOneThrough
    {
        return $this->hasOneThrough(TRCProjectModel::class, TRCClientModel::class, 'team_id', 'client_id');
    }
}

class TRCClientModel extends Model
{
    protected $table = 'trc_clients';

    protected $guarded = [];
}

class TRCProjectModel extends Model
{
    protected $table = 'trc_projects';

    protected $guarded = [];
}

class TRCProjectResource extends Resource
{
    public static function model(): string
    {
        return TRCProjectModel::class;
    }

    public static function uriKey(): string
    {
        return 'trc-projects';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')->required()];
    }
}

/** An app that sets the intermediate key itself, as canCreate(true) expects. */
class TRCOptInProjectResource extends TRCProjectResource
{
    public static function uriKey(): string
    {
        return 'trc-opt-in-projects';
    }

    public function beforeSave(Model $model, Request $request, bool $creating): void
    {
        if ($creating) {
            $model->setAttribute('client_id', (int) $request->input('client'));
        }

        parent::beforeSave($model, $request, $creating);
    }
}

class TRCTeamResource extends Resource
{
    public static function model(): string
    {
        return TRCTeamModel::class;
    }

    public static function uriKey(): string
    {
        return 'trc-teams';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            HasMany::make('Plain projects', 'plainProjects')->relatedResource('trc-projects'),
            HasManyThrough::make('Through projects', 'throughProjects')->relatedResource('trc-projects'),
            HasManyThrough::make('Opt-in projects', 'optInProjects')->relatedResource('trc-opt-in-projects')->canCreate(true),
            HasOne::make('First project', 'firstProject')->relatedResource('trc-projects'),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    foreach (['trc_projects', 'trc_clients', 'trc_teams'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('trc_teams', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    Schema::create('trc_clients', function ($table) {
        $table->id();
        $table->string('name');
        $table->unsignedBigInteger('team_id');
        $table->timestamps();
    });
    Schema::create('trc_projects', function ($table) {
        $table->id();
        $table->string('name');
        $table->unsignedBigInteger('client_id');
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    foreach ([TRCProjectResource::class, TRCOptInProjectResource::class, TRCTeamResource::class] as $resource) {
        $registry->register($resource);
    }

    // The team's id (1) is another team's client id: a create that writes
    // the team's key into `client_id` files the project under that client.
    $this->team = TRCTeamModel::create(['name' => 'Team A']);
    $other = TRCTeamModel::create(['name' => 'Team B']);
    TRCClientModel::forceCreate(['id' => 1, 'name' => 'Team B client', 'team_id' => $other->id]);
    $this->client = TRCClientModel::forceCreate(['id' => 50, 'name' => 'Team A client', 'team_id' => $this->team->id]);
});

afterEach(function () {
    foreach (['trc_projects', 'trc_clients', 'trc_teams'] as $table) {
        Schema::dropIfExists($table);
    }
});

it('refuses a create through a plain HasMany field declared on a hasManyThrough relationship', function () {
    $this->postJson("/martis/api/resources/trc-teams/{$this->team->id}/has-many/plainProjects", ['name' => 'Misfiled'])
        ->assertStatus(403)
        ->assertJsonPath('message', 'Records cannot be created through a hasManyThrough relationship.');

    expect(TRCProjectModel::count())->toBe(0);
});

it('refuses a create through a HasManyThrough field that did not opt in', function () {
    $this->postJson("/martis/api/resources/trc-teams/{$this->team->id}/has-many/throughProjects", ['name' => 'Misfiled'])
        ->assertStatus(403);

    expect(TRCProjectModel::count())->toBe(0);
});

it('keeps the canCreate(true) escape hatch of a HasManyThrough field', function () {
    $this->postJson("/martis/api/resources/trc-teams/{$this->team->id}/has-many/optInProjects", ['name' => 'Filed by the app', 'client' => $this->client->id])
        ->assertStatus(201);

    expect(TRCProjectModel::sole()->client_id)->toBe($this->client->id);
});

it('refuses a create through a HasOne field declared on a hasOneThrough relationship', function () {
    $this->postJson("/martis/api/resources/trc-teams/{$this->team->id}/has-one/firstProject", ['name' => 'Misfiled'])
        ->assertStatus(403)
        ->assertJsonPath('message', 'Records cannot be created through a hasOneThrough relationship.');

    expect(TRCProjectModel::count())->toBe(0);
});
