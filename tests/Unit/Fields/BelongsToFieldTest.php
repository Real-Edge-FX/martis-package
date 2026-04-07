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
