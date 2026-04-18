<?php

use Illuminate\Database\Eloquent\Model;
use Martis\FieldContext;
use Martis\Fields\Icon;

class IconTestModel extends Model
{
    protected $table = 'clients';

    protected $fillable = ['icon', 'priority', 'brand_color'];

    public $timestamps = false;
}

// ---------------------------------------------------------------------------
// Construction — Mode A (display-only fixed icon)
// ---------------------------------------------------------------------------

it('Icon::make(attribute, fixedIcon) creates a display-only field', function () {
    $field = Icon::make('status', 'rocket');

    expect($field->attribute())->toBe('status')
        ->and($field->type())->toBe('icon')
        ->and($field->isStored())->toBeFalse();

    // Display-only fields are hidden from forms by default.
    $arr = $field->toArray();
    expect($arr['showOnForms'])->toBeFalse();
    expect($arr['fixedIcon'])->toBe('rocket');
});

it('Mode A resolves the fixed icon regardless of the model', function () {
    $model = new IconTestModel;  // no icon attribute
    $field = Icon::make('status', 'rocket');

    expect($field->resolveForModel($model))->toBe(['icon' => 'rocket', 'color' => null]);
});

// ---------------------------------------------------------------------------
// Mode B — stored()
// ---------------------------------------------------------------------------

it('Mode B reads the icon name from the model column', function () {
    $model = new IconTestModel(['icon' => 'star']);
    $field = Icon::make('icon')->stored();

    expect($field->resolveForModel($model)['icon'])->toBe('star');
});

it('Mode B fills the model with a new icon name', function () {
    $model = new IconTestModel;
    $field = Icon::make('icon')->stored();

    $field->fill($model, 'crown');

    expect($model->getAttribute('icon'))->toBe('crown');
});

it('Mode B fill() silently drops values outside the palette', function () {
    $model = new IconTestModel;
    $field = Icon::make('icon')->stored()->palette(['rocket', 'star']);

    $field->fill($model, 'not-in-palette');
    expect($model->getAttribute('icon'))->toBeNull();

    $field->fill($model, 'rocket');
    expect($model->getAttribute('icon'))->toBe('rocket');
});

it('Mode B does not fill when not stored (display-only field)', function () {
    $model = new IconTestModel;
    $field = Icon::make('status', 'rocket');  // not ->stored()

    $field->fill($model, 'anything');
    expect($model->getAttribute('status'))->toBeNull();
});

// ---------------------------------------------------------------------------
// map() — declarative value → icon+color
// ---------------------------------------------------------------------------

it('map() accepts both string shortcuts and associative arrays', function () {
    $field = Icon::make('priority')->stored()->map([
        'high' => ['icon' => 'fire', 'color' => 'danger'],
        'medium' => ['icon' => 'clock', 'color' => 'warning'],
        'low' => 'check',  // shortcut
    ]);

    $map = $field->getMap();
    expect($map)
        ->toHaveKey('high')
        ->toHaveKey('medium')
        ->toHaveKey('low');
    expect($map['high'])->toBe(['icon' => 'fire', 'color' => 'danger']);
    expect($map['low'])->toBe(['icon' => 'check']);
});

it('map() resolves the DB value to the mapped icon + color', function () {
    $model = new IconTestModel(['icon' => 'high']);
    $field = Icon::make('icon')->stored()->map([
        'high' => ['icon' => 'fire', 'color' => 'danger'],
    ]);

    expect($field->resolveForModel($model))->toBe(['icon' => 'fire', 'color' => 'danger']);
});

// ---------------------------------------------------------------------------
// color() + colorFrom()
// ---------------------------------------------------------------------------

it('color() accepts semantic tokens, CSS vars and raw CSS colors', function () {
    $semantic = Icon::make('x', 'star')->color('success');
    $cssVar = Icon::make('x', 'star')->color('var(--custom)');
    $hex = Icon::make('x', 'star')->color('#ec4899');

    expect($semantic->getColor())->toBe('success')
        ->and($cssVar->getColor())->toBe('var(--custom)')
        ->and($hex->getColor())->toBe('#ec4899');
});

it('colorFrom() reads the color from a sibling attribute on the model', function () {
    $model = new IconTestModel(['icon' => 'star', 'brand_color' => '#00bfa5']);
    $field = Icon::make('icon')->stored()->colorFrom('brand_color');

    expect($field->resolveForModel($model))->toBe(['icon' => 'star', 'color' => '#00bfa5']);
});

it('colorFrom() falls back to ->color() when the sibling attribute is empty', function () {
    $model = new IconTestModel(['icon' => 'star', 'brand_color' => null]);
    $field = Icon::make('icon')->stored()->colorFrom('brand_color')->color('accent');

    expect($field->resolveForModel($model))->toBe(['icon' => 'star', 'color' => 'accent']);
});

// ---------------------------------------------------------------------------
// Mode C — icon() resolver
// ---------------------------------------------------------------------------

it('Mode C resolver can return a plain string', function () {
    $model = new IconTestModel(['priority' => 'high']);
    $field = Icon::make('state')->icon(fn ($m) => $m->priority === 'high' ? 'fire' : 'check');

    expect($field->resolveForModel($model)['icon'])->toBe('fire');
});

it('Mode C resolver can return an icon+color array', function () {
    $model = new IconTestModel(['priority' => 'low']);
    $field = Icon::make('state')->icon(fn ($m) => [
        'icon' => 'check',
        'color' => 'success',
    ]);

    expect($field->resolveForModel($model))->toBe(['icon' => 'check', 'color' => 'success']);
});

// ---------------------------------------------------------------------------
// resolve() vs resolveForDisplay() — form vs display contract
// ---------------------------------------------------------------------------

it('resolve() returns the raw string (so form inputs receive what they can edit)', function () {
    $model = new IconTestModel(['icon' => 'star']);
    $field = Icon::make('icon')->stored()->colorFrom('brand_color');

    expect($field->resolve($model))->toBe('star');
});

it('resolve() returns the fixed icon name for Mode A (display-only)', function () {
    $model = new IconTestModel;
    $field = Icon::make('status', 'rocket');

    expect($field->resolve($model))->toBe('rocket');
});

it('resolveForDisplay() returns the `{icon, color}` pair for index/detail', function () {
    $model = new IconTestModel(['icon' => 'star', 'brand_color' => '#f472b6']);
    $field = Icon::make('icon')->stored()->colorFrom('brand_color');

    expect($field->resolveForDisplay($model))->toBe(['icon' => 'star', 'color' => '#f472b6']);
});

// ---------------------------------------------------------------------------
// toArray — serialization contract
// ---------------------------------------------------------------------------

it('toArray emits the expected extra attributes', function () {
    $field = Icon::make('icon')
        ->stored()
        ->palette(['rocket', 'star'])
        ->color('success')
        ->colorFrom('brand_color')
        ->size(24);

    $arr = $field->toArray();

    expect($arr)
        ->toHaveKey('type', 'icon')
        ->toHaveKey('stored', true)
        ->toHaveKey('color', 'success')
        ->toHaveKey('colorFrom', 'brand_color')
        ->toHaveKey('palette', ['rocket', 'star'])
        ->toHaveKey('size', 24);
});

it('toArray omits size when left at the default (16)', function () {
    $arr = Icon::make('x', 'star')->toArray();

    expect($arr)->not->toHaveKey('size');
});
