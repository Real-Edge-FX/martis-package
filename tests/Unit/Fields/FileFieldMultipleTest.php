<?php

use Martis\Fields\File;
use Martis\Fields\Image;

// ===========================================================================
// File field — multiple mode unit tests (no Storage needed)
// ===========================================================================

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

it('File multiple() exposes multiple=true in toArray', function () {
    $field = File::make('documents')->multiple();
    $arr = $field->toArray();

    expect($arr['multiple'])->toBeTrue();
});

it('File default multiple is false', function () {
    $field = File::make('attachment');

    expect($field->isMultiple())->toBeFalse()
        ->and($field->toArray()['multiple'])->toBeFalse();
});

it('File multiple(false) disables multiple', function () {
    $field = File::make('documents')->multiple()->multiple(false);

    expect($field->isMultiple())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Validation rules — multiple mode
// ---------------------------------------------------------------------------

it('File multiple buildRules returns array rule', function () {
    $field = File::make('documents')->multiple();
    $rules = $field->buildRules();

    expect($rules)->toContain('array');
    expect($rules)->not->toContain('file');
});

it('File multiple required adds required + array', function () {
    $field = File::make('documents')->multiple()->required();
    $rules = $field->buildRules();

    expect($rules)->toContain('required')
        ->and($rules)->toContain('array');
});

it('File multiple nullable adds nullable + array', function () {
    $field = File::make('documents')->multiple()->nullable();
    $rules = $field->buildRules();

    expect($rules)->toContain('nullable')
        ->and($rules)->toContain('array');
});

it('File multiple buildItemRules returns file + mimes + max', function () {
    $field = File::make('documents')
        ->multiple()
        ->acceptedTypes(['pdf', 'doc'])
        ->maxSize(5120);

    $itemRules = $field->buildItemRules();

    expect($itemRules)->toContain('file')
        ->and($itemRules)->toContain('mimes:pdf,doc')
        ->and($itemRules)->toContain('max:5120');
});

it('File single buildItemRules returns empty', function () {
    $field = File::make('attachment');

    expect($field->buildItemRules())->toBe([]);
});

// ---------------------------------------------------------------------------
// Image multiple validation
// ---------------------------------------------------------------------------

it('Image multiple buildRules returns array without file or image', function () {
    $field = Image::make('gallery')->multiple();
    $rules = $field->buildRules();

    expect($rules)->toContain('array')
        ->and($rules)->not->toContain('file')
        ->and($rules)->not->toContain('image');
});

it('Image multiple buildItemRules returns image rule instead of file', function () {
    $field = Image::make('gallery')->multiple();
    $itemRules = $field->buildItemRules();

    expect($itemRules)->toContain('image')
        ->and($itemRules)->not->toContain('file');
});

it('Image multiple buildItemRules includes mimes and max', function () {
    $field = Image::make('gallery')
        ->multiple()
        ->acceptedTypes(['jpg', 'png'])
        ->maxSize(2048);

    $itemRules = $field->buildItemRules();

    expect($itemRules)->toContain('image')
        ->and($itemRules)->toContain('mimes:jpg,png')
        ->and($itemRules)->toContain('max:2048');
});

// ---------------------------------------------------------------------------
// toArray
// ---------------------------------------------------------------------------

it('File multiple toArray contains all required keys', function () {
    $field = File::make('documents')->multiple();
    $arr = $field->toArray();

    expect($arr)->toHaveKeys([
        'attribute', 'label', 'type', 'nullable', 'readonly',
        'required', 'rules', 'disk', 'storagePath', 'maxSize',
        'acceptedTypes', 'multiple',
    ]);
});

it('Image multiple toArray contains thumbnail and multiple keys', function () {
    $field = Image::make('gallery')->multiple()->thumbnail(200, 200);
    $arr = $field->toArray();

    expect($arr['multiple'])->toBeTrue()
        ->and($arr['thumbnailWidth'])->toBe(200)
        ->and($arr['thumbnailHeight'])->toBe(200);
});
