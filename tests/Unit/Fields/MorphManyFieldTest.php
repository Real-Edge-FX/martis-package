<?php

use Illuminate\Database\Eloquent\Model;
use Martis\Fields\MorphMany;

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------

class MorphManyTestModel extends Model
{
    protected $table = 'users';

    public $timestamps = false;
}

// ---------------------------------------------------------------------------
// Construction
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Related resource key
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Visibility defaults
// ---------------------------------------------------------------------------

it('MorphMany is hidden from index and forms by default', function () {
    $field = MorphMany::make('Comments');
    $arr = $field->toArray();

    expect($arr['showOnIndex'])->toBeFalse();
    expect($arr['showOnCreate'])->toBeFalse();
    expect($arr['showOnUpdate'])->toBeFalse();
    expect($arr['showOnDetail'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// Meta attributes
// ---------------------------------------------------------------------------

it('MorphMany extra attributes include morphManyMeta', function () {
    $field = MorphMany::make('Comments');
    $arr = $field->toArray();

    expect($arr)->toHaveKey('morphManyMeta');
    expect($arr['morphManyMeta']['canCreate'])->toBeTrue();
    expect($arr['morphManyMeta']['canUpdate'])->toBeTrue();
    expect($arr['morphManyMeta']['canDelete'])->toBeTrue();
    expect($arr['morphManyMeta']['searchable'])->toBeTrue();
    expect($arr['morphManyMeta']['perPage'])->toBe(10);
});

it('MorphMany canCreate disables create button', function () {
    $field = MorphMany::make('Comments')->canCreate(false);
    $arr = $field->toArray();

    expect($arr['morphManyMeta']['canCreate'])->toBeFalse();
});

it('MorphMany canDelete disables delete button', function () {
    $field = MorphMany::make('Comments')->canDelete(false);
    $arr = $field->toArray();

    expect($arr['morphManyMeta']['canDelete'])->toBeFalse();
});

it('MorphMany perPage sets per-page default', function () {
    $field = MorphMany::make('Comments')->perPage(25);
    $arr = $field->toArray();

    expect($arr['morphManyMeta']['perPage'])->toBe(25);
});

// ---------------------------------------------------------------------------
// Resolve / Fill
// ---------------------------------------------------------------------------

