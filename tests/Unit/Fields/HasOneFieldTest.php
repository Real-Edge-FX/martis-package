<?php

use Illuminate\Database\Eloquent\Model;
use Martis\Fields\HasOne;

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------

class HasOneTestModel extends Model
{
    protected $table = 'users';

    public $timestamps = false;
}

// ---------------------------------------------------------------------------
// Construction
// ---------------------------------------------------------------------------

it('HasOne::make creates field with correct label', function () {
    $field = HasOne::make('Profile');

    expect($field->label())->toBe('Profile');
});

it('HasOne::make infers relationship name from label', function () {
    $field = HasOne::make('Profile');

    expect($field->getRelationship())->toBe('profile');
});

it('HasOne::make accepts explicit relationship name', function () {
    $field = HasOne::make('User Profile', 'userProfile');

    expect($field->getRelationship())->toBe('userProfile');
});

it('HasOne type returns has_one', function () {
    $field = HasOne::make('Profile');

    expect($field->type())->toBe('has_one');
});

// ---------------------------------------------------------------------------
// Related resource key
// ---------------------------------------------------------------------------

it('HasOne infers related resource key from relationship', function () {
    $field = HasOne::make('Profile');

    expect($field->getRelatedResourceKey())->toBe('profiles');
});

it('HasOne allows explicit related resource key', function () {
    $field = HasOne::make('Profile')->relatedResource('user-profiles');

    expect($field->getRelatedResourceKey())->toBe('user-profiles');
});

// ---------------------------------------------------------------------------
// Visibility
// ---------------------------------------------------------------------------

it('HasOne is detail-only by default', function () {
    $field = HasOne::make('Profile');
    $serialized = $field->toArray();

    expect($serialized['showOnDetail'])->toBeTrue()
        ->and($serialized['showOnIndex'])->toBeFalse();
});

it('HasOne showOnForms is false by default', function () {
    $field = HasOne::make('Profile');
    $serialized = $field->toArray();

    expect($serialized['showOnForms'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// Schema / serialization
// ---------------------------------------------------------------------------

it('HasOne toArray includes relationship metadata', function () {
    $field = HasOne::make('Profile', 'profile');
    $data = $field->toArray();

    expect($data['type'])->toBe('has_one')
        ->and($data['relationship'])->toBe('profile')
        ->and($data['relatedResource'])->toBe('profiles')
        ->and($data)->toHaveKey('hasOneMeta');
});

it('HasOne meta includes all expected keys', function () {
    $field = HasOne::make('Profile');
    $data = $field->toArray();
    $meta = $data['hasOneMeta'];

    expect($meta)->toHaveKeys(['canCreate', 'canUpdate', 'canDelete']);
});

it('HasOne meta defaults are correct', function () {
    $field = HasOne::make('Profile');
    $meta = $field->toArray()['hasOneMeta'];

    expect($meta['canCreate'])->toBeTrue()
        ->and($meta['canUpdate'])->toBeTrue()
        ->and($meta['canDelete'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// Configuration methods
// ---------------------------------------------------------------------------

it('HasOne canCreate can be disabled', function () {
    $field = HasOne::make('Profile')->canCreate(false);
    $meta = $field->toArray()['hasOneMeta'];

    expect($meta['canCreate'])->toBeFalse();
});

it('HasOne canUpdate can be disabled', function () {
    $field = HasOne::make('Profile')->canUpdate(false);
    $meta = $field->toArray()['hasOneMeta'];

    expect($meta['canUpdate'])->toBeFalse();
});

it('HasOne canDelete can be disabled', function () {
    $field = HasOne::make('Profile')->canDelete(false);
    $meta = $field->toArray()['hasOneMeta'];

    expect($meta['canDelete'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// Resolve / Fill
// ---------------------------------------------------------------------------

it('HasOne resolve returns null', function () {
    $model = new HasOneTestModel;
    $field = HasOne::make('Profile');

    expect($field->resolve($model))->toBeNull();
});

it('HasOne fill is a no-op', function () {
    $model = new HasOneTestModel;
    $field = HasOne::make('Profile');
    $field->fill($model, 'some value');

    // No exception thrown, no attribute set
    expect(true)->toBeTrue();
});

// ---------------------------------------------------------------------------
// validateRelationship
// ---------------------------------------------------------------------------

it('HasOne validateRelationship throws when method missing', function () {
    $model = new HasOneTestModel;
    $field = HasOne::make('Profile');

    expect(fn () => $field->validateRelationship($model))
        ->toThrow(InvalidArgumentException::class);
});
