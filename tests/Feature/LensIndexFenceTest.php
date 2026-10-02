<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Http\Requests\LensRequest;
use Martis\Lenses\Lens;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * A lens lists the records the resource's index lists: the resource's
 * `scopes()` and `indexQuery()` (the tenant / ownership fence) run on the
 * lens's base query before `Lens::query()`, so a lens that does not repeat
 * the constraint never lists another tenant's rows, field values or summary
 * aggregates, and the lens actions never run on them. A lens that
 * deliberately aggregates across the fence opts out with
 * `public static bool $withoutIndexScope = true`.
 */

// ── Fixtures ────────────────────────────────────────────────────────────────

class LensFenceItem extends Model
{
    use SoftDeletes;

    protected $table = 'lens_fence_items';

    protected $fillable = ['name', 'tenant_id', 'hidden', 'amount'];
}

class LensFenceRename extends Action
{
    /** @param Collection<int, Model> $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        foreach ($models as $model) {
            $model->forceFill(['name' => 'renamed'])->save();
        }

        return ActionResponse::message('Done.');
    }
}

/** Lists what the resource's index lists: no constraint of its own. */
class LensFenceDefaultLens extends Lens
{
    public function query(LensRequest $request, Builder $query): Builder
    {
        return $request->withOrdering($query, fn (Builder $q) => $q->orderBy('id'));
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }

    public function summary(Request $request, Builder $query): array
    {
        return [
            'count' => ['label' => 'Count', 'value' => $query->count()],
            'amount' => ['label' => 'Amount', 'value' => (int) $query->sum('amount')],
        ];
    }

    public function actions(Request $request): array
    {
        return [LensFenceRename::make()];
    }
}

/** Widens its own query with an `orWhere()`, which must not OR the fence away. */
class LensFenceOrLens extends LensFenceDefaultLens
{
    public function query(LensRequest $request, Builder $query): Builder
    {
        return $query->where('name', 'Mine')->orWhere('name', 'Theirs')->orderBy('id');
    }
}

/** Aggregates across the fence on purpose. */
class LensFenceAcrossLens extends LensFenceDefaultLens
{
    public static bool $withoutIndexScope = true;
}

class LensFenceItemResource extends Resource
{
    public static function model(): string
    {
        return LensFenceItem::class;
    }

    public static function uriKey(): string
    {
        return 'lens-fence-items';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public static function softDeletes(): bool
    {
        return true;
    }

    public static function scopes(Request $request): array
    {
        return ['tenant' => fn (Builder $query): Builder => $query->where('tenant_id', 1)];
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query->where('hidden', false);
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }

    public function actions(Request $request): array
    {
        return [LensFenceRename::make()];
    }

    public function lenses(Request $request): array
    {
        return [new LensFenceDefaultLens, new LensFenceOrLens, new LensFenceAcrossLens];
    }
}

const LENS_FENCE_BASE = '/martis/api/resources/lens-fence-items';

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('lens_fence_items');
    Schema::create('lens_fence_items', function ($table) {
        $table->id();
        $table->string('name');
        $table->unsignedInteger('tenant_id')->default(1);
        $table->boolean('hidden')->default(false);
        $table->integer('amount')->default(0);
        $table->softDeletes();
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(LensFenceItemResource::class);

    // Inside the fence (tenant 1, not hidden), outside it by tenant, outside
    // it by indexQuery().
    $this->mine = LensFenceItem::create(['name' => 'Mine', 'tenant_id' => 1, 'amount' => 10]);
    $this->theirs = LensFenceItem::create(['name' => 'Theirs', 'tenant_id' => 2, 'amount' => 1000]);
    $this->hiddenOne = LensFenceItem::create(['name' => 'Hidden', 'tenant_id' => 1, 'hidden' => true, 'amount' => 5000]);

    Cache::flush();
});

afterEach(function () {
    Schema::dropIfExists('lens_fence_items');
});

// ── Listing ─────────────────────────────────────────────────────────────────

it('lists on a lens only the records the resource index lists', function () {
    $response = $this->getJson(LENS_FENCE_BASE.'/lenses/lens-fence-default')->assertOk();

    expect(collect($response->json('data'))->pluck('name')->all())->toBe(['Mine'])
        ->and($response->json('meta.total'))->toBe(1);
});

it('lists on the resource index exactly what the default lens lists', function () {
    $index = $this->getJson(LENS_FENCE_BASE)->assertOk();

    expect(collect($index->json('data'))->pluck('name')->all())->toBe(['Mine']);
});

it('aggregates the lens summary over the fenced records only', function () {
    $response = $this->getJson(LENS_FENCE_BASE.'/lenses/lens-fence-default')->assertOk();

    expect($response->json('meta.summary.count.value'))->toBe(1)
        ->and($response->json('meta.summary.amount.value'))->toBe(10);
});

it('keeps the fence on a lens whose own query ORs a constraint', function () {
    // `where(name = Mine) or where(name = Theirs)` would list the other tenant's
    // record if the fence were ANDed beside it instead of around it.
    $response = $this->getJson(LENS_FENCE_BASE.'/lenses/lens-fence-or')->assertOk();

    expect(collect($response->json('data'))->pluck('name')->all())->toBe(['Mine'])
        ->and($response->json('meta.total'))->toBe(1)
        ->and($response->json('meta.summary.count.value'))->toBe(1);
});

it('fences the trashed records a lens lists as the index fences them', function () {
    $trashedMine = LensFenceItem::create(['name' => 'Trashed mine', 'tenant_id' => 1, 'amount' => 1]);
    $trashedTheirs = LensFenceItem::create(['name' => 'Trashed theirs', 'tenant_id' => 2, 'amount' => 1]);
    $trashedMine->delete();
    $trashedTheirs->delete();

    $with = $this->getJson(LENS_FENCE_BASE.'/lenses/lens-fence-default?trashed=with')->assertOk();
    $only = $this->getJson(LENS_FENCE_BASE.'/lenses/lens-fence-default?trashed=only')->assertOk();

    expect(collect($with->json('data'))->pluck('name')->all())->toBe(['Mine', 'Trashed mine'])
        ->and(collect($only->json('data'))->pluck('name')->all())->toBe(['Trashed mine']);
});

it('lets a lens that opts out aggregate across the fence', function () {
    $response = $this->getJson(LENS_FENCE_BASE.'/lenses/lens-fence-across')->assertOk();

    expect(collect($response->json('data'))->pluck('name')->all())->toBe(['Mine', 'Theirs', 'Hidden'])
        ->and($response->json('meta.total'))->toBe(3)
        ->and($response->json('meta.summary.amount.value'))->toBe(6010);
});

// ── Actions ─────────────────────────────────────────────────────────────────

it('runs a lens action only on the records the fence lets through', function () {
    $this->postJson(LENS_FENCE_BASE.'/lenses/lens-fence-default/actions/lens-fence-rename', [
        'resources' => [$this->theirs->id],
    ])->assertNotFound();
    $this->postJson(LENS_FENCE_BASE.'/lenses/lens-fence-default/actions/lens-fence-rename', [
        'resources' => [$this->hiddenOne->id],
    ])->assertNotFound();

    expect($this->theirs->fresh()->name)->toBe('Theirs')
        ->and($this->hiddenOne->fresh()->name)->toBe('Hidden');

    // Named with one inside the fence, the others are left out of the run.
    $this->postJson(LENS_FENCE_BASE.'/lenses/lens-fence-default/actions/lens-fence-rename', [
        'resources' => [$this->mine->id, $this->theirs->id],
    ])->assertOk();

    expect($this->mine->fresh()->name)->toBe('renamed')
        ->and($this->theirs->fresh()->name)->toBe('Theirs');
});

it('keeps the fence on the records a lens action runs on when the lens query ORs a constraint', function () {
    $this->postJson(LENS_FENCE_BASE.'/lenses/lens-fence-or/actions/lens-fence-rename', [
        'resources' => [$this->theirs->id],
    ])->assertNotFound();

    expect($this->theirs->fresh()->name)->toBe('Theirs');
});

it('lets a lens that opts out run its actions across the fence', function () {
    $this->postJson(LENS_FENCE_BASE.'/lenses/lens-fence-across/actions/lens-fence-rename', [
        'resources' => [$this->theirs->id],
    ])->assertOk();

    expect($this->theirs->fresh()->name)->toBe('renamed');
});
