<?php

use Illuminate\Database\Eloquent\Model;
use Martis\Fields\Url;

class UrlTestModel extends Model
{
    protected $table = 'users';
    protected $fillable = ['website_url'];
    public $timestamps = false;
}

// ---------------------------------------------------------------------------
// Construction
// ---------------------------------------------------------------------------

it('Url::make creates a url field', function () {
    $field = Url::make('website_url');

    expect($field->attribute())->toBe('website_url')
        ->and($field->label())->toBe('Website Url')
        ->and($field->type())->toBe('url');
});

it('Url::make accepts custom label', function () {
    $field = Url::make('website_url', 'Website');

    expect($field->label())->toBe('Website');
});

// ---------------------------------------------------------------------------
// API configuration
// ---------------------------------------------------------------------------

it('Url displayText() sets static link text', function () {
    $field = Url::make('website_url')->displayText('Visit Site');

    expect($field->getDisplayText())->toBe('Visit Site')
        ->and($field->toArray()['displayText'])->toBe('Visit Site');
});

it('Url without displayText does not include it in toArray', function () {
    $field = Url::make('website_url');

    expect($field->toArray())->not->toHaveKey('displayText');
});

// ---------------------------------------------------------------------------
// Resolve
// ---------------------------------------------------------------------------

it('Url resolves value from model', function () {
    $model = new UrlTestModel(['website_url' => 'https://example.com']);
    $field = Url::make('website_url');

    expect($field->resolve($model))->toBe('https://example.com');
});

it('Url resolves null as null', function () {
    $model = new UrlTestModel(['website_url' => null]);
    $field = Url::make('website_url');

    expect($field->resolve($model))->toBeNull();
});

// ---------------------------------------------------------------------------
// displayUsing
// ---------------------------------------------------------------------------

it('Url resolveForDisplay applies displayUsing callback', function () {
    $model = new UrlTestModel(['website_url' => 'https://example.com']);
    $field = Url::make('website_url')
        ->displayUsing(fn ($value) => 'Go to ' . parse_url($value, PHP_URL_HOST));

    expect($field->resolveForDisplay($model))->toBe('Go to example.com');
});

// ---------------------------------------------------------------------------
// Fill
// ---------------------------------------------------------------------------

it('Url fill() sets value on model', function () {
    $model = new UrlTestModel;
    $field = Url::make('website_url');

    $field->fill($model, 'https://new.example.com');

    expect($model->getAttribute('website_url'))->toBe('https://new.example.com');
});

it('Url fill() does nothing when readonly', function () {
    $model = new UrlTestModel(['website_url' => 'https://original.com']);
    $field = Url::make('website_url')->readonly();

    $field->fill($model, 'https://changed.com');

    expect($model->getAttribute('website_url'))->toBe('https://original.com');
});

it('Url fill() stores null', function () {
    $model = new UrlTestModel;
    $field = Url::make('website_url');

    $field->fill($model, null);

    expect($model->getAttribute('website_url'))->toBeNull();
});

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

it('Url buildRules includes url rule', function () {
    $field = Url::make('website_url');

    expect($field->buildRules())->toContain('url');
});

// ---------------------------------------------------------------------------
// toArray
// ---------------------------------------------------------------------------

it('Url toArray contains required keys', function () {
    $field = Url::make('website_url', 'Website')
        ->displayText('Visit');

    $arr = $field->toArray();

    expect($arr)->toHaveKeys(['attribute', 'label', 'type', 'nullable', 'readonly', 'required'])
        ->and($arr['type'])->toBe('url')
        ->and($arr['displayText'])->toBe('Visit');
});

// ---------------------------------------------------------------------------
// Computed value via resolveUsing
// ---------------------------------------------------------------------------

it('Url supports computed values via resolveUsing', function () {
    $model = new UrlTestModel(['website_url' => 'ignored']);
    $field = Url::make('website_url')
        ->resolveUsing(fn ($value, $model) => 'https://computed.example.com');

    expect($field->resolve($model))->toBe('https://computed.example.com');
});

// ---------------------------------------------------------------------------
// Visibility
// ---------------------------------------------------------------------------

it('Url is shown on all contexts by default', function () {
    $field = Url::make('website_url');

    expect($field->isVisibleForContext('index'))->toBeTrue()
        ->and($field->isVisibleForContext('detail'))->toBeTrue()
        ->and($field->isVisibleForContext('create'))->toBeTrue()
        ->and($field->isVisibleForContext('update'))->toBeTrue();
});
