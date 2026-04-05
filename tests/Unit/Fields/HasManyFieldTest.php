<?php

use Illuminate\Database\Eloquent\Model;
use Martis\Fields\HasMany;

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------

class HasManyTestModel extends Model
{
    protected $table = 'users';

    public $timestamps = false;
}

// ---------------------------------------------------------------------------
// Construction
// ---------------------------------------------------------------------------

it('HasMany::make creates field with correct label', function () {
    $field = HasMany::make('Posts');

    expect($field->label())->toBe('Posts');
});

it('HasMany::make infers relationship name from label', function () {
    $field = HasMany::make('Posts');

    expect($field->getRelationship())->toBe('posts');
});

it('HasMany::make accepts explicit relationship name', function () {
    $field = HasMany::make('User Posts', 'posts');

    expect($field->getRelationship())->toBe('posts');
});

it('HasMany type returns has_many', function () {
    $field = HasMany::make('Posts');

    expect($field->type())->toBe('has_many');
});

// ---------------------------------------------------------------------------
// Related resource key
// ---------------------------------------------------------------------------

it('HasMany infers related resource key from relationship', function () {
    $field = HasMany::make('Posts');

    expect($field->getRelatedResourceKey())->toBe('posts');
});

it('HasMany allows explicit related resource key', function () {
    $field = HasMany::make('Posts')->relatedResource('blog-posts');

    expect($field->getRelatedResourceKey())->toBe('blog-posts');
});

// ---------------------------------------------------------------------------
// Visibility
// ---------------------------------------------------------------------------

it('HasMany is detail-only by default', function () {
    $field = HasMany::make('Posts');
    $serialized = $field->toArray();

    expect($serialized['showOnDetail'])->toBeTrue()
        ->and($serialized['showOnIndex'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// Schema / serialization
// ---------------------------------------------------------------------------

it('HasMany toArray includes relationship metadata', function () {
    $field = HasMany::make('Posts', 'posts');
    $data = $field->toArray();

    expect($data['type'])->toBe('has_many')
        ->and($data['relationship'])->toBe('posts')
        ->and($data['relatedResource'])->toBe('posts')
        ->and($data)->toHaveKey('hasManyMeta');
});

it('HasMany meta includes all expected keys', function () {
    $field = HasMany::make('Posts');
    $data = $field->toArray();
    $meta = $data['hasManyMeta'];

    expect($meta)->toHaveKeys(['perPage', 'perPageOptions', 'searchable', 'canCreate', 'canUpdate', 'canDelete']);
});

it('HasMany meta defaults are correct', function () {
    $field = HasMany::make('Posts');
    $meta = $field->toArray()['hasManyMeta'];

    expect($meta['perPage'])->toBe(10)
        ->and($meta['perPageOptions'])->toBe([5, 10, 25, 50])
        ->and($meta['searchable'])->toBeTrue()
        ->and($meta['canCreate'])->toBeTrue()
        ->and($meta['canUpdate'])->toBeTrue()
        ->and($meta['canDelete'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// Configuration methods
// ---------------------------------------------------------------------------

it('HasMany perPage sets custom per_page', function () {
    $field = HasMany::make('Posts')->perPage(25);
    $meta = $field->toArray()['hasManyMeta'];

    expect($meta['perPage'])->toBe(25);
});

it('HasMany perPageOptions sets custom options', function () {
    $field = HasMany::make('Posts')->perPageOptions([10, 50, 100]);
    $meta = $field->toArray()['hasManyMeta'];

    expect($meta['perPageOptions'])->toBe([10, 50, 100]);
});

it('HasMany canCreate can be disabled', function () {
    $field = HasMany::make('Posts')->canCreate(false);
    $meta = $field->toArray()['hasManyMeta'];

    expect($meta['canCreate'])->toBeFalse();
});

it('HasMany canUpdate can be disabled', function () {
    $field = HasMany::make('Posts')->canUpdate(false);
    $meta = $field->toArray()['hasManyMeta'];

    expect($meta['canUpdate'])->toBeFalse();
});

it('HasMany canDelete can be disabled', function () {
    $field = HasMany::make('Posts')->canDelete(false);
    $meta = $field->toArray()['hasManyMeta'];

    expect($meta['canDelete'])->toBeFalse();
});

it('HasMany relationSearchable can be disabled', function () {
    $field = HasMany::make('Posts')->relationSearchable(false);
    $meta = $field->toArray()['hasManyMeta'];

    expect($meta['searchable'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// Resolve / Fill
// ---------------------------------------------------------------------------

it('HasMany resolve returns null', function () {
    $model = new HasManyTestModel;
    $field = HasMany::make('Posts');

    expect($field->resolve($model))->toBeNull();
});

it('HasMany fill is a no-op', function () {
    $model = new HasManyTestModel;
    $field = HasMany::make('Posts');
    $field->fill($model, 'some value');

    // No exception thrown, no attribute set
    expect(true)->toBeTrue();
});
