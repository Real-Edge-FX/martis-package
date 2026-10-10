<?php

declare(strict_types=1);

use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\HasMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// A Resource policy may answer with Laravel's `Illuminate\Auth\Access\Response`
// (https://laravel.com/docs/authorization#policy-responses), as the Gate
// allows. Martis calls the policy directly and, up to v1.39.7, cast the
// answer to bool: an object is always true, so `Response::deny()` and
// `Response::denyAsNotFound()` ALLOWED (a `PUT` saved the record). The answer
// is now read through `allowed()`, in `before()` too. On 1.x every denial
// answers 403; 2.11 answers the status a denial carries.
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
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('pr_children');
    Schema::dropIfExists('pr_items');
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
    Resource::flushPolicyCache();

    $this->actingAs((new Authenticatable)->forceFill(['id' => 1, 'name' => 'Agent']));
});

afterEach(function () {
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

// ---- a denial with a status still denies ----------------------------------

it('denies with 403 when the policy answers with a status', function (string $mode) {
    PRItemPolicy::$mode = $mode;
    $id = $this->item->getKey();

    $this->getJson("/martis/api/resources/pr-items/{$id}/peek")->assertStatus(403);
    $this->putJson("/martis/api/resources/pr-items/{$id}", ['title' => 'Changed'])->assertStatus(403);

    expect($this->item->fresh()->title)->toBe('Kept');
})->with(['not-found', 'gone']);

it('keeps answering 403 for a plain false', function () {
    PRItemPolicy::$mode = 'false';

    $this->getJson("/martis/api/resources/pr-items/{$this->item->getKey()}/peek")->assertStatus(403);
});
