<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne as EloquentHasOne;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\HasOne;
use Martis\Fields\Text;
use Martis\Fields\Textarea;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------

class HOParentModel extends Model
{
    protected $table = 'ho_test_parents';

    protected $fillable = ['name'];

    public function profile(): EloquentHasOne
    {
        return $this->hasOne(HOChildModel::class, 'parent_id');
    }
}

class HOChildModel extends Model
{
    protected $table = 'ho_test_children';

    protected $fillable = ['bio', 'website', 'parent_id'];
}

class HOParentResource extends Resource
{
    public static function model(): string
    {
        return HOParentModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->sortable()->searchable()->required(),
            HasOne::make('Profile', 'profile')->relatedResource('h-o-child-models'),
        ];
    }
}

class HOChildResource extends Resource
{
    public static function model(): string
    {
        return HOChildModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('bio')->nullable(),
            Textarea::make('website')->nullable(),
        ];
    }
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    Schema::dropIfExists('ho_test_children');
    Schema::dropIfExists('ho_test_parents');

    Schema::create('ho_test_parents', function ($t) {
        $t->id();
        $t->string('name');
        $t->timestamps();
    });

    Schema::create('ho_test_children', function ($t) {
        $t->id();
        $t->string('bio')->nullable();
        $t->string('website')->nullable();
        $t->foreignId('parent_id')->constrained('ho_test_parents')->cascadeOnDelete();
        $t->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->register(HOParentResource::class);
    $registry->register(HOChildResource::class);

    $this->withoutMiddleware(MartisAuthenticate::class);
});

afterEach(function () {
    Schema::dropIfExists('ho_test_children');
    Schema::dropIfExists('ho_test_parents');
});

// ---------------------------------------------------------------------------
// GET (show) — returns null when no related record
// ---------------------------------------------------------------------------

it('has-one show returns null when no related record', function () {
    $parent = HOParentModel::create(['name' => 'Alice']);
    $uri = HOParentResource::uriKey();

    $response = $this->getJson("/martis/api/resources/{$uri}/{$parent->id}/has-one/profile");

    $response->assertStatus(200)->assertJson(['data' => null]);
});

// ---------------------------------------------------------------------------
// GET (show) — returns the related record
// ---------------------------------------------------------------------------

it('has-one show returns related record when it exists', function () {
    $parent = HOParentModel::create(['name' => 'Bob']);
    HOChildModel::create(['bio' => 'Engineer', 'website' => 'https://example.com', 'parent_id' => $parent->id]);
    $uri = HOParentResource::uriKey();

    $response = $this->getJson("/martis/api/resources/{$uri}/{$parent->id}/has-one/profile");

    $response->assertStatus(200)
        ->assertJsonPath('data.bio', 'Engineer');
});

// ---------------------------------------------------------------------------
// POST (store)
// ---------------------------------------------------------------------------

it('has-one store creates related record', function () {
    $parent = HOParentModel::create(['name' => 'Carol']);
    $uri = HOParentResource::uriKey();

    $response = $this->postJson(
        "/martis/api/resources/{$uri}/{$parent->id}/has-one/profile",
        ['bio' => 'Designer', 'website' => null]
    );

    $response->assertStatus(201)->assertJsonPath('data.bio', 'Designer');
    expect(HOChildModel::where('parent_id', $parent->id)->count())->toBe(1);
});

it('has-one store rejects creation when related already exists', function () {
    $parent = HOParentModel::create(['name' => 'Dave']);
    HOChildModel::create(['bio' => 'Existing', 'parent_id' => $parent->id]);
    $uri = HOParentResource::uriKey();

    $response = $this->postJson(
        "/martis/api/resources/{$uri}/{$parent->id}/has-one/profile",
        ['bio' => 'Duplicate']
    );

    $response->assertStatus(500);
});

// ---------------------------------------------------------------------------
// PUT (update)
// ---------------------------------------------------------------------------

it('has-one update modifies the related record', function () {
    $parent = HOParentModel::create(['name' => 'Eve']);
    HOChildModel::create(['bio' => 'Old bio', 'parent_id' => $parent->id]);
    $uri = HOParentResource::uriKey();

    $response = $this->putJson(
        "/martis/api/resources/{$uri}/{$parent->id}/has-one/profile",
        ['bio' => 'Updated bio']
    );

    $response->assertStatus(200)->assertJsonPath('data.bio', 'Updated bio');
});

it('has-one update returns 404 when no related record', function () {
    $parent = HOParentModel::create(['name' => 'Frank']);
    $uri = HOParentResource::uriKey();

    $response = $this->putJson(
        "/martis/api/resources/{$uri}/{$parent->id}/has-one/profile",
        ['bio' => 'Orphan bio']
    );

    $response->assertStatus(404);
});

// ---------------------------------------------------------------------------
// DELETE (destroy)
// ---------------------------------------------------------------------------

it('has-one destroy deletes the related record', function () {
    $parent = HOParentModel::create(['name' => 'Grace']);
    HOChildModel::create(['bio' => 'To be deleted', 'parent_id' => $parent->id]);
    $uri = HOParentResource::uriKey();

    $response = $this->deleteJson(
        "/martis/api/resources/{$uri}/{$parent->id}/has-one/profile"
    );

    $response->assertStatus(200);
    expect(HOChildModel::where('parent_id', $parent->id)->count())->toBe(0);
});

it('has-one destroy returns 404 when no related record', function () {
    $parent = HOParentModel::create(['name' => 'Henry']);
    $uri = HOParentResource::uriKey();

    $response = $this->deleteJson(
        "/martis/api/resources/{$uri}/{$parent->id}/has-one/profile"
    );

    $response->assertStatus(404);
});

// ---------------------------------------------------------------------------
// Error: invalid resource
// ---------------------------------------------------------------------------

it('has-one returns 404 for unknown resource', function () {
    $response = $this->getJson('/martis/api/resources/unknown-resource/1/has-one/profile');

    $response->assertStatus(404);
});

it('has-one returns 404 for unknown parent', function () {
    $uri = HOParentResource::uriKey();

    $response = $this->getJson("/martis/api/resources/{$uri}/99999/has-one/profile");

    $response->assertStatus(404);
});
