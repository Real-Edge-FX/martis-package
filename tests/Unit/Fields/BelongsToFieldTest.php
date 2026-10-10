<?php

use Illuminate\Database\Eloquent\Model;
use Martis\Enums\ModalSize;
use Martis\Fields\BelongsTo;

// ---------------------------------------------------------------------------
// showCreateRelationButton / hideCreateRelationButton
// ---------------------------------------------------------------------------

it('BelongsTo showCreateRelationButton defaults to false', function () {
    $field = BelongsTo::make('author');

    expect($field->isShowCreateRelationButton())->toBeFalse();
});

it('BelongsTo showCreateRelationButton can be enabled', function () {
    $field = BelongsTo::make('author')
        ->showCreateRelationButton();

    expect($field->isShowCreateRelationButton())->toBeTrue();
});

it('BelongsTo showCreateRelationButton with explicit true', function () {
    $field = BelongsTo::make('author')
        ->showCreateRelationButton(true);

    expect($field->isShowCreateRelationButton())->toBeTrue();
});

it('BelongsTo showCreateRelationButton with explicit false', function () {
    $field = BelongsTo::make('author')
        ->showCreateRelationButton(false);

    expect($field->isShowCreateRelationButton())->toBeFalse();
});

it('BelongsTo hideCreateRelationButton hides the button', function () {
    $field = BelongsTo::make('author')
        ->showCreateRelationButton()
        ->hideCreateRelationButton();

    expect($field->isShowCreateRelationButton())->toBeFalse();
});

// ---------------------------------------------------------------------------
// modalSize
// ---------------------------------------------------------------------------

it('BelongsTo modalSize defaults to 2xl', function () {
    $field = BelongsTo::make('author');

    expect($field->getModalSize())->toBe(ModalSize::TwoExtraLarge);
});

it('BelongsTo modalSize can be set with enum', function () {
    $field = BelongsTo::make('author')
        ->modalSize(ModalSize::Large);

    expect($field->getModalSize())->toBe(ModalSize::Large);
});

it('BelongsTo modalSize can be set with Large enum', function () {
    $field = BelongsTo::make('author')
        ->modalSize(ModalSize::Large);

    expect($field->getModalSize())->toBe(ModalSize::Large);
});

it('BelongsTo modalSize can be set to Small enum', function () {
    $field = BelongsTo::make('author')
        ->modalSize(ModalSize::Small);

    expect($field->getModalSize())->toBe(ModalSize::Small);
});

// ---------------------------------------------------------------------------
// extraAttributes serialization
// ---------------------------------------------------------------------------

it('BelongsTo toArray includes showCreateRelationButton and modalSize', function () {
    $field = BelongsTo::make('author')
        ->relatedResource('users')
        ->showCreateRelationButton()
        ->modalSize(ModalSize::Large);

    $arr = $field->toArray();

    expect($arr['showCreateRelationButton'])->toBeTrue()
        ->and($arr['modalSize'])->toBe('lg');
});

it('BelongsTo toArray shows false for showCreateRelationButton when disabled', function () {
    $field = BelongsTo::make('author')
        ->relatedResource('users');

    $arr = $field->toArray();

    // showCreateRelationButton = false is filtered out by array_filter since it's falsy
    // but modalSize should be present as '2xl'
    expect(isset($arr['showCreateRelationButton']) ? $arr['showCreateRelationButton'] : false)->toBeFalse()
        ->and($arr['modalSize'])->toBe('2xl');
});

// ---------------------------------------------------------------------------
// withSubtitles / subtitleAttribute
// ---------------------------------------------------------------------------

it('BelongsTo withSubtitles defaults to false', function () {
    $field = BelongsTo::make('author');
    $arr = $field->toArray();
    expect(isset($arr['withSubtitles']) ? $arr['withSubtitles'] : false)->toBeFalse();
});

it('BelongsTo withSubtitles can be enabled', function () {
    $field = BelongsTo::make('author')->withSubtitles();
    $arr = $field->toArray();
    expect($arr['withSubtitles'])->toBeTrue()
        ->and($arr['subtitleAttribute'])->toBe('subtitle');
});

it('BelongsTo withSubtitles with explicit false', function () {
    $field = BelongsTo::make('author')->withSubtitles(false);
    $arr = $field->toArray();
    expect(isset($arr['withSubtitles']) ? $arr['withSubtitles'] : false)->toBeFalse();
});

it('BelongsTo subtitleAttribute enables withSubtitles and sets attribute', function () {
    $field = BelongsTo::make('author')->subtitleAttribute('description');
    $arr = $field->toArray();
    expect($arr['withSubtitles'])->toBeTrue()
        ->and($arr['subtitleAttribute'])->toBe('description');
});

// ---------------------------------------------------------------------------
// peekable / noPeeking
// ---------------------------------------------------------------------------

it('BelongsTo peekable defaults to true', function () {
    $field = BelongsTo::make('author');
    $arr = $field->toArray();
    expect($arr['peekable'])->toBeTrue();
});

it('BelongsTo noPeeking disables peek', function () {
    $field = BelongsTo::make('author')->noPeeking();
    $arr = $field->toArray();
    expect($arr['peekable'])->toBeFalse();
});

it('BelongsTo peekable with explicit false', function () {
    $field = BelongsTo::make('author')->peekable(false);
    $arr = $field->toArray();
    expect($arr['peekable'])->toBeFalse();
});

it('BelongsTo peekable with explicit true', function () {
    $field = BelongsTo::make('author')->peekable(true);
    $arr = $field->toArray();
    expect($arr['peekable'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// withoutTrashed
// ---------------------------------------------------------------------------

it('BelongsTo withoutTrashed defaults to false', function () {
    $field = BelongsTo::make('author');
    expect($field->isWithoutTrashed())->toBeFalse();
});

it('BelongsTo withoutTrashed can be enabled', function () {
    $field = BelongsTo::make('author')->withoutTrashed();
    expect($field->isWithoutTrashed())->toBeTrue();
});

it('BelongsTo withoutTrashed not in toArray when false', function () {
    $field = BelongsTo::make('author');
    $arr = $field->toArray();
    expect(isset($arr['withoutTrashed']) ? $arr['withoutTrashed'] : false)->toBeFalse();
});

it('BelongsTo withoutTrashed present in toArray when enabled', function () {
    $field = BelongsTo::make('author')->withoutTrashed();
    $arr = $field->toArray();
    expect($arr['withoutTrashed'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// dontReorderAssociatables
// ---------------------------------------------------------------------------

it('BelongsTo dontReorderAssociatables defaults to false', function () {
    $field = BelongsTo::make('author');
    expect($field->isDontReorderAssociatables())->toBeFalse();
});

it('BelongsTo dontReorderAssociatables can be enabled', function () {
    $field = BelongsTo::make('author')->dontReorderAssociatables();
    expect($field->isDontReorderAssociatables())->toBeTrue();
});

// ---------------------------------------------------------------------------
// relatableQueryUsing
// ---------------------------------------------------------------------------

it('BelongsTo relatableQueryUsing stores closure', function () {
    $closure = fn ($request, $query) => $query;
    $field = BelongsTo::make('author')->relatableQueryUsing($closure);
    expect($field->getRelatableQueryClosure())->toBe($closure);
});

it('BelongsTo relatableQueryUsing is null by default', function () {
    $field = BelongsTo::make('author');
    expect($field->getRelatableQueryClosure())->toBeNull();
});

// ---------------------------------------------------------------------------
// resolve() subtitle
// ---------------------------------------------------------------------------

it('BelongsTo resolve() returns subtitle key always present', function () {
    $field = BelongsTo::make('author');
    // resolve() with null FK returns null (not array)
    $model = new class extends Model
    {
        protected $guarded = [];
    };
    $model->author_id = null;
    expect($field->resolve($model))->toBeNull();
});

it('BelongsTo toArray withSubtitles includes subtitleAttribute', function () {
    $field = BelongsTo::make('author')->withSubtitles()->subtitleAttribute('bio');
    $arr = $field->toArray();
    expect($arr['withSubtitles'])->toBeTrue()
        ->and($arr['subtitleAttribute'])->toBe('bio');
});

// ---------------------------------------------------------------------------
// reduceSubmittedValue / reduceSubmittedValues (v2.10.0)
// ---------------------------------------------------------------------------

it('BelongsTo reduces a {id, title} map to its id and leaves every other value alone', function (mixed $value, mixed $expected) {
    expect(BelongsTo::make('author')->reduceSubmittedValue($value))->toBe($expected);
})->with([
    'an int id in a map' => [['id' => 3, 'title' => 'Ana'], 3],
    'a string id in a map' => [['id' => '3', 'title' => 'Ana'], '3'],
    'a map with the empty id' => [['id' => '', 'title' => 'Ana'], null],
    'a map with no id' => [['title' => 'Ana'], null],
    'a bare id' => [7, 7],
    'a bare string id' => ['7', '7'],
    'null' => [null, null],
    'a map carrying the trashed opt-in' => [['id' => 3, 'title' => 'Ana', 'trashed' => true], ['id' => 3, 'title' => 'Ana', 'trashed' => true]],
    'a map with trashed false' => [['id' => 3, 'trashed' => false], 3],
    'a boolean id in a map stays malformed' => [['id' => true], ['id' => true]],
    'a float id in a map stays malformed' => [['id' => 1.5], ['id' => 1.5]],
    'a nested map id stays malformed' => [['id' => ['id' => 1]], ['id' => ['id' => 1]]],
]);

it('BelongsTo reduceSubmittedValues reduces the BelongsTo maps by attribute and skips MorphTo and other fields', function () {
    $fields = [
        BelongsTo::make('author'),
        BelongsTo::make('editor'),
        Martis\Fields\MorphTo::make('owner'),
        Martis\Fields\Text::make('title'),
    ];

    $values = [
        'author_id' => ['id' => 4, 'title' => 'Ana'],
        'editor_id' => 9,
        'owner' => ['type' => 'users', 'id' => 5],
        'title' => ['id' => 1],
    ];

    expect(BelongsTo::reduceSubmittedValues($fields, $values))->toBe(['author_id' => 4]);
});
