<?php

use Illuminate\Database\Eloquent\Model;
use Martis\Fields\MorphToMany;

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------

class MorphToManyTestModel extends Model
{
    protected $table = 'users';

    public $timestamps = false;
}

// ---------------------------------------------------------------------------
// Construction
// ---------------------------------------------------------------------------

it('MorphToMany::make creates field with correct label', function () {
    $field = MorphToMany::make('Tags');

    expect($field->label())->toBe('Tags');
});

it('MorphToMany::make infers relationship name from label', function () {
    $field = MorphToMany::make('Tags');

    expect($field->getRelationship())->toBe('tags');
});

it('MorphToMany::make accepts explicit relationship name', function () {
    $field = MorphToMany::make('Post Tags', 'tags');

    expect($field->getRelationship())->toBe('tags');
});

it('MorphToMany type returns morph_to_many', function () {
    $field = MorphToMany::make('Tags');

    expect($field->type())->toBe('morph_to_many');
});

// ---------------------------------------------------------------------------
// Related resource key
// ---------------------------------------------------------------------------

it('MorphToMany infers related resource key from relationship', function () {
    $field = MorphToMany::make('Tags');

    expect($field->getRelatedResourceKey())->toBe('tags');
});

it('MorphToMany allows explicit related resource key', function () {
    $field = MorphToMany::make('Tags')->relatedResource('label-tags');

    expect($field->getRelatedResourceKey())->toBe('label-tags');
});

// ---------------------------------------------------------------------------
// Visibility defaults
// ---------------------------------------------------------------------------

it('MorphToMany is hidden from index by default', function () {
    $field = MorphToMany::make('Tags');
    $arr = $field->toArray();

    expect($arr['showOnIndex'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// Meta attributes
// ---------------------------------------------------------------------------

it('MorphToMany extra attributes include morphToManyMeta', function () {
    $field = MorphToMany::make('Tags');
    $arr = $field->toArray();

    expect($arr)->toHaveKey('morphToManyMeta');
    expect($arr['morphToManyMeta']['canAttach'])->toBeTrue();
    expect($arr['morphToManyMeta']['canDetach'])->toBeTrue();
    expect($arr['morphToManyMeta']['perPage'])->toBe(10);
});

it('MorphToMany canAttach disables attach button', function () {
    $field = MorphToMany::make('Tags')->canAttach(false);
    $arr = $field->toArray();

    expect($arr['morphToManyMeta']['canAttach'])->toBeFalse();
});

it('MorphToMany canDetach disables detach button', function () {
    $field = MorphToMany::make('Tags')->canDetach(false);
    $arr = $field->toArray();

    expect($arr['morphToManyMeta']['canDetach'])->toBeFalse();
});

it('MorphToMany allowDuplicateRelations enables duplicate attach', function () {
    $field = MorphToMany::make('Tags')->allowDuplicateRelations();
    $arr = $field->toArray();

    expect($arr['allowDuplicateRelations'])->toBeTrue();
});

it('MorphToMany searchable enables search in attach modal', function () {
    $field = MorphToMany::make('Tags')->searchable();
    $arr = $field->toArray();

    expect($arr['searchable'])->toBeTrue();
});

it('MorphToMany collapsable enables collapse', function () {
    $field = MorphToMany::make('Tags')->collapsable();
    $arr = $field->toArray();

    expect($arr['collapsable'])->toBeTrue();
});

it('MorphToMany showCreateRelationButton enables inline create', function () {
    $field = MorphToMany::make('Tags')->showCreateRelationButton();
    $arr = $field->toArray();

    expect($arr['showCreateRelationButton'])->toBeTrue();
});

it('MorphToMany perPage sets per-page default', function () {
    $field = MorphToMany::make('Tags')->perPage(25);
    $arr = $field->toArray();

    expect($arr['morphToManyMeta']['perPage'])->toBe(25);
});

it('MorphToMany relatableQueryUsing stores closure', function () {
    $closure = fn ($req, $q) => $q->where('active', 1);
    $field = MorphToMany::make('Tags')->relatableQueryUsing($closure);

    expect($field->getRelatableQueryClosure())->toBeCallable();
});

// ---------------------------------------------------------------------------
// Resolve / Fill
// ---------------------------------------------------------------------------

it('MorphToMany resolve returns null on detail page', function () {
    $field = MorphToMany::make('Tags');
    $model = new MorphToManyTestModel;
    $model->id = 1;

    expect($field->resolve($model))->toBeNull();
});

it('MorphToMany fill is a no-op', function () {
    $field = MorphToMany::make('Tags');
    $model = new MorphToManyTestModel;

    $field->fill($model, ['some' => 'data']);

    expect($model->getDirty())->toBeEmpty();
});
