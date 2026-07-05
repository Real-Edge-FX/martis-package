<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\BelongsTo;
use Martis\Fields\Slug;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// Test fixtures — Models
// ---------------------------------------------------------------------------

class RoutableTeamModel extends Model
{
    protected $table = 'routable_teams';

    protected $fillable = ['name'];
}

class RoutableNormalModel extends Model
{
    protected $table = 'routable_normal_items';

    protected $fillable = ['title', 'slug', 'team_id'];
}

class RoutableHeadlessModel extends Model
{
    protected $table = 'routable_headless_items';

    protected $fillable = ['title', 'slug', 'team_id'];
}

// ---------------------------------------------------------------------------
// Test fixtures — Resources
// ---------------------------------------------------------------------------

class RoutableTeamResource extends Resource
{
    public static function model(): string
    {
        return RoutableTeamModel::class;
    }

    public static function uriKey(): string
    {
        return 'routable-teams';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->searchable(),
        ];
    }
}

// A normal resource: routable() defaults to true.
class RoutableNormalResource extends Resource
{
    public static function model(): string
    {
        return RoutableNormalModel::class;
    }

    public static function uriKey(): string
    {
        return 'routable-normal-items';
    }

    public static function titleAttribute(): string
    {
        return 'title';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->searchable()->required(),
            Slug::make('slug')->from('title'),
            BelongsTo::make('team_id', 'Team')
                ->relatedResource('routable-teams')
                ->titleAttribute('name')
                ->nullable(),
        ];
    }
}

// A headless resource: routable() overridden to false. Still registered and
// usable as a relation target / data source.
class RoutableHeadlessResource extends Resource
{
    public static function model(): string
    {
        return RoutableHeadlessModel::class;
    }

    public static function uriKey(): string
    {
        return 'routable-headless-items';
    }

    public static function titleAttribute(): string
    {
        return 'title';
    }

    public static function routable(): bool
    {
        return false;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->searchable()->required(),
            Slug::make('slug')->from('title'),
            BelongsTo::make('team_id', 'Team')
                ->relatedResource('routable-teams')
                ->titleAttribute('name')
                ->nullable(),
        ];
    }
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::create('routable_teams', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('routable_normal_items', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->string('slug')->unique();
        $table->foreignId('team_id')->nullable();
        $table->timestamps();
    });

    Schema::create('routable_headless_items', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->string('slug')->unique();
        $table->foreignId('team_id')->nullable();
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->register(RoutableTeamResource::class);
    $registry->register(RoutableNormalResource::class);
    $registry->register(RoutableHeadlessResource::class);

    RoutableTeamModel::create(['name' => 'Alpha Team']);

    RoutableNormalModel::create(['title' => 'Normal One', 'slug' => 'normal-one']);
    RoutableHeadlessModel::create(['title' => 'Headless One', 'slug' => 'headless-one']);
});

afterEach(function () {
    Schema::dropIfExists('routable_normal_items');
    Schema::dropIfExists('routable_headless_items');
    Schema::dropIfExists('routable_teams');

    app(ResourceRegistry::class)->flush();
});

// ---------------------------------------------------------------------------
// Blocked: human/routable surface returns 404 for the non-routable resource
// ---------------------------------------------------------------------------

it('returns 404 for index on a non-routable resource', function () {
    $this->getJson('/martis/api/resources/routable-headless-items')->assertNotFound();
});

it('returns 404 for show on a non-routable resource', function () {
    $item = RoutableHeadlessModel::first();
    $this->getJson("/martis/api/resources/routable-headless-items/{$item->id}")->assertNotFound();
});

it('returns 404 for schema on a non-routable resource', function () {
    $this->getJson('/martis/api/resources/routable-headless-items/schema')->assertNotFound();
});

it('returns 404 for store on a non-routable resource', function () {
    $this->postJson('/martis/api/resources/routable-headless-items', [
        'title' => 'New',
        'slug' => 'new-headless',
    ])->assertNotFound();
});

it('returns 404 for update on a non-routable resource', function () {
    $item = RoutableHeadlessModel::first();
    $this->putJson("/martis/api/resources/routable-headless-items/{$item->id}", [
        'title' => 'Updated',
        'slug' => 'headless-one',
    ])->assertNotFound();
});

it('returns 404 for destroy on a non-routable resource', function () {
    $item = RoutableHeadlessModel::first();
    $this->deleteJson("/martis/api/resources/routable-headless-items/{$item->id}")->assertNotFound();
});

// ---------------------------------------------------------------------------
// Backward-compat: the normal (routable-by-default) resource still works
// ---------------------------------------------------------------------------

it('still returns 200 for index on a normal (routable) resource', function () {
    $this->getJson('/martis/api/resources/routable-normal-items')->assertOk();
});

it('still returns 200 for show on a normal (routable) resource', function () {
    $item = RoutableNormalModel::first();
    $this->getJson("/martis/api/resources/routable-normal-items/{$item->id}")->assertOk();
});

it('still returns 200 for schema on a normal (routable) resource', function () {
    $this->getJson('/martis/api/resources/routable-normal-items/schema')->assertOk();
});

// ---------------------------------------------------------------------------
// Kept working: relation / data-source endpoints stay usable (CRITICAL)
// ---------------------------------------------------------------------------

it('keeps the relatable endpoint working for a non-routable resource as the related target', function () {
    $response = $this->getJson(
        '/martis/api/resources/_/_/relatable/team_id?related_resource=routable-headless-items'
    );

    $response->assertOk();
    // relatable here targets the routable-teams resource via BelongsTo field
    // defined on the headless resource — confirms the headless resource's
    // own field configuration is used to resolve relatable options.
});

it('keeps the relatable endpoint working when the source resource is non-routable', function () {
    $item = RoutableHeadlessModel::first();

    $response = $this->getJson("/martis/api/resources/routable-headless-items/{$item->id}/relatable/team_id");

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['name'])->toBe('Alpha Team');
});

it('keeps the slug-check endpoint working for a non-routable resource', function () {
    $response = $this->getJson('/martis/api/resources/routable-headless-items/slug-check/slug?value=brand-new-slug');

    $response->assertOk();
    $response->assertJsonPath('data.available', true);
});

// ---------------------------------------------------------------------------
// Excluded from listings: navigation, command palette, global search
// ---------------------------------------------------------------------------

it('excludes the non-routable resource from the navigation payload', function () {
    $response = $this->getJson('/martis/api/navigation');

    $response->assertOk();
    $json = json_encode($response->json());

    expect($json)->not->toContain('routable-headless-items');
    expect($json)->toContain('routable-normal-items');
});

it('excludes the non-routable resource from the command palette payload', function () {
    $response = $this->getJson('/martis/api/command-palette');

    $response->assertOk();
    $resources = $response->json('resources');
    $uriKeys = collect($resources)->pluck('uriKey')->all();

    expect($uriKeys)->not->toContain('routable-headless-items');
    expect($uriKeys)->toContain('routable-normal-items');
});

it('excludes the non-routable resource from global search results', function () {
    $response = $this->getJson('/martis/api/search?q=One');

    $response->assertOk();
    $json = json_encode($response->json());

    expect($json)->not->toContain('routable-headless-items');
});
