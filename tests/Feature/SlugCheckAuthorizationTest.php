<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\Slug;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * The slug check answers whether a slug is taken, so it needs the ability to
 * write one (create without an id, update on the record the id names), not
 * viewAny alone. Its uniqueness probe reads the whole table by default; a
 * Slug declared withinIndexScope() probes only the records the resource's
 * scopes() and indexQuery() let the user list.
 */

class SlugAuthzModel extends Model
{
    protected $table = 'slug_authz_items';

    protected $guarded = [];

    public $timestamps = false;
}

class SlugAuthzPolicy
{
    public function viewAny($user): bool
    {
        return true;
    }

    public function view($user, $model): bool
    {
        return true;
    }

    /** Only the user whose email starts with "writer" may create. */
    public function create($user): bool
    {
        return str_starts_with((string) $user->email, 'writer');
    }

    /** Only the user whose email starts with "writer" may update record 1. */
    public function update($user, $model): bool
    {
        return str_starts_with((string) $user->email, 'writer') && (int) $model->getKey() === 1;
    }
}

abstract class SlugAuthzBaseResource extends Resource
{
    public static ?string $policy = SlugAuthzPolicy::class;

    public static function model(): string
    {
        return SlugAuthzModel::class;
    }

    /** Records of tenant 1 are the ones the user may list. */
    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query->where('tenant_id', 1);
    }

    abstract protected function slug(): Slug;

    public function fields(Request $request): array
    {
        return [Text::make('name'), $this->slug()->from('name')];
    }
}

class SlugAuthzGlobalResource extends SlugAuthzBaseResource
{
    public static function uriKey(): string
    {
        return 'slug-authz-global';
    }

    protected function slug(): Slug
    {
        return Slug::make('slug');
    }
}

class SlugAuthzScopedResource extends SlugAuthzBaseResource
{
    public static function uriKey(): string
    {
        return 'slug-authz-scoped';
    }

    protected function slug(): Slug
    {
        return Slug::make('slug')->withinIndexScope();
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('slug_authz_items');
    Schema::create('slug_authz_items', function ($table) {
        $table->id();
        $table->unsignedInteger('tenant_id')->default(1);
        $table->string('name')->default('');
        $table->string('slug')->nullable();
    });

    // Record 1: tenant 1 (listable). Record 2: another tenant, its slug is a
    // secret to this user.
    SlugAuthzModel::create(['tenant_id' => 1, 'name' => 'Mine', 'slug' => 'mine']);
    SlugAuthzModel::create(['tenant_id' => 2, 'name' => 'Theirs', 'slug' => 'other-tenant-client']);
    SlugAuthzModel::create(['tenant_id' => 2, 'name' => 'Theirs 2', 'slug' => 'other-tenant-client-2']);

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(SlugAuthzGlobalResource::class);
    $registry->register(SlugAuthzScopedResource::class);
});

afterEach(function () {
    Schema::dropIfExists('slug_authz_items');
    Resource::flushPolicyCache();
});

function slugAuthzActingAs($test, string $email): void
{
    $test->actingAs((new Authenticatable)->forceFill(['id' => 1, 'name' => 'User', 'email' => $email]));
}

it('refuses the slug check to a user who may only list the resource', function () {
    slugAuthzActingAs($this, 'reader@test.local');

    foreach (['', '&id=1', '&id=2', '&id=_', '&id=999'] as $id) {
        $response = $this->getJson('/martis/api/resources/slug-authz-global/slug-check/slug?value=other-tenant-client'.$id);

        $response->assertForbidden();
        expect($response->getContent())->not->toContain('available')->not->toContain('suggestion');
    }
});

it('answers the slug check to a user who may create', function () {
    slugAuthzActingAs($this, 'writer@test.local');

    $this->getJson('/martis/api/resources/slug-authz-global/slug-check/slug?value=free-slug')
        ->assertOk()
        ->assertJsonPath('data.available', true);
});

it('answers the slug check of the update form to a user who may update the record', function () {
    slugAuthzActingAs($this, 'writer@test.local');

    // Record 1 keeps its own slug.
    $this->getJson('/martis/api/resources/slug-authz-global/slug-check/slug?value=mine&id=1')
        ->assertOk()
        ->assertJsonPath('data.available', true);
});

it('does not tell a record the user may not update from a missing one', function () {
    slugAuthzActingAs($this, 'reader@test.local');

    $foreign = $this->getJson('/martis/api/resources/slug-authz-global/slug-check/slug?value=mine&id=2');
    $missing = $this->getJson('/martis/api/resources/slug-authz-global/slug-check/slug?value=mine&id=999');

    $foreign->assertForbidden();
    expect($foreign->json())->toBe($missing->json())->and($foreign->status())->toBe($missing->status());
});

it('probes the whole table by default, so a writer learns a slug outside their scope exists', function () {
    slugAuthzActingAs($this, 'writer@test.local');

    $this->getJson('/martis/api/resources/slug-authz-global/slug-check/slug?value=other-tenant-client')
        ->assertOk()
        ->assertJsonPath('data.available', false)
        ->assertJsonPath('data.suggestion', 'other-tenant-client-3');
});

it('probes only the index scope for a slug declared withinIndexScope()', function () {
    slugAuthzActingAs($this, 'writer@test.local');

    // A slug of another tenant reads free, and so does its suggestion chain.
    $this->getJson('/martis/api/resources/slug-authz-scoped/slug-check/slug?value=other-tenant-client')
        ->assertOk()
        ->assertJsonPath('data.available', true)
        ->assertJsonPath('data.suggestion', null);

    // A slug the user can list is still taken, with a suggestion that skips
    // listable records only.
    $this->getJson('/martis/api/resources/slug-authz-scoped/slug-check/slug?value=mine')
        ->assertOk()
        ->assertJsonPath('data.available', false)
        ->assertJsonPath('data.suggestion', 'mine-2');
});

it('exposes the withinIndexScope option on the field', function () {
    expect(Slug::make('slug')->isWithinIndexScope())->toBeFalse()
        ->and(Slug::make('slug')->withinIndexScope()->isWithinIndexScope())->toBeTrue()
        ->and(Slug::make('slug')->withinIndexScope()->withinIndexScope(false)->isWithinIndexScope())->toBeFalse();
});
