<?php

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

it('BelongsTo modalSize can be set with string', function () {
    $field = BelongsTo::make('author')
        ->modalSize('lg');

    expect($field->getModalSize())->toBe(ModalSize::Large);
});

it('BelongsTo modalSize can be set to sm', function () {
    $field = BelongsTo::make('author')
        ->modalSize('sm');

    expect($field->getModalSize())->toBe(ModalSize::Small);
});

// ---------------------------------------------------------------------------
// extraAttributes serialization
// ---------------------------------------------------------------------------

it('BelongsTo toArray includes showCreateRelationButton and modalSize', function () {
    $field = BelongsTo::make('author')
        ->relatedResource('users')
        ->showCreateRelationButton()
        ->modalSize('lg');

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
