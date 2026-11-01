<?php

use Illuminate\Database\Eloquent\Model;
use Martis\Fields\MorphOne;

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------

class MorphOneTestModel extends Model
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

it('MorphOne is hidden from index and forms by default', function () {
    $field = MorphOne::make('Image');
    $arr = $field->toArray();

    expect($arr['showOnIndex'])->toBeFalse();
    expect($arr['showOnCreate'])->toBeFalse();
    expect($arr['showOnUpdate'])->toBeFalse();
    expect($arr['showOnDetail'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// Meta attributes
// ---------------------------------------------------------------------------

it('MorphOne extra attributes include morphOneMeta', function () {
    $field = MorphOne::make('Image');
    $arr = $field->toArray();

    expect($arr)->toHaveKey('morphOneMeta');
    expect($arr['morphOneMeta']['canCreate'])->toBeTrue();
    expect($arr['morphOneMeta']['canUpdate'])->toBeTrue();
    expect($arr['morphOneMeta']['canDelete'])->toBeTrue();
});

it('MorphOne canCreate disables create button', function () {
    $field = MorphOne::make('Image')->canCreate(false);
    $arr = $field->toArray();

    expect($arr['morphOneMeta']['canCreate'])->toBeFalse();
});

it('MorphOne relatedResource sets key explicitly', function () {
    $field = MorphOne::make('Image')->relatedResource('media');
    $arr = $field->toArray();

    expect($arr['relatedResource'])->toBe('media');
});

// ---------------------------------------------------------------------------
// Resolve / Fill
// ---------------------------------------------------------------------------
