<?php

use Illuminate\Database\Eloquent\Model;
use Martis\Fields\BooleanGroup;

// ---------------------------------------------------------------------------
// BooleanGroup::fill() stores the flags the field offers and nothing else
// (F010). The offered set is the developer's way of limiting which flags a
// user may set (an options() closure can scope it per user), so a submitted
// map is projected onto getOptions(): unknown keys are ignored, values become
// booleans, and the stored flags the user was not offered stay as they are.
// ---------------------------------------------------------------------------

class BooleanGroupWriteModel extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['permissions' => 'array'];
}

class BooleanGroupWriteUncastModel extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

it('ignores submitted keys the options do not offer', function () {
    $model = new BooleanGroupWriteModel;
    $field = BooleanGroup::make('permissions')->options(['view' => 'View', 'edit' => 'Edit']);

    $field->fill($model, ['view' => true, 'edit' => false, 'admin' => true]);

    expect($model->getAttribute('permissions'))->toBe(['view' => true, 'edit' => false]);
});

it('casts each offered value to a boolean', function () {
    $model = new BooleanGroupWriteModel;
    $field = BooleanGroup::make('permissions')->options(['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D', 'e' => 'E']);

    $field->fill($model, ['a' => 1, 'b' => 'false', 'c' => '1', 'd' => 'yes', 'e' => ['nested' => true]]);

    expect($model->getAttribute('permissions'))->toBe(['a' => true, 'b' => false, 'c' => true, 'd' => true, 'e' => false]);
});

it('stores an offered flag the submission leaves out as off', function () {
    $model = new BooleanGroupWriteModel;
    $field = BooleanGroup::make('permissions')->options(['view' => 'View', 'edit' => 'Edit']);

    $field->fill($model, ['view' => true]);

    expect($model->getAttribute('permissions'))->toBe(['view' => true, 'edit' => false]);
});

it('keeps the stored flags the user was not offered', function () {
    $model = new BooleanGroupWriteModel(['permissions' => ['view' => false, 'edit' => false, 'admin' => true]]);
    $field = BooleanGroup::make('permissions')->options(fn () => ['view' => 'View', 'edit' => 'Edit']);

    $field->fill($model, ['view' => true, 'edit' => true, 'admin' => false]);

    // `admin` was not offered: the editor can neither flip it nor erase it.
    expect($model->getAttribute('permissions'))->toBe(['view' => true, 'edit' => true, 'admin' => true]);
});

it('keeps the stored flags when the column is a JSON string', function () {
    $model = new BooleanGroupWriteUncastModel(['permissions' => '{"view":false,"admin":true}']);
    $field = BooleanGroup::make('permissions')->options(['view' => 'View']);

    $field->fill($model, ['view' => true, 'admin' => false]);

    expect(json_decode((string) $model->getAttribute('permissions'), true))->toBe(['view' => true, 'admin' => true]);
});

it('reads the submitted map from a JSON string, as the multipart path sends it', function () {
    $model = new BooleanGroupWriteModel;
    $field = BooleanGroup::make('permissions')->options(['view' => 'View']);

    $field->fill($model, '{"view":true,"admin":true}');

    expect($model->getAttribute('permissions'))->toBe(['view' => true]);
});

it('stores nothing for an offered set that a closure resolves to no flags', function () {
    $model = new BooleanGroupWriteModel(['permissions' => ['admin' => true]]);
    $field = BooleanGroup::make('permissions')->options(fn () => []);

    $field->fill($model, ['admin' => false, 'other' => true]);

    expect($model->getAttribute('permissions'))->toBe(['admin' => true]);
});

it('clears an emptied field to null when no flag is left to keep', function () {
    $model = new BooleanGroupWriteModel(['permissions' => ['view' => true]]);
    $field = BooleanGroup::make('permissions')->options(['view' => 'View']);

    $field->fill($model, null);

    expect($model->getAttribute('permissions'))->toBeNull();
});

it('clears only the offered flags when an emptied field has stored flags the user was not offered', function () {
    $model = new BooleanGroupWriteModel(['permissions' => ['view' => true, 'admin' => true]]);
    $field = BooleanGroup::make('permissions')->options(['view' => 'View']);

    $field->fill($model, '');

    expect($model->getAttribute('permissions'))->toBe(['admin' => true]);
});

it('hands a fillUsing() callback the offered flags only', function () {
    $model = new BooleanGroupWriteModel;
    $received = null;
    $field = BooleanGroup::make('permissions')
        ->options(['view' => 'View'])
        ->fillUsing(function (Model $model, mixed $value) use (&$received): void {
            $received = $value;
        });

    $field->fill($model, ['view' => true, 'admin' => true]);

    expect($received)->toBe(['view' => true])
        ->and($model->getAttributes())->not->toHaveKey('permissions');
});

it('writes nothing while a readonly closure holds the field locked', function () {
    $model = new BooleanGroupWriteModel(['permissions' => ['view' => false]]);
    $field = BooleanGroup::make('permissions')->options(['view' => 'View'])->readonly(fn () => true);

    $field->fill($model, ['view' => true]);

    expect($model->getAttribute('permissions'))->toBe(['view' => false]);
});
