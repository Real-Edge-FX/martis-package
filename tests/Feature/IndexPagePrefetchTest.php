<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo as EloquentBelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Contracts\ActionContract;
use Martis\Contracts\ResourceContract;
use Martis\Fields\BelongsToMany;
use Martis\Fields\HasMany;
use Martis\Fields\Text;
use Martis\Http\Controllers\ResourceController;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Http\Requests\LensRequest;
use Martis\Lenses\Lens;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Support\ListingGuard;
use Martis\Support\PageContext;
use Martis\Testing\AssertsFlatQueries;
use PHPUnit\Framework\ExpectationFailedException;

uses(AssertsFlatQueries::class);

/*
 * v2.20.0: `Resource::prefetchIndexPage()` is called once per listed page
 * (index, lens, relationship panel) and never when an action resolves its
 * records, `PageContext` carries the facts to field resolvers and `canRun()`,
 * `Action::canRunForRows()` decides a page in one call and still stops the
 * run on a row it denies, `AssertsFlatQueries` proves a listing is flat and
 * the listing guard catches a lazy load without leaking global state.
 */

// ── Fixtures ────────────────────────────────────────────────────────────────

class PFParent extends Model
{
    protected $table = 'pf_parents';

    protected $fillable = ['name'];

    public function items(): EloquentHasMany
    {
        return $this->hasMany(PFItem::class, 'parent_id');
    }

    public function linked(): EloquentBelongsToMany
    {
        return $this->belongsToMany(PFItem::class, 'pf_links', 'parent_id', 'item_id');
    }
}

class PFItem extends Model
{
    protected $table = 'pf_items';

    protected $fillable = ['title', 'parent_id'];

    public function parent(): EloquentBelongsTo
    {
        return $this->belongsTo(PFParent::class, 'parent_id');
    }
}

/** What the fixtures observed. */
class PFLog
{
    /** @var list<array{rows: list<int>, method: string, open: bool}> */
    public static array $prefetch = [];

    /** @var list<list<int>> The keys each batched rule was asked about. */
    public static array $batchCalls = [];

    public static int $perRowCalls = 0;

    /** @var list<list<int>> The keys each run handled. */
    public static array $runs = [];

    /** @var list<int> Rows the batched rules deny. */
    public static array $denied = [];

    public static int $prefetchQueries = 0;

    /** Whether the hook also eager loads the parent. */
    public static bool $eagerLoadParent = false;

    public static function reset(): void
    {
        self::$prefetch = [];
        self::$batchCalls = [];
        self::$perRowCalls = 0;
        self::$runs = [];
        self::$denied = [];
        self::$prefetchQueries = 0;
        self::$eagerLoadParent = false;
    }
}

abstract class PFAction extends Action
{
    /** @param Collection<int, Model> $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        PFLog::$runs[] = $models->map(fn (Model $m): int => (int) $m->getKey())->sort()->values()->all();

        return ActionResponse::message('Done.');
    }
}

/** Only a batched rule. */
class PFBatchAction extends PFAction
{
    public function __construct()
    {
        $this->canRunForRows(function (Request $request, Collection $rows): array {
            PFLog::$batchCalls[] = $rows->map(fn (Model $m): int => (int) $m->getKey())->all();

            return $rows->mapWithKeys(fn (Model $m): array => [$m->getKey() => ! in_array((int) $m->getKey(), PFLog::$denied, true)])->all();
        });
    }

    public function uriKey(): string
    {
        return 'pf-batch';
    }
}

/** A batched rule that answers only the rows it denies, with a per-row rule behind it. */
class PFPartialAction extends PFAction
{
    public function __construct()
    {
        $this->canRunForRows(fn (Request $request, Collection $rows): array => $rows
            ->filter(fn (Model $m): bool => in_array((int) $m->getKey(), PFLog::$denied, true))
            ->mapWithKeys(fn (Model $m): array => [$m->getKey() => false])
            ->all());
        $this->canRun(function (Request $request, Model $model): bool {
            PFLog::$perRowCalls++;

            return $model->getAttribute('title') !== 'Per row denied';
        });
    }

    public function uriKey(): string
    {
        return 'pf-partial';
    }
}

/** Only a per-row rule, reading the page context with the domain rule as fallback. */
class PFRowAction extends PFAction
{
    public function __construct()
    {
        $this->canRun(function (Request $request, Model $model): bool {
            return (bool) PageContext::current($request)->get('allowed', $model, $model->getAttribute('title') !== 'Blocked');
        });
    }

    public function uriKey(): string
    {
        return 'pf-row';
    }
}

/** A batched rule that answers only the rows it allows, with string keys and no per-row rule. */
class PFOmitAction extends PFAction
{
    public function __construct()
    {
        // Keyed by the string form of the key, only for the rows it allows.
        $this->canRunForRows(fn (Request $request, Collection $rows): array => $rows
            ->filter(fn (Model $m): bool => ! in_array((int) $m->getKey(), PFLog::$denied, true) && $m->getAttribute('title') !== 'Blocked')
            ->mapWithKeys(fn (Model $m): array => [(string) $m->getKey() => true])
            ->all());
    }

    public function uriKey(): string
    {
        return 'pf-omit';
    }
}

/** A batched rule that returns something that is not an array. */
class PFBrokenBatchAction extends PFAction
{
    public function __construct()
    {
        $this->canRunForRows(fn (): mixed => null);
    }

    public function uriKey(): string
    {
        return 'pf-broken';
    }
}

/** A batched rule that returns a list (keys 0..n-1), not a map keyed by row key. */
class PFListBatchAction extends PFAction
{
    public function __construct()
    {
        // "Only the second row", written as a list: its keys are positions, not row keys.
        $this->canRunForRows(fn (Request $request, Collection $rows): array => $rows->values()->map(fn (Model $m, int $i): bool => $i === 1)->all());
    }

    public function uriKey(): string
    {
        return 'pf-list';
    }
}

/** A batched rule plus its own `authorizedToRun()` override: both must allow. */
class PFOverrideAction extends PFAction
{
    /** @var list<int> Rows the override denies. */
    public static array $overrideDenied = [];

    public function __construct()
    {
        $this->canRunForRows(function (Request $request, Collection $rows): array {
            return $rows->mapWithKeys(fn (Model $m): array => [$m->getKey() => ! in_array((int) $m->getKey(), PFLog::$denied, true)])->all();
        });
    }

    public function authorizedToRun(Request $request, Model $model): bool
    {
        return ! in_array((int) $model->getKey(), self::$overrideDenied, true);
    }

    public function uriKey(): string
    {
        return 'pf-override';
    }
}

/** The same, but the override defers to the base rule with `parent::`. */
class PFOverrideParentAction extends PFAction
{
    public function __construct()
    {
        $this->canRunForRows(function (Request $request, Collection $rows): array {
            return $rows->mapWithKeys(fn (Model $m): array => [$m->getKey() => ! in_array((int) $m->getKey(), PFLog::$denied, true)])->all();
        });
    }

    public function authorizedToRun(Request $request, Model $model): bool
    {
        return parent::authorizedToRun($request, $model) && $model->getAttribute('title') !== 'Parent denied';
    }

    public function uriKey(): string
    {
        return 'pf-override-parent';
    }
}

class PFItemResource extends Resource
{
    public static function model(): string
    {
        return PFItem::class;
    }

    public static function uriKey(): string
    {
        return 'pf-items';
    }

    public static function prefetchIndexPage(Request $request, EloquentCollection $rows, PageContext $context): void
    {
        PFLog::$prefetch[] = [
            'rows' => $rows->map(fn (Model $m): int => (int) $m->getKey())->all(),
            'method' => $request->method(),
            'open' => PageContext::isOpen($request),
        ];

        PFLog::$prefetchQueries++;
        $context->putMany('score', PFItem::query()->whereIn('id', $rows->modelKeys())->pluck('id', 'id')->map(fn (int $id): int => $id * 10)->all());
        $context->putMany('allowed', $rows->mapWithKeys(fn (Model $m): array => [$m->getKey() => $m->getAttribute('title') !== 'Blocked'])->all());

        if (PFLog::$eagerLoadParent) {
            $rows->load('parent');
        }
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title', 'Title'),
            Text::make('score', 'Score')->resolveUsing(
                fn (mixed $value, Model $model): mixed => PageContext::current()->get('score', $model, 'none'),
            ),
        ];
    }

    public function actions(Request $request): array
    {
        return [
            (new PFBatchAction)->showInline(),
            (new PFPartialAction)->showInline(),
            (new PFRowAction)->showInline(),
            (new PFOmitAction)->showInline(),
            (new PFOverrideAction)->showInline(),
            (new PFOverrideParentAction)->showInline(),
        ];
    }
}

/** The default hook: nothing overridden. */
class PFPlainItemResource extends Resource
{
    public static function model(): string
    {
        return PFItem::class;
    }

    public static function uriKey(): string
    {
        return 'pf-plain-items';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title', 'Title')];
    }
}

/** A resource whose only action is a custom class that implements ActionContract. */
class PFCustomActionResource extends PFPlainItemResource
{
    public static ?ActionContract $custom = null;

    public static function uriKey(): string
    {
        return 'pf-custom-items';
    }

    public function actions(Request $request): array
    {
        return [static::$custom];
    }
}

/** A resource class that is not a Resource: it only implements the contract's shape. */
class PFContractOnlyResource {}

class PFListResource extends PFPlainItemResource
{
    public static function uriKey(): string
    {
        return 'pf-list-items';
    }

    public function actions(Request $request): array
    {
        return [(new PFListBatchAction)->showInline()];
    }
}

/** An N+1 on purpose: each row asks the database for its parent. */
class PFNPlusOneItemResource extends PFPlainItemResource
{
    public static function uriKey(): string
    {
        return 'pf-n-plus-one-items';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title', 'Title'),
            Text::make('parent_name', 'Parent')->resolveUsing(
                fn (mixed $value, Model $model): mixed => DB::table('pf_parents')->where('id', $model->getAttribute('parent_id'))->value('name'),
            ),
        ];
    }
}

/** Lazy loads the parent relation of each row. */
class PFLazyItemResource extends PFItemResource
{
    public static function uriKey(): string
    {
        return 'pf-lazy-items';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title', 'Title'),
            Text::make('parent_name', 'Parent')->resolveUsing(
                fn (mixed $value, Model $model): mixed => $model->parent?->name,
            ),
        ];
    }
}

class PFLens extends Lens
{
    public function query(LensRequest $request, Builder $query): Builder
    {
        return $query;
    }

    public function uriKey(): string
    {
        return 'pf-lens';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title', 'Title')];
    }
}

class PFItemWithLensResource extends PFItemResource
{
    public static function uriKey(): string
    {
        return 'pf-lens-items';
    }

    public function lenses(Request $request): array
    {
        return [new PFLens];
    }
}

/** A batched pivot action, declared on the many-to-many field. */
class PFPivotAction extends PFAction
{
    public function __construct()
    {
        $this->canRunForRows(function (Request $request, Collection $rows): array {
            PFLog::$batchCalls[] = $rows->map(fn (Model $m): int => (int) $m->getKey())->all();

            return $rows->mapWithKeys(fn (Model $m): array => [$m->getKey() => ! in_array((int) $m->getKey(), PFLog::$denied, true)])->all();
        });
    }

    public function uriKey(): string
    {
        return 'pf-pivot';
    }
}

class PFParentResource extends Resource
{
    public static function model(): string
    {
        return PFParent::class;
    }

    public static function uriKey(): string
    {
        return 'pf-parents';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name', 'Name'),
            HasMany::make('Items', 'items')->relatedResource('pf-items'),
            BelongsToMany::make('Linked', 'linked')->relatedResource('pf-items')
                ->actions(fn (): array => [(new PFPivotAction)->pivotAction()]),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);
    PFLog::reset();
    PFOverrideAction::$overrideDenied = [];
    config(['martis.dev.prevent_lazy_loading_in_listings' => false]);

    Schema::disableForeignKeyConstraints();
    foreach (['pf_links', 'pf_items', 'pf_parents'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::enableForeignKeyConstraints();

    Schema::create('pf_parents', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    Schema::create('pf_items', function ($table) {
        $table->id();
        $table->unsignedBigInteger('parent_id')->nullable();
        $table->string('title');
        $table->timestamps();
    });
    Schema::create('pf_links', function ($table) {
        $table->unsignedBigInteger('parent_id');
        $table->unsignedBigInteger('item_id');
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    foreach ([
        PFItemResource::class, PFPlainItemResource::class, PFNPlusOneItemResource::class,
        PFLazyItemResource::class, PFItemWithLensResource::class, PFParentResource::class,
    ] as $class) {
        $registry->register($class);
    }

    $this->parent = PFParent::create(['name' => 'Parent']);
    $this->a = PFItem::create(['title' => 'A', 'parent_id' => $this->parent->id]);
    $this->b = PFItem::create(['title' => 'B', 'parent_id' => $this->parent->id]);
    $this->c = PFItem::create(['title' => 'Blocked', 'parent_id' => $this->parent->id]);
    foreach ([$this->a, $this->b, $this->c] as $item) {
        DB::table('pf_links')->insert(['parent_id' => $this->parent->id, 'item_id' => $item->id]);
    }
    $this->ids = [(int) $this->a->id, (int) $this->b->id, (int) $this->c->id];
});

afterEach(function () {
    Schema::disableForeignKeyConstraints();
    foreach (['pf_links', 'pf_items', 'pf_parents'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::enableForeignKeyConstraints();
    app(ResourceRegistry::class)->flush();
    PFLog::reset();
});

// ── PageContext ─────────────────────────────────────────────────────────────

it('keeps and reads facts per row by model, int or string key', function () {
    $context = new PageContext;
    $item = new PFItem;
    $item->id = 7;

    $context->put('flag', $item, true)
        ->put('flag', 8, false)
        ->put('note', 'abc', 'uuid-like')
        ->putMany('score', [1 => 10, '2' => 20]);

    expect($context->get('flag', 7))->toBeTrue()
        ->and($context->get('flag', '8'))->toBeFalse()
        ->and($context->get('note', 'abc'))->toBe('uuid-like')
        ->and($context->get('score', 2))->toBe(20)
        ->and($context->has('flag', $item))->toBeTrue()
        ->and($context->has('flag', 9))->toBeFalse()
        ->and($context->get('flag', 9, 'fallback'))->toBe('fallback')
        ->and($context->get('missing', 1))->toBeNull();
});

it('tells a stored null from no fact', function () {
    $context = (new PageContext)->put('maybe', 1, null);

    expect($context->has('maybe', 1))->toBeTrue()
        ->and($context->get('maybe', 1, 'default'))->toBeNull()
        ->and($context->has('maybe', 2))->toBeFalse();
});

it('is empty and not kept outside a listing request', function () {
    $request = Request::create('/anything');

    expect(PageContext::isOpen($request))->toBeFalse();

    PageContext::current($request)->put('lost', 1, true);

    expect(PageContext::current($request)->has('lost', 1))->toBeFalse()
        ->and(PageContext::current($request)->get('lost', 1, 'default'))->toBe('default');

    $opened = PageContext::begin($request);
    $opened->put('kept', 1, true);

    expect(PageContext::isOpen($request))->toBeTrue()
        ->and(PageContext::current($request))->toBe($opened)
        ->and(PageContext::current($request)->get('kept', 1))->toBeTrue()
        ->and(PageContext::begin($request))->not->toBe($opened);
});

// ── Where the hook runs ─────────────────────────────────────────────────────

it('prefetches once for the resource index, before the rows are serialized', function () {
    $rows = $this->getJson('/martis/api/resources/pf-items')->assertOk()->json('data');

    expect(PFLog::$prefetch)->toHaveCount(1)
        ->and(PFLog::$prefetch[0]['rows'])->toBe($this->ids)
        ->and(PFLog::$prefetch[0]['method'])->toBe('GET')
        ->and(PFLog::$prefetch[0]['open'])->toBeTrue();

    // The field resolver read the page context.
    expect(collect($rows)->pluck('score', 'id')->all())->toBe([
        $this->ids[0] => $this->ids[0] * 10,
        $this->ids[1] => $this->ids[1] * 10,
        $this->ids[2] => $this->ids[2] * 10,
    ]);
});

it('hands a canRun the facts of the page', function () {
    $rows = collect($this->getJson('/martis/api/resources/pf-items')->json('data'))->keyBy('id');

    expect($rows[$this->ids[0]]['_actionAuthorization']['pf-row'])->toBeTrue()
        ->and($rows[$this->ids[2]]['_actionAuthorization']['pf-row'])->toBeFalse();
});

it('prefetches once for a lens', function () {
    $this->getJson('/martis/api/resources/pf-lens-items/lenses/pf-lens')->assertOk();

    expect(PFLog::$prefetch)->toHaveCount(1)
        ->and(PFLog::$prefetch[0]['rows'])->toBe($this->ids)
        ->and(PFLog::$prefetch[0]['open'])->toBeTrue();
});

it('prefetches once for a has-many panel and for a belongs-to-many panel', function () {
    $this->getJson("/martis/api/resources/pf-parents/{$this->parent->id}/has-many/items")->assertOk();

    expect(PFLog::$prefetch)->toHaveCount(1)
        ->and(PFLog::$prefetch[0]['rows'])->toBe($this->ids);

    PFLog::$prefetch = [];
    $this->getJson("/martis/api/resources/pf-parents/{$this->parent->id}/belongs-to-many/linked")->assertOk();

    expect(PFLog::$prefetch)->toHaveCount(1)
        ->and(PFLog::$prefetch[0]['rows'])->toEqualCanonicalizing($this->ids);
});

it('skips the hook for an empty page', function () {
    PFItem::query()->delete();

    $this->getJson('/martis/api/resources/pf-items')->assertOk();

    expect(PFLog::$prefetch)->toBe([]);
});

it('does not prefetch when an action resolves its records', function () {
    $this->postJson('/martis/api/resources/pf-items/actions/pf-row', ['resources' => [$this->ids[0]]])->assertOk();

    expect(PFLog::$prefetch)->toBe([])
        ->and(PFLog::$runs)->toBe([[$this->ids[0]]]);
});

it('does not prefetch for the detail, the edit form or an action fields request', function () {
    $this->getJson("/martis/api/resources/pf-items/{$this->ids[0]}")->assertOk();
    $this->getJson("/martis/api/resources/pf-items/{$this->ids[0]}?context=update")->assertOk();
    $this->getJson("/martis/api/resources/pf-items/actions/pf-row/fields?resourceId={$this->ids[0]}")->assertOk();

    expect(PFLog::$prefetch)->toBe([]);
});

it('does not touch the default path: a resource that ignores the hook lists as before', function () {
    $rows = $this->getJson('/martis/api/resources/pf-plain-items')->assertOk()->json('data');

    expect($rows)->toHaveCount(3)
        ->and(PFLog::$prefetch)->toBe([]);
});

// ── canRunForRows() ─────────────────────────────────────────────────────────

it('asks a batched rule once per page, with every row', function () {
    PFLog::$denied = [$this->ids[1]];

    $rows = collect($this->getJson('/martis/api/resources/pf-items')->assertOk()->json('data'))->keyBy('id');

    expect(PFLog::$batchCalls)->toHaveCount(1)
        ->and(PFLog::$batchCalls[0])->toBe($this->ids);

    expect($rows[$this->ids[0]]['_actionAuthorization']['pf-batch'])->toBeTrue()
        ->and($rows[$this->ids[1]]['_actionAuthorization']['pf-batch'])->toBeFalse()
        ->and($rows[$this->ids[2]]['_actionAuthorization']['pf-batch'])->toBeTrue();
});

it('asks a batched rule once per page on a lens and a panel too', function () {
    $this->getJson('/martis/api/resources/pf-lens-items/lenses/pf-lens')->assertOk();
    $lens = PFLog::$batchCalls;

    PFLog::$batchCalls = [];
    $this->getJson("/martis/api/resources/pf-parents/{$this->parent->id}/has-many/items")->assertOk();

    // A lens maps every action; the panel only its inline actions: pf-batch is one of them.
    expect($lens)->toBe([$this->ids])
        ->and(PFLog::$batchCalls)->toBe([$this->ids]);
});

it('falls back to the per-row rule for a row the batch does not answer', function () {
    PFLog::$denied = [$this->ids[0]];
    PFItem::query()->whereKey($this->ids[1])->update(['title' => 'Per row denied']);

    $rows = collect($this->getJson('/martis/api/resources/pf-items')->assertOk()->json('data'))->keyBy('id');

    // The batch denies row 0 and says nothing of the rest: row 1 falls back to the per-row rule.
    expect($rows[$this->ids[0]]['_actionAuthorization']['pf-partial'])->toBeFalse()
        ->and($rows[$this->ids[1]]['_actionAuthorization']['pf-partial'])->toBeFalse()
        ->and($rows[$this->ids[2]]['_actionAuthorization']['pf-partial'])->toBeTrue()
        // The per-row rule ran for the two rows the batch left out, not for the denied one.
        ->and(PFLog::$perRowCalls)->toBe(2);
});

it('denies a row no rule answers, and throws, naming the action, for a batch that is not an array', function () {
    $rows = new Collection([$this->a, $this->b]);

    // No entry in the batch and no per-row rule: denied.
    expect((new PFOmitAction)->authorizedToRunRows(Request::create('/'), new Collection([$this->a, $this->c])))->toBe([
        (string) $this->a->id => true,
        (string) $this->c->id => false,
    ]);

    // No entry in the batch, a per-row rule that allows: allowed.
    $action = (new PFPartialAction)->canRun(fn (): bool => true);
    expect($action->authorizedToRunRows(Request::create('/'), $rows))->toBe([
        (string) $this->a->id => true,
        (string) $this->b->id => true,
    ]);

    // Int and string keys meet.
    $ints = (new PFBatchAction)->canRunForRows(fn (): array => [$this->a->id => false, (string) $this->b->id => true]);
    expect($ints->authorizedToRunRows(Request::create('/'), $rows))->toBe([
        (string) $this->a->id => false,
        (string) $this->b->id => true,
    ]);

    (new PFBrokenBatchAction)->authorizedToRunRows(Request::create('/'), $rows);
})->throws(UnexpectedValueException::class, 'PFBrokenBatchAction::canRunForRows() must return an array');

it('asks the batch for one record when a single check reaches it', function () {
    PFLog::$denied = [$this->ids[1]];
    $action = new PFBatchAction;

    expect($action->authorizedToRun(Request::create('/'), $this->a))->toBeTrue()
        ->and($action->authorizedToRun(Request::create('/'), $this->b))->toBeFalse()
        ->and(PFLog::$batchCalls)->toBe([[$this->ids[0]], [$this->ids[1]]]);
});

it('keeps the per-row path byte for byte when no batch is set', function () {
    $action = (new PFRowAction);
    $rows = new Collection([$this->a, $this->c]);

    expect($action->hasBatchedCanRun())->toBeFalse()
        ->and($action->authorizedToRunRows(Request::create('/'), $rows))->toBe([
            (string) $this->a->id => true,
            (string) $this->c->id => false,
        ])
        ->and($action->authorizedToRun(Request::create('/'), $this->c))->toBeFalse();
});

// ── The run refuses what the page hides ─────────────────────────────────────

it('refuses to run an action on a row its batched rule denies', function () {
    PFLog::$denied = [$this->ids[1]];

    // Listed as denied, so the run on that row is refused, and nothing ran.
    $this->postJson('/martis/api/resources/pf-items/actions/pf-batch', ['resources' => [$this->ids[1]]])
        ->assertNotFound();

    expect(PFLog::$runs)->toBe([]);
});

it('refuses a bulk run when one target is denied, asking the batch once for all of them', function () {
    PFLog::$denied = [$this->ids[2]];

    $this->postJson('/martis/api/resources/pf-items/actions/pf-batch', ['resources' => $this->ids])->assertNotFound();

    expect(PFLog::$runs)->toBe([])
        ->and(PFLog::$batchCalls)->toHaveCount(1)
        ->and(PFLog::$batchCalls[0])->toEqualCanonicalizing($this->ids);
});

it('runs an action on rows its batched rule allows', function () {
    PFLog::$denied = [$this->ids[2]];

    $this->postJson('/martis/api/resources/pf-items/actions/pf-batch', ['resources' => [$this->ids[0], $this->ids[1]]])
        ->assertOk();

    expect(PFLog::$runs)->toBe([[$this->ids[0], $this->ids[1]]]);
});

it('keeps the rule of a row the batch does not answer on the run too', function () {
    PFLog::$denied = [$this->ids[0]];
    PFItem::query()->whereKey($this->ids[1])->update(['title' => 'Per row denied']);

    // Denied by the batch.
    $this->postJson('/martis/api/resources/pf-items/actions/pf-partial', ['resources' => [$this->ids[0]]])->assertNotFound();
    // Not answered by the batch, denied by the per-row rule.
    $this->postJson('/martis/api/resources/pf-items/actions/pf-partial', ['resources' => [$this->ids[1]]])->assertNotFound();
    expect(PFLog::$runs)->toBe([]);

    // Not answered by the batch, allowed by the per-row rule.
    $this->postJson('/martis/api/resources/pf-items/actions/pf-partial', ['resources' => [$this->ids[2]]])->assertOk();
    expect(PFLog::$runs)->toBe([[$this->ids[2]]]);
});

it('hides a row the batch omits and refuses its run, on every path', function () {
    $rows = collect($this->getJson('/martis/api/resources/pf-items')->assertOk()->json('data'))->keyBy('id');

    expect($rows[$this->ids[0]]['_actionAuthorization']['pf-omit'])->toBeTrue()
        ->and($rows[$this->ids[2]]['_actionAuthorization']['pf-omit'])->toBeFalse();

    // "Blocked" is omitted by the batch: refused on the run, the fields request and a bulk run.
    $this->postJson('/martis/api/resources/pf-items/actions/pf-omit', ['resources' => [$this->ids[2]]])->assertNotFound();
    $this->postJson('/martis/api/resources/pf-items/actions/pf-omit', ['resources' => $this->ids])->assertNotFound();
    $this->getJson("/martis/api/resources/pf-items/actions/pf-omit/fields?resourceId={$this->ids[2]}")->assertNotFound();
    expect(PFLog::$runs)->toBe([]);

    $this->postJson('/martis/api/resources/pf-items/actions/pf-omit', ['resources' => [$this->ids[0]]])->assertOk();
    expect(PFLog::$runs)->toBe([[$this->ids[0]]]);
});

it('still validates a per-row canRun on the run when no batch is set', function () {
    // "Blocked" is denied by the domain rule: the page context is empty on a POST, so the fallback decides.
    $this->postJson('/martis/api/resources/pf-items/actions/pf-row', ['resources' => [$this->ids[2]]])->assertNotFound();
    expect(PFLog::$runs)->toBe([]);

    $this->postJson('/martis/api/resources/pf-items/actions/pf-row', ['resources' => [$this->ids[0]]])->assertOk();
    expect(PFLog::$runs)->toBe([[$this->ids[0]]]);
});

it('refuses the action fields request for a row the batch denies', function () {
    PFLog::$denied = [$this->ids[1]];

    $this->getJson("/martis/api/resources/pf-items/actions/pf-batch/fields?resourceId={$this->ids[1]}")->assertNotFound();
    $this->getJson("/martis/api/resources/pf-items/actions/pf-batch/fields?resourceId={$this->ids[0]}")->assertOk();
});

it('refuses a pivot run on a related record the batch denies', function () {
    PFLog::$denied = [$this->ids[1]];
    $route = fn (): string => route('martis.api.resources.belongs-to-many.actions.execute', [
        'resource' => 'pf-parents',
        'id' => $this->parent->id,
        'relationship' => 'linked',
        'action' => 'pf-pivot',
    ]);

    $this->postJson($route(), ['resources' => [$this->ids[1]], 'fields' => []])->assertNotFound();
    expect(PFLog::$runs)->toBe([]);

    $this->postJson($route(), ['resources' => [$this->ids[0]], 'fields' => []])->assertOk();
    expect(PFLog::$runs)->toBe([[$this->ids[0]]]);
});

// ── The contracts stay as they were (review fix) ────────────────────────────

it('keeps the new members off the contracts: they live on Action and Resource only', function () {
    foreach (['canRunForRows', 'hasBatchedCanRun', 'authorizedToRunRows'] as $method) {
        expect(method_exists(ActionContract::class, $method))->toBeFalse($method)
            ->and(method_exists(Action::class, $method))->toBeTrue($method);
    }

    expect(method_exists(ResourceContract::class, 'prefetchIndexPage'))->toBeFalse()
        ->and(method_exists(Resource::class, 'prefetchIndexPage'))->toBeTrue();
});

it('maps and refuses an action that only implements ActionContract, with the per-row rule', function () {
    $custom = Mockery::mock(ActionContract::class)->shouldIgnoreMissing();
    $custom->shouldReceive('uriKey')->andReturn('pf-custom');
    $custom->shouldReceive('authorizedToSee')->andReturnTrue();
    $custom->shouldReceive('isShownInline')->andReturnTrue();
    $custom->shouldReceive('isStandalone')->andReturnFalse();
    $custom->shouldReceive('isDestructive')->andReturnFalse();
    $custom->shouldReceive('authorizedToRun')->andReturnUsing(
        fn (Request $request, Model $model): bool => $model->getAttribute('title') !== 'Blocked',
    );

    PFCustomActionResource::$custom = $custom;
    app(ResourceRegistry::class)->register(PFCustomActionResource::class);

    $controller = new class(app(ResourceRegistry::class)) extends ResourceController
    {
        public function authorizer(Request $request, string $class, iterable $rows): ?Closure
        {
            return $this->rowActionAuthorizer($request, $class, false, null, $rows);
        }

        public function denied(Request $request, ActionContract $action, EloquentCollection $models, string $class): mixed
        {
            return $this->runDenied($request, $action, $models, $class);
        }

        public function prefetch(Request $request, string $class, iterable $rows): void
        {
            $this->prefetchListingPage($request, $class, $rows);
        }
    };

    $request = Request::create('/');
    $items = PFItem::query()->orderBy('id')->get();

    $map = $controller->authorizer($request, PFCustomActionResource::class, $items);
    expect($map($items[0]))->toBe(['pf-custom' => true])
        ->and($map($items[2]))->toBe(['pf-custom' => false]);

    expect($controller->denied($request, $custom, new EloquentCollection([$items[0]]), PFCustomActionResource::class))->toBeNull()
        ->and($controller->denied($request, $custom, new EloquentCollection([$items[2]]), PFCustomActionResource::class)?->getStatusCode())->toBe(404);

    // A resource class that is not a Resource (it only implements the contract) has no hook to call: skipped.
    $controller->prefetch($request, PFContractOnlyResource::class, $items);
    expect(true)->toBeTrue();
});

// ── Review fixes: a list answer, an overridden authorizedToRun() ────────────

it('throws, naming the action, when canRunForRows answers keys that match no row (a list)', function () {
    $rows = new Collection([$this->a, $this->b, $this->c]);

    (new PFListBatchAction)->authorizedToRunRows(Request::create('/'), $rows);
})->throws(UnexpectedValueException::class, 'PFListBatchAction::canRunForRows() answered for key [0]');

it('fails loudly on the page, not silently misaligned, for a list answer', function () {
    $registry = app(ResourceRegistry::class);
    $registry->register(PFListResource::class);
    $this->withoutExceptionHandling();

    $this->getJson('/martis/api/resources/pf-list-items');
})->throws(UnexpectedValueException::class, 'PFListBatchAction::canRunForRows() answered for key [0]');

it('still accepts a map that names only some of the rows, in any key type', function () {
    $rows = new Collection([$this->a, $this->b]);
    $action = (new PFBatchAction)->canRunForRows(fn (): array => [(string) $this->a->id => true]);

    expect($action->authorizedToRunRows(Request::create('/'), $rows))->toBe([
        (string) $this->a->id => true,
        (string) $this->b->id => false,
    ]);
});

it('denies when either the batched rule or an overridden authorizedToRun denies, on the page', function () {
    PFLog::$denied = [$this->ids[0]];
    PFOverrideAction::$overrideDenied = [$this->ids[1]];

    $rows = collect($this->getJson('/martis/api/resources/pf-items')->assertOk()->json('data'))->keyBy('id');

    // 0: batch denies. 1: override denies. 2: both allow.
    expect($rows[$this->ids[0]]['_actionAuthorization']['pf-override'])->toBeFalse()
        ->and($rows[$this->ids[1]]['_actionAuthorization']['pf-override'])->toBeFalse()
        ->and($rows[$this->ids[2]]['_actionAuthorization']['pf-override'])->toBeTrue();
});

it('denies when either denies, on the run, the fields request and a single check', function () {
    PFLog::$denied = [$this->ids[0]];
    PFOverrideAction::$overrideDenied = [$this->ids[1]];

    foreach ([0, 1] as $i) {
        $this->postJson('/martis/api/resources/pf-items/actions/pf-override', ['resources' => [$this->ids[$i]]])->assertNotFound();
        $this->getJson("/martis/api/resources/pf-items/actions/pf-override/fields?resourceId={$this->ids[$i]}")->assertNotFound();
    }
    $this->postJson('/martis/api/resources/pf-items/actions/pf-override', ['resources' => $this->ids])->assertNotFound();
    expect(PFLog::$runs)->toBe([]);

    $this->postJson('/martis/api/resources/pf-items/actions/pf-override', ['resources' => [$this->ids[2]]])->assertOk();
    expect(PFLog::$runs)->toBe([[$this->ids[2]]]);
});

it('decides one record on the detail page with both rules too', function () {
    PFLog::$denied = [$this->ids[0]];
    PFOverrideAction::$overrideDenied = [$this->ids[1]];

    $map = fn (int $id): array => $this->getJson("/martis/api/resources/pf-items/{$id}")->assertOk()->json('data._actionAuthorization');

    expect($map($this->ids[0])['pf-override'])->toBeFalse()
        ->and($map($this->ids[1])['pf-override'])->toBeFalse()
        ->and($map($this->ids[2])['pf-override'])->toBeTrue();
});

it('never allows more than the batch when an override allows everything', function () {
    PFLog::$denied = [$this->ids[2]];
    $action = new PFOverrideAction; // The override denies nothing here.

    expect($action->authorizedToRunRows(Request::create('/'), new Collection([$this->a, $this->c])))->toBe([
        (string) $this->a->id => true,
        (string) $this->c->id => false,
    ]);
});

it('does not recurse when the override defers to parent::authorizedToRun()', function () {
    PFLog::$denied = [$this->ids[0]];
    PFItem::query()->whereKey($this->ids[1])->update(['title' => 'Parent denied']);
    $this->b->refresh();

    $action = new PFOverrideParentAction;
    $rows = new Collection([$this->a, $this->b, $this->c]);

    expect($action->authorizedToRunRows(Request::create('/'), $rows))->toBe([
        (string) $this->a->id => false,
        (string) $this->b->id => false,
        (string) $this->c->id => true,
    ])->and($action->authorizedToRun(Request::create('/'), $this->c))->toBeTrue();
});

it('keeps asking the batch once for the page when no override exists', function () {
    (new PFBatchAction)->authorizedToRunRows(Request::create('/'), new Collection([$this->a, $this->b, $this->c]));

    expect(PFLog::$batchCalls)->toHaveCount(1);
});

// ── AssertsFlatQueries ──────────────────────────────────────────────────────

it('passes assertFlatQueries on a flat listing', function () {
    $counts = $this->assertFlatQueries(
        seed: function (int $rows): void {
            for ($i = 0; $i < $rows; $i++) {
                PFItem::create(['title' => "Row {$i}", 'parent_id' => $this->parent->id]);
            }
        },
        request: fn () => $this->getJson('/martis/api/resources/pf-plain-items?per_page=100')->assertOk(),
    );

    expect($counts['few'])->toBe($counts['many'])->and($counts['few'])->toBeGreaterThan(0);
});

it('fails assertFlatQueries on an N+1 listing, naming the repeated query', function () {
    $run = fn () => $this->assertFlatQueries(
        seed: function (int $rows): void {
            for ($i = 0; $i < $rows; $i++) {
                PFItem::create(['title' => "Row {$i}", 'parent_id' => $this->parent->id]);
            }
        },
        request: fn () => $this->getJson('/martis/api/resources/pf-n-plus-one-items?per_page=100')->assertOk(),
    );

    expect($run)->toThrow(function (ExpectationFailedException $e): void {
        expect($e->getMessage())
            ->toContain('Expected a flat number of queries')
            ->toContain('with 2 rows')
            ->toContain('with 20 rows')
            ->toContain('+18 x select "name" from "pf_parents"');
    });
});

it('flattens a listing whose hook eager loads what the rows read', function () {
    PFLog::$eagerLoadParent = true;

    $counts = $this->assertFlatQueries(
        seed: function (int $rows): void {
            for ($i = 0; $i < $rows; $i++) {
                PFItem::create(['title' => "Row {$i}", 'parent_id' => $this->parent->id]);
            }
        },
        request: fn () => $this->getJson('/martis/api/resources/pf-lazy-items?per_page=100')->assertOk(),
    );

    expect($counts['few'])->toBe($counts['many']);
});

it('fails assertFlatQueries on the same listing when the hook does not eager load', function () {
    $run = fn () => $this->assertFlatQueries(
        seed: function (int $rows): void {
            for ($i = 0; $i < $rows; $i++) {
                PFItem::create(['title' => "Row {$i}", 'parent_id' => $this->parent->id]);
            }
        },
        request: fn () => $this->getJson('/martis/api/resources/pf-lazy-items?per_page=100')->assertOk(),
    );

    expect($run)->toThrow(ExpectationFailedException::class);
});

// ── The lazy loading guard ──────────────────────────────────────────────────

it('leaves a listing alone while the guard is off', function () {
    $rows = $this->getJson('/martis/api/resources/pf-lazy-items')->assertOk()->json('data');

    expect(collect($rows)->pluck('parent_name')->unique()->all())->toBe(['Parent']);
});

it('throws on a lazy load while a listing is serialized when the guard is on', function () {
    config(['martis.dev.prevent_lazy_loading_in_listings' => true]);
    $this->withoutExceptionHandling();

    $this->getJson('/martis/api/resources/pf-lazy-items');
})->throws(LazyLoadingViolationException::class);

it('passes the guard when the hook eager loads what the rows read', function () {
    config(['martis.dev.prevent_lazy_loading_in_listings' => true]);
    PFLog::$eagerLoadParent = true;
    $this->withoutExceptionHandling();

    $rows = $this->getJson('/martis/api/resources/pf-lazy-items')->assertOk()->json('data');

    expect(collect($rows)->pluck('parent_name')->unique()->all())->toBe(['Parent']);
});

it('restores every flag and the global state after the guarded serialization, even when it throws', function () {
    config(['martis.dev.prevent_lazy_loading_in_listings' => true]);
    $items = PFItem::query()->get();
    $handlerBefore = (new ReflectionProperty(Model::class, 'lazyLoadingViolationCallback'))->getValue();

    expect(fn () => ListingGuard::run($items, fn () => $items->first()->parent))->toThrow(LazyLoadingViolationException::class);

    expect($items->first()->preventsLazyLoading)->toBeFalse()
        ->and(Model::preventsLazyLoading())->toBeFalse()
        ->and((new ReflectionProperty(Model::class, 'lazyLoadingViolationCallback'))->getValue())->toBe($handlerBefore);

    // Unguarded again: the lazy load works.
    expect($items->first()->parent->name)->toBe('Parent');
});

it('logs a warning instead of throwing outside local and testing, then restores the app handler', function () {
    config(['martis.dev.prevent_lazy_loading_in_listings' => true]);
    app()['env'] = 'production';

    $appHandler = static function (): void {};
    Model::handleLazyLoadingViolationUsing($appHandler);
    Log::shouldReceive('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'parent') && str_contains($message, PFItem::class));

    $items = PFItem::query()->get();
    $name = ListingGuard::run($items, fn () => $items->first()->parent->name);

    expect($name)->toBe('Parent')
        ->and((new ReflectionProperty(Model::class, 'lazyLoadingViolationCallback'))->getValue())->toBe($appHandler)
        ->and($items->first()->preventsLazyLoading)->toBeFalse();

    Model::handleLazyLoadingViolationUsing(null);
    app()['env'] = 'testing';
});

it('does nothing for a listing it does not guard', function () {
    config(['martis.dev.prevent_lazy_loading_in_listings' => false]);
    $items = PFItem::query()->get();

    expect(ListingGuard::run($items, fn () => $items->first()->parent->name))->toBe('Parent');
});
