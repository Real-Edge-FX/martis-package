<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ── Test Fixtures ────────────────────────────────────────────────

class AuthzOpenModel extends Model
{
    use SoftDeletes;

    protected $table = 'authz_open_items';

    protected $fillable = ['title'];
}

class AuthzLockedModel extends Model
{
    use SoftDeletes;

    protected $table = 'authz_locked_items';

    protected $fillable = ['title'];
}

class AuthzEndpointPermissivePolicy
{
    public function viewAny($user): bool
    {
        return true;
    }

    public function view($user, $model): bool
    {
        return true;
    }

    public function create($user): bool
    {
        return true;
    }

    public function update($user, $model): bool
    {
        return true;
    }

    public function delete($user, $model): bool
    {
        return true;
    }

    public function restore($user, $model): bool
    {
        return true;
    }

    public function forceDelete($user, $model): bool
    {
        return true;
    }

    public function replicate($user, $model): bool
    {
        return true;
    }
}

class AuthzEndpointRestrictivePolicy
{
    public function viewAny($user): bool
    {
        return true;
    }

    public function view($user, $model): bool
    {
        return false;
    }

    public function create($user): bool
    {
        return false;
    }

    public function update($user, $model): bool
    {
        return false;
    }

    public function delete($user, $model): bool
    {
        return false;
    }

    public function restore($user, $model): bool
    {
        return false;
    }

    public function forceDelete($user, $model): bool
    {
        return false;
    }

    public function replicate($user, $model): bool
    {
        return false;
    }
}

class AuthzOpenItemResource extends Resource
{
    public static ?string $policy = AuthzEndpointPermissivePolicy::class;

    public static function model(): string
    {
        return AuthzOpenModel::class;
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')->required()];
    }
}

class AuthzLockedItemResource extends Resource
{
    public static ?string $policy = AuthzEndpointRestrictivePolicy::class;

    public static function model(): string
    {
        return AuthzLockedModel::class;
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')->required()];
    }
}

// ── Setup ────────────────────────────────────────────────────────

beforeEach(function () {
    config([
        'database.default' => 'mysql',
        'database.connections.mysql' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'martis_playground',
            'username' => 'martis',
            'password' => 'martis_password',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => false,
        ],
    ]);

    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('authz_locked_items');
    Schema::dropIfExists('authz_open_items');

    Schema::create('authz_open_items', function ($table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('authz_locked_items', function ($table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
        $table->softDeletes();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(AuthzOpenItemResource::class);
    $registry->register(AuthzLockedItemResource::class);

    $this->user = (new Authenticatable)->forceFill(['id' => 1, 'name' => 'Test User']);
    $this->actingAs($this->user);
});

afterEach(function () {
    Schema::dropIfExists('authz_locked_items');
    Schema::dropIfExists('authz_open_items');
    Resource::flushPolicyCache();
});

// ── ForceDelete Endpoint ─────────────────────────────────────────

it('force deletes a soft-deleted record when authorized', function () {
    $uriKey = AuthzOpenItemResource::uriKey();
    $item = AuthzOpenModel::create(['title' => 'Force Delete Me']);
    $item->delete();

    $response = $this->deleteJson("/martis/api/resources/{$uriKey}/{$item->id}/force");

    $response->assertStatus(200);
    expect(AuthzOpenModel::withTrashed()->find($item->id))->toBeNull();
});

it('denies force delete when policy forbids', function () {
    $uriKey = AuthzLockedItemResource::uriKey();
    $item = AuthzLockedModel::create(['title' => 'Cannot Force Delete']);
    $item->delete();

    $response = $this->deleteJson("/martis/api/resources/{$uriKey}/{$item->id}/force");

    $response->assertStatus(404);
    expect(AuthzLockedModel::withTrashed()->find($item->id))->not->toBeNull();
});

// ── Replicate Endpoint ──────────────────────────────────────────

it('returns replicate fields when authorized', function () {
    $uriKey = AuthzOpenItemResource::uriKey();
    $item = AuthzOpenModel::create(['title' => 'Replicate Me']);

    $response = $this->getJson("/martis/api/resources/{$uriKey}/{$item->id}/replicate");

    $response->assertStatus(200);
    $response->assertJsonStructure(['data' => ['values', 'fromResourceId']]);
    expect((int) $response->json('data.fromResourceId'))->toBe($item->id);
});

it('denies replicate fields when policy forbids', function () {
    $uriKey = AuthzLockedItemResource::uriKey();
    $item = AuthzLockedModel::create(['title' => 'Cannot Replicate']);

    $response = $this->getJson("/martis/api/resources/{$uriKey}/{$item->id}/replicate");

    $response->assertStatus(404);
});

// ── Authorization Metadata in Responses ─────────────────────────

it('show includes _authorization metadata in response', function () {
    $uriKey = AuthzOpenItemResource::uriKey();
    $item = AuthzOpenModel::create(['title' => 'Check Auth Meta']);

    $response = $this->getJson("/martis/api/resources/{$uriKey}/{$item->id}");

    $response->assertStatus(200);
    $data = $response->json('data');
    expect($data)->toHaveKey('_authorization');
    expect($data['_authorization'])->toHaveKeys([
        'authorizedToView',
        'authorizedToUpdate',
        'authorizedToDelete',
        'authorizedToReplicate',
    ]);
});

it('schema includes collection authorization metadata', function () {
    $uriKey = AuthzOpenItemResource::uriKey();

    $response = $this->getJson("/martis/api/resources/{$uriKey}/schema");

    $response->assertStatus(200);
    $data = $response->json('data');
    expect($data)->toHaveKey('authorization');
    expect($data['authorization'])->toHaveKey('authorizedToCreate');
});

// ── Authorization Enforcement on Standard Endpoints ─────────────

it('denies show when view is not authorized', function () {
    $uriKey = AuthzLockedItemResource::uriKey();
    $item = AuthzLockedModel::create(['title' => 'Cannot View']);

    $response = $this->getJson("/martis/api/resources/{$uriKey}/{$item->id}");

    $response->assertStatus(404);
});

it('denies store when create is not authorized', function () {
    $uriKey = AuthzLockedItemResource::uriKey();

    $response = $this->postJson("/martis/api/resources/{$uriKey}", ['title' => 'Blocked']);

    expect($response->status())->toBeGreaterThanOrEqual(400);
});

it('denies update when update is not authorized', function () {
    $uriKey = AuthzLockedItemResource::uriKey();
    $item = AuthzLockedModel::create(['title' => 'Cannot Update']);

    $response = $this->putJson("/martis/api/resources/{$uriKey}/{$item->id}", ['title' => 'Blocked']);

    expect($response->status())->toBeGreaterThanOrEqual(400);
});

it('denies destroy when delete is not authorized', function () {
    $uriKey = AuthzLockedItemResource::uriKey();
    $item = AuthzLockedModel::create(['title' => 'Cannot Delete']);

    $response = $this->deleteJson("/martis/api/resources/{$uriKey}/{$item->id}");

    expect($response->status())->toBeGreaterThanOrEqual(400);
    expect(AuthzLockedModel::find($item->id))->not->toBeNull();
});

// ── Route Registration ──────────────────────────────────────────

it('registers forceDelete and replicate routes', function () {
    $routeNames = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($r) => $r->getName())
        ->filter()
        ->values()
        ->toArray();

    expect($routeNames)->toContain('martis.api.resources.force-delete');
    expect($routeNames)->toContain('martis.api.resources.replicate');
});
