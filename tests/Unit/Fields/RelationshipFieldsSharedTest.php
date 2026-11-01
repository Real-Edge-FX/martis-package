<?php

use Illuminate\Database\Eloquent\Model;
use Martis\Fields\HasMany;
use Martis\Fields\HasOne;
use Martis\Fields\MorphMany;
use Martis\Fields\MorphOne;
use Martis\Fields\MorphToMany;

// ---------------------------------------------------------------------------
// Behaviour every HasMany / HasOne / MorphMany / MorphOne / MorphToMany field
// shares. Each class overrides resolve() and fill(), so each is covered here.
// Class-specific behaviour stays in the per-field test files.
//
// Row: class, label, inferred relationship, type, inferred related key,
//      explicit label, explicit relationship, explicit related key
// ---------------------------------------------------------------------------

dataset('relationship fields', [
    'HasMany' => [HasMany::class, 'Posts', 'posts', 'has_many', 'posts', 'User Posts', 'userPosts', 'blog-posts'],
    'HasOne' => [HasOne::class, 'Profile', 'profile', 'has_one', 'profiles', 'User Profile', 'userProfile', 'user-profiles'],
    'MorphMany' => [MorphMany::class, 'Comments', 'comments', 'morph_many', 'comments', 'User Comments', 'userComments', 'blog-comments'],
    'MorphOne' => [MorphOne::class, 'Image', 'image', 'morph_one', 'images', 'Profile Image', 'profileImage', 'media-items'],
    'MorphToMany' => [MorphToMany::class, 'Tags', 'tags', 'morph_to_many', 'tags', 'Post Tags', 'postTags', 'label-tags'],
]);

function relationshipFieldModel(): Model
{
    return new class(['name' => 'Alice']) extends Model
    {
        protected $table = 'users';

        protected $guarded = [];

        public $timestamps = false;
    };
}

it('::make keeps the label and infers the relationship name from it', function (string $class, string $label, string $relationship) {
    $field = $class::make($label);

    expect($field->label())->toBe($label)
        ->and($field->getRelationship())->toBe($relationship);
})->with('relationship fields');

it('::make accepts an explicit relationship name', function (string $class, $l, $r, $t, $k, string $explicitLabel, string $explicitRelationship) {
    $field = $class::make($explicitLabel, $explicitRelationship);

    expect($field->label())->toBe($explicitLabel)
        ->and($field->getRelationship())->toBe($explicitRelationship);
})->with('relationship fields');

it('reports its type', function (string $class, string $label, $r, string $type) {
    expect($class::make($label)->type())->toBe($type);
})->with('relationship fields');

it('infers the related resource key from the relationship', function (string $class, string $label, $r, $t, string $key) {
    expect($class::make($label)->getRelatedResourceKey())->toBe($key);
})->with('relationship fields');

it('allows an explicit related resource key', function (string $class, string $label, $r, $t, $k, $el, $er, string $explicitKey) {
    $field = $class::make($label)->relatedResource($explicitKey);

    expect($field->getRelatedResourceKey())->toBe($explicitKey);
})->with('relationship fields');

it('resolves to null', function (string $class, string $label) {
    $model = relationshipFieldModel();
    $model->id = 1;

    expect($class::make($label)->resolve($model))->toBeNull();
})->with('relationship fields');

it('fill leaves the model attributes and dirty state untouched', function (string $class, string $label) {
    $model = relationshipFieldModel();
    $model->syncOriginal();
    $before = $model->getAttributes();

    $class::make($label)->fill($model, ['some' => 'data']);

    expect($model->getAttributes())->toBe($before)
        ->and($model->getDirty())->toBe([]);
})->with('relationship fields');
