<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Actions\DestructiveAction;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * The command palette lists the standalone actions of the resources the
 * user may list, with the gates the sidebar and the action endpoints apply:
 * an action the user may not see (`canSee()`) is not named in the payload,
 * and the actions of a resource the sidebar hides (`displayInNavigation()`)
 * or locks (`lockedFor()`) are not offered as shortcuts.
 */

class PalAuthzModel extends Model
{
    protected $table = 'pal_authz_items';

    protected $fillable = ['title'];
}

class PalAuthzAction extends Action
{
    public ?string $name = 'Plain';

    /** @param Collection<int, Model> $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        return ActionResponse::message('ok');
    }
}

class PalAuthzPurgeAction extends DestructiveAction
{
    public ?string $name = 'Purge all tenants';

    /** @param Collection<int, Model> $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse|DestructiveAction|null
    {
        return ActionResponse::message('ok');
    }
}

class PalAuthzAdminAction extends PalAuthzAction
{
    public ?string $name = 'Seen by admin';
}

abstract class PalAuthzBaseResource extends Resource
{
    public static function model(): string
    {
        return PalAuthzModel::class;
    }

    public function fields(Request $request): array
    {
        return [];
    }

    public function actions(Request $request): array
    {
        return [PalAuthzAction::make()->standalone()];
    }
}

/** Standalone actions with a `canSee()` that denies one of them. */
class PalAuthzGatedResource extends PalAuthzBaseResource
{
    public static function uriKey(): string
    {
        return 'pal-authz-gated';
    }

    public function actions(Request $request): array
    {
        return [
            PalAuthzAction::make()->standalone(),
            PalAuthzPurgeAction::make()->standalone()->canSee(fn (Request $request): bool => false),
            PalAuthzAdminAction::make()->standalone()->canSee(fn (Request $request): bool => $request->user()?->email === 'admin@test.local'),
        ];
    }
}

class PalAuthzHiddenNavResource extends PalAuthzBaseResource
{
    public static function uriKey(): string
    {
        return 'pal-authz-hidden-nav';
    }

    public static function displayInNavigation(): bool
    {
        return false;
    }
}

class PalAuthzLockedResource extends PalAuthzBaseResource
{
    public function __construct(?Model $model = null)
    {
        parent::__construct($model);
        $this->lockedFor(fn (Request $request): bool => true);
    }

    public static function uriKey(): string
    {
        return 'pal-authz-locked';
    }
}

class PalAuthzUnlockedResource extends PalAuthzBaseResource
{
    public function __construct(?Model $model = null)
    {
        parent::__construct($model);
        $this->lockedFor(fn (Request $request): bool => false);
    }

    public static function uriKey(): string
    {
        return 'pal-authz-unlocked';
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('pal_authz_items');
    Schema::create('pal_authz_items', function (Blueprint $table) {
        $table->id();
        $table->string('title')->default('');
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(PalAuthzGatedResource::class);
    $registry->register(PalAuthzHiddenNavResource::class);
    $registry->register(PalAuthzLockedResource::class);
    $registry->register(PalAuthzUnlockedResource::class);

    $this->actingAs((new Authenticatable)->forceFill(['id' => 1, 'name' => 'Test', 'email' => 'user@test.local']));
});

afterEach(function () {
    Schema::dropIfExists('pal_authz_items');
    app(ResourceRegistry::class)->flush();
});

it('leaves out a standalone action the user may not see', function () {
    $response = $this->getJson('/martis/api/command-palette')->assertOk();

    $labels = collect($response->json('actions'))->where('resourceUriKey', 'pal-authz-gated')->pluck('label')->all();

    expect($labels)->toBe(['Plain']);
    expect($response->getContent())->not->toContain('Purge all tenants')->not->toContain('Seen by admin');
});

it('lists a gated standalone action for the user its canSee() allows', function () {
    $this->actingAs((new Authenticatable)->forceFill(['id' => 2, 'name' => 'Admin', 'email' => 'admin@test.local']));

    $labels = collect($this->getJson('/martis/api/command-palette')->assertOk()->json('actions'))
        ->where('resourceUriKey', 'pal-authz-gated')->pluck('label')->sort()->values()->all();

    expect($labels)->toBe(['Plain', 'Seen by admin']);
});

it('leaves out the actions of a resource the sidebar does not list', function () {
    $response = $this->getJson('/martis/api/command-palette')->assertOk();

    expect(collect($response->json('actions'))->where('resourceUriKey', 'pal-authz-hidden-nav')->all())->toBe([]);
    expect(collect($response->json('resources'))->where('uriKey', 'pal-authz-hidden-nav')->all())->toBe([]);
});

it('leaves out the actions of a resource locked for the user, and keeps an unlocked one', function () {
    $actions = collect($this->getJson('/martis/api/command-palette')->assertOk()->json('actions'));

    expect($actions->where('resourceUriKey', 'pal-authz-locked')->all())->toBe([]);
    expect($actions->where('resourceUriKey', 'pal-authz-unlocked')->pluck('label')->all())->toBe(['Plain']);
});
