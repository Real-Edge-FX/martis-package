<?php

use Illuminate\Database\Eloquent\Model;
use Martis\FieldContext;
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
        ->displayUsing(fn ($value) => 'Go to '.parse_url($value, PHP_URL_HOST));

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

it('Url buildRules includes a URL closure validator', function () {
    $field = Url::make('website_url');
    $rules = $field->buildRules();

    $closure = array_values(array_filter($rules, fn ($r) => $r instanceof Closure));
    expect($closure)->toHaveCount(1);

    // Exercise the closure: a value missing its scheme should still pass,
    // because the closure normalises before validating.
    $failed = false;
    $closure[0]('website_url', 'www.example.com', function () use (&$failed) {
        $failed = true;
    });
    expect($failed)->toBeFalse();
});

it('Url buildRules fails on values that cannot be normalised to a URL', function () {
    $field = Url::make('website_url');
    $rules = $field->buildRules();
    $closure = array_values(array_filter($rules, fn ($r) => $r instanceof Closure))[0];

    $messages = [];
    $closure('website_url', 'not a url at all /@', function (string $msg) use (&$messages) {
        $messages[] = $msg;
    });
    expect($messages)->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// Normalisation — Martis accepts bare hostnames by auto-prepending the scheme
// ---------------------------------------------------------------------------

it('Url::normaliseUrl keeps https:// prefix intact', function () {
    expect(Url::normaliseUrl('https://example.com'))->toBe('https://example.com');
});

it('Url::normaliseUrl auto-prepends http:// when no scheme is present', function () {
    expect(Url::normaliseUrl('www.google.com.br'))->toBe('http://www.google.com.br');
});

it('Url fill() persists the normalised URL', function () {
    $model = new UrlTestModel;
    Url::make('website_url')->fill($model, 'www.example.com');
    expect($model->getAttribute('website_url'))->toBe('http://www.example.com');
});

it('Url fill() stores null when only whitespace is submitted', function () {
    $model = new UrlTestModel;
    Url::make('website_url')->fill($model, '   ');
    expect($model->getAttribute('website_url'))->toBeNull();
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

    expect($field->isVisibleForContext(FieldContext::INDEX))->toBeTrue()
        ->and($field->isVisibleForContext(FieldContext::DETAIL))->toBeTrue()
        ->and($field->isVisibleForContext(FieldContext::CREATE))->toBeTrue()
        ->and($field->isVisibleForContext(FieldContext::UPDATE))->toBeTrue();
});
