<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Martis\Contracts\FieldContract;
use Martis\Events\AfterDelete;
use Martis\Events\AfterSave;
use Martis\Events\BeforeDelete;
use Martis\Events\BeforeSave;
use Martis\Fields\Text;

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------

class HookTestModel extends Model
{
    protected $table = 'users';

    protected $fillable = ['name', 'email'];

    public $timestamps = false;
}

class HookTestResource extends Martis\Resource
{
    public static function model(): string
    {
        return HookTestModel::class;
    }

    /** @return list<FieldContract> */
    public function fields(Request $request): array
    {
        return [];
    }
}

// ---------------------------------------------------------------------------
// BeforeSave / AfterSave hooks
// ---------------------------------------------------------------------------

it('dispatches BeforeSave event on create', function () {
    Event::fake([BeforeSave::class]);

    $model = new HookTestModel(['name' => 'Test', 'email' => 'test@test.com']);
    $request = Request::create('/');
    $res = new HookTestResource($model);

    $res->beforeSave($model, $request, creating: true);

    Event::assertDispatched(BeforeSave::class, function (BeforeSave $event) use ($model, $request) {
        return $event->model === $model
            && $event->request === $request
            && $event->creating === true
            && $event->resourceClass === HookTestResource::class;
    });
});

it('dispatches BeforeSave event on update', function () {
    Event::fake([BeforeSave::class]);

    $model = new HookTestModel;
    $request = Request::create('/');
    $res = new HookTestResource($model);

    $res->beforeSave($model, $request, creating: false);

    Event::assertDispatched(BeforeSave::class, function (BeforeSave $event) {
        return $event->creating === false;
    });
});

it('dispatches AfterSave event', function () {
    Event::fake([AfterSave::class]);

    $model = new HookTestModel;
    $request = Request::create('/');
    $res = new HookTestResource($model);

    $res->afterSave($model, $request, creating: true);

    Event::assertDispatched(AfterSave::class, function (AfterSave $event) use ($model) {
        return $event->model === $model && $event->creating === true;
    });
});

// ---------------------------------------------------------------------------
// BeforeDelete / AfterDelete hooks
// ---------------------------------------------------------------------------

it('dispatches BeforeDelete event', function () {
    Event::fake([BeforeDelete::class]);

    $model = new HookTestModel;
    $request = Request::create('/');
    $res = new HookTestResource($model);

    $res->beforeDelete($model, $request);

    Event::assertDispatched(BeforeDelete::class, function (BeforeDelete $event) use ($model, $request) {
        return $event->model === $model
            && $event->request === $request
            && $event->resourceClass === HookTestResource::class;
    });
});

it('dispatches AfterDelete event', function () {
    Event::fake([AfterDelete::class]);

    $model = new HookTestModel;
    $request = Request::create('/');
    $res = new HookTestResource($model);

    $res->afterDelete($model, $request);

    Event::assertDispatched(AfterDelete::class, function (AfterDelete $event) use ($model) {
        return $event->model === $model;
    });
});

// ---------------------------------------------------------------------------
// Hooks can be overridden by concrete resources
// ---------------------------------------------------------------------------

it('allows concrete resource to override beforeSave without dispatching event', function () {
    $called = false;

    $resource = new class(new HookTestModel) extends Martis\Resource
    {
        public static function model(): string
        {
            return HookTestModel::class;
        }

        /** @return list<FieldContract> */
        public function fields(Request $request): array
        {
            return [];
        }

        public function beforeSave(Model $model, Request $request, bool $creating): void
        {
            $model->name = 'overridden';
            // Intentionally not calling parent — no event dispatched
        }
    };

    $model = new HookTestModel(['name' => 'original']);
    $request = Request::create('/');

    Event::fake([BeforeSave::class]);

    $resource->beforeSave($model, $request, creating: true);

    expect($model->name)->toBe('overridden');
    Event::assertNotDispatched(BeforeSave::class);
});

// ---------------------------------------------------------------------------
// Field component key
// ---------------------------------------------------------------------------

it('serializes component key in field toArray when set', function () {
    $field = Text::make('status')->component('status-badge');

    expect($field->toArray()['component'])->toBe('status-badge');
    expect($field->getComponentKey())->toBe('status-badge');
});

it('serializes null component key when not set', function () {
    $field = Text::make('title');

    expect($field->toArray()['component'])->toBeNull();
    expect($field->getComponentKey())->toBeNull();
});
