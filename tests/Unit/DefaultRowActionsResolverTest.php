<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Martis\Contracts\FieldContract;
use Martis\Enums\DefaultRowAction;
use Martis\Resource;
use Martis\Tests\TestCase;

uses(TestCase::class);

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------

class DefaultRowActionsResolverTestModel extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

class ResourceWithDefaultsAll extends Resource
{
    public static function model(): string
    {
        return DefaultRowActionsResolverTestModel::class;
    }

    /** @return list<FieldContract> */
    public function fields(Request $request): array
    {
        return [];
    }
}

class ResourceWithViewAndEditOnly extends Resource
{
    public static function model(): string
    {
        return DefaultRowActionsResolverTestModel::class;
    }

    /** @return list<FieldContract> */
    public function fields(Request $request): array
    {
        return [];
    }

    public function defaultRowActions(Request $request): bool|array
    {
        return [DefaultRowAction::View, DefaultRowAction::Edit];
    }
}

class ResourceOptedOut extends Resource
{
    public static function model(): string
    {
        return DefaultRowActionsResolverTestModel::class;
    }

    /** @return list<FieldContract> */
    public function fields(Request $request): array
    {
        return [];
    }

    public function defaultRowActions(Request $request): bool|array
    {
        return false;
    }
}

// ---------------------------------------------------------------------------
// resolveDefaultRowActions — config + per-resource composition
// ---------------------------------------------------------------------------

it('returns all three actions when global enabled and no resource override', function () {
    config()->set('martis.index.default_row_actions', [
        'enabled' => true,
        'view' => true,
        'edit' => true,
        'delete' => true,
    ]);

    $resolved = (new ResourceWithDefaultsAll)->resolveDefaultRowActions(Request::create('/'));

    expect($resolved)->toEqual([
        'enabled' => true,
        'view' => true,
        'edit' => true,
        'delete' => true,
    ]);
});

it('zeroes every action when the global enabled flag is false', function () {
    config()->set('martis.index.default_row_actions.enabled', false);

    $resolved = (new ResourceWithDefaultsAll)->resolveDefaultRowActions(Request::create('/'));

    expect($resolved)->toEqual([
        'enabled' => false,
        'view' => false,
        'edit' => false,
        'delete' => false,
    ]);
});

it('hides delete globally when the per-action delete config is false', function () {
    config()->set('martis.index.default_row_actions', [
        'enabled' => true,
        'view' => true,
        'edit' => true,
        'delete' => false,
    ]);

    $resolved = (new ResourceWithDefaultsAll)->resolveDefaultRowActions(Request::create('/'));

    expect($resolved['enabled'])->toBeTrue();
    expect($resolved['view'])->toBeTrue();
    expect($resolved['edit'])->toBeTrue();
    expect($resolved['delete'])->toBeFalse();
});

it('global per-action AND-composes with the resource whitelist', function () {
    // Resource asks for view + edit; global says delete is off (irrelevant
    // because resource did not whitelist it) and view + edit are on.
    config()->set('martis.index.default_row_actions', [
        'enabled' => true,
        'view' => true,
        'edit' => true,
        'delete' => false,
    ]);

    $resolved = (new ResourceWithViewAndEditOnly)->resolveDefaultRowActions(Request::create('/'));

    expect($resolved)->toEqual([
        'enabled' => true,
        'view' => true,
        'edit' => true,
        'delete' => false,
    ]);
});

it('global per-action wins when it disables an action the resource whitelisted', function () {
    // Resource whitelists view + edit; global flips edit off — resolver
    // must AND the two and end up with view-only.
    config()->set('martis.index.default_row_actions', [
        'enabled' => true,
        'view' => true,
        'edit' => false,
        'delete' => true,
    ]);

    $resolved = (new ResourceWithViewAndEditOnly)->resolveDefaultRowActions(Request::create('/'));

    expect($resolved['view'])->toBeTrue();
    expect($resolved['edit'])->toBeFalse();
    expect($resolved['delete'])->toBeFalse(); // resource never whitelisted it
});

it('zeroes everything when the resource opts out via false', function () {
    config()->set('martis.index.default_row_actions.enabled', true);

    $resolved = (new ResourceOptedOut)->resolveDefaultRowActions(Request::create('/'));

    expect($resolved)->toEqual([
        'enabled' => false,
        'view' => false,
        'edit' => false,
        'delete' => false,
    ]);
});
