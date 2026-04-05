<?php

use Illuminate\Database\Eloquent\Model;
use Martis\Enums\CodeLanguage;
use Martis\FieldContext;
use Martis\Fields\Code;

class CodeTestModel extends Model
{
    protected $table = 'users';

    protected $fillable = ['snippet', 'config_json'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['config_json' => 'array'];
    }
}

// ---------------------------------------------------------------------------
// Construction
// ---------------------------------------------------------------------------

it('Code::make creates a code field', function () {
    $field = Code::make('snippet');

    expect($field->attribute())->toBe('snippet')
        ->and($field->label())->toBe('Snippet')
        ->and($field->type())->toBe('code');
});

it('Code is hidden from index by default', function () {
    $field = Code::make('snippet');

    expect($field->isVisibleForContext(FieldContext::INDEX))->toBeFalse()
        ->and($field->isVisibleForContext(FieldContext::DETAIL))->toBeTrue()
        ->and($field->isVisibleForContext(FieldContext::CREATE))->toBeTrue()
        ->and($field->isVisibleForContext(FieldContext::UPDATE))->toBeTrue();
});

// ---------------------------------------------------------------------------
// API configuration — json()
// ---------------------------------------------------------------------------

it('Code json() enables JSON mode', function () {
    $field = Code::make('config_json')->json();

    expect($field->isJson())->toBeTrue()
        ->and($field->toArray()['json'])->toBeTrue();
});

it('Code defaults to non-JSON mode', function () {
    $field = Code::make('snippet');

    expect($field->isJson())->toBeFalse()
        ->and($field->toArray()['json'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// API configuration — language()
// ---------------------------------------------------------------------------

it('Code language() sets syntax highlighting language', function () {
    $field = Code::make('snippet')->language(CodeLanguage::Php);

    expect($field->getLanguage())->toBe(CodeLanguage::Php)
        ->and($field->toArray()['language'])->toBe('php');
});

it('Code defaults to javascript language', function () {
    $field = Code::make('snippet');

    expect($field->getLanguage())->toBe(CodeLanguage::Javascript);
});

// ---------------------------------------------------------------------------
// Resolve — plain text
// ---------------------------------------------------------------------------

it('Code resolves plain text value', function () {
    $model = new CodeTestModel(['snippet' => 'echo "hello";']);
    $field = Code::make('snippet');

    expect($field->resolve($model))->toBe('echo "hello";');
});

it('Code resolves null as null', function () {
    $model = new CodeTestModel(['snippet' => null]);
    $field = Code::make('snippet');

    expect($field->resolve($model))->toBeNull();
});

// ---------------------------------------------------------------------------
// Resolve — JSON mode
// ---------------------------------------------------------------------------

it('Code json() resolves array to pretty-printed JSON', function () {
    $model = new CodeTestModel(['config_json' => ['key' => 'value']]);
    $field = Code::make('config_json')->json();

    $resolved = $field->resolve($model);

    expect($resolved)->toContain('"key"')
        ->and($resolved)->toContain('"value"');
    // Verify it's valid JSON and pretty-printed (contains newlines)
    expect(json_decode($resolved, true))->toBe(['key' => 'value'])
        ->and($resolved)->toContain("\n");
});

it('Code json() resolves JSON string to pretty-printed JSON', function () {
    $model = new CodeTestModel(['snippet' => '{"a":1,"b":2}']);
    $field = Code::make('snippet')->json();

    $resolved = $field->resolve($model);

    expect(json_decode($resolved, true))->toBe(['a' => 1, 'b' => 2])
        ->and($resolved)->toContain("\n");
});

// ---------------------------------------------------------------------------
// Fill — plain text
// ---------------------------------------------------------------------------

it('Code fill() sets plain text value', function () {
    $model = new CodeTestModel;
    $field = Code::make('snippet');

    $field->fill($model, 'console.log("hi")');

    expect($model->getAttribute('snippet'))->toBe('console.log("hi")');
});

it('Code fill() does nothing when readonly', function () {
    $model = new CodeTestModel(['snippet' => 'original']);
    $field = Code::make('snippet')->readonly();

    $field->fill($model, 'changed');

    expect($model->getAttribute('snippet'))->toBe('original');
});

// ---------------------------------------------------------------------------
// Fill — JSON mode
// ---------------------------------------------------------------------------

it('Code json() fill() decodes JSON string before storing', function () {
    $model = new CodeTestModel;
    $field = Code::make('config_json')->json();

    $field->fill($model, '{"key": "value"}');

    expect($model->getAttribute('config_json'))->toBe(['key' => 'value']);
});

it('Code json() fill() stores invalid JSON as-is', function () {
    $model = new CodeTestModel;
    $field = Code::make('snippet')->json();

    $field->fill($model, 'not json');

    expect($model->getAttribute('snippet'))->toBe('not json');
});

// ---------------------------------------------------------------------------
// toArray
// ---------------------------------------------------------------------------

it('Code toArray contains required keys', function () {
    $field = Code::make('snippet', 'Snippet')
        ->json()
        ->language(CodeLanguage::Php);

    $arr = $field->toArray();

    expect($arr)->toHaveKeys(['attribute', 'label', 'type', 'json', 'language'])
        ->and($arr['type'])->toBe('code')
        ->and($arr['json'])->toBeTrue()
        ->and($arr['language'])->toBe('php');
});

// ---------------------------------------------------------------------------
// Validation — json is NOT auto-added
// ---------------------------------------------------------------------------

it('Code json() does NOT auto-add json validation rule', function () {
    $field = Code::make('config_json')->json();

    expect($field->buildRules())->not->toContain('json');
});

// ---------------------------------------------------------------------------
// resolveUsing / fillUsing callbacks
// ---------------------------------------------------------------------------

it('Code respects resolveUsing callback', function () {
    $model = new CodeTestModel(['snippet' => 'original']);
    $field = Code::make('snippet')
        ->resolveUsing(fn ($value) => strtoupper($value));

    expect($field->resolve($model))->toBe('ORIGINAL');
});

it('Code respects fillUsing callback', function () {
    $model = new CodeTestModel;
    $captured = null;

    $field = Code::make('snippet')
        ->fillUsing(function ($m, $value, $attr) use (&$captured) {
            $captured = $value;
        });

    $field->fill($model, 'test code');

    expect($captured)->toBe('test code');
});
