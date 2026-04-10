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

it('MorphMany::make creates field with correct label', function () {
    $field = MorphMany::make('Comments');

    expect($field->label())->toBe('Comments');
});

it('MorphMany::make infers relationship name from label', function () {
    $field = MorphMany::make('Comments');

    expect($field->getRelationship())->toBe('comments');
});

it('MorphMany::make accepts explicit relationship name', function () {
    $field = MorphMany::make('User Comments', 'comments');

    expect($field->getRelationship())->toBe('comments');
});

it('MorphMany type returns morph_many', function () {
    $field = MorphMany::make('Comments');

    expect($field->type())->toBe('morph_many');
});

// ---------------------------------------------------------------------------
// Related resource key
// ---------------------------------------------------------------------------

it('MorphMany infers related resource key from relationship', function () {
    $field = MorphMany::make('Comments');

    expect($field->getRelatedResourceKey())->toBe('comments');
});

it('MorphMany allows explicit related resource key', function () {
    $field = MorphMany::make('Comments')->relatedResource('blog-comments');

    expect($field->getRelatedResourceKey())->toBe('blog-comments');
});

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

it('MorphMany resolve returns null on detail page', function () {
    $field = MorphMany::make('Comments');
    $model = new MorphManyTestModel;
    $model->id = 1;

    expect($field->resolve($model))->toBeNull();
});

it('MorphMany fill is a no-op', function () {
    $field = MorphMany::make('Comments');
    $model = new MorphManyTestModel;

    $field->fill($model, ['some' => 'data']);

    expect($model->getDirty())->toBeEmpty();
});
