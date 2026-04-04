<?php

use Martis\Fields\File;
use Martis\Fields\Image;

// ===========================================================================
// File field — unit tests (no Storage/database needed)
// ===========================================================================

// ---------------------------------------------------------------------------
// Basic construction
// ---------------------------------------------------------------------------

it('File::make creates a file field', function () {
    $field = File::make('attachment');

    expect($field->attribute())->toBe('attachment')
        ->and($field->label())->toBe('Attachment')
        ->and($field->type())->toBe('file');
});

it('File::make accepts custom label', function () {
    $field = File::make('attachment', 'Upload Document');

    expect($field->label())->toBe('Upload Document');
});

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

it('File disk() sets the storage disk', function () {
    $field = File::make('attachment')->disk('s3');

    expect($field->getDisk())->toBe('s3');
    expect($field->toArray()['disk'])->toBe('s3');
});

it('File default disk is public', function () {
    $field = File::make('attachment');

    expect($field->getDisk())->toBe('public');
});

it('File maxSize appears in toArray', function () {
    $field = File::make('attachment')->maxSize(10240);

    expect($field->toArray()['maxSize'])->toBe(10240);
});

it('File acceptedTypes appears in toArray', function () {
    $field = File::make('attachment')->acceptedTypes(['pdf', 'doc']);

    expect($field->toArray()['acceptedTypes'])->toBe(['pdf', 'doc']);
});

it('File storagePath appears in toArray', function () {
    $field = File::make('attachment')->storagePath('docs/uploads');

    expect($field->toArray()['storagePath'])->toBe('docs/uploads');
});

// ---------------------------------------------------------------------------
// Validation rules
// ---------------------------------------------------------------------------

it('File buildRules contains file rule', function () {
    $field = File::make('attachment');

    expect($field->buildRules())->toContain('file');
});

it('File buildRules contains mimes rule when acceptedTypes set', function () {
    $field = File::make('attachment')->acceptedTypes(['pdf', 'doc']);

    expect($field->buildRules())->toContain('mimes:pdf,doc');
});

it('File buildRules contains max rule when maxSize set', function () {
    $field = File::make('attachment')->maxSize(5120);

    expect($field->buildRules())->toContain('max:5120');
});

it('File required adds required + file rules', function () {
    $field = File::make('attachment')->required();
    $rules = $field->buildRules();

    expect($rules)->toContain('required')
        ->and($rules)->toContain('file');
});

it('File nullable adds nullable + file rules', function () {
    $field = File::make('attachment')->nullable();
    $rules = $field->buildRules();

    expect($rules)->toContain('nullable')
        ->and($rules)->toContain('file');
});

// ---------------------------------------------------------------------------
// toArray
// ---------------------------------------------------------------------------

it('File toArray contains all required keys', function () {
    $field = File::make('attachment');
    $arr = $field->toArray();

    expect($arr)->toHaveKeys([
        'attribute', 'label', 'type', 'nullable', 'readonly',
        'required', 'rules', 'disk', 'storagePath', 'maxSize', 'acceptedTypes',
    ]);
});

// ===========================================================================
// Image field — unit tests
// ===========================================================================

it('Image::make creates an image field', function () {
    $field = Image::make('featured_image');

    expect($field->attribute())->toBe('featured_image')
        ->and($field->type())->toBe('image');
});

it('Image buildRules contains image rule, not file', function () {
    $field = Image::make('featured_image');
    $rules = $field->buildRules();

    expect($rules)->toContain('image')
        ->and($rules)->not->toContain('file');
});

it('Image buildRules contains mimes when acceptedTypes set', function () {
    $field = Image::make('featured_image')->acceptedTypes(['jpg', 'png']);
    $rules = $field->buildRules();

    expect($rules)->toContain('mimes:jpg,png')
        ->and($rules)->toContain('image');
});

it('Image buildRules contains max when maxSize set', function () {
    $field = Image::make('featured_image')->maxSize(2048);

    expect($field->buildRules())->toContain('max:2048');
});

it('Image thumbnail dimensions appear in toArray', function () {
    $field = Image::make('featured_image')->thumbnail(400, 300);
    $arr = $field->toArray();

    expect($arr['thumbnailWidth'])->toBe(400)
        ->and($arr['thumbnailHeight'])->toBe(300);
});

it('Image default thumbnail dimensions are null', function () {
    $field = Image::make('featured_image');
    $arr = $field->toArray();

    expect($arr['thumbnailWidth'])->toBeNull()
        ->and($arr['thumbnailHeight'])->toBeNull();
});

it('Image toArray contains all required keys', function () {
    $field = Image::make('featured_image')->thumbnail(300, 300);
    $arr = $field->toArray();

    expect($arr)->toHaveKeys([
        'attribute', 'label', 'type', 'nullable', 'readonly',
        'required', 'rules', 'disk', 'storagePath', 'maxSize',
        'acceptedTypes', 'thumbnailWidth', 'thumbnailHeight',
    ]);
});
