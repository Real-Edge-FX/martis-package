<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Martis\Contracts\FieldContract;
use Martis\Resource;

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------

class RedirectsAndWithTestModel extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

class ResourceWithoutWith extends Resource
{
    public static function model(): string
    {
        return RedirectsAndWithTestModel::class;
    }

    /** @return list<FieldContract> */
    public function fields(Request $request): array
    {
        return [];
    }
}

class ResourceWithWith extends Resource
{
    /** @var list<string> */
    protected static array $with = ['author', 'tags'];

    public static function model(): string
    {
        return RedirectsAndWithTestModel::class;
    }

    /** @return list<FieldContract> */
    public function fields(Request $request): array
    {
        return [];
    }
}

class ResourceWithCustomRedirects extends Resource
{
    public static function model(): string
    {
        return RedirectsAndWithTestModel::class;
    }

    /** @return list<FieldContract> */
    public function fields(Request $request): array
    {
        return [];
    }

    public function redirectAfterCreate(Model $model, Request $request): ?string
    {
        return '/custom/create/'.($model->getAttribute('id') ?? 'new');
    }

    public function redirectAfterUpdate(Model $model, Request $request): ?string
    {
        return '/custom/update/'.($model->getAttribute('id') ?? 'new');
    }
}

// ---------------------------------------------------------------------------
// applyWith() — declarative eager loading
// ---------------------------------------------------------------------------

it('applyWith is a no-op when static $with is empty', function () {
    $query = Mockery::mock(Builder::class);
    $query->shouldNotReceive('with');

    $result = ResourceWithoutWith::applyWith($query);

    expect($result)->toBe($query);
});

it('applyWith calls $query->with() with the declared relation list', function () {
    $query = Mockery::mock(Builder::class);
    $query->shouldReceive('with')->once()->with(['author', 'tags'])->andReturn($query);

    $result = ResourceWithWith::applyWith($query);

    expect($result)->toBe($query);
});

// ---------------------------------------------------------------------------
// redirectAfterCreate / redirectAfterUpdate hooks
// ---------------------------------------------------------------------------

it('redirectAfterCreate returns null by default', function () {
    $model = new RedirectsAndWithTestModel;
    $res = new ResourceWithoutWith($model);

    expect($res->redirectAfterCreate($model, Request::create('/')))->toBeNull();
});

it('redirectAfterUpdate returns null by default', function () {
    $model = new RedirectsAndWithTestModel;
    $res = new ResourceWithoutWith($model);

    expect($res->redirectAfterUpdate($model, Request::create('/')))->toBeNull();
});

it('redirectAfterCreate is overridable per resource', function () {
    $model = new RedirectsAndWithTestModel(['id' => 42]);
    $res = new ResourceWithCustomRedirects($model);

    expect($res->redirectAfterCreate($model, Request::create('/')))->toBe('/custom/create/42');
});

it('redirectAfterUpdate is overridable per resource', function () {
    $model = new RedirectsAndWithTestModel(['id' => 17]);
    $res = new ResourceWithCustomRedirects($model);

    expect($res->redirectAfterUpdate($model, Request::create('/')))->toBe('/custom/update/17');
});
