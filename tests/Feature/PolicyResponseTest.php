<?php

declare(strict_types=1);

use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Fields\BelongsToMany;
use Martis\Fields\HasMany;
use Martis\Fields\HasOne;
use Martis\Fields\MorphMany;
use Martis\Fields\MorphOne;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// A Resource policy may answer with Laravel's `Illuminate\Auth\Access\Response`
// (https://laravel.com/docs/authorization#policy-responses), as the Gate
// allows. Martis calls the policy directly and, up to v2.10, cast the answer
// to bool: an object is always true, so `Response::deny()` and
// `Response::denyAsNotFound()` ALLOWED (a `PUT` saved the record). The answer
// is now read through `allowed()`, in `before()` too, and a denial that
// carries a status (`denyAsNotFound()`, `denyWithStatus()`) answers that
// status on the endpoints that read or write one record, so a policy can make
// a record it denies look exactly like a missing one.
// ---------------------------------------------------------------------------

class PRItem extends Model
{
    use SoftDeletes;

    protected $table = 'pr_items';

    protected $guarded = [];

    public function children()
    {
        return $this->hasMany(PRChild::class, 'item_id');
    }

    public function profile()
    {
        return $this->hasOne(PRChild::class, 'item_id');
    }

    public function kids()
    {
        return $this->belongsToMany(PRChild::class, 'pr_item_kid', 'item_id', 'child_id');
    }

    public function notes()
    {
        return $this->morphMany(PRNote::class, 'notable');
    }

    public function pinned()
    {
        return $this->morphOne(PRNote::class, 'notable');
    }
}

class PRNote extends Model
{
    protected $table = 'pr_notes';

    protected $guarded = [];

    public $timestamps = false;
}

class PRNoteResource extends Resource
{
    public static function model(): string
    {
        return PRNote::class;
    }

    public static function uriKey(): string
    {
        return 'pr-notes';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')];
    }
}

class PRTouchAction extends Action
{
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        return null;
    }

    public function uriKey(): string
    {
        return 'pr-touch';
    }
}

class PRChild extends Model
{
    protected $table = 'pr_children';

    protected $guarded = [];

    public $timestamps = false;
}

/** The answer each record ability gives, by mode. */
function prAnswer(string $mode): Response|bool
{
    return match ($mode) {
        'allow' => Response::allow(),
        'deny' => Response::deny('Not yours.'),
        'not-found' => Response::denyAsNotFound(),
        'gone' => Response::denyWithStatus(410),
        'false' => false,
    };
}

class PRItemPolicy
{
    public static string $mode = 'allow';

    public static ?string $before = null;

    public function before($user, string $ability): Response|bool|null
    {
        return static::$before === null ? null : prAnswer(static::$before);
    }

    public function viewAny($user): bool
    {
        return true;
    }

    public function create($user): bool
    {
        return true;
    }

    public function view($user, $model): Response|bool
    {
        return prAnswer(static::$mode);
    }

    public function update($user, $model): Response|bool
    {
        return prAnswer(static::$mode);
    }

    public function delete($user, $model): Response|bool
    {
        return prAnswer(static::$mode);
    }

    public function restore($user, $model): Response|bool
    {
        return prAnswer(static::$mode);
    }

    public function forceDelete($user, $model): Response|bool
    {
        return prAnswer(static::$mode);
    }

    public function replicate($user, $model): Response|bool
    {
        return prAnswer(static::$mode);
    }

    public function runAction($user, $model): Response|bool
    {
        return prAnswer(static::$mode);
    }

    public function attachAnyPRChild($user, $model): Response|bool
    {
        return prAnswer(static::$mode);
    }
}

class PRChildPolicy
{
    public static string $mode = 'allow';

    public function viewAny($user): bool
    {
        return true;
    }

    public function view($user, $model): bool
    {
        return true;
    }

    public function update($user, $model): Response|bool
    {
        return prAnswer(static::$mode);
    }

    public function delete($user, $model): Response|bool
    {
        return prAnswer(static::$mode);
    }
}

class PRChildResource extends Resource
{
    public static ?string $policy = PRChildPolicy::class;

    public static function model(): string
    {
        return PRChild::class;
    }

    public static function uriKey(): string
    {
        return 'pr-children';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')];
    }

    public function actions(Request $request): array
    {
        return [new PRTouchAction];
    }
}

class PRItemResource extends Resource
{
    public static ?string $policy = PRItemPolicy::class;

    public static function model(): string
    {
        return PRItem::class;
    }

    public static function uriKey(): string
    {
        return 'pr-items';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->required(),
            HasMany::make('children', 'children', PRChildResource::class),
            HasOne::make('profile', 'profile', PRChildResource::class),
            BelongsToMany::make('kids', 'kids', PRChildResource::class),
            MorphMany::make('notes', 'notes', PRNoteResource::class),
            MorphOne::make('pinned', 'pinned', PRNoteResource::class),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('pr_notes');
    Schema::dropIfExists('pr_item_kid');
    Schema::dropIfExists('pr_children');
    Schema::dropIfExists('pr_items');
    Schema::create('pr_notes', function ($t) {
        $t->id();
        $t->nullableMorphs('notable');
        $t->string('title');
    });
    Schema::create('pr_item_kid', function ($t) {
        $t->unsignedBigInteger('item_id');
        $t->unsignedBigInteger('child_id');
    });
    Schema::create('pr_items', function ($t) {
        $t->id();
        $t->string('title');
        $t->timestamps();
        $t->softDeletes();
    });
    Schema::create('pr_children', function ($t) {
        $t->id();
        $t->unsignedBigInteger('item_id');
        $t->string('title');
    });

    $this->item = PRItem::query()->create(['title' => 'Kept']);
    $this->child = PRChild::query()->create(['item_id' => $this->item->getKey(), 'title' => 'Kept']);

    PRItemPolicy::$mode = 'allow';
    PRItemPolicy::$before = null;
    PRChildPolicy::$mode = 'allow';

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(PRItemResource::class);
    $registry->register(PRChildResource::class);
    $registry->register(PRNoteResource::class);
    $registry->register(PRSplitResource::class);
    $registry->register(PRNoReplicateItemResource::class);
    PRSplitPolicy::$answers = [];
    PRNoReplicatePolicy::$answers = [];
    Resource::flushPolicyCache();

    $this->actingAs((new Authenticatable)->forceFill(['id' => 1, 'name' => 'Agent']));
});

afterEach(function () {
    Schema::dropIfExists('pr_notes');
    Schema::dropIfExists('pr_item_kid');
    Schema::dropIfExists('pr_children');
    Schema::dropIfExists('pr_items');
    app(ResourceRegistry::class)->flush();
    Resource::flushPolicyCache();
});

/** A request carrying the acting user, as the controllers receive it. */
function prRequest(): Request
{
    $request = Request::create('/');
    $request->setUserResolver(fn () => auth()->user());

    return $request;
}

dataset('record endpoints', function () {
    $base = fn (int|string $id) => "/martis/api/resources/pr-items/{$id}";

    return [
        'GET show' => ['getJson', fn ($id) => $base($id), false],
        'GET show (update context)' => ['getJson', fn ($id) => $base($id).'?context=update', false],
        'PUT update' => ['putJson', fn ($id) => $base($id), false, ['title' => 'Changed']],
        'DELETE destroy' => ['deleteJson', fn ($id) => $base($id), false],
        'PUT restore' => ['putJson', fn ($id) => $base($id).'/restore', true],
        'DELETE force' => ['deleteJson', fn ($id) => $base($id).'/force', true],
        'GET replicate' => ['getJson', fn ($id) => $base($id).'/replicate', false],
        'GET peek' => ['getJson', fn ($id) => $base($id).'/peek', false],
        'GET has-many index' => ['getJson', fn ($id) => $base($id).'/has-many/children', false],
    ];
});

dataset('related record writes', function () {
    $base = fn (int $parent, int|string $child) => "/martis/api/resources/pr-items/{$parent}/has-many/children/{$child}";

    return [
        'PUT has-many update' => ['putJson', $base, ['title' => 'Changed']],
        'DELETE has-many destroy' => ['deleteJson', $base],
    ];
});

// ---- Response::deny() denies -----------------------------------------------

it('denies a record request when the policy answers Response::deny()', function (string $method, Closure $url, bool $trashed, array $payload = []) {
    PRItemPolicy::$mode = 'deny';
    if ($trashed) {
        $this->item->delete();
    }

    $this->{$method}($url($this->item->getKey()), $payload)
        ->assertStatus(403)
        ->assertExactJson(['message' => 'This action is unauthorized.', 'errors' => []]);

    $item = PRItem::withTrashed()->find($this->item->getKey());
    expect($item)->not->toBeNull()
        ->and($item->title)->toBe('Kept')
        ->and($item->trashed())->toBe($trashed);
})->with('record endpoints');

it('denies a related record write when the related policy answers Response::deny()', function (string $method, Closure $url, array $payload = []) {
    PRChildPolicy::$mode = 'deny';

    $this->{$method}($url($this->item->getKey(), $this->child->getKey()), $payload)->assertStatus(403);

    expect(PRChild::query()->find($this->child->getKey())?->title)->toBe('Kept');
})->with('related record writes');

it('allows when the policy answers Response::allow() (control)', function () {
    $id = $this->item->getKey();

    $this->getJson("/martis/api/resources/pr-items/{$id}")->assertOk();
    $this->putJson("/martis/api/resources/pr-items/{$id}", ['title' => 'Changed'])->assertOk();

    expect($this->item->fresh()->title)->toBe('Changed');
});

it('reads a Response from before() too', function () {
    $id = $this->item->getKey();

    PRItemPolicy::$before = 'deny';
    $this->getJson("/martis/api/resources/pr-items/{$id}")->assertStatus(403);
    $this->putJson("/martis/api/resources/pr-items/{$id}", ['title' => 'Changed'])->assertStatus(403);
    expect($this->item->fresh()->title)->toBe('Kept');

    PRItemPolicy::$before = 'allow';
    PRItemPolicy::$mode = 'false';
    $this->getJson("/martis/api/resources/pr-items/{$id}")->assertOk();
});

it('reports Response::deny() as denied in the authorization metadata and the relationship abilities', function () {
    PRItemPolicy::$mode = 'deny';
    $resource = new PRItemResource($this->item);
    $request = prRequest();

    expect($resource->authorizationMetadata($request))->toMatchArray([
        'authorizedToView' => false,
        'authorizedToUpdate' => false,
        'authorizedToDelete' => false,
        'authorizedToReplicate' => false,
        'authorizedToRunAction' => false,
    ])
        ->and($resource->authorizedToAttachAny($request, PRChild::class))->toBeFalse();

    PRItemPolicy::$mode = 'allow';

    expect($resource->authorizedToAttachAny($request, PRChild::class))->toBeTrue();
});

// ---- Response::denyAsNotFound() looks like a missing record ----------------

it('answers a record the policy denies as not found exactly as a missing one', function (string $method, Closure $url, bool $trashed, array $payload = []) {
    PRItemPolicy::$mode = 'not-found';
    if ($trashed) {
        $this->item->delete();
    }

    $denied = $this->{$method}($url($this->item->getKey()), $payload);
    $missing = $this->{$method}($url(999999), $payload);

    $missing->assertStatus(404);
    $denied->assertStatus(404);
    expect($denied->json())->toBe($missing->json());

    $item = PRItem::withTrashed()->find($this->item->getKey());
    expect($item->title)->toBe('Kept')->and($item->trashed())->toBe($trashed);
})->with('record endpoints');

it('answers 404 for the record endpoints only because the policy denied (control)', function (string $method, Closure $url, bool $trashed, array $payload = []) {
    if ($trashed) {
        $this->item->delete();
    }

    expect($this->{$method}($url($this->item->getKey()), $payload)->status())->toBeLessThan(400);
})->with('record endpoints');

it('answers a related record the policy denies as not found exactly as a missing one', function (string $method, Closure $url, array $payload = []) {
    PRChildPolicy::$mode = 'not-found';

    $denied = $this->{$method}($url($this->item->getKey(), $this->child->getKey()), $payload);
    $missing = $this->{$method}($url($this->item->getKey(), 999999), $payload);

    $missing->assertStatus(404);
    $denied->assertStatus(404);
    expect($denied->json())->toBe($missing->json())
        ->and(PRChild::query()->find($this->child->getKey())?->title)->toBe('Kept');
})->with('related record writes');

it('answers the status a policy denies with', function () {
    PRItemPolicy::$mode = 'gone';

    $this->getJson("/martis/api/resources/pr-items/{$this->item->getKey()}/peek")
        ->assertStatus(410)
        ->assertJsonPath('message', 'This action is unauthorized.');
});

it('keeps answering 403 for a plain false or Response::deny()', function (string $mode) {
    PRItemPolicy::$mode = $mode;

    $this->getJson("/martis/api/resources/pr-items/{$this->item->getKey()}/peek")->assertStatus(403);
})->with(['false', 'deny']);

it('keeps a denial status per ability and drops it on the next check', function () {
    $resource = new PRItemResource($this->item);
    $request = prRequest();

    PRItemPolicy::$mode = 'not-found';
    expect($resource->authorizedToUpdate($request))->toBeFalse()
        ->and($resource->policyDenialStatus('update'))->toBe(404)
        ->and($resource->policyDenialStatus('view'))->toBeNull();

    PRItemPolicy::$mode = 'false';
    expect($resource->authorizedToUpdate($request))->toBeFalse()
        ->and($resource->policyDenialStatus('update'))->toBeNull();

    PRItemPolicy::$mode = 'gone';
    $resource->authorizedToView($request);
    PRItemPolicy::$mode = 'allow';
    expect($resource->authorizedToView($request))->toBeTrue()
        ->and($resource->policyDenialStatus('view'))->toBeNull();
});

// ---- the routes that find a record through a parent ------------------------

dataset('relationship parent routes', function () {
    $base = fn (int|string $id) => "/martis/api/resources/pr-items/{$id}";

    return [
        'GET has-one show' => [fn ($id) => $base($id).'/has-one/profile'],
        'GET morph-one show' => [fn ($id) => $base($id).'/morph-one/pinned'],
        'GET morph-many index' => [fn ($id) => $base($id).'/morph-many/notes'],
        'GET belongs-to-many index' => [fn ($id) => $base($id).'/belongs-to-many/kids'],
    ];
});

it('answers a parent the policy denies as not found exactly as a missing one, on every relationship route', function (Closure $url) {
    PRItemPolicy::$mode = 'not-found';

    $denied = $this->getJson($url($this->item->getKey()));
    $missing = $this->getJson($url(999999));

    $missing->assertStatus(404);
    $denied->assertStatus(404);
    expect($denied->json())->toBe($missing->json());
})->with('relationship parent routes');

it('serves the relationship routes while the parent policy allows (control)', function (Closure $url) {
    expect($this->getJson($url($this->item->getKey()))->status())->toBeLessThan(400);
})->with('relationship parent routes');

it('denies a parent with 403 on every relationship route when the policy answers Response::deny()', function (Closure $url) {
    PRItemPolicy::$mode = 'deny';

    $this->getJson($url($this->item->getKey()))->assertStatus(403);
})->with('relationship parent routes');

it('answers a relationship action of a parent the policy denies as not found exactly as a missing parent', function () {
    $run = fn (int|string $parent) => $this->postJson('/martis/api/resources/pr-children/actions/pr-touch', [
        'resources' => [$this->child->getKey()],
        'viaResource' => 'pr-items',
        'viaResourceId' => $parent,
        'viaRelationship' => 'children',
    ]);

    expect($run($this->item->getKey())->status())->toBeLessThan(400);

    PRItemPolicy::$mode = 'not-found';
    $denied = $run($this->item->getKey());
    $missing = $run(999999);

    $missing->assertStatus(404);
    $denied->assertStatus(404);
    expect($denied->json())->toBe($missing->json());
});

it('answers the sync-field of a record the policy denies updating as not found exactly as a missing one', function () {
    $sync = fn (int|string $id) => $this->postJson('/martis/api/resources/pr-items/sync-field', [
        'field' => 'title',
        'context' => 'update',
        'id' => $id,
        'formData' => ['title' => 'x'],
    ]);

    // Past the gate, a field that is not reactive answers 422 (control).
    $sync($this->item->getKey())->assertStatus(422)->assertJsonPath('errors.0.message', 'Field [title] is not reactive.');

    PRItemPolicy::$mode = 'not-found';
    $denied = $sync($this->item->getKey());
    $missing = $sync(999999);

    $missing->assertStatus(404);
    $denied->assertStatus(404);
    expect($denied->json())->toBe($missing->json());
});

// ---- the answer of each ability is its own ----------------------------------

/** A policy whose abilities answer each in its own mode: `$answers['replicate'] = 'not-found'`. */
class PRSplitPolicy
{
    /** @var array<string, string> */
    public static array $answers = [];

    public function viewAny($user): bool
    {
        return true;
    }

    public function create($user): Response|bool
    {
        return prAnswer(static::$answers['create'] ?? 'allow');
    }

    public function view($user, $model): Response|bool
    {
        return prAnswer(static::$answers['view'] ?? 'allow');
    }

    public function update($user, $model): Response|bool
    {
        return prAnswer(static::$answers['update'] ?? 'allow');
    }

    public function replicate($user, $model): Response|bool
    {
        return prAnswer(static::$answers['replicate'] ?? 'allow');
    }
}

class PRSplitResource extends PRItemResource
{
    public static ?string $policy = PRSplitPolicy::class;

    public static function uriKey(): string
    {
        return 'pr-split-items';
    }
}

/** A policy with no `replicate` method at all: the prefill falls back to create AND update. */
class PRNoReplicatePolicy
{
    /** @var array<string, string> */
    public static array $answers = [];

    public function viewAny($user): bool
    {
        return true;
    }

    public function create($user): Response|bool
    {
        return prAnswer(static::$answers['create'] ?? 'allow');
    }

    public function view($user, $model): Response|bool
    {
        return prAnswer(static::$answers['view'] ?? 'allow');
    }

    public function update($user, $model): Response|bool
    {
        return prAnswer(static::$answers['update'] ?? 'allow');
    }
}

class PRNoReplicateItemResource extends PRItemResource
{
    public static ?string $policy = PRNoReplicatePolicy::class;

    public static function uriKey(): string
    {
        return 'pr-no-replicate-items';
    }
}

it('answers the replicate prefill with the status of the replicate ability, not of view', function () {
    $id = $this->item->getKey();
    PRSplitPolicy::$answers = ['view' => 'allow', 'replicate' => 'not-found'];

    $denied = $this->getJson("/martis/api/resources/pr-split-items/{$id}/replicate");
    $missing = $this->getJson('/martis/api/resources/pr-split-items/999999/replicate');

    $missing->assertStatus(404);
    $denied->assertStatus(404);
    expect($denied->json())->toBe($missing->json());

    // The record is still viewable and updatable: only the replicate ability refused.
    $this->getJson("/martis/api/resources/pr-split-items/{$id}")->assertOk();

    PRSplitPolicy::$answers = ['view' => 'allow', 'replicate' => 'deny'];
    $this->getJson("/martis/api/resources/pr-split-items/{$id}/replicate")->assertStatus(403);

    PRSplitPolicy::$answers = ['view' => 'allow', 'update' => 'not-found', 'replicate' => 'allow'];
    $this->getJson("/martis/api/resources/pr-split-items/{$id}/replicate")->assertOk();
});

it('hands the status of the ability that stops the create AND update fallback to the replicate prefill', function (array $answers, int $status) {
    PRNoReplicatePolicy::$answers = ['view' => 'allow'] + $answers;
    $id = $this->item->getKey();

    $response = $this->getJson("/martis/api/resources/pr-no-replicate-items/{$id}/replicate");
    $response->assertStatus($status);

    if ($status === 404) {
        expect($response->json())->toBe($this->getJson('/martis/api/resources/pr-no-replicate-items/999999/replicate')->json());
    }
})->with([
    'update answers not found' => [['update' => 'not-found'], 404],
    'update answers a status' => [['update' => 'gone'], 410],
    'create answers not found' => [['create' => 'not-found'], 404],
    'update answers Response::deny()' => [['update' => 'deny'], 403],
    'update answers false' => [['update' => 'false'], 403],
    'both allow (control)' => [[], 200],
]);

it('does not keep a replicate status after the fallback allows', function () {
    $resource = new PRNoReplicateItemResource($this->item);
    $request = prRequest();

    PRNoReplicatePolicy::$answers = ['update' => 'not-found'];
    expect($resource->authorizedToReplicate($request))->toBeFalse()
        ->and($resource->policyDenialStatus('replicate'))->toBe(404);

    PRNoReplicatePolicy::$answers = [];
    expect($resource->authorizedToReplicate($request))->toBeTrue()
        ->and($resource->policyDenialStatus('replicate'))->toBeNull();

    PRNoReplicatePolicy::$answers = ['update' => 'false'];
    expect($resource->authorizedToReplicate($request))->toBeFalse()
        ->and($resource->policyDenialStatus('replicate'))->toBeNull();
});

// ---- the report's case: a headless resource's peek -------------------------

/** Lets the viewer peek at the items titled "Mine" only, hiding the others. */
class PRItemCardPolicy
{
    public function viewAny($user): bool
    {
        return true;
    }

    public function view($user, $model): Response
    {
        return $model->getAttribute('title') === 'Mine' ? Response::allow() : Response::denyAsNotFound();
    }
}

class PRItemCardResource extends PRItemResource
{
    public static ?string $policy = PRItemCardPolicy::class;

    public static function uriKey(): string
    {
        return 'pr-item-cards';
    }

    public static function routable(): bool
    {
        return false;
    }
}

it('lets a headless resource answer the peek of a record outside its view as for a missing id', function () {
    app(ResourceRegistry::class)->register(PRItemCardResource::class);
    Resource::flushPolicyCache();
    $mine = PRItem::query()->create(['title' => 'Mine']);

    $this->getJson("/martis/api/resources/pr-item-cards/{$mine->getKey()}/peek")->assertOk();

    $foreign = $this->getJson("/martis/api/resources/pr-item-cards/{$this->item->getKey()}/peek");
    $missing = $this->getJson('/martis/api/resources/pr-item-cards/999999/peek');

    $foreign->assertStatus(404);
    expect($foreign->json())->toBe($missing->json());
});
