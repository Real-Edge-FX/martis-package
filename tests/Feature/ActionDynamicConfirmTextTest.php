<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * v2.17.0: confirmText(), confirmButtonText() and cancelButtonText() take a
 * closure, resolved by the fields request of each opening of the modal with
 * the record it runs on (or null and the selection count for a bulk run).
 */

class DynConfirmItem extends Model
{
    protected $table = 'dyn_confirm_items';

    protected $fillable = ['title', 'hidden'];
}

class DynConfirmLog
{
    public static int $calls = 0;
}

class DynConfirmDelete extends Action
{
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        return null;
    }

    public function uriKey(): string
    {
        return 'dyn-delete';
    }
}

class DynConfirmBroken extends DynConfirmDelete
{
    public function uriKey(): string
    {
        return 'dyn-broken';
    }
}

class DynConfirmStatic extends DynConfirmDelete
{
    public function uriKey(): string
    {
        return 'dyn-static';
    }
}

class DynConfirmResource extends Resource
{
    public static function model(): string
    {
        return DynConfirmItem::class;
    }

    public static function uriKey(): string
    {
        return 'dyn-confirm-items';
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query->where('hidden', false);
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')];
    }

    public function actions(Request $request): array
    {
        return [
            (new DynConfirmDelete)
                ->confirmText(function (Request $request, ?Model $model, int $count): string {
                    DynConfirmLog::$calls++;

                    return $model !== null ? "Delete «{$model->getAttribute('title')}» for good?" : "Delete {$count} items for good?";
                })
                ->confirmButtonText(fn (Request $request, ?Model $model) => $model !== null ? "Delete «{$model->getAttribute('title')}»" : null),
            (new DynConfirmBroken)->confirmText(fn () => 42),
            (new DynConfirmStatic)->confirmText('Sure?'),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);
    DynConfirmLog::$calls = 0;

    Schema::dropIfExists('dyn_confirm_items');
    Schema::create('dyn_confirm_items', function ($table) {
        $table->id();
        $table->string('title');
        $table->boolean('hidden')->default(false);
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(DynConfirmResource::class);
});

afterEach(fn () => Schema::dropIfExists('dyn_confirm_items'));

const DYN_URL = '/martis/api/resources/dyn-confirm-items/actions';

it('serializes a closure text as null with dynamicConfirm, and a string text as it is', function () {
    $actions = collect($this->getJson('/martis/api/resources/dyn-confirm-items/schema')->assertOk()->json('data.actions'))->keyBy('uriKey');

    expect($actions['dyn-delete']['confirmText'])->toBeNull()
        ->and($actions['dyn-delete']['dynamicConfirm'])->toBeTrue()
        ->and($actions['dyn-static']['confirmText'])->toBe('Sure?')
        ->and($actions['dyn-static']['dynamicConfirm'])->toBeFalse()
        ->and(DynConfirmLog::$calls)->toBe(0);
});

it('resolves the texts with the record a one-record modal runs on', function () {
    $item = DynConfirmItem::create(['title' => 'Spring guide']);

    $data = $this->getJson(DYN_URL."/dyn-delete/fields?resourceId={$item->id}")->assertOk()->json('data');

    expect($data['confirmText'])->toBe('Delete «Spring guide» for good?')
        ->and($data['confirmButtonText'])->toBe('Delete «Spring guide»')
        ->and($data['cancelButtonText'])->toBeNull()
        ->and($data)->toHaveKey('fields');
});

it('resolves a bulk text with no record and the selection count, clamping a bad count to 0', function () {
    expect($this->getJson(DYN_URL.'/dyn-delete/fields?count=3')->assertOk()->json('data'))
        ->toMatchArray(['confirmText' => 'Delete 3 items for good?', 'confirmButtonText' => null]);

    foreach (['-2', 'abc', '1e3'] as $bad) {
        expect($this->getJson(DYN_URL."/dyn-delete/fields?count={$bad}")->json('data.confirmText'))->toBe('Delete 0 items for good?');
    }
});

it('never runs the closure for a record outside the index scope', function () {
    $hidden = DynConfirmItem::create(['title' => 'Secret', 'hidden' => true]);

    $this->getJson(DYN_URL."/dyn-delete/fields?resourceId={$hidden->id}")->assertNotFound();

    expect(DynConfirmLog::$calls)->toBe(0);
});

it('returns a string text as it is from the fields request', function () {
    expect($this->getJson(DYN_URL.'/dyn-static/fields')->assertOk()->json('data.confirmText'))->toBe('Sure?');
});

it('throws when a closure returns something other than a string or null, naming the action and the option', function () {
    $this->withoutExceptionHandling();

    $this->getJson(DYN_URL.'/dyn-broken/fields');
})->throws(InvalidArgumentException::class, 'The confirmText() closure of DynConfirmBroken must return a string or null, int returned.');
