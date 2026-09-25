<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Fields\HasMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// `viewAny` is the entry gate to a resource: it already protects index,
// schema, search, lenses and metrics. Every per-id endpoint must consult it
// BEFORE the record query, so (a) a resource the user cannot list answers 403
// (not 404) and record ids cannot be probed through it, and (b) a model scope
// that fails closed (throws when no tenant is resolved) never turns a
// deep-link into a 500. The record-level checks (view / update / delete …)
// stay where they were, after the query.
//
// The fixture policy is the adversarial shape: `viewAny` denied while every
// record-level ability is granted, so any endpoint that still reaches the
// record query would answer 200 and prove the gate is missing.
// ---------------------------------------------------------------------------

class VAGItem extends Model
{
    use SoftDeletes;

    protected $table = 'vag_items';

    protected $guarded = [];

    public function children()
    {
        return $this->hasMany(VAGChild::class, 'item_id');
    }
}

class VAGChild extends Model
{
    protected $table = 'vag_children';

    protected $guarded = [];

    public $timestamps = false;
}

class VAGListDeniedPolicy
{
    public function viewAny($user): bool
    {
        return false;
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

    public function runAction($user): bool
    {
        return true;
    }
}

class VAGPingAction extends Action
{
    public ?string $name = 'Ping';

    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        return ActionResponse::message('pong');
    }
}

class VAGChildResource extends Resource
{
    public static function model(): string
    {
        return VAGChild::class;
    }

    public static function uriKey(): string
    {
        return 'vag-children';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')];
    }
}

class VAGItemResource extends Resource
{
    public static ?string $policy = VAGListDeniedPolicy::class;

    public static function model(): string
    {
        return VAGItem::class;
    }

    public static function uriKey(): string
    {
        return 'vag-items';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->required(),
            HasMany::make('children', 'children', VAGChildResource::class),
        ];
    }

    public function actions(Request $request): array
    {
        return [VAGPingAction::make()];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('vag_children');
    Schema::dropIfExists('vag_items');
    Schema::create('vag_items', function ($t) {
        $t->id();
        $t->string('title');
        $t->timestamps();
        $t->softDeletes();
    });
    Schema::create('vag_children', function ($t) {
        $t->id();
        $t->unsignedBigInteger('item_id');
        $t->string('title');
    });

    $this->item = VAGItem::query()->create(['title' => 'Probe me']);

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(VAGItemResource::class);
    $registry->register(VAGChildResource::class);
    Resource::flushPolicyCache();

    $this->actingAs((new Authenticatable)->forceFill(['id' => 1, 'name' => 'Agent']));
});

afterEach(function () {
    Schema::dropIfExists('vag_children');
    Schema::dropIfExists('vag_items');
    app(ResourceRegistry::class)->flush();
    Resource::flushPolicyCache();
});

/** True when any logged query touched the resource table. */
function vagTouchedItems(): bool
{
    return collect(DB::getQueryLog())->contains(
        fn (array $q) => str_contains((string) $q['query'], 'vag_items'),
    );
}

dataset('per-id endpoints', function () {
    $base = fn (int $id) => "/martis/api/resources/vag-items/{$id}";

    return [
        'GET show' => ['getJson', fn (int $id) => $base($id)],
        'GET show (update context)' => ['getJson', fn (int $id) => $base($id).'?context=update'],
        'PUT update' => ['putJson', fn (int $id) => $base($id), ['title' => 'renamed']],
        'DELETE destroy' => ['deleteJson', fn (int $id) => $base($id)],
        'PUT restore' => ['putJson', fn (int $id) => $base($id).'/restore'],
        'DELETE force' => ['deleteJson', fn (int $id) => $base($id).'/force'],
        'GET replicate' => ['getJson', fn (int $id) => $base($id).'/replicate'],
        'GET peek' => ['getJson', fn (int $id) => $base($id).'/peek'],
        'POST single action' => ['postJson', fn (int $id) => $base($id).'/actions/vag-ping-action'],
        'GET has-many index' => ['getJson', fn (int $id) => $base($id).'/has-many/children'],
        'GET has-one show' => ['getJson', fn (int $id) => $base($id).'/has-one/children'],
        'GET belongs-to-many index' => ['getJson', fn (int $id) => $base($id).'/belongs-to-many/children'],
        'GET belongs-to-many attachable' => ['getJson', fn (int $id) => $base($id).'/belongs-to-many/children/attachable'],
        'GET morph-to-many index' => ['getJson', fn (int $id) => $base($id).'/morph-to-many/children'],
        'GET morph-many index' => ['getJson', fn (int $id) => $base($id).'/morph-many/children'],
        'GET morph-one show' => ['getJson', fn (int $id) => $base($id).'/morph-one/children'],
        'GET pivot actions' => ['getJson', fn (int $id) => $base($id).'/belongs-to-many/children/actions'],
        'GET pivot action fields' => ['getJson', fn (int $id) => $base($id).'/belongs-to-many/children/actions/vag-ping-action/fields'],
        'POST pivot action' => ['postJson', fn (int $id) => $base($id).'/belongs-to-many/children/actions/vag-ping-action'],
        'GET morph-to-many pivot actions' => ['getJson', fn (int $id) => $base($id).'/morph-to-many/children/actions'],
        'GET morph-to-many pivot action fields' => ['getJson', fn (int $id) => $base($id).'/morph-to-many/children/actions/vag-ping-action/fields'],
        'POST morph-to-many pivot action' => ['postJson', fn (int $id) => $base($id).'/morph-to-many/children/actions/vag-ping-action'],
    ];
});

it('answers 403 before the record query when viewAny is denied', function (string $method, Closure $url, array $payload = []) {
    DB::enableQueryLog();

    $response = $this->{$method}($url((int) $this->item->getKey()), $payload);

    $response->assertStatus(403);
    expect(vagTouchedItems())->toBeFalse();
})->with('per-id endpoints');

it('answers 403, not 404, for an id that does not exist when viewAny is denied (no probing oracle)', function () {
    DB::enableQueryLog();

    $this->getJson('/martis/api/resources/vag-items/999999')->assertStatus(403);

    expect(vagTouchedItems())->toBeFalse();
});

it('gates the bulk action endpoint on viewAny before resolving the selected records', function () {
    DB::enableQueryLog();

    $this->postJson('/martis/api/resources/vag-items/actions/vag-ping-action', [
        'resources' => [$this->item->getKey()],
    ])->assertStatus(403);

    expect(vagTouchedItems())->toBeFalse();
});

it('refuses a create without viewAny, as the per-id endpoints do', function (string $path) {
    $before = VAGItem::withTrashed()->count();

    $this->postJson($path, ['title' => 'Created'])->assertStatus(403);

    expect(VAGItem::withTrashed()->count())->toBe($before);
})->with([
    'POST store' => ['/martis/api/resources/vag-items'],
    'POST inline create' => ['/martis/api/resources/vag-items/inline-create'],
]);

it('refuses the inline create form without viewAny', function () {
    $this->getJson('/martis/api/resources/vag-items/inline-create-schema')->assertStatus(403);
});

it('keeps the record-level checks once viewAny is granted (control)', function () {
    VAGItemResource::$policy = VAGControlPolicy::class;
    Resource::flushPolicyCache();

    try {
        $id = (int) $this->item->getKey();

        $this->getJson("/martis/api/resources/vag-items/{$id}")->assertOk();
        // `view` granted, `update` denied → the record-level gate still fires.
        $this->putJson("/martis/api/resources/vag-items/{$id}", ['title' => 'x'])->assertStatus(403);
        $this->getJson('/martis/api/resources/vag-items/999999')->assertStatus(404);
    } finally {
        VAGItemResource::$policy = VAGListDeniedPolicy::class;
        Resource::flushPolicyCache();
    }
});

class VAGControlPolicy extends VAGListDeniedPolicy
{
    public function viewAny($user): bool
    {
        return true;
    }

    public function update($user, $model): bool
    {
        return false;
    }
}
