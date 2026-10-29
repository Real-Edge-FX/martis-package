<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Models\ActionEvent;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * A "headless" action is one a custom component runs through the run
 * endpoint: `exceptOnIndex()->exceptOnDetail()->exceptInline()` hides it from
 * every menu and from `GET .../actions?context=...`, and the command palette
 * does not offer it, while the run keeps every gate (viewAny, canSee, canRun,
 * the runAction policy, the action events). As in Nova, where the run reads no
 * show flag. There is no dedicated flag: the three `except*` calls are the
 * contract.
 */

class HeadlessItem extends Model
{
    protected $table = 'headless_items';

    protected $fillable = ['name'];
}

class HeadlessLog
{
    /** @var list<int> */
    public static array $ran = [];
}

class HeadlessAction extends Action
{
    public ?string $name = 'Sync from component';

    /** @param Collection<int, Model> $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        foreach ($models as $model) {
            HeadlessLog::$ran[] = (int) $model->getKey();
            $model->forceFill(['name' => 'synced'])->save();
        }

        return ActionResponse::message('Synced.');
    }
}

class HeadlessVisible extends HeadlessAction
{
    public ?string $name = 'Visible';
}

class HeadlessStandalone extends HeadlessAction
{
    public ?string $name = 'Standalone headless';
}

class HeadlessShown extends HeadlessAction
{
    public ?string $name = 'Standalone shown';
}

class HeadlessResource extends Resource
{
    public static function model(): string
    {
        return HeadlessItem::class;
    }

    public static function uriKey(): string
    {
        return 'headless-items';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }

    public function actions(Request $request): array
    {
        return [
            HeadlessVisible::make()->showInline(),
            HeadlessAction::make()->exceptOnIndex()->exceptOnDetail()->exceptInline()
                ->canRun(fn (Request $request, Model $model): bool => $model->getAttribute('name') !== 'Locked'),
            HeadlessShown::make()->standalone(),
            HeadlessStandalone::make()->standalone()->exceptOnIndex()->exceptOnDetail()->exceptInline(),
        ];
    }

    // The policy the run checks: runAction is denied on "No policy".
    public function authorizedToRunAction(Request $request): bool
    {
        return $this->model?->getAttribute('name') !== 'No policy';
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);
    HeadlessLog::$ran = [];

    foreach (['headless_items', 'martis_action_events'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('headless_items', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    // The shape of stubs/create_martis_action_events_table.php.stub.
    Schema::create('martis_action_events', function ($t) {
        $t->id();
        $t->string('batch_id')->index();
        $t->unsignedBigInteger('user_id')->nullable();
        $t->string('name');
        $t->string('actionable_type')->nullable();
        $t->string('actionable_id')->nullable();
        $t->string('target_type')->nullable();
        $t->string('target_id')->nullable();
        $t->string('model_type')->nullable();
        $t->string('model_id')->nullable();
        $t->text('fields');
        $t->string('status', 25)->default('running');
        $t->text('exception');
        $t->text('original');
        $t->text('changes');
        $t->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(HeadlessResource::class);

    $this->item = HeadlessItem::create(['name' => 'Item']);
});

afterEach(function () {
    foreach (['headless_items', 'martis_action_events'] as $table) {
        Schema::dropIfExists($table);
    }
    app(ResourceRegistry::class)->flush();
});

const HEADLESS_BASE = '/martis/api/resources/headless-items';

it('is absent from the index, detail and inline action lists, whose other actions stay', function () {
    foreach (['index', 'detail', 'inline'] as $context) {
        $keys = collect($this->getJson(HEADLESS_BASE."/actions?context={$context}")->assertOk()->json('data.actions'))->pluck('uriKey');

        expect($keys->contains('headless-action'))->toBeFalse()
            ->and($keys->contains('headless-standalone'))->toBeFalse();
    }

    $inline = collect($this->getJson(HEADLESS_BASE.'/actions?context=inline')->json('data.actions'))->pluck('uriKey')->all();
    expect($inline)->toBe(['headless-visible']);
});

it('stays runnable through the single-record endpoint, and its fields are served', function () {
    $this->getJson(HEADLESS_BASE.'/actions/headless-action/fields')->assertOk();

    $this->postJson(HEADLESS_BASE."/{$this->item->id}/actions/headless-action")->assertOk();

    expect(HeadlessLog::$ran)->toBe([$this->item->id])
        ->and($this->item->fresh()->name)->toBe('synced');
});

it('runs a standalone headless action through the bulk endpoint', function () {
    $this->postJson(HEADLESS_BASE.'/actions/headless-standalone')->assertOk();
});

it('still refuses it on a record its canRun or the runAction policy denies', function () {
    $locked = HeadlessItem::create(['name' => 'Locked']);
    $noPolicy = HeadlessItem::create(['name' => 'No policy']);

    $this->postJson(HEADLESS_BASE."/{$locked->id}/actions/headless-action")->assertNotFound();
    $this->postJson(HEADLESS_BASE."/{$noPolicy->id}/actions/headless-action")->assertNotFound();

    expect(HeadlessLog::$ran)->toBe([])
        ->and($locked->fresh()->name)->toBe('Locked')
        ->and($noPolicy->fresh()->name)->toBe('No policy');
});

it('logs an action event for a headless run, as for any other', function () {
    $this->postJson(HEADLESS_BASE."/{$this->item->id}/actions/headless-action")->assertOk();

    $event = ActionEvent::query()->sole();

    expect($event->name)->toBe('Sync from component')
        ->and($event->status)->toBe('completed')
        ->and((string) $event->model_id)->toBe((string) $this->item->id);
});

it('is not offered by the command palette, while a standalone action shown on the index is', function () {
    $labels = collect($this->getJson('/martis/api/command-palette')->assertOk()->json('actions'))
        ->where('resourceUriKey', 'headless-items')->pluck('label')->all();

    expect($labels)->toBe(['Standalone shown']);
});

it('is still listed by the schema, which does not filter by surface', function () {
    $keys = collect($this->getJson(HEADLESS_BASE.'/schema')->assertOk()->json('data.actions'))->pluck('uriKey');

    expect($keys->contains('headless-action'))->toBeTrue()
        ->and($keys->contains('headless-standalone'))->toBeTrue();
});
