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

it('MorphOne::make creates field with correct label', function () {
    $field = MorphOne::make('Image');

    expect($field->label())->toBe('Image');
});

it('MorphOne::make infers relationship name from label', function () {
    $field = MorphOne::make('Image');

    expect($field->getRelationship())->toBe('image');
});

it('MorphOne::make accepts explicit relationship name', function () {
    $field = MorphOne::make('Profile Image', 'image');

    expect($field->getRelationship())->toBe('image');
});

it('MorphOne type returns morph_one', function () {
    $field = MorphOne::make('Image');

    expect($field->type())->toBe('morph_one');
});

// ---------------------------------------------------------------------------
// Related resource key
// ---------------------------------------------------------------------------

it('MorphOne infers related resource key from relationship', function () {
    $field = MorphOne::make('Image');

    expect($field->getRelatedResourceKey())->toBe('images');
});

it('MorphOne allows explicit related resource key', function () {
    $field = MorphOne::make('Image')->relatedResource('media-items');

    expect($field->getRelatedResourceKey())->toBe('media-items');
});

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

it('MorphOne resolve returns null', function () {
    $field = MorphOne::make('Image');
    $model = new MorphOneTestModel;
    $model->id = 1;

    expect($field->resolve($model))->toBeNull();
});

it('MorphOne fill is a no-op', function () {
    $field = MorphOne::make('Image');
    $model = new MorphOneTestModel;

    $field->fill($model, ['some' => 'data']);

    expect($model->getDirty())->toBeEmpty();
});
