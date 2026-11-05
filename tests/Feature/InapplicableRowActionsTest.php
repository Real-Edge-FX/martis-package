<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Enums\InapplicableAction;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * v2.17.0: whenInapplicable() and inRowMenu() reach the schema, a resource's
 * $maxInlineButtons reaches it too, and the detail payload carries the same
 * per-action run verdict (`_actionAuthorization`) as an index row, so the
 * detail page can grey out or leave out what the run would refuse.
 */

class IRAModel extends Model
{
    protected $table = 'ira_items';

    protected $fillable = ['title', 'live'];

    protected $casts = ['live' => 'boolean'];
}

class IRASwitchOn extends Action
{
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        return null;
    }

    public function uriKey(): string
    {
        return 'ira-switch-on';
    }
}

class IRASwitchOff extends IRASwitchOn
{
    public function uriKey(): string
    {
        return 'ira-switch-off';
    }
}

class IRAResource extends Resource
{
    protected static ?int $maxInlineButtons = 2;

    public static function model(): string
    {
        return IRAModel::class;
    }

    public static function uriKey(): string
    {
        return 'ira-items';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')];
    }

    public function actions(Request $request): array
    {
        return [
            (new IRASwitchOn)->showInline()->whenInapplicable(InapplicableAction::Hide)
                ->canRun(fn ($request, $model) => ! $model->live),
            (new IRASwitchOff)->showInline()->inRowMenu()->whenInapplicable(InapplicableAction::KeepSlot)
                ->canRun(fn ($request, $model) => (bool) $model->live),
        ];
    }
}

class IRANegativeResource extends IRAResource
{
    protected static ?int $maxInlineButtons = -1;
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('ira_items');
    Schema::create('ira_items', function ($table) {
        $table->id();
        $table->string('title');
        $table->boolean('live')->default(false);
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(IRAResource::class);
});

afterEach(fn () => Schema::dropIfExists('ira_items'));

it('serializes whenInapplicable(), inRowMenu() and the default of each', function () {
    $actions = collect($this->getJson('/martis/api/resources/ira-items/schema')->assertOk()->json('data.actions'))->keyBy('uriKey');

    expect($actions['ira-switch-on']['whenInapplicable'])->toBe('hide')
        ->and($actions['ira-switch-on']['inRowMenu'])->toBeFalse()
        ->and($actions['ira-switch-off']['whenInapplicable'])->toBe('keep_slot')
        ->and($actions['ira-switch-off']['inRowMenu'])->toBeTrue()
        ->and((new IRASwitchOn)->jsonSerialize()['whenInapplicable'])->toBe('disabled');
});

it('exposes the resource maxInlineButtons in the schema, null by default', function () {
    expect($this->getJson('/martis/api/resources/ira-items/schema')->json('data.maxInlineButtons'))->toBe(2)
        ->and(Resource::maxInlineButtons())->toBeNull();
});

it('throws for a negative maxInlineButtons, naming the resource', function () {
    IRANegativeResource::maxInlineButtons();
})->throws(InvalidArgumentException::class, 'IRANegativeResource::$maxInlineButtons');

it('carries the per-action run verdict on the detail payload, as an index row does', function () {
    $off = IRAModel::create(['title' => 'Off item', 'live' => false]);
    $live = IRAModel::create(['title' => 'Live item', 'live' => true]);

    expect($this->getJson("/martis/api/resources/ira-items/{$off->id}")->assertOk()->json('data._actionAuthorization'))
        ->toBe(['ira-switch-on' => true, 'ira-switch-off' => false])
        ->and($this->getJson("/martis/api/resources/ira-items/{$live->id}")->json('data._actionAuthorization'))
        ->toBe(['ira-switch-on' => false, 'ira-switch-off' => true]);

    // The index rows hold the same verdicts.
    $rows = collect($this->getJson('/martis/api/resources/ira-items')->assertOk()->json('data'))->keyBy('id');
    expect($rows[$off->id]['_actionAuthorization'])->toBe(['ira-switch-on' => true, 'ira-switch-off' => false]);
});

it('does not add the verdict to the edit form payload', function () {
    $item = IRAModel::create(['title' => 'Off item']);

    expect($this->getJson("/martis/api/resources/ira-items/{$item->id}?context=update")->assertOk()->json('data'))
        ->not->toHaveKey('_actionAuthorization');
});
