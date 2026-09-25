<?php

declare(strict_types=1);

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Enums\FilterType;
use Martis\Fields\BelongsTo;
use Martis\Fields\Text;
use Martis\Filters\Filter;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Http\Requests\LensRequest;
use Martis\Lenses\Lens;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * An action a lens declares in its own `actions()` runs from the lens, as in
 * Nova, where a lens's actions are resolved from the lens
 * (`LensActionController`, `LensActionRequest`). The lens page runs them,
 * reads their fields and their relatable options through
 * `/resources/{resource}/lenses/{lens}/actions/...`; the resource's routes
 * keep running the resource's actions. Before
 * v2.0.1 the endpoints looked at the resource's `actions()` only, so an
 * action the lens alone declares answered 404.
 */

// ── Fixtures ────────────────────────────────────────────────────────────────

class LensActionItem extends Model
{
    protected $table = 'lens_action_items';

    protected $fillable = ['name', 'tenant_id', 'locked'];
}

/** Renames the records it runs on to its own label. */
abstract class LensActionRename extends Action
{
    protected string $to = '';

    /** @var list<array<string, mixed>> The attributes of each record it ran on, as it received them. */
    public static array $received = [];

    /** @param Collection<int, Model> $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        foreach ($models as $model) {
            self::$received[] = $model->getAttributes();
            $model->forceFill(['name' => $this->to])->save();
        }

        return ActionResponse::message('Done.');
    }
}

class LensActionResourceOnly extends LensActionRename
{
    protected string $to = 'by resource';
}

class LensActionLensOnly extends LensActionRename
{
    protected string $to = 'by lens';

    public function fields(Request $request): array
    {
        return [
            Text::make('note')->nullable(),
            BelongsTo::make('owner', 'Owner')->relatedResource('lens-action-items')->titleAttribute('name')->nullable(),
        ];
    }
}

class LensActionHidden extends LensActionRename
{
    protected string $to = 'hidden ran';
}

class LensActionStandalone extends Action
{
    public static bool $ran = false;

    /** @param Collection<int, Model> $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        self::$ran = true;

        return ActionResponse::message('Standalone done.');
    }
}

/** `name = value`. */
class LensActionNameFilter extends Filter
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

/** A lens that lists aggregates (one row per record, only its key and a count). */
class LensActionAggregateLens extends Lens
{
    public function query(LensRequest $request, Builder $query): Builder
    {
        return $query->select('lens_action_items.id')->selectRaw('count(*) as weight')->groupBy('lens_action_items.id');
    }

    public function actions(Request $request): array
    {
        return [LensActionLensOnly::make()];
    }
}

/** A lens that paginates itself: its actions have no query to read records from. */
class LensActionPaginatingLens extends Lens
{
    public function query(LensRequest $request, Builder $query): Paginator
    {
        return $query->paginate();
    }

    public function actions(Request $request): array
    {
        return [LensActionLensOnly::make()];
    }
}

/** A lens with its own actions: they replace the resource's. */
class LensActionOwnLens extends Lens
{
    public function query(LensRequest $request, Builder $query): Builder
    {
        return $request->withOrdering($request->withFilters($query->where('name', '!=', 'Unlisted')));
    }

    public function filters(Request $request): array
    {
        return [LensActionNameFilter::make('Name')];
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }

    public function actions(Request $request): array
    {
        return [
            LensActionLensOnly::make()->canRun(fn (Request $request, Model $model): bool => ! $model->getAttribute('locked')),
            LensActionHidden::make()->canSee(fn (): bool => false),
            LensActionStandalone::make()->standalone(),
        ];
    }
}

/** A lens without `actions()`: it inherits the resource's. */
class LensActionInheritingLens extends Lens
{
    public function query(LensRequest $request, Builder $query): Builder
    {
        return $query;
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

/** A lens the user cannot see, with an action of its own. */
class LensActionForbiddenLens extends LensActionOwnLens {}

class LensActionItemResource extends Resource
{
    public static function model(): string
    {
        return LensActionItem::class;
    }

    public static function uriKey(): string
    {
        return 'lens-action-items';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public static function scopes(Request $request): array
    {
        return ['tenant' => fn (Builder $query): Builder => $query->where('tenant_id', 1)];
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }

    public function actions(Request $request): array
    {
        return [LensActionResourceOnly::make()];
    }

    public function lenses(Request $request): array
    {
        return [
            new LensActionOwnLens,
            new LensActionInheritingLens,
            new LensActionAggregateLens,
            new LensActionPaginatingLens,
            (new LensActionForbiddenLens)->canSee(fn (): bool => false),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);
    LensActionStandalone::$ran = false;
    LensActionRename::$received = [];

    Schema::create('lens_action_items', function ($table) {
        $table->id();
        $table->string('name');
        $table->unsignedInteger('tenant_id')->default(1);
        $table->boolean('locked')->default(false);
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(LensActionItemResource::class);
});

const LENS_ACTION_BASE = '/martis/api/resources/lens-action-items';

// ── Running ─────────────────────────────────────────────────────────────────

it('runs an action only the lens declares from the lens action route', function () {
    $item = LensActionItem::create(['name' => 'Ada']);

    $this->postJson(LENS_ACTION_BASE.'/lenses/lens-action-own/actions/lens-action-lens-only', [
        'resources' => [$item->id],
    ])->assertOk();

    expect($item->fresh()->name)->toBe('by lens');
});

it('runs a standalone action only the lens declares', function () {
    $this->postJson(LENS_ACTION_BASE.'/lenses/lens-action-own/actions/lens-action-standalone', [])
        ->assertOk();

    expect(LensActionStandalone::$ran)->toBeTrue();
});

it('keeps the resource action routes to the resource actions', function () {
    $item = LensActionItem::create(['name' => 'Ada']);

    $this->postJson(LENS_ACTION_BASE.'/actions/lens-action-lens-only', ['resources' => [$item->id]])->assertNotFound();
    $this->postJson(LENS_ACTION_BASE.'/actions/lens-action-resource-only', ['resources' => [$item->id]])->assertOk();

    expect($item->fresh()->name)->toBe('by resource');
});

it('runs from a lens the actions it lists, not the resource actions it replaced', function () {
    $item = LensActionItem::create(['name' => 'Ada']);

    $this->postJson(LENS_ACTION_BASE.'/lenses/lens-action-own/actions/lens-action-resource-only', [
        'resources' => [$item->id],
    ])->assertNotFound();

    expect($item->fresh()->name)->toBe('Ada');
});

it('runs the resource actions from a lens that inherits them', function () {
    $item = LensActionItem::create(['name' => 'Ada']);

    $this->postJson(LENS_ACTION_BASE.'/lenses/lens-action-inheriting/actions/lens-action-resource-only', [
        'resources' => [$item->id],
    ])->assertOk();

    expect($item->fresh()->name)->toBe('by resource');
});

it('refuses a lens action the user cannot see, or cannot run on the record', function () {
    $item = LensActionItem::create(['name' => 'Ada']);
    $locked = LensActionItem::create(['name' => 'Locked', 'locked' => true]);

    $this->postJson(LENS_ACTION_BASE.'/lenses/lens-action-own/actions/lens-action-hidden', [
        'resources' => [$item->id],
    ])->assertForbidden();
    $this->postJson(LENS_ACTION_BASE.'/lenses/lens-action-own/actions/lens-action-lens-only', [
        'resources' => [$locked->id],
    ])->assertNotFound();

    expect($item->fresh()->name)->toBe('Ada')
        ->and($locked->fresh()->name)->toBe('Locked');
});

it('refuses the actions of a lens the user cannot see, or that does not exist', function () {
    $item = LensActionItem::create(['name' => 'Ada']);

    $this->postJson(LENS_ACTION_BASE.'/lenses/lens-action-forbidden/actions/lens-action-lens-only', [
        'resources' => [$item->id],
    ])->assertForbidden();
    $this->postJson(LENS_ACTION_BASE.'/lenses/no-such-lens/actions/lens-action-lens-only', [
        'resources' => [$item->id],
    ])->assertNotFound();
    $this->getJson(LENS_ACTION_BASE.'/lenses/lens-action-forbidden/actions/lens-action-lens-only/fields')->assertForbidden();

    expect($item->fresh()->name)->toBe('Ada');
});

// ── The records a lens action runs on ───────────────────────────────────────
//
// As in Nova (`LensActionRequest`), the selected records are the ones the
// lens's query lists, not the resource index's: the resource's scopes() do
// not apply, and a record the lens does not list does not resolve.

it('runs a lens action on a record the lens lists, whatever the resource scopes() hide', function () {
    $theirs = LensActionItem::create(['name' => 'Theirs', 'tenant_id' => 2]);

    $this->postJson(LENS_ACTION_BASE.'/lenses/lens-action-own/actions/lens-action-lens-only', [
        'resources' => [$theirs->id],
    ])->assertOk();

    expect($theirs->fresh()->name)->toBe('by lens');
});

it('answers 404 for a record the lens does not list', function () {
    $unlisted = LensActionItem::create(['name' => 'Unlisted']);
    $listed = LensActionItem::create(['name' => 'Listed']);

    $this->postJson(LENS_ACTION_BASE.'/lenses/lens-action-own/actions/lens-action-lens-only', [
        'resources' => [$unlisted->id],
    ])->assertNotFound();
    // Named with a listed one, it is left out of the run.
    $this->postJson(LENS_ACTION_BASE.'/lenses/lens-action-own/actions/lens-action-lens-only', [
        'resources' => [$unlisted->id, $listed->id],
    ])->assertOk();

    expect($unlisted->fresh()->name)->toBe('Unlisted')
        ->and($listed->fresh()->name)->toBe('by lens');
});

it('reads the records through the lens filters the request carries', function () {
    $ada = LensActionItem::create(['name' => 'Ada']);
    $filters = urlencode(json_encode(['name' => 'Bob']));

    $this->postJson(LENS_ACTION_BASE."/lenses/lens-action-own/actions/lens-action-lens-only?filters={$filters}", [
        'resources' => [$ada->id],
    ])->assertNotFound();

    expect($ada->fresh()->name)->toBe('Ada');
});

it('hands the action whole records from a lens that lists aggregates', function () {
    $item = LensActionItem::create(['name' => 'Ada', 'tenant_id' => 7]);

    $this->postJson(LENS_ACTION_BASE.'/lenses/lens-action-aggregate/actions/lens-action-lens-only', [
        'resources' => [$item->id],
    ])->assertOk();

    // The action read the record's own columns, not the lens's aggregate row.
    expect(LensActionRename::$received)->toHaveCount(1)
        ->and(LensActionRename::$received[0])->toMatchArray(['name' => 'Ada', 'tenant_id' => 7])
        ->and(LensActionRename::$received[0])->not->toHaveKey('weight')
        ->and($item->fresh()->name)->toBe('by lens');
});

it('fails loudly when the lens returns a paginator instead of a query', function () {
    $item = LensActionItem::create(['name' => 'Ada']);

    $this->withoutExceptionHandling();

    expect(fn () => $this->postJson(LENS_ACTION_BASE.'/lenses/lens-action-paginating/actions/lens-action-lens-only', [
        'resources' => [$item->id],
    ]))->toThrow(LogicException::class, 'LensActionPaginatingLens::query() must return an Eloquent query');

    expect($item->fresh()->name)->toBe('Ada');
});

// ── Fields, relatable options, listing ──────────────────────────────────────

it('serves the fields and the relatable options of an action only the lens declares', function () {
    LensActionItem::create(['name' => 'Ada']);

    $this->getJson(LENS_ACTION_BASE.'/lenses/lens-action-own/actions/lens-action-lens-only/fields')
        ->assertOk()
        ->assertJsonPath('data.fields.0.attribute', 'note');
    $this->getJson(LENS_ACTION_BASE.'/lenses/lens-action-own/actions/lens-action-lens-only/relatable/owner_id')
        ->assertOk();

    $this->getJson(LENS_ACTION_BASE.'/actions/lens-action-lens-only/fields')->assertNotFound();
    $this->getJson(LENS_ACTION_BASE.'/actions/lens-action-lens-only/relatable/owner_id')->assertNotFound();
});

it('lists the actions a lens runs on its actions endpoint', function () {
    $keys = fn (string $path): array => collect($this->getJson(LENS_ACTION_BASE.$path)->assertOk()->json('data.actions'))
        ->pluck('uriKey')->all();

    expect($keys('/lenses/lens-action-own/actions'))->toBe(['lens-action-lens-only', 'lens-action-standalone'])
        ->and($keys('/lenses/lens-action-inheriting/actions'))->toBe(['lens-action-resource-only'])
        ->and($keys('/actions'))->toBe(['lens-action-resource-only']);
    $this->getJson(LENS_ACTION_BASE.'/lenses/lens-action-forbidden/actions')->assertForbidden();
});

it('marks on each lens row whether each lens action may run on it', function () {
    $item = LensActionItem::create(['name' => 'Ada']);
    $locked = LensActionItem::create(['name' => 'Locked', 'locked' => true]);

    $rows = collect($this->getJson(LENS_ACTION_BASE.'/lenses/lens-action-own')->assertOk()->json('data'))->keyBy('id');

    expect($rows[$item->id]['_actionAuthorization'])->toMatchArray(['lens-action-lens-only' => true])
        ->and($rows[$locked->id]['_actionAuthorization'])->toMatchArray(['lens-action-lens-only' => false])
        ->and($rows[$item->id]['_actionAuthorization'])->not->toHaveKey('lens-action-hidden');
});
