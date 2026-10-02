<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Cache\MartisCache;
use Martis\Cards\Card;
use Martis\Concerns\HasGate;
use Martis\Concerns\ProvidesToolFields;
use Martis\Contracts\ProvidesFields;
use Martis\Dashboards\Dashboard;
use Martis\Enums\FilterType;
use Martis\Facades\Martis;
use Martis\Fields\BelongsTo;
use Martis\Fields\HasMany;
use Martis\Fields\Select;
use Martis\Fields\Text;
use Martis\Filters\Filter;
use Martis\Gates\SoftGate;
use Martis\Http\Middleware\EnforceSoftGate;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Http\Requests\LensRequest;
use Martis\Lenses\Lens;
use Martis\Metrics\ValueMetric;
use Martis\Metrics\ValueResult;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Tools\Tool;

/*
 * The soft lock (`lockedFor()`, `requirePlan()`) withholds an entity's data,
 * not only its page. A user a lock closes out answers 403 with the lock
 * payload on every endpoint that serves the entity's data: the page
 * endpoints keep answering 200 with `{ locked: true, lock }`
 * (RouteGuardTest), and `martis.gate` (EnforceSoftGate) and `martis.tool`
 * (AuthorizeTool) refuse the data routes. `canSee()` and the policies still
 * win over the lock.
 */

// ── Fixtures ────────────────────────────────────────────────────────────────

/** The lock switch of the fixtures: `true` locks them, as a plan below the tier does. */
final class SgState
{
    public static bool $locked = true;

    public static bool $ran = false;
}

const SG_MODAL = ['title' => 'Pro feature', 'message' => 'Upgrade to unlock.', 'cta' => ['label' => 'Upgrade', 'url' => '/billing']];

class SgGroup extends Model
{
    protected $table = 'sg_groups';

    protected $guarded = [];

    public $timestamps = false;

    public function items(): EloquentHasMany
    {
        return $this->hasMany(SgItem::class, 'sg_group_id');
    }
}

class SgItem extends Model
{
    protected $table = 'sg_items';

    protected $guarded = [];

    public $timestamps = false;
}

class SgRename extends Action
{
    /** @param Collection<int, Model> $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        SgState::$ran = true;
        foreach ($models as $model) {
            $model->forceFill(['name' => 'renamed'])->save();
        }

        return ActionResponse::message('Done.');
    }
}

/** `name = value`; open to everyone. */
class SgOpenFilter extends Filter
{
    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query->where('name', $value);
    }

    public function filterType(): FilterType
    {
        return FilterType::Select;
    }
}

/** `name = value`; soft-locked for the user. */
class SgPaidFilter extends SgOpenFilter
{
    public function __construct(string $name = 'Paid')
    {
        parent::__construct($name);
        $this->lockedFor(fn (): bool => SgState::$locked)->lockModal(SG_MODAL);
    }
}

class SgCountMetric extends ValueMetric
{
    public function calculate(Request $request): ValueResult
    {
        return $this->result($this->applyFilterScope(SgItem::query())->count());
    }
}

/** A metric that adopts the soft gate, as a Card does. */
class SgPaidMetric extends SgCountMetric
{
    use HasGate;

    public function __construct(string $name = 'Paid', ?string $uriKey = 'paid')
    {
        parent::__construct($name, $uriKey);
        $this->lockedFor(fn (): bool => SgState::$locked)->lockModal(SG_MODAL);
    }
}

class SgOpenLens extends Lens
{
    public function query(LensRequest $request, Builder $query): Builder
    {
        return $request->withFilters($query);
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }

    public function filters(Request $request): array
    {
        return [SgOpenFilter::make('Open'), new SgPaidFilter];
    }
}

class SgPaidLens extends SgOpenLens
{
    public function __construct()
    {
        parent::__construct();
        $this->lockedFor(fn (): bool => SgState::$locked)->lockModal(SG_MODAL);
    }
}

/** An unlocked parent resource: its panel, picker and metrics point at the locked one. */
class SgGroupResource extends Resource
{
    public static function model(): string
    {
        return SgGroup::class;
    }

    public static function uriKey(): string
    {
        return 'sg-groups';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            BelongsTo::make('featured_item_id', 'Featured item')->relatedResource('sg-items')->titleAttribute('name')->nullable(),
            HasMany::make('Items', 'items', SgItemResource::class)->showOnIndex(),
        ];
    }

    public function filters(Request $request): array
    {
        return [SgOpenFilter::make('Open'), new SgPaidFilter];
    }

    public function lenses(Request $request): array
    {
        return [new SgOpenLens, new SgPaidLens];
    }

    public function cards(Request $request): array
    {
        return [SgCountMetric::make('Open count', 'open-count'), new SgPaidMetric];
    }
}

/** The locked resource. */
class SgItemResource extends Resource
{
    public function __construct(?Model $model = null)
    {
        parent::__construct($model);
        $this->lockedFor(fn (): bool => SgState::$locked)->lockModal(SG_MODAL);
    }

    public static function model(): string
    {
        return SgItem::class;
    }

    public static function uriKey(): string
    {
        return 'sg-items';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->rules(['required'])->searchable(),
            BelongsTo::make('sg_group_id', 'Group')->relatedResource('sg-groups')->titleAttribute('name')->nullable(),
            Select::make('kind')->searchOptionsUsing(fn (string $term): array => ['a' => 'A']),
        ];
    }

    public function actions(Request $request): array
    {
        return [SgRename::make()];
    }

    public function filters(Request $request): array
    {
        return [SgOpenFilter::make('Open'), new SgPaidFilter];
    }

    public function lenses(Request $request): array
    {
        return [new SgOpenLens];
    }

    public function cards(Request $request): array
    {
        return [SgCountMetric::make('Open count', 'open-count')];
    }
}

class SgLockedDashboard extends Dashboard
{
    public function __construct()
    {
        parent::__construct(name: 'Pro Lab', uriKey: 'pro-lab');
        $this->lockedFor(fn (): bool => SgState::$locked)->lockModal(SG_MODAL);
    }

    public function cards(Request $request): array
    {
        return [SgCountMetric::make('Open count', 'open-count')];
    }
}

class SgOpenDashboard extends Dashboard
{
    public function __construct()
    {
        parent::__construct(name: 'Home', uriKey: 'home');
    }

    public function cards(Request $request): array
    {
        return [SgCountMetric::make('Open count', 'open-count'), new SgPaidMetric];
    }

    public function filters(Request $request): array
    {
        return [SgOpenFilter::make('Open'), new SgPaidFilter];
    }
}

class SgLockedTool extends Tool implements ProvidesFields
{
    use ProvidesToolFields;

    public function __construct(public ?string $routesPath = null)
    {
        parent::__construct(name: 'Locked Tool', uriKey: 'sg-tool');
        $this->withComponent('tool:sg-tool');
        $this->lockedFor(fn (): bool => SgState::$locked)->lockModal(SG_MODAL);
    }

    public function boot(): void
    {
        if ($this->routesPath !== null) {
            $this->loadRoutes($this->routesPath);
        }
    }

    public function fields(Request $request): array
    {
        return [Text::make('title'), Select::make('model')->searchOptionsUsing(fn (string $term): array => ['a' => 'A'])];
    }
}

/** Locked and hidden: `canSee()` wins. */
class SgHiddenLockedTool extends SgLockedTool
{
    public function __construct()
    {
        parent::__construct();
        $this->canSee(fn (): bool => false);
    }

    public function uriKey(): string
    {
        return 'sg-hidden-tool';
    }
}

const SG_BASE = '/martis/api';

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);
    SgState::$locked = true;
    SgState::$ran = false;

    Schema::dropIfExists('sg_groups');
    Schema::create('sg_groups', function ($table) {
        $table->id();
        $table->string('name');
        $table->unsignedBigInteger('featured_item_id')->nullable();
    });
    Schema::dropIfExists('sg_items');
    Schema::create('sg_items', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('kind')->nullable();
        $table->unsignedBigInteger('sg_group_id')->nullable();
    });

    $this->group = SgGroup::create(['name' => 'Group']);
    $this->item = SgItem::create(['name' => 'Ada', 'sg_group_id' => $this->group->id]);
    SgItem::create(['name' => 'Bob', 'sg_group_id' => $this->group->id]);

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(SgGroupResource::class);
    $registry->register(SgItemResource::class);

    Martis::dashboards([]);
    Martis::tools([]);
    app(MartisCache::class)->clear('dashboards');
    Cache::flush();
});

afterEach(function () {
    Martis::dashboards([]);
    Martis::tools([]);
    app(MartisCache::class)->clear('dashboards');
    Schema::dropIfExists('sg_groups');
    Schema::dropIfExists('sg_items');
});

/** The 403 every data endpoint answers a locked user. */
function sgAssertLocked($response): void
{
    $response->assertForbidden()
        ->assertJsonPath('locked', true)
        ->assertJsonPath('lock.modal.title', 'Pro feature')
        ->assertJsonPath('lock.modal.cta.url', '/billing')
        ->assertJsonPath('lock.reason', 'gated')
        ->assertJsonPath('message', __('martis::messages.feature_locked'));
}

// ── Every route that names an entity carries the gate ───────────────────────

it('puts the soft gate on every API route that names a resource, a dashboard or a tool', function () {
    $named = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'martis.api.'))
        ->filter(fn (RoutingRoute $route): bool => collect(['resource', 'dashboard', 'uriKey'])
            ->contains(fn (string $parameter): bool => in_array($parameter, $route->parameterNames(), true)));

    expect($named->count())->toBeGreaterThan(60);

    // The middleware the router resolves for the route: `withoutMiddleware()` applied.
    $ungated = $named
        ->reject(fn (RoutingRoute $route): bool => in_array(EnforceSoftGate::class, app('router')->gatherRouteMiddleware($route), true))
        ->map(fn (RoutingRoute $route): string => $route->getName())
        ->values()
        ->all();

    // The two page endpoints answer the lock themselves, with a 200.
    expect($ungated)->toEqualCanonicalizing(['martis.api.dashboards.show', 'martis.api.tools.show']);
});

// ── A locked Resource: every entry point ────────────────────────────────────

it('refuses every data endpoint of a locked resource with the lock payload', function (string $method, string $path) {
    $response = $this->json($method, SG_BASE.'/resources/sg-items'.str_replace('{id}', (string) $this->item->id, $path), $method === 'GET' ? [] : ['name' => 'Changed']);

    sgAssertLocked($response);
    expect(SgState::$ran)->toBeFalse()
        ->and(SgItem::query()->count())->toBe(2)
        ->and($this->item->fresh()->name)->toBe('Ada');
})->with([
    'schema' => ['GET', '/schema'],
    'index' => ['GET', ''],
    'store' => ['POST', ''],
    'show' => ['GET', '/{id}'],
    'update' => ['PUT', '/{id}'],
    'destroy' => ['DELETE', '/{id}'],
    'force delete' => ['DELETE', '/{id}/force'],
    'restore' => ['PUT', '/{id}/restore'],
    'replicate' => ['GET', '/{id}/replicate'],
    'peek' => ['GET', '/{id}/peek'],
    'relatable (its own picker)' => ['GET', '/{id}/relatable/sg_group_id'],
    'relatable on a create form' => ['GET', '/_/relatable/sg_group_id'],
    'select option search' => ['GET', '/fields/kind/options'],
    'slug check' => ['GET', '/slug-check/name'],
    'sync field' => ['POST', '/sync-field'],
    'inline create schema' => ['GET', '/inline-create-schema'],
    'inline create' => ['POST', '/inline-create'],
    'resource card' => ['GET', '/cards/open-count'],
    'actions list' => ['GET', '/actions'],
    'action fields' => ['GET', '/actions/sg-rename/fields'],
    'action relatable' => ['GET', '/actions/sg-rename/relatable/owner'],
    'run action on a selection' => ['POST', '/actions/sg-rename'],
    'run action on a record' => ['POST', '/{id}/actions/sg-rename'],
    'lens' => ['GET', '/lenses/sg-open'],
    'lens actions' => ['GET', '/lenses/sg-open/actions'],
    'run a lens action' => ['POST', '/lenses/sg-open/actions/sg-rename'],
    'has many panel' => ['GET', '/{id}/has-many/items'],
    'has many store' => ['POST', '/{id}/has-many/items'],
    'has one' => ['GET', '/{id}/has-one/profile'],
    'belongs to many' => ['GET', '/{id}/belongs-to-many/tags'],
    'belongs to many attach' => ['POST', '/{id}/belongs-to-many/tags/attach'],
    'morph many' => ['GET', '/{id}/morph-many/comments'],
    'morph to many' => ['GET', '/{id}/morph-to-many/tags'],
    'morph one' => ['GET', '/{id}/morph-one/image'],
]);

it('serves the same endpoints once the resource is unlocked', function () {
    SgState::$locked = false;

    $this->getJson(SG_BASE.'/resources/sg-items')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson(SG_BASE.'/resources/sg-items/schema')->assertOk();
    $this->getJson(SG_BASE.'/resources/sg-items/'.$this->item->id)->assertOk();
    $this->getJson(SG_BASE.'/resources/sg-items/cards/open-count')->assertOk();
    $this->getJson(SG_BASE.'/resources/sg-items/lenses/sg-open')->assertOk();
});

it('refuses a write on a locked resource without writing', function () {
    $this->postJson(SG_BASE.'/resources/sg-items', ['name' => 'New'])->assertForbidden();
    $this->putJson(SG_BASE.'/resources/sg-items/'.$this->item->id, ['name' => 'Changed'])->assertForbidden();
    $this->deleteJson(SG_BASE.'/resources/sg-items/'.$this->item->id)->assertForbidden();
    $this->postJson(SG_BASE.'/resources/sg-items/actions/sg-rename', ['resources' => [$this->item->id]])->assertForbidden();

    expect(SgItem::query()->pluck('name')->all())->toBe(['Ada', 'Bob'])
        ->and(SgState::$ran)->toBeFalse();
});

it('lets the policy answer a user the resource viewAny closes, before the lock', function () {
    Gate::policy(SgItem::class, SgDenyAllPolicy::class);
    Resource::flushPolicyCache();

    // Not the lock: `canSee()` / `viewAny` win, so the lock is only told to
    // a user who may see the resource.
    $response = $this->getJson(SG_BASE.'/resources/sg-items')->assertForbidden();

    expect($response->json('locked'))->toBeNull()
        ->and($response->json('lock'))->toBeNull();

    Resource::flushPolicyCache();
});

class SgDenyAllPolicy
{
    public function viewAny($user = null): bool
    {
        return false;
    }
}

// ── What serves another resource's records ──────────────────────────────────

it('refuses the picker of an unlocked resource that lists the records of a locked one', function () {
    sgAssertLocked($this->getJson(SG_BASE.'/resources/sg-groups/_/relatable/featured_item_id'));
    sgAssertLocked($this->getJson(SG_BASE.'/resources/_/_/relatable/featured_item_id?related_resource=sg-items'));

    SgState::$locked = false;

    $this->getJson(SG_BASE.'/resources/sg-groups/_/relatable/featured_item_id')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson(SG_BASE.'/resources/_/_/relatable/featured_item_id?related_resource=sg-items')->assertOk()->assertJsonCount(2, 'data');
});

it('closes the relationship panel that lists the records of a locked resource', function () {
    $panel = SG_BASE.'/resources/sg-groups/'.$this->group->id.'/has-many/items';

    $this->getJson($panel)->assertForbidden();

    $detail = $this->getJson(SG_BASE.'/resources/sg-groups/'.$this->group->id)->assertOk();
    expect(collect($detail->json('data'))->keys()->all())->not->toContain('items');

    // Nor does the parent's index carry the count of the locked records.
    $index = $this->getJson(SG_BASE.'/resources/sg-groups')->assertOk();
    expect(collect($index->json('data.0'))->keys()->all())->not->toContain('items');

    SgState::$locked = false;

    $this->getJson($panel)->assertOk()->assertJsonCount(2, 'data');
    expect($this->getJson(SG_BASE.'/resources/sg-groups')->assertOk()->json('data.0.items'))->not->toBeNull();
});

it('leaves a locked resource out of the global search', function () {
    $groups = fn () => collect($this->getJson(SG_BASE.'/search?q=Ada')->assertOk()->json('results'))->pluck('resource')->all();

    expect($groups())->not->toContain('sg-items');

    SgState::$locked = false;

    expect($groups())->toContain('sg-items');
});

it('rejects a write that names a record of a locked resource', function () {
    $this->postJson(SG_BASE.'/resources/sg-groups', ['name' => 'New', 'featured_item_id' => $this->item->id])
        ->assertUnprocessable();

    expect(SgGroup::query()->count())->toBe(1);

    SgState::$locked = false;

    $this->postJson(SG_BASE.'/resources/sg-groups', ['name' => 'New', 'featured_item_id' => $this->item->id])
        ->assertCreated();
});

it('shows no live count for a locked resource on the sidebar badges', function () {
    $counts = fn () => $this->getJson(SG_BASE.'/navigation/badges')->assertOk()->json();

    expect($counts())->not->toHaveKey('resource:sg-items');

    SgState::$locked = false;
    app(MartisCache::class)->clear('navigation');

    expect($counts())->toHaveKey('resource:sg-items');
});

// ── Lens, card and filter locks on an unlocked resource ─────────────────────

it('refuses the data routes of a locked lens, not those of its open sibling', function (string $method, string $path) {
    sgAssertLocked($this->json($method, SG_BASE.'/resources/sg-groups/lenses/sg-paid'.$path));

    // The sibling lens answers; the resource itself is not locked.
    $this->getJson(SG_BASE.'/resources/sg-groups/lenses/sg-open')->assertOk();
})->with([
    'lens page' => ['GET', ''],
    'lens actions' => ['GET', '/actions'],
    'lens action fields' => ['GET', '/actions/sg-rename/fields'],
    'lens action relatable' => ['GET', '/actions/sg-rename/relatable/owner'],
    'run a lens action' => ['POST', '/actions/sg-rename'],
]);

it('serves the lens once its lock lifts', function () {
    SgState::$locked = false;

    $this->getJson(SG_BASE.'/resources/sg-groups/lenses/sg-paid')->assertOk()->assertJsonCount(1, 'data');
});

it('refuses the compute route of a locked card and computes an open one', function () {
    sgAssertLocked($this->getJson(SG_BASE.'/resources/sg-groups/cards/paid'));

    $this->getJson(SG_BASE.'/resources/sg-groups/cards/open-count')->assertOk();

    SgState::$locked = false;

    $this->getJson(SG_BASE.'/resources/sg-groups/cards/paid')->assertOk();
});

it('does not apply a locked filter on the index, whatever the request names', function () {
    SgState::$locked = false;
    $locked = fn (string $path) => collect($this->getJson($path)->assertOk()->json('data'))->pluck('name')->all();
    $filters = fn (array $values) => urlencode(json_encode($values));

    // Control: an open filter narrows the list.
    expect($locked(SG_BASE.'/resources/sg-items?filters='.$filters(['paid' => 'Ada'])))->toBe(['Ada']);

    SgState::$locked = true;
    // The resource is locked now: the filter lock is tested on the group resource.
    SgGroup::create(['name' => 'Other']);
    $names = fn (array $values) => collect($this->getJson(SG_BASE.'/resources/sg-groups?filters='.$filters($values))->assertOk()->json('data'))->pluck('name')->all();

    expect($names(['open' => 'Group']))->toBe(['Group'])
        ->and($names(['paid' => 'Group']))->toBe(['Group', 'Other']);
});

it('does not apply a locked filter on a lens', function () {
    $filters = urlencode(json_encode(['paid' => 'Ada']));
    $names = fn (string $query) => collect($this->getJson(SG_BASE.'/resources/sg-groups/lenses/sg-open?'.$query)->assertOk()->json('data'))->pluck('name')->all();

    SgGroup::create(['name' => 'Other']);

    expect($names('filters='.urlencode(json_encode(['open' => 'Group']))))->toBe(['Group'])
        ->and($names('filters='.$filters))->toBe(['Group', 'Other']);
});

// ── A locked Dashboard and its cards ────────────────────────────────────────

it('refuses the card route of a locked dashboard, though its page answers 200', function () {
    Martis::dashboards([new SgLockedDashboard]);

    sgAssertLocked($this->getJson(SG_BASE.'/dashboards/pro-lab/cards/open-count'));
    $this->getJson(SG_BASE.'/dashboards/pro-lab')->assertOk()->assertJsonPath('data.locked', true);

    SgState::$locked = false;

    $this->getJson(SG_BASE.'/dashboards/pro-lab/cards/open-count')->assertOk()->assertJsonPath('data.result.value', 2);
});

it('refuses a locked card of an unlocked dashboard, and computes an open one', function () {
    Martis::dashboards([new SgOpenDashboard]);

    sgAssertLocked($this->getJson(SG_BASE.'/dashboards/home/cards/paid'));
    $this->getJson(SG_BASE.'/dashboards/home/cards/open-count')->assertOk()->assertJsonPath('data.result.value', 2);

    SgState::$locked = false;

    $this->getJson(SG_BASE.'/dashboards/home/cards/paid')->assertOk();
});

it('does not apply a locked dashboard filter to a card', function () {
    Martis::dashboards([new SgOpenDashboard]);
    SgState::$locked = false;
    $value = fn (array $filters) => $this->getJson(SG_BASE.'/dashboards/home/cards/open-count?filters='.urlencode(json_encode($filters)))
        ->assertOk()->json('data.result.value');

    // Control: the unlocked filter narrows the card.
    expect($value(['paid' => 'Ada']))->toBe(1);

    SgState::$locked = true;

    expect($value(['paid' => 'Ada']))->toBe(2)
        ->and($value(['open' => 'Ada']))->toBe(1);
});

it('answers 404 to a dashboard card the user may not see, or that does not exist', function () {
    Martis::dashboards([new SgOpenDashboard]);

    $this->getJson(SG_BASE.'/dashboards/home/cards/missing')->assertNotFound();
    $this->getJson(SG_BASE.'/dashboards/missing/cards/open-count')->assertNotFound();
});

it('withholds the meta of a locked card from the dashboard payload', function () {
    $dashboard = new class extends Dashboard
    {
        public function __construct()
        {
            parent::__construct(name: 'Cards', uriKey: 'cards');
        }

        public function cards(Request $request): array
        {
            return [
                (new Card('Paid card', 'paid-card'))->withMeta(['secret' => 'figures'])
                    ->lockedFor(fn (): bool => SgState::$locked),
                (new Card('Open card', 'open-card'))->withMeta(['public' => 'figures']),
            ];
        }
    };
    Martis::dashboards([$dashboard]);

    $cards = collect($this->getJson(SG_BASE.'/dashboards/cards')->assertOk()->json('data.cards'))->keyBy('uriKey');

    expect($cards['paid-card']['lock'])->not->toBeNull()
        ->and($cards['paid-card']['meta'])->toBe([])
        ->and($cards['open-card']['meta'])->toBe(['public' => 'figures']);

    SgState::$locked = false;
    app(MartisCache::class)->clear('dashboards');

    $cards = collect($this->getJson(SG_BASE.'/dashboards/cards')->assertOk()->json('data.cards'))->keyBy('uriKey');

    expect($cards['paid-card']['meta'])->toBe(['secret' => 'figures']);
});

// ── A locked Tool ───────────────────────────────────────────────────────────

it('refuses the field schema and the option search of a locked tool, though its page answers 200', function () {
    Martis::tools([new SgLockedTool]);

    sgAssertLocked($this->getJson(SG_BASE.'/tools/sg-tool/fields'));
    sgAssertLocked($this->getJson(SG_BASE.'/tools/sg-tool/fields/model/options?search=a'));
    $this->getJson(SG_BASE.'/tools/sg-tool')->assertOk()->assertJsonPath('locked', true);

    SgState::$locked = false;

    $this->getJson(SG_BASE.'/tools/sg-tool/fields')->assertOk();
    $this->getJson(SG_BASE.'/tools/sg-tool/fields/model/options?search=a')->assertOk();
});

it('answers a hidden tool as before, never with its lock', function () {
    Martis::tools([new SgHiddenLockedTool]);

    $this->getJson(SG_BASE.'/tools/sg-hidden-tool/fields')->assertNotFound()->assertJsonMissingPath('lock');
});

it('refuses the routes a locked tool loads itself with the lock payload', function () {
    $routes = tempnam(sys_get_temp_dir(), 'martis_sg_tool_');
    file_put_contents($routes, <<<'PHP'
        <?php

        use Illuminate\Support\Facades\Route;

        Route::get('/data', fn () => response()->json(['paid' => 'data']));
        Route::post('/data', fn () => response()->json(['written' => true]));
        PHP);

    try {
        Martis::tools([new SgLockedTool($routes)]);
        Martis::getFacadeRoot()?->bootTools();

        sgAssertLocked($this->getJson(SG_BASE.'/tools/sg-tool/data'));
        sgAssertLocked($this->postJson(SG_BASE.'/tools/sg-tool/data'));

        // A request that does not expect JSON gets a plain 403 with no payload.
        $this->get(SG_BASE.'/tools/sg-tool/data', ['Accept' => 'text/html'])->assertForbidden();

        SgState::$locked = false;

        $this->getJson(SG_BASE.'/tools/sg-tool/data')->assertOk()->assertExactJson(['paid' => 'data']);
    } finally {
        @unlink($routes);
    }
});

// ── The shared guard ────────────────────────────────────────────────────────

it('gives a locked entity one refusal shape: 403, the error envelope and the lock payload', function () {
    $lock = ['reason' => 'plan:pro', 'modal' => ['title' => 'T']];

    $response = SoftGate::refusal($lock);

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getData(true))->toBe([
            'message' => __('martis::messages.feature_locked'),
            'errors' => [],
            'locked' => true,
            'lock' => $lock,
        ]);
});

it('reports no lock for an entity that cannot be locked or is not', function () {
    $request = Request::create('/');

    expect(SoftGate::lockOf(new stdClass, $request))->toBeNull()
        ->and(SoftGate::lockOf(['a' => 'b'], $request))->toBeNull()
        ->and(SoftGate::refusalFor(new stdClass, $request))->toBeNull()
        ->and(SoftGate::isLocked(new SgOpenFilter('x'), $request))->toBeFalse()
        ->and(SoftGate::isLocked(new SgPaidFilter, $request))->toBeTrue();
});
