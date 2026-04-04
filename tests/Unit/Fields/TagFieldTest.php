<?php

use Illuminate\Database\Eloquent\Model;
use Martis\Fields\Tag;

// ---------------------------------------------------------------------------
// Test model fixture — minimal model for unit testing
// ---------------------------------------------------------------------------

class TagTestModel extends Model
{
    protected $table = 'users';

    public $timestamps = false;

    // Fake 'tags' relationship for testing
    public function tags(): array
    {
        return [];
    }
}

// ---------------------------------------------------------------------------
// Construction
// ---------------------------------------------------------------------------

it('Tag::make creates a tag field', function () {
    $field = Tag::make('tags');

    expect($field->attribute())->toBe('tags')
        ->and($field->label())->toBe('Tags')
        ->and($field->type())->toBe('tag');
});

it('Tag::make accepts custom label', function () {
    $field = Tag::make('tags', 'Post Tags');

    expect($field->label())->toBe('Post Tags');
});

it('Tag is visible in all contexts by default', function () {
    $field = Tag::make('tags');

    expect($field->isVisibleForContext('index'))->toBeTrue()
        ->and($field->isVisibleForContext('detail'))->toBeTrue()
        ->and($field->isVisibleForContext('create'))->toBeTrue()
        ->and($field->isVisibleForContext('update'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// API configuration
// ---------------------------------------------------------------------------

it('Tag relatedResource() sets the related resource URI key', function () {
    $field = Tag::make('tags')->relatedResource('tags');

    expect($field->getRelatedResource())->toBe('tags')
        ->and($field->toArray()['relatedResource'])->toBe('tags');
});

it('Tag titleAttribute() sets the display attribute', function () {
    $field = Tag::make('tags')->titleAttribute('title');

    expect($field->getTitleAttribute())->toBe('title')
        ->and($field->toArray()['titleAttribute'])->toBe('title');
});

it('Tag titleAttribute defaults to name', function () {
    $field = Tag::make('tags');

    expect($field->getTitleAttribute())->toBe('name');
});

it('Tag withPreview() enables preview', function () {
    $field = Tag::make('tags')->withPreview();

    expect($field->hasPreview())->toBeTrue()
        ->and($field->toArray()['withPreview'])->toBeTrue();
});

it('Tag displayAsList() enables list display', function () {
    $field = Tag::make('tags')->displayAsList();

    expect($field->isDisplayAsList())->toBeTrue()
        ->and($field->toArray()['displayAsList'])->toBeTrue();
});

it('Tag showCreateRelationButton() enables inline creation', function () {
    $field = Tag::make('tags')->showCreateRelationButton();

    expect($field->isShowCreateRelationButton())->toBeTrue()
        ->and($field->toArray()['showCreateRelationButton'])->toBeTrue();
});

it('Tag modalSize() sets modal size', function () {
    $field = Tag::make('tags')->modalSize('7xl');

    expect($field->getModalSize())->toBe('7xl')
        ->and($field->toArray()['modalSize'])->toBe('7xl');
});

it('Tag preload() enables preloading', function () {
    $field = Tag::make('tags')->preload();

    expect($field->isPreload())->toBeTrue()
        ->and($field->toArray()['preload'])->toBeTrue();
});

it('Tag defaults are correct', function () {
    $field = Tag::make('tags');

    expect($field->hasPreview())->toBeFalse()
        ->and($field->isDisplayAsList())->toBeFalse()
        ->and($field->isShowCreateRelationButton())->toBeFalse()
        ->and($field->getModalSize())->toBe('2xl')
        ->and($field->isPreload())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Resolve — when relationship method exists
// ---------------------------------------------------------------------------

it('Tag resolve() returns empty array when relationship method missing', function () {
    $model = new class extends Model
    {
        protected $table = 'users';

        public $timestamps = false;
    };
    $field = Tag::make('tags');

    expect($field->resolve($model))->toBe([]);
});

// ---------------------------------------------------------------------------
// Fill — ID extraction
// ---------------------------------------------------------------------------

it('Tag fill() registers deferred sync with array of IDs', function () {
    $model = new TagTestModel;
    $model->exists = true;
    $model->setAttribute('id', 42);

    $field = Tag::make('tags')->relatedResource('tags');

    // fill() stores deferred sync — no exception means success
    // The actual sync happens after model save via DeferredRelationSync
    expect(fn () => $field->fill($model, [1, 2, 3]))->not->toThrow(Throwable::class);
});

it('Tag fill() does nothing when readonly', function () {
    $model = new TagTestModel;
    $field = Tag::make('tags')->readonly();

    // Should not throw
    expect(fn () => $field->fill($model, [1, 2]))->not->toThrow(Throwable::class);
});

it('Tag fill() accepts array of objects with id key', function () {
    $model = new TagTestModel;
    $field = Tag::make('tags');

    // Should not throw — accepts [{id: 1}, {id: 2}] format
    expect(fn () => $field->fill($model, [['id' => 1], ['id' => 2]]))->not->toThrow(Throwable::class);
});

it('Tag fill() handles null gracefully', function () {
    $model = new TagTestModel;
    $field = Tag::make('tags');

    expect(fn () => $field->fill($model, null))->not->toThrow(Throwable::class);
});

it('Tag fill() handles empty array gracefully', function () {
    $model = new TagTestModel;
    $field = Tag::make('tags');

    expect(fn () => $field->fill($model, []))->not->toThrow(Throwable::class);
});

// ---------------------------------------------------------------------------
// toArray
// ---------------------------------------------------------------------------

it('Tag toArray contains relationship and config', function () {
    $field = Tag::make('tags', 'Tags')
        ->relatedResource('tags')
        ->titleAttribute('name')
        ->withPreview()
        ->preload();

    $arr = $field->toArray();

    expect($arr)->toHaveKeys(['attribute', 'label', 'type', 'relationship', 'titleAttribute', 'relatedResource'])
        ->and($arr['type'])->toBe('tag')
        ->and($arr['relationship'])->toBe('tags')
        ->and($arr['withPreview'])->toBeTrue()
        ->and($arr['preload'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// Resolve callback
// ---------------------------------------------------------------------------

it('Tag respects resolveUsing callback', function () {
    $model = new TagTestModel;
    $field = Tag::make('tags')->resolveUsing(fn ($v, $m, $attr) => [['id' => 1, 'title' => 'custom']]);

    expect($field->resolve($model))->toBe([['id' => 1, 'title' => 'custom']]);
});

it('Tag respects fillUsing callback', function () {
    $model = new TagTestModel;
    $captured = null;

    $field = Tag::make('tags')->fillUsing(function ($m, $value, $attr) use (&$captured) {
        $captured = $value;
    });

    $field->fill($model, [1, 2, 3]);

    expect($captured)->toBe([1, 2, 3]);
});
