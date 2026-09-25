<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough as EloquentHasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOneThrough as EloquentHasOneThrough;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\HasMany;
use Martis\Fields\HasManyThrough;
use Martis\Fields\HasOne;
use Martis\Fields\HasOneThrough;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * A Through relationship reaches its records across an intermediate model and
 * has no foreign key of its own: a create through it would write the parent's
 * key into the relationship's second key (`client_id` below), which links the
 * record to whichever intermediate has that id. As in Nova, a Through
 * relationship takes no create, so the relationship endpoints refuse one
 * (403). An update or a delete through it reaches a record the relationship
 * holds, under the related resource's policies, as on a plain hasMany. Each
 * route is probed below, next to the nearest case on the other side.
 */

// ---------------------------------------------------------------------------
// Fixtures: manager -> clients (manager_id) -> projects (client_id)
// ---------------------------------------------------------------------------

class TROManagerModel extends Model
{
    protected $table = 'tro_managers';

    protected $fillable = ['name'];

    public function clients(): EloquentHasMany
    {
        return $this->hasMany(TROClientModel::class, 'manager_id');
    }

    public function projects(): EloquentHasManyThrough
    {
        return $this->hasManyThrough(TROProjectModel::class, TROClientModel::class, 'manager_id', 'client_id');
    }

    public function firstProject(): EloquentHasOneThrough
    {
        return $this->hasOneThrough(TROProjectModel::class, TROClientModel::class, 'manager_id', 'client_id');
    }
}

class TROClientModel extends Model
{
    protected $table = 'tro_clients';

    protected $fillable = ['name', 'manager_id'];
}

class TROProjectModel extends Model
{
    protected $table = 'tro_projects';

    protected $fillable = ['title', 'client_id'];
}

/** The Through relationships declared with the Through fields. */
class TROManagerResource extends Resource
{
    public static function model(): string
    {
        return TROManagerModel::class;
    }

    public static function uriKey(): string
    {
        return 'tro-managers';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->required(),
            HasMany::make('Clients', 'clients')->relatedResource('tro-clients'),
            HasManyThrough::make('Projects', 'projects')->relatedResource('tro-projects'),
            HasOneThrough::make('First project', 'firstProject')->relatedResource('tro-projects'),
        ];
    }
}

/** The same Through relationships declared with the plain HasMany / HasOne fields. */
class TROPlainFieldManagerResource extends Resource
{
    public static function model(): string
    {
        return TROManagerModel::class;
    }

    public static function uriKey(): string
    {
        return 'tro-plain-managers';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->required(),
            HasMany::make('Projects', 'projects')->relatedResource('tro-projects'),
            HasOne::make('First project', 'firstProject')->relatedResource('tro-projects'),
        ];
    }
}

/** A HasManyThrough field declared on a plain hasMany relationship. */
class TROMisdeclaredManagerResource extends Resource
{
    public static function model(): string
    {
        return TROManagerModel::class;
    }

    public static function uriKey(): string
    {
        return 'tro-misdeclared-managers';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->required(),
            HasManyThrough::make('Clients', 'clients')->relatedResource('tro-clients'),
        ];
    }
}

/** The Through fields pointing at a related resource whose policy denies writes. */
class TROLockedManagerResource extends Resource
{
    public static function model(): string
    {
        return TROManagerModel::class;
    }

    public static function uriKey(): string
    {
        return 'tro-locked-managers';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->required(),
            HasManyThrough::make('Projects', 'projects')->relatedResource('tro-locked-projects'),
            HasOneThrough::make('First project', 'firstProject')->relatedResource('tro-locked-projects'),
        ];
    }
}

class TROClientResource extends Resource
{
    public static function model(): string
    {
        return TROClientModel::class;
    }

    public static function uriKey(): string
    {
        return 'tro-clients';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->required(),
        ];
    }
}

class TROProjectResource extends Resource
{
    public static function model(): string
    {
        return TROProjectModel::class;
    }

    public static function uriKey(): string
    {
        return 'tro-projects';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->required(),
        ];
    }
}

class TROLockedProjectResource extends TROProjectResource
{
    public static function uriKey(): string
    {
        return 'tro-locked-projects';
    }

    public function authorizedToUpdate(Request $request): bool
    {
        return false;
    }

    public function authorizedToDelete(Request $request): bool
    {
        return false;
    }
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('tro_projects');
    Schema::dropIfExists('tro_clients');
    Schema::dropIfExists('tro_managers');

    Schema::create('tro_managers', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('tro_clients', function ($table) {
        $table->id();
        $table->string('name');
        $table->unsignedBigInteger('manager_id');
        $table->timestamps();
    });

    Schema::create('tro_projects', function ($table) {
        $table->id();
        $table->string('title');
        $table->unsignedBigInteger('client_id');
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(TROManagerResource::class);
    $registry->register(TROPlainFieldManagerResource::class);
    $registry->register(TROMisdeclaredManagerResource::class);
    $registry->register(TROLockedManagerResource::class);
    $registry->register(TROClientResource::class);
    $registry->register(TROProjectResource::class);
    $registry->register(TROLockedProjectResource::class);

    // Each manager's id is the id of the other manager's client, so a create
    // that puts the parent's key into `client_id` files the project under
    // the other manager.
    $this->alice = TROManagerModel::create(['name' => 'Alice']);
    $this->bob = TROManagerModel::create(['name' => 'Bob']);
    $this->aliceClient = TROClientModel::forceCreate(['id' => $this->bob->id, 'name' => 'Alice client', 'manager_id' => $this->alice->id]);
    $this->bobClient = TROClientModel::forceCreate(['id' => $this->alice->id, 'name' => 'Bob client', 'manager_id' => $this->bob->id]);
    $this->aliceProject = TROProjectModel::create(['title' => 'Alice project', 'client_id' => $this->aliceClient->id]);
    $this->bobProject = TROProjectModel::create(['title' => 'Bob project', 'client_id' => $this->bobClient->id]);

    // Carol has no project, so a has-one create goes past its "already
    // exists" check; the client with her id is Alice's, where such a create
    // would file the project.
    $this->carol = TROManagerModel::create(['name' => 'Carol']);
    TROClientModel::forceCreate(['id' => $this->carol->id, 'name' => 'Alice second client', 'manager_id' => $this->alice->id]);
});

afterEach(function () {
    Schema::dropIfExists('tro_projects');
    Schema::dropIfExists('tro_clients');
    Schema::dropIfExists('tro_managers');
    app(ResourceRegistry::class)->flush();
});

/** @return list<array<string, mixed>> */
function troTable(string $table): array
{
    return DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
}

/** The URL of `$path` on `$manager`'s record, `{project}` standing for `$project`'s id. */
function troUrl(string $resource, TROManagerModel $manager, string $path, TROProjectModel $project): string
{
    return "/martis/api/resources/{$resource}/{$manager->id}/".str_replace('{project}', (string) $project->id, $path);
}

// ---------------------------------------------------------------------------
// A create through a Through relationship is refused
// ---------------------------------------------------------------------------

dataset('tro through creates', [
    'HasManyThrough field: POST has-many store' => ['tro-managers', 'bob', 'has-many/projects', 'hasManyThrough'],
    'HasMany field on a hasManyThrough relationship: POST has-many store' => ['tro-plain-managers', 'bob', 'has-many/projects', 'hasManyThrough'],
    'HasManyThrough field on a plain hasMany relationship: POST has-many store' => ['tro-misdeclared-managers', 'bob', 'has-many/clients', 'hasManyThrough'],
    'HasOneThrough field: POST has-one store' => ['tro-managers', 'bob', 'has-one/firstProject', 'hasOneThrough'],
    'HasOne field on a hasOneThrough relationship: POST has-one store' => ['tro-plain-managers', 'bob', 'has-one/firstProject', 'hasOneThrough'],
    'HasOneThrough field: POST has-one store for a parent with no record' => ['tro-managers', 'carol', 'has-one/firstProject', 'hasOneThrough'],
    'HasOne field on a hasOneThrough relationship: POST has-one store for a parent with no record' => ['tro-plain-managers', 'carol', 'has-one/firstProject', 'hasOneThrough'],
]);

it('refuses to create a record through a Through relationship and writes nothing', function (string $resource, string $manager, string $path, string $kind) {
    $projects = troTable('tro_projects');
    $clients = troTable('tro_clients');

    $this->postJson(troUrl($resource, $this->{$manager}, $path, $this->bobProject), [
        'title' => 'Created through the relationship',
        'name' => 'Created through the relationship',
    ])
        ->assertStatus(403)
        ->assertJsonPath('message', "Records cannot be created through a {$kind} relationship.");

    expect(troTable('tro_projects'))->toBe($projects)
        ->and(troTable('tro_clients'))->toBe($clients);
})->with('tro through creates');

// ---------------------------------------------------------------------------
// An update or a delete through a Through relationship reaches its record
// ---------------------------------------------------------------------------

dataset('tro through updates', [
    'HasManyThrough field: PUT has-many update' => ['tro-managers', 'has-many/projects/{project}'],
    'HasMany field on a hasManyThrough relationship: PUT has-many update' => ['tro-plain-managers', 'has-many/projects/{project}'],
    'HasOneThrough field: PUT has-one update' => ['tro-managers', 'has-one/firstProject'],
    'HasOne field on a hasOneThrough relationship: PUT has-one update' => ['tro-plain-managers', 'has-one/firstProject'],
]);

dataset('tro through deletes', [
    'HasManyThrough field: DELETE has-many destroy' => ['tro-managers', 'has-many/projects/{project}'],
    'HasMany field on a hasManyThrough relationship: DELETE has-many destroy' => ['tro-plain-managers', 'has-many/projects/{project}'],
    'HasOneThrough field: DELETE has-one destroy' => ['tro-managers', 'has-one/firstProject'],
    'HasOne field on a hasOneThrough relationship: DELETE has-one destroy' => ['tro-plain-managers', 'has-one/firstProject'],
]);

it('updates the related record through a Through relationship without moving it', function (string $resource, string $path) {
    $this->putJson(troUrl($resource, $this->alice, $path, $this->aliceProject), ['title' => 'Renamed through the relationship'])
        ->assertStatus(200)
        ->assertJsonPath('data.title', 'Renamed through the relationship');

    $project = $this->aliceProject->fresh();
    expect($project->title)->toBe('Renamed through the relationship')
        ->and($project->client_id)->toBe($this->aliceClient->id)
        ->and($this->bobProject->fresh()->title)->toBe('Bob project');
})->with('tro through updates');

it('deletes the related record through a Through relationship', function (string $resource, string $path) {
    $this->deleteJson(troUrl($resource, $this->alice, $path, $this->aliceProject))->assertStatus(200);

    expect(TROProjectModel::find($this->aliceProject->id))->toBeNull()
        ->and(TROProjectModel::find($this->bobProject->id))->not->toBeNull();
})->with('tro through deletes');

it('answers 404 to an update or a delete of a record the Through relationship does not reach', function (string $method) {
    $projects = troTable('tro_projects');

    $this->{$method}(troUrl('tro-managers', $this->alice, 'has-many/projects/{project}', $this->bobProject), ['title' => 'Renamed through the relationship'])
        ->assertStatus(404);

    expect(troTable('tro_projects'))->toBe($projects);
})->with(['putJson', 'deleteJson']);

it('refuses an update or a delete through a Through relationship that the related policy denies', function (string $method, string $path) {
    $projects = troTable('tro_projects');

    $this->{$method}(troUrl('tro-locked-managers', $this->alice, $path, $this->aliceProject), ['title' => 'Renamed through the relationship'])
        ->assertStatus(403)
        ->assertJsonPath('message', 'This action is unauthorized.');

    expect(troTable('tro_projects'))->toBe($projects);
})->with([
    'PUT has-many update' => ['putJson', 'has-many/projects/{project}'],
    'DELETE has-many destroy' => ['deleteJson', 'has-many/projects/{project}'],
    'PUT has-one update' => ['putJson', 'has-one/firstProject'],
    'DELETE has-one destroy' => ['deleteJson', 'has-one/firstProject'],
]);

// ---------------------------------------------------------------------------
// The schema the detail page reads
// ---------------------------------------------------------------------------

it('offers no Create on the Through panels and keeps their other actions', function () {
    $fields = collect($this->getJson('/martis/api/resources/tro-managers/schema')
        ->assertStatus(200)
        ->json('data.fieldsForDetail'))->keyBy('relationship');

    expect($fields['projects']['type'])->toBe('has_many_through')
        ->and($fields['projects']['hasManyMeta'])->toMatchArray([
            'canCreate' => false,
            'canUpdate' => true,
            'canDelete' => true,
            'hideRestoreAction' => false,
            'hideForceDeleteAction' => false,
        ])
        ->and($fields['firstProject']['type'])->toBe('has_one_through')
        ->and($fields['firstProject']['hasOneMeta'])->toMatchArray([
            'canCreate' => false,
            'canUpdate' => true,
            'canDelete' => true,
        ])
        // The plain HasMany on the same parent offers Create.
        ->and($fields['clients']['hasManyMeta'])->toMatchArray([
            'canCreate' => true,
            'canUpdate' => true,
            'canDelete' => true,
        ]);
});

// ---------------------------------------------------------------------------
// Controls: reads stay open, the plain hasMany next to them stays writable
// ---------------------------------------------------------------------------

it('still lists the records of a HasManyThrough relationship', function () {
    $this->getJson("/martis/api/resources/tro-managers/{$this->alice->id}/has-many/projects")
        ->assertStatus(200)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.title', 'Alice project');
});

it('still shows the record of a HasOneThrough relationship', function () {
    $this->getJson("/martis/api/resources/tro-managers/{$this->alice->id}/has-one/firstProject")
        ->assertStatus(200)
        ->assertJsonPath('data.title', 'Alice project');
});

it('still creates, updates and deletes through the plain hasMany on the same parent', function () {
    $base = "/martis/api/resources/tro-managers/{$this->alice->id}/has-many/clients";

    $created = $this->postJson($base, ['name' => 'New client'])->assertStatus(201);
    $newClient = TROClientModel::findOrFail($created->json('data.id'));
    expect($newClient->manager_id)->toBe($this->alice->id);

    $this->putJson("{$base}/{$newClient->id}", ['name' => 'Renamed client'])->assertStatus(200);
    expect($newClient->fresh()->name)->toBe('Renamed client');

    $this->deleteJson("{$base}/{$newClient->id}")->assertStatus(200);
    expect(TROClientModel::find($newClient->id))->toBeNull();
});
