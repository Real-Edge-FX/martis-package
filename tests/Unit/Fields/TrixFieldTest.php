<?php

use Illuminate\Database\Eloquent\Model;
use Martis\Fields\Trix;

class TrixTestModel extends Model
{
    protected $table = 'users';
    protected $fillable = ['bio_html'];
    public $timestamps = false;
}

// ---------------------------------------------------------------------------
// Construction
// ---------------------------------------------------------------------------

it('Trix::make creates a trix field', function () {
    $field = Trix::make('bio_html');

    expect($field->attribute())->toBe('bio_html')
        ->and($field->label())->toBe('Bio Html')
        ->and($field->type())->toBe('trix');
});

it('Trix is hidden from index by default', function () {
    $field = Trix::make('bio_html');

    expect($field->isVisibleForContext('index'))->toBeFalse()
        ->and($field->isVisibleForContext('detail'))->toBeTrue()
        ->and($field->isVisibleForContext('create'))->toBeTrue()
        ->and($field->isVisibleForContext('update'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// API configuration — alwaysShow()
// ---------------------------------------------------------------------------

it('Trix alwaysShow() sets flag', function () {
    $field = Trix::make('bio_html')->alwaysShow();

    expect($field->isAlwaysShow())->toBeTrue()
        ->and($field->toArray()['alwaysShow'])->toBeTrue();
});

it('Trix defaults to not always show', function () {
    $field = Trix::make('bio_html');

    expect($field->isAlwaysShow())->toBeFalse();
    expect($field->toArray())->not->toHaveKey('alwaysShow');
});

// ---------------------------------------------------------------------------
// API configuration — withFiles()
// ---------------------------------------------------------------------------

it('Trix withFiles() sets disk', function () {
    $field = Trix::make('bio_html')->withFiles('public');

    expect($field->getWithFilesDisk())->toBe('public')
        ->and($field->toArray()['withFiles'])->toBe('public');
});

it('Trix without withFiles does not include it', function () {
    $field = Trix::make('bio_html');

    expect($field->getWithFilesDisk())->toBeNull()
        ->and($field->toArray())->not->toHaveKey('withFiles');
});

// ---------------------------------------------------------------------------
// Resolve — stores raw HTML
// ---------------------------------------------------------------------------

it('Trix resolves HTML from model', function () {
    $html = '<p>Hello <strong>world</strong></p>';
    $model = new TrixTestModel(['bio_html' => $html]);
    $field = Trix::make('bio_html');

    expect($field->resolve($model))->toBe($html);
});

it('Trix resolves null as null', function () {
    $model = new TrixTestModel(['bio_html' => null]);
    $field = Trix::make('bio_html');

    expect($field->resolve($model))->toBeNull();
});

// ---------------------------------------------------------------------------
// Fill — stores raw HTML
// ---------------------------------------------------------------------------

it('Trix fill() stores raw HTML string', function () {
    $model = new TrixTestModel;
    $field = Trix::make('bio_html');
    $html = '<h1>Title</h1><p>Content here</p>';

    $field->fill($model, $html);

    expect($model->getAttribute('bio_html'))->toBe($html);
});

it('Trix fill() does nothing when readonly', function () {
    $model = new TrixTestModel(['bio_html' => '<p>original</p>']);
    $field = Trix::make('bio_html')->readonly();

    $field->fill($model, '<p>changed</p>');

    expect($model->getAttribute('bio_html'))->toBe('<p>original</p>');
});

it('Trix fill() stores null', function () {
    $model = new TrixTestModel;
    $field = Trix::make('bio_html');

    $field->fill($model, null);

    expect($model->getAttribute('bio_html'))->toBeNull();
});

// ---------------------------------------------------------------------------
// toArray
// ---------------------------------------------------------------------------

it('Trix toArray contains required keys', function () {
    $field = Trix::make('bio_html', 'Biography')
        ->alwaysShow()
        ->withFiles('public');

    $arr = $field->toArray();

    expect($arr)->toHaveKeys(['attribute', 'label', 'type', 'alwaysShow', 'withFiles'])
        ->and($arr['type'])->toBe('trix')
        ->and($arr['alwaysShow'])->toBeTrue()
        ->and($arr['withFiles'])->toBe('public');
});

// ---------------------------------------------------------------------------
// Callbacks
// ---------------------------------------------------------------------------

it('Trix respects resolveUsing callback', function () {
    $model = new TrixTestModel(['bio_html' => '<p>text</p>']);
    $field = Trix::make('bio_html')
        ->resolveUsing(fn ($value) => strip_tags($value));

    expect($field->resolve($model))->toBe('text');
});

it('Trix respects fillUsing callback', function () {
    $model = new TrixTestModel;
    $captured = null;

    $field = Trix::make('bio_html')
        ->fillUsing(function ($m, $value, $attr) use (&$captured) {
            $captured = $value;
        });

    $field->fill($model, '<p>test</p>');

    expect($captured)->toBe('<p>test</p>');
});
