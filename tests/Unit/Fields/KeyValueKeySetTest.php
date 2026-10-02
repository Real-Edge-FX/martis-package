<?php

use Illuminate\Database\Eloquent\Model;
use Martis\Fields\KeyValue;

// ---------------------------------------------------------------------------
// disableEditingKeys(), disableAddingRows() and disableDeletingRows() shape
// the form, but a request does not have to go through the form: fill()
// enforces them (F107). The key set a user may rely on is the stored map's
// for a record that exists, the field's default() keys for a new one.
// ---------------------------------------------------------------------------

class KeyValueKeySetModel extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['meta' => 'array'];
}

class KeyValueKeySetUncastModel extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

/** A record that exists with a stored map. */
function storedKeyValueModel(array $meta): KeyValueKeySetModel
{
    $model = new KeyValueKeySetModel(['meta' => $meta]);
    $model->exists = true;

    return $model;
}

function fixedKeyField(): KeyValue
{
    return KeyValue::make('meta')->disableEditingKeys()->disableAddingRows()->disableDeletingRows();
}

// ---- update: the stored keys are the set ----------------------------------

it('drops a new key while rows cannot be added', function () {
    $model = storedKeyValueModel(['a' => '1', 'b' => '2']);

    KeyValue::make('meta')->disableAddingRows()->fill($model, ['a' => 'x', 'b' => 'y', 'z' => 'new']);

    expect($model->getAttribute('meta'))->toBe(['a' => 'x', 'b' => 'y']);
});

it('drops a renamed key while keys cannot be edited', function () {
    $model = storedKeyValueModel(['a' => '1', 'b' => '2']);

    // `b` renamed to `b2`: the new name is dropped, and as rows can still be
    // deleted the old key goes with it.
    KeyValue::make('meta')->disableEditingKeys()->fill($model, ['a' => '1', 'b2' => '2']);

    expect($model->getAttribute('meta'))->toBe(['a' => '1']);
});

it('keeps the stored key a write leaves out while rows cannot be deleted', function () {
    $model = storedKeyValueModel(['a' => '1', 'b' => '2']);

    KeyValue::make('meta')->disableDeletingRows()->fill($model, ['a' => 'x']);

    expect($model->getAttribute('meta'))->toBe(['a' => 'x', 'b' => '2']);
});

it('still deletes a row when only adding and editing are disabled', function () {
    $model = storedKeyValueModel(['a' => '1', 'b' => '2']);

    KeyValue::make('meta')->disableEditingKeys()->disableAddingRows()->fill($model, ['a' => 'x']);

    expect($model->getAttribute('meta'))->toBe(['a' => 'x']);
});

it('still adds a row when only deleting is disabled', function () {
    $model = storedKeyValueModel(['a' => '1']);

    KeyValue::make('meta')->disableDeletingRows()->fill($model, ['a' => '1', 'z' => 'new']);

    expect($model->getAttribute('meta'))->toBe(['a' => '1', 'z' => 'new']);
});

it('holds a fixed set to its stored keys and takes only their values', function () {
    $model = storedKeyValueModel(['a' => '1', 'b' => '2']);

    fixedKeyField()->fill($model, ['b' => 'y', 'z' => 'new', 'c' => 'also new']);

    // `a` is back with its stored value, `b` took the new value, the new
    // keys are dropped, and the stored order is kept.
    expect($model->getAttribute('meta'))->toBe(['a' => '1', 'b' => 'y']);
});

it('reads the submitted rows of the form as well as a map', function () {
    $model = storedKeyValueModel(['a' => '1', 'b' => '2']);

    fixedKeyField()->fill($model, [['key' => 'a', 'value' => 'x'], ['key' => 'z', 'value' => 'new']]);

    expect($model->getAttribute('meta'))->toBe(['a' => 'x', 'b' => '2']);
});

it('reads the submitted map from a JSON string, as the multipart path sends it', function () {
    $model = storedKeyValueModel(['a' => '1', 'b' => '2']);

    fixedKeyField()->fill($model, '{"a":"x","z":"new"}');

    expect($model->getAttribute('meta'))->toBe(['a' => 'x', 'b' => '2']);
});

it('restores a fixed set that an empty value clears', function () {
    $model = storedKeyValueModel(['a' => '1', 'b' => '2']);

    fixedKeyField()->fill($model, null);

    expect($model->getAttribute('meta'))->toBe(['a' => '1', 'b' => '2']);
});

it('clears an emptied map when no flag fixes its keys', function () {
    $model = storedKeyValueModel(['a' => '1', 'b' => '2']);

    KeyValue::make('meta')->fill($model, null);

    expect($model->getAttribute('meta'))->toBeNull();
});

it('reads the stored keys of an uncast JSON column', function () {
    $model = new KeyValueKeySetUncastModel(['meta' => '{"a":"1","b":"2"}']);
    $model->exists = true;

    fixedKeyField()->fill($model, ['a' => 'x', 'z' => 'new']);

    expect(json_decode((string) $model->getAttribute('meta'), true))->toBe(['a' => 'x', 'b' => '2']);
});

it('keeps a nested stored value a restored row held', function () {
    $model = storedKeyValueModel(['a' => '1', 'tags' => ['x', 'y']]);

    fixedKeyField()->fill($model, ['a' => 'x']);

    expect($model->getAttribute('meta'))->toBe(['a' => 'x', 'tags' => ['x', 'y']]);
});

it('leaves a field without flags as it was: any key is stored', function () {
    $model = storedKeyValueModel(['a' => '1']);

    KeyValue::make('meta')->fill($model, ['z' => 'new', 'y' => 'also']);

    expect($model->getAttribute('meta'))->toBe(['z' => 'new', 'y' => 'also']);
});

// ---- create: the default keys are the set ---------------------------------

it('holds a new record to the default keys', function () {
    $model = new KeyValueKeySetModel;

    fixedKeyField()
        ->default(['mon' => '9-17', 'tue' => '9-17'])
        ->fill($model, ['mon' => '10-16', 'sun' => 'open']);

    expect($model->getAttribute('meta'))->toBe(['mon' => '10-16', 'tue' => '9-17']);
});

it('reads the default keys of a new record from a closure and from rows', function () {
    $model = new KeyValueKeySetModel;

    fixedKeyField()
        ->default(fn () => [['key' => 'mon', 'value' => '9-17'], ['key' => 'tue', 'value' => '9-17']])
        ->fill($model, ['tue' => 'closed']);

    expect($model->getAttribute('meta'))->toBe(['mon' => '9-17', 'tue' => 'closed']);
});

it('stores nothing for a new record with no default keys while rows cannot be added', function () {
    $model = new KeyValueKeySetModel;

    KeyValue::make('meta')->disableAddingRows()->fill($model, ['z' => 'new']);

    expect($model->getAttribute('meta'))->toBeNull();
});

it('lets a new record add any key while only deleting is disabled', function () {
    $model = new KeyValueKeySetModel;

    KeyValue::make('meta')->disableDeletingRows()->default(['mon' => '9-17'])->fill($model, ['z' => 'new']);

    expect($model->getAttribute('meta'))->toBe(['mon' => '9-17', 'z' => 'new']);
});

it('writes nothing while a readonly closure holds the field locked', function () {
    $model = storedKeyValueModel(['a' => '1']);

    fixedKeyField()->readonly(fn () => true)->fill($model, ['a' => 'x']);

    expect($model->getAttribute('meta'))->toBe(['a' => '1']);
});
