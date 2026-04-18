<?php

use Illuminate\Database\Eloquent\Model;
use Martis\Fields\BooleanGroup;

class BoolGroupTestModel extends Model
{
    protected $table = 'users';

    protected $fillable = ['permissions'];

    protected $casts = ['permissions' => 'array'];

    public $timestamps = false;
}

it('BooleanGroup::make creates a field with the right type', function () {
    $field = BooleanGroup::make('permissions');

    expect($field->type())->toBe('boolean_group')
        ->and($field->attribute())->toBe('permissions');
});

it('BooleanGroup options() registers the flag map', function () {
    $field = BooleanGroup::make('permissions')->options([
        'view' => 'View',
        'edit' => 'Edit',
    ]);

    expect($field->getOptions())->toBe(['view' => 'View', 'edit' => 'Edit'])
        ->and($field->toArray()['options'])->toBe(['view' => 'View', 'edit' => 'Edit']);
});

it('BooleanGroup labels() serialises the translated labels', function () {
    $field = BooleanGroup::make('permissions')
        ->options(['view' => 'View', 'edit' => 'Edit'])
        ->labels(['view' => 'Ver', 'edit' => 'Editar']);

    expect($field->toArray()['labels'])->toBe(['view' => 'Ver', 'edit' => 'Editar']);
});

it('BooleanGroup resolve normalises stored JSON into a bool map', function () {
    $model = new BoolGroupTestModel(['permissions' => ['view' => true, 'edit' => false]]);
    $field = BooleanGroup::make('permissions')->options([
        'view' => 'View',
        'edit' => 'Edit',
        'delete' => 'Delete',
    ]);

    expect($field->resolve($model))->toBe([
        'view' => true,
        'edit' => false,
        'delete' => false,
    ]);
});

it('BooleanGroup resolve returns all-false payload when the model attribute is null', function () {
    $model = new BoolGroupTestModel;
    $field = BooleanGroup::make('permissions')->options(['view' => 'V', 'edit' => 'E']);

    expect($field->resolve($model))->toBe(['view' => false, 'edit' => false]);
});

// ⭐ differentials

it('BooleanGroup grouped() registers the section map', function () {
    $field = BooleanGroup::make('permissions')
        ->options(['view' => 'V', 'edit' => 'E', 'delete' => 'D', 'billing' => 'B'])
        ->grouped([
            'Clients' => ['view', 'edit', 'delete'],
            'Billing' => ['billing'],
        ]);

    expect($field->getGroups())->toBe([
        'Clients' => ['view', 'edit', 'delete'],
        'Billing' => ['billing'],
    ])->and($field->toArray()['groups'])->toHaveKey('Clients');
});

it('BooleanGroup minChecked / maxChecked are exposed in the schema', function () {
    $field = BooleanGroup::make('permissions')
        ->options(['view' => 'V', 'edit' => 'E'])
        ->minChecked(1)
        ->maxChecked(1);

    expect($field->getMinChecked())->toBe(1)
        ->and($field->getMaxChecked())->toBe(1)
        ->and($field->toArray()['minChecked'])->toBe(1)
        ->and($field->toArray()['maxChecked'])->toBe(1);
});

it('BooleanGroup requireAny() compiles to minChecked(1)', function () {
    $field = BooleanGroup::make('permissions')
        ->options(['a' => 'A', 'b' => 'B'])
        ->requireAny();

    expect($field->getMinChecked())->toBe(1);
});

it('BooleanGroup requireAll() compiles to minChecked(count(options))', function () {
    $field = BooleanGroup::make('permissions')
        ->options(['a' => 'A', 'b' => 'B', 'c' => 'C'])
        ->requireAll();

    expect($field->getMinChecked())->toBe(3);
});
