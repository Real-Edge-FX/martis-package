<?php

use Illuminate\Database\Eloquent\Model;
use Martis\Fields\KeyValue;

// ---------------------------------------------------------------------------
// Test model fixture
// ---------------------------------------------------------------------------

class KeyValueTestModel extends Model
{
    protected $table = 'users';

    protected $fillable = ['meta'];

    public $timestamps = false;
}

// ---------------------------------------------------------------------------
// Construction
// ---------------------------------------------------------------------------

it('KeyValue::make creates a key_value field', function () {
    $field = KeyValue::make('meta');

    expect($field->attribute())->toBe('meta')
        ->and($field->label())->toBe('Meta')
        ->and($field->type())->toBe('key_value');
});

it('KeyValue::make accepts custom label', function () {
    $field = KeyValue::make('meta', 'Custom Meta');

    expect($field->label())->toBe('Custom Meta');
});

it('KeyValue is hidden from index by default', function () {
    $field = KeyValue::make('meta');

    expect($field->isVisibleForContext('index'))->toBeFalse()
        ->and($field->isVisibleForContext('create'))->toBeTrue()
        ->and($field->isVisibleForContext('update'))->toBeTrue()
        ->and($field->isVisibleForContext('detail'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// API configuration
// ---------------------------------------------------------------------------

it('KeyValue keyLabel() sets key column label', function () {
    $field = KeyValue::make('meta')->keyLabel('Setting');

    expect($field->getKeyLabel())->toBe('Setting')
        ->and($field->toArray()['keyLabel'])->toBe('Setting');
});

it('KeyValue valueLabel() sets value column label', function () {
    $field = KeyValue::make('meta')->valueLabel('Content');

    expect($field->getValueLabel())->toBe('Content')
        ->and($field->toArray()['valueLabel'])->toBe('Content');
});

it('KeyValue actionText() sets add-row button label', function () {
    $field = KeyValue::make('meta')->actionText('Add Entry');

    expect($field->getActionText())->toBe('Add Entry')
        ->and($field->toArray()['actionText'])->toBe('Add Entry');
});

it('KeyValue disableEditingKeys() disables key editing', function () {
    $field = KeyValue::make('meta')->disableEditingKeys();

    expect($field->isEditingKeysDisabled())->toBeTrue()
        ->and($field->toArray()['editingKeysDisabled'])->toBeTrue();
});

it('KeyValue disableAddingRows() disables row addition', function () {
    $field = KeyValue::make('meta')->disableAddingRows();

    expect($field->isAddingRowsDisabled())->toBeTrue()
        ->and($field->toArray()['addingRowsDisabled'])->toBeTrue();
});

it('KeyValue defaults are correct', function () {
    $field = KeyValue::make('meta');

    expect($field->getKeyLabel())->toBe('Key')
        ->and($field->getValueLabel())->toBe('Value')
        ->and($field->getActionText())->toBe('Add Row')
        ->and($field->isEditingKeysDisabled())->toBeFalse()
        ->and($field->isAddingRowsDisabled())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Resolve (deserialization)
// ---------------------------------------------------------------------------

it('KeyValue resolves null as empty array', function () {
    $model = new KeyValueTestModel(['meta' => null]);
    $field = KeyValue::make('meta');

    expect($field->resolve($model))->toBe([]);
});

it('KeyValue resolves empty string as empty array', function () {
    $model = new KeyValueTestModel(['meta' => '']);
    $field = KeyValue::make('meta');

    expect($field->resolve($model))->toBe([]);
});

it('KeyValue resolves JSON string to rows', function () {
    $model = new KeyValueTestModel(['meta' => '{"color":"red","size":"large"}']);
    $field = KeyValue::make('meta');

    $result = $field->resolve($model);
    expect($result)->toHaveCount(2)
        ->and($result[0])->toBe(['key' => 'color', 'value' => 'red'])
        ->and($result[1])->toBe(['key' => 'size', 'value' => 'large']);
});

it('KeyValue resolves associative array to rows', function () {
    $model = new KeyValueTestModel(['meta' => ['foo' => 'bar', 'baz' => 'qux']]);
    $field = KeyValue::make('meta');

    $result = $field->resolve($model);
    expect($result)->toHaveCount(2)
        ->and($result[0])->toBe(['key' => 'foo', 'value' => 'bar']);
});

it('KeyValue resolves already-rows format unchanged', function () {
    $raw = [['key' => 'a', 'value' => 'b'], ['key' => 'c', 'value' => 'd']];
    $model = new KeyValueTestModel(['meta' => $raw]);
    $field = KeyValue::make('meta');

    $result = $field->resolve($model);
    expect($result)->toHaveCount(2)
        ->and($result[0])->toBe(['key' => 'a', 'value' => 'b']);
});

// ---------------------------------------------------------------------------
// Fill (serialization)
// ---------------------------------------------------------------------------

it('KeyValue fill() accepts rows and stores JSON', function () {
    $model = new KeyValueTestModel;
    $field = KeyValue::make('meta');

    $field->fill($model, [['key' => 'color', 'value' => 'blue']]);

    $stored = $model->getAttribute('meta');
    expect(json_decode($stored, true))->toBe(['color' => 'blue']);
});

it('KeyValue fill() accepts associative array', function () {
    $model = new KeyValueTestModel;
    $field = KeyValue::make('meta');

    $field->fill($model, ['env' => 'production', 'debug' => 'false']);

    $stored = $model->getAttribute('meta');
    expect(json_decode($stored, true))->toBe(['env' => 'production', 'debug' => 'false']);
});

it('KeyValue fill() stores null for empty array', function () {
    $model = new KeyValueTestModel;
    $field = KeyValue::make('meta');

    $field->fill($model, []);

    expect($model->getAttribute('meta'))->toBeNull();
});

it('KeyValue fill() stores null for null value', function () {
    $model = new KeyValueTestModel;
    $field = KeyValue::make('meta');

    $field->fill($model, null);

    expect($model->getAttribute('meta'))->toBeNull();
});

it('KeyValue fill() does nothing when readonly', function () {
    $model = new KeyValueTestModel(['meta' => '{"original":"value"}']);
    $field = KeyValue::make('meta')->readonly();

    $field->fill($model, [['key' => 'new', 'value' => 'data']]);

    expect($model->getAttribute('meta'))->toBe('{"original":"value"}');
});

it('KeyValue fill() accepts JSON string', function () {
    $model = new KeyValueTestModel;
    $field = KeyValue::make('meta');

    $field->fill($model, '{"x":"1","y":"2"}');

    $stored = $model->getAttribute('meta');
    expect(json_decode($stored, true))->toBe(['x' => '1', 'y' => '2']);
});

// ---------------------------------------------------------------------------
// toArray
// ---------------------------------------------------------------------------

it('KeyValue toArray contains all required keys', function () {
    $field = KeyValue::make('meta', 'Metadata')
        ->keyLabel('Setting')
        ->valueLabel('Value')
        ->actionText('Add')
        ->disableEditingKeys();

    $arr = $field->toArray();

    expect($arr)->toHaveKeys([
        'attribute', 'label', 'type', 'nullable', 'readonly', 'required',
        'keyLabel', 'valueLabel', 'actionText', 'editingKeysDisabled', 'addingRowsDisabled',
    ])
        ->and($arr['type'])->toBe('key_value')
        ->and($arr['keyLabel'])->toBe('Setting')
        ->and($arr['editingKeysDisabled'])->toBeTrue()
        ->and($arr['addingRowsDisabled'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// Resolve callback
// ---------------------------------------------------------------------------

it('KeyValue respects resolveUsing callback', function () {
    $model = new KeyValueTestModel(['meta' => '{"k":"v"}']);
    $field = KeyValue::make('meta')->resolveUsing(fn ($value, $model, $attr) => [['key' => 'overridden', 'value' => 'yes']]);

    expect($field->resolve($model))->toBe([['key' => 'overridden', 'value' => 'yes']]);
});

it('KeyValue respects fillUsing callback', function () {
    $model = new KeyValueTestModel;
    $captured = null;

    $field = KeyValue::make('meta')->fillUsing(function ($m, $value, $attr) use (&$captured) {
        $captured = $value;
    });

    $field->fill($model, [['key' => 'a', 'value' => 'b']]);

    expect($captured)->toBe([['key' => 'a', 'value' => 'b']]);
});
