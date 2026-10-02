<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Fields\Select;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Http\Requests\LensRequest;
use Martis\Lenses\Lens;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * The action list and the action field schema are served only to a user who
 * may list the resource (`viewAny`), as running an action is. The field
 * schema carries labels, help text, defaults and the option lists a
 * developer fills from the database, so a resource the user cannot list
 * must not hand them out. Covers the resource and lens routes.
 */

class ActDiscoveryModel extends Model
{
    protected $table = 'act_discovery_items';

    protected $fillable = ['title'];
}

class ActDiscoveryDeniedPolicy
{
    public function viewAny($user): bool
    {
        return false;
    }

    public function view($user, $model): bool
    {
        return true;
    }
}

class ActDiscoveryAllowedPolicy
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

class ActDiscoveryAction extends Action
{
    public ?string $name = 'Reassign';

    /** @param Collection<int, Model> $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        return ActionResponse::message('Done.');
    }

    public function fields(Request $request): array
    {
        return [Select::make('owner')->options(['1' => 'Secret Customer'])];
    }
}

class ActDiscoveryLens extends Lens
{
    public function query(LensRequest $request, Builder $query): Builder
    {
        return $query;
    }
}

abstract class ActDiscoveryResource extends Resource
{
    public static function model(): string
    {
        return ActDiscoveryModel::class;
    }

    public function fields(Request $request): array
    {
        return [];
    }

    public function actions(Request $request): array
    {
        return [ActDiscoveryAction::make()];
    }

    public function lenses(Request $request): array
    {
        return [new ActDiscoveryLens];
    }
}

class ActDiscoveryDeniedResource extends ActDiscoveryResource
{
    public static ?string $policy = ActDiscoveryDeniedPolicy::class;

    public static function uriKey(): string
    {
        return 'act-discovery-denied';
    }
}

class ActDiscoveryAllowedResource extends ActDiscoveryResource
{
    public static ?string $policy = ActDiscoveryAllowedPolicy::class;

    public static function uriKey(): string
    {
        return 'act-discovery-allowed';
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('act_discovery_items');
    Schema::create('act_discovery_items', function ($table) {
        $table->id();
        $table->string('title')->default('');
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(ActDiscoveryDeniedResource::class);
    $registry->register(ActDiscoveryAllowedResource::class);

    $this->actingAs((new Authenticatable)->forceFill(['id' => 1, 'name' => 'Test', 'email' => 'user@test.local']));
});

afterEach(function () {
    Schema::dropIfExists('act_discovery_items');
    Resource::flushPolicyCache();
});

it('refuses the action list of a resource the user may not viewAny', function () {
    $response = $this->getJson('/martis/api/resources/act-discovery-denied/actions');

    $response->assertForbidden();
    expect($response->getContent())->not->toContain('act-discovery-action')->not->toContain('Reassign');
});

it('refuses the action list of a lens of a resource the user may not viewAny', function () {
    $response = $this->getJson('/martis/api/resources/act-discovery-denied/lenses/act-discovery/actions');

    $response->assertForbidden();
    expect($response->getContent())->not->toContain('Reassign');
});

it('refuses the field schema of an action of a resource the user may not viewAny', function () {
    $response = $this->getJson('/martis/api/resources/act-discovery-denied/actions/act-discovery-action/fields');

    $response->assertForbidden();
    expect($response->getContent())->not->toContain('Secret Customer');
});

it('refuses the field schema of a lens action of a resource the user may not viewAny', function () {
    $response = $this->getJson('/martis/api/resources/act-discovery-denied/lenses/act-discovery/actions/act-discovery-action/fields');

    $response->assertForbidden();
    expect($response->getContent())->not->toContain('Secret Customer');
});

it('still lists the actions and their fields of a resource the user may viewAny', function () {
    $this->getJson('/martis/api/resources/act-discovery-allowed/actions')
        ->assertOk()
        ->assertJsonPath('data.actions.0.uriKey', 'act-discovery-action');

    $this->getJson('/martis/api/resources/act-discovery-allowed/lenses/act-discovery/actions')
        ->assertOk()
        ->assertJsonPath('data.actions.0.uriKey', 'act-discovery-action');

    $this->getJson('/martis/api/resources/act-discovery-allowed/actions/act-discovery-action/fields')
        ->assertOk()
        ->assertSee('Secret Customer');

    $this->getJson('/martis/api/resources/act-discovery-allowed/lenses/act-discovery/actions/act-discovery-action/fields')
        ->assertOk()
        ->assertSee('Secret Customer');
});
