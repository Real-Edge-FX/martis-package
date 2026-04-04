<?php

use Illuminate\Database\Eloquent\Model;
use Martis\Fields\Color;

class ColorTestModel extends Model
{
    protected $table = 'users';
    protected $fillable = ['label_color'];
    public $timestamps = false;
}

// ---------------------------------------------------------------------------
// Construction
// ---------------------------------------------------------------------------

it('Color::make creates a color field', function () {
    $field = Color::make('label_color');

    expect($field->attribute())->toBe('label_color')
        ->and($field->label())->toBe('Label Color')
        ->and($field->type())->toBe('color');
});

it('Color::make accepts custom label', function () {
    $field = Color::make('label_color', 'Color');

    expect($field->label())->toBe('Color');
});

// ---------------------------------------------------------------------------
// Visibility — shown on all contexts by default
// ---------------------------------------------------------------------------

it('Color is shown on all contexts by default', function () {
    $field = Color::make('label_color');

    expect($field->isVisibleForContext('index'))->toBeTrue()
        ->and($field->isVisibleForContext('detail'))->toBeTrue()
        ->and($field->isVisibleForContext('create'))->toBeTrue()
        ->and($field->isVisibleForContext('update'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Resolve
// ---------------------------------------------------------------------------

it('Color resolves hex value from model', function () {
    $model = new ColorTestModel(['label_color' => '#ff5733']);
    $field = Color::make('label_color');

    expect($field->resolve($model))->toBe('#ff5733');
});

it('Color resolves null as null', function () {
    $model = new ColorTestModel(['label_color' => null]);
    $field = Color::make('label_color');

    expect($field->resolve($model))->toBeNull();
});

// ---------------------------------------------------------------------------
// Fill
// ---------------------------------------------------------------------------

it('Color fill() persists hex value', function () {
    $model = new ColorTestModel;
    $field = Color::make('label_color');

    $field->fill($model, '#00ff00');

    expect($model->getAttribute('label_color'))->toBe('#00ff00');
});

it('Color fill() does nothing when readonly', function () {
    $model = new ColorTestModel(['label_color' => '#000000']);
    $field = Color::make('label_color')->readonly();

    $field->fill($model, '#ffffff');

    expect($model->getAttribute('label_color'))->toBe('#000000');
});

it('Color fill() stores null', function () {
    $model = new ColorTestModel;
    $field = Color::make('label_color');

    $field->fill($model, null);

    expect($model->getAttribute('label_color'))->toBeNull();
});

// ---------------------------------------------------------------------------
// toArray
// ---------------------------------------------------------------------------

it('Color toArray contains required keys', function () {
    $field = Color::make('label_color', 'Color');

    $arr = $field->toArray();

    expect($arr)->toHaveKeys(['attribute', 'label', 'type', 'nullable', 'readonly', 'required'])
        ->and($arr['type'])->toBe('color');
});

// ---------------------------------------------------------------------------
// Display consistency
// ---------------------------------------------------------------------------

it('Color resolveForDisplay returns same value as resolve', function () {
    $model = new ColorTestModel(['label_color' => '#3b82f6']);
    $field = Color::make('label_color');

    expect($field->resolveForDisplay($model))->toBe('#3b82f6');
});
