<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Martis\FieldContext;
use Martis\Fields\Field;
use Martis\Fields\Text;
use Martis\Resource;
use Martis\Tests\TestCase;

uses(TestCase::class)
    ->beforeEach(function () {
        request()->setUserResolver(fn () => auth()->user());
    })
    ->afterEach(function () {
        Resource::flushPolicyCache();
    });

function testUser(): Authenticatable
{
    return (new Authenticatable)->forceFill(['id' => 1, 'name' => 'Test']);
}

// ── Test Fixtures ────────────────────────────────────────────────

class AuthzModel extends Model
{
    protected $table = 'users';

    protected $guarded = [];
}

class AuthzSoftModel extends Model
{
    use SoftDeletes;

    protected $table = 'users';

    protected $guarded = [];
}

// --- Policies ---

class AuthzPermissivePolicy
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

    public function runAction($user, $model): bool
    {
        return true;
    }

    public function runDestructiveAction($user, $model): bool
    {
        return true;
    }

    public function add($user, $model, string $relatedModelClass): bool
    {
        return true;
    }

    public function attachAny($user, $model, string $relatedModelClass): bool
    {
        return true;
    }

    public function attach($user, $model, $relatedModel): bool
    {
        return true;
    }

    public function detach($user, $model, $relatedModel): bool
    {
        return true;
    }
}

class AuthzRestrictivePolicy
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

// Only viewAny + view defined — tests defaults matrix
class AuthzPartialPolicy
{
    public function viewAny($user): bool
    {
        return true;
    }

    public function view($user, $model): bool
    {
        return true;
    }
}

// before() intercepts forceDelete, falls through on everything else
class AuthzBeforePolicy
{
    public function before($user, string $ability, $model = null): ?bool
    {
        if ($ability === 'forceDelete') {
            return false;
        }

        return null;
    }

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
}

// Denies all relational abilities using the convention: {ability}{ModelBaseName}
class AuthzRelationshipPolicy
{
    public function viewAny($user): bool
    {
        return true;
    }

    public function addAuthzModel($user, $model): bool
    {
        return false;
    }

    public function attachAnyAuthzModel($user, $model): bool
    {
        return false;
    }

    public function attachAuthzModel($user, $model, $relatedModel): bool
    {
        return false;
    }

    public function detachAuthzModel($user, $model, $relatedModel): bool
    {
        return false;
    }
}

// --- Resources ---

class AuthzNoPolicyResource extends Resource
{
    public static function model(): string
    {
        return AuthzModel::class;
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

class AuthzPermissivePolicyResource extends Resource
{
    public static ?string $policy = AuthzPermissivePolicy::class;

    public static function model(): string
    {
        return AuthzModel::class;
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

class AuthzRestrictivePolicyResource extends Resource
{
    public static ?string $policy = AuthzRestrictivePolicy::class;

    public static function model(): string
    {
        return AuthzModel::class;
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

class AuthzPartialPolicyResource extends Resource
{
    public static ?string $policy = AuthzPartialPolicy::class;

    public static function model(): string
    {
        return AuthzModel::class;
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

class AuthzBeforePolicyResource extends Resource
{
    public static ?string $policy = AuthzBeforePolicy::class;

    public static function model(): string
    {
        return AuthzModel::class;
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

class AuthzRelationshipPolicyResource extends Resource
{
    public static ?string $policy = AuthzRelationshipPolicy::class;

    public static function model(): string
    {
        return AuthzModel::class;
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

class AuthzSoftDeleteResource extends Resource
{
    public static ?string $policy = AuthzPermissivePolicy::class;

    public static function model(): string
    {
        return AuthzSoftModel::class;
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

// ── Policy Resolution ────────────────────────────────────────────

it('returns null when no policy is found', function () {
    expect(AuthzNoPolicyResource::resolvePolicy())->toBeNull();
});

it('resolves explicit $policy property', function () {
    $policy = AuthzPermissivePolicyResource::resolvePolicy();
    expect($policy)->toBeInstanceOf(AuthzPermissivePolicy::class);
});

it('resolves Gate model policy when no explicit policy', function () {
    Gate::policy(AuthzModel::class, AuthzRestrictivePolicy::class);
    $policy = AuthzNoPolicyResource::resolvePolicy();
    expect($policy)->toBeInstanceOf(AuthzRestrictivePolicy::class);
});

it('explicit policy takes precedence over Gate policy', function () {
    Gate::policy(AuthzModel::class, AuthzRestrictivePolicy::class);
    $policy = AuthzPermissivePolicyResource::resolvePolicy();
    expect($policy)->toBeInstanceOf(AuthzPermissivePolicy::class);
});

it('caches resolved policies', function () {
    $p1 = AuthzPermissivePolicyResource::resolvePolicy();
    $p2 = AuthzPermissivePolicyResource::resolvePolicy();
    expect($p1)->toBe($p2);
});

it('flushPolicyCache resets the cache', function () {
    $p1 = AuthzPermissivePolicyResource::resolvePolicy();
    Resource::flushPolicyCache();
    $p2 = AuthzPermissivePolicyResource::resolvePolicy();
    expect($p1)->not->toBe($p2);
    expect($p2)->toBeInstanceOf(AuthzPermissivePolicy::class);
});

it('authorizable returns true by default', function () {
    expect(AuthzPermissivePolicyResource::authorizable())->toBeTrue();
    expect(AuthzNoPolicyResource::authorizable())->toBeTrue();
});

// ── No Policy = Permissive ───────────────────────────────────────

it('allows all operations when no policy exists', function () {
    $this->actingAs(testUser());
    $resource = new AuthzNoPolicyResource(new AuthzModel(['id' => 1]));

    expect($resource->authorizedToViewAny(request()))->toBeTrue();
    expect($resource->authorizedToView(request()))->toBeTrue();
    expect($resource->authorizedToCreate(request()))->toBeTrue();
    expect($resource->authorizedToUpdate(request()))->toBeTrue();
    expect($resource->authorizedToDelete(request()))->toBeTrue();
});

// ── Permissive Policy ────────────────────────────────────────────

it('allows all operations with permissive policy', function () {
    $this->actingAs(testUser());
    $resource = new AuthzPermissivePolicyResource(new AuthzModel(['id' => 1]));

    expect($resource->authorizedToViewAny(request()))->toBeTrue();
    expect($resource->authorizedToView(request()))->toBeTrue();
    expect($resource->authorizedToCreate(request()))->toBeTrue();
    expect($resource->authorizedToUpdate(request()))->toBeTrue();
    expect($resource->authorizedToDelete(request()))->toBeTrue();
    expect($resource->authorizedToRestore(request()))->toBeTrue();
    expect($resource->authorizedToForceDelete(request()))->toBeTrue();
    expect($resource->authorizedToReplicate(request()))->toBeTrue();
});

// ── Restrictive Policy ──────────────────────────────────────────

it('denies operations with restrictive policy', function () {
    $this->actingAs(testUser());
    $resource = new AuthzRestrictivePolicyResource(new AuthzModel(['id' => 1]));

    expect($resource->authorizedToViewAny(request()))->toBeTrue();
    expect($resource->authorizedToView(request()))->toBeFalse();
    expect($resource->authorizedToCreate(request()))->toBeFalse();
    expect($resource->authorizedToUpdate(request()))->toBeFalse();
    expect($resource->authorizedToDelete(request()))->toBeFalse();
    expect($resource->authorizedToRestore(request()))->toBeFalse();
    expect($resource->authorizedToForceDelete(request()))->toBeFalse();
    expect($resource->authorizedToReplicate(request()))->toBeFalse();
});

// ── Defaults Matrix (Partial Policy) ────────────────────────────

it('defaults missing abilities per the defaults matrix', function () {
    $this->actingAs(testUser());
    $resource = new AuthzPartialPolicyResource(new AuthzModel(['id' => 1]));

    // Defined in policy
    expect($resource->authorizedToViewAny(request()))->toBeTrue();
    expect($resource->authorizedToView(request()))->toBeTrue();
    // Missing -> default false
    expect($resource->authorizedToCreate(request()))->toBeFalse();
    expect($resource->authorizedToUpdate(request()))->toBeFalse();
    expect($resource->authorizedToDelete(request()))->toBeFalse();
    expect($resource->authorizedToRestore(request()))->toBeFalse();
    expect($resource->authorizedToForceDelete(request()))->toBeFalse();
    // Replicate fallback: create(false) && update(false) -> false
    expect($resource->authorizedToReplicate(request()))->toBeFalse();
});

it('defaults relational abilities to true when missing from policy', function () {
    $this->actingAs(testUser());
    $resource = new AuthzPartialPolicyResource(new AuthzModel(['id' => 1]));

    expect($resource->authorizedToAdd(request(), AuthzModel::class))->toBeTrue();
    expect($resource->authorizedToAttachAny(request(), AuthzModel::class))->toBeTrue();
    expect($resource->authorizedToAttach(request(), new AuthzModel))->toBeTrue();
    expect($resource->authorizedToDetach(request(), new AuthzModel))->toBeTrue();
});

// ── before() Callback ───────────────────────────────────────────

it('before callback overrides specific abilities', function () {
    $this->actingAs(testUser());
    $resource = new AuthzBeforePolicyResource(new AuthzModel(['id' => 1]));

    // before() returns false for forceDelete
    expect($resource->authorizedToForceDelete(request()))->toBeFalse();
    // before() returns null -> falls through to method
    expect($resource->authorizedToView(request()))->toBeTrue();
    expect($resource->authorizedToCreate(request()))->toBeTrue();
});

// ── Relational Abilities ────────────────────────────────────────

it('denies relational abilities when policy explicitly denies', function () {
    $this->actingAs(testUser());
    $resource = new AuthzRelationshipPolicyResource(new AuthzModel(['id' => 1]));

    expect($resource->authorizedToAdd(request(), AuthzModel::class))->toBeFalse();
    expect($resource->authorizedToAttachAny(request(), AuthzModel::class))->toBeFalse();
    expect($resource->authorizedToAttach(request(), new AuthzModel))->toBeFalse();
    expect($resource->authorizedToDetach(request(), new AuthzModel))->toBeFalse();
});

// ── Unauthenticated User ────────────────────────────────────────

it('denies all operations when no user is authenticated and policy exists', function () {
    $resource = new AuthzPermissivePolicyResource(new AuthzModel(['id' => 1]));

    expect($resource->authorizedToViewAny(request()))->toBeFalse();
    expect($resource->authorizedToView(request()))->toBeFalse();
    expect($resource->authorizedToCreate(request()))->toBeFalse();
});

// ── Action Abilities ─────────────────────────────────────────────

it('runAction falls back to update when missing from policy', function () {
    $this->actingAs(testUser());

    // PartialPolicy: no runAction, no update -> default false
    $resource = new AuthzPartialPolicyResource(new AuthzModel(['id' => 1]));
    expect($resource->authorizedToRunAction(request()))->toBeFalse();

    // BeforePolicy: no runAction, has update=true -> true
    $resource2 = new AuthzBeforePolicyResource(new AuthzModel(['id' => 1]));
    expect($resource2->authorizedToRunAction(request()))->toBeTrue();
});

it('runDestructiveAction falls back to delete when missing from policy', function () {
    $this->actingAs(testUser());

    // PartialPolicy: no runDestructiveAction, no delete -> default false
    $resource = new AuthzPartialPolicyResource(new AuthzModel(['id' => 1]));
    expect($resource->authorizedToRunDestructiveAction(request()))->toBeFalse();

    // BeforePolicy: no runDestructiveAction, has delete=true -> true
    $resource2 = new AuthzBeforePolicyResource(new AuthzModel(['id' => 1]));
    expect($resource2->authorizedToRunDestructiveAction(request()))->toBeTrue();
});

// ── Replicate Fallback ──────────────────────────────────────────

it('replicate requires both create AND update when no explicit replicate', function () {
    $this->actingAs(testUser());

    // BeforePolicy: create=true, update=true, no replicate -> true
    $resource = new AuthzBeforePolicyResource(new AuthzModel(['id' => 1]));
    expect($resource->authorizedToReplicate(request()))->toBeTrue();

    // PartialPolicy: no create, no update -> both default false -> false
    $resource2 = new AuthzPartialPolicyResource(new AuthzModel(['id' => 1]));
    expect($resource2->authorizedToReplicate(request()))->toBeFalse();
});

// ── Authorization Metadata ──────────────────────────────────────

it('authorizationMetadata returns correct keys for non-soft-deletable resource', function () {
    $this->actingAs(testUser());
    $resource = new AuthzPermissivePolicyResource(new AuthzModel(['id' => 1]));
    $meta = $resource->authorizationMetadata(request());

    expect($meta)->toBeArray();
    expect($meta)->toHaveKeys([
        'authorizedToView',
        'authorizedToUpdate',
        'authorizedToDelete',
        'authorizedToReplicate',
        'authorizedToRunAction',
        'authorizedToRunDestructiveAction',
    ]);
    expect($meta)->not->toHaveKey('authorizedToRestore');
    expect($meta)->not->toHaveKey('authorizedToForceDelete');
});

it('authorizationMetadata includes restore/forceDelete for soft-deletable resources', function () {
    $this->actingAs(testUser());
    $resource = new AuthzSoftDeleteResource(new AuthzSoftModel(['id' => 1]));
    $meta = $resource->authorizationMetadata(request());

    expect($meta)->toHaveKeys([
        'authorizedToView',
        'authorizedToUpdate',
        'authorizedToDelete',
        'authorizedToRestore',
        'authorizedToForceDelete',
        'authorizedToReplicate',
    ]);
});

it('collectionAuthorizationMetadata returns correct keys', function () {
    $this->actingAs(testUser());
    $resource = new AuthzPermissivePolicyResource(new AuthzModel(['id' => 1]));
    $meta = $resource->collectionAuthorizationMetadata(request());

    expect($meta)->toBeArray();
    expect($meta)->toHaveKeys(['authorizedToViewAny', 'authorizedToCreate']);
});

// ── Soft Deletes ────────────────────────────────────────────────

it('detects soft deletes on model', function () {
    expect(AuthzSoftDeleteResource::softDeletes())->toBeTrue();
    expect(AuthzPermissivePolicyResource::softDeletes())->toBeFalse();
});

// ── Field-Level Authorization ───────────────────────────────────

it('canSee sets a visibility callback on fields', function () {
    $this->actingAs(testUser());

    $hidden = Text::make('secret');
    $hidden->canSee(fn (Request $request) => false);
    expect($hidden->isAuthorizedToSee(request()))->toBeFalse();

    $visible = Text::make('public');
    $visible->canSee(fn (Request $request) => true);
    expect($visible->isAuthorizedToSee(request()))->toBeTrue();
});

it('isAuthorizedToSee defaults to true when no callback', function () {
    $field = Text::make('name');
    expect($field->isAuthorizedToSee(request()))->toBeTrue();
});

it('canSeeWhen checks a Gate ability', function () {
    $user = testUser();
    $this->actingAs($user);
    Gate::define('admin', fn ($u) => true);
    Gate::define('super-admin', fn ($u) => false);

    $req = Request::create('/');
    $req->setUserResolver(fn () => $user);

    $allowed = Text::make('name');
    $allowed->canSeeWhen('admin');
    expect($allowed->isAuthorizedToSee($req))->toBeTrue();

    $denied = Text::make('email');
    $denied->canSeeWhen('super-admin');
    expect($denied->isAuthorizedToSee($req))->toBeFalse();
});

it('filterForContext respects canSee authorization', function () {
    $this->actingAs(testUser());

    $visible = Text::make('visible');
    $hidden = Text::make('hidden')->canSee(fn () => false);
    $alsoVisible = Text::make('also_visible')->canSee(fn () => true);

    $filtered = Field::filterForContext(
        [$visible, $hidden, $alsoVisible],
        FieldContext::INDEX,
    );

    expect($filtered)->toHaveCount(2);
});
