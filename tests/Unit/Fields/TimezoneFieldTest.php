<?php

use Illuminate\Database\Eloquent\Model;
use Martis\Fields\Timezone;

class TimezoneTestModel extends Model
{
    protected $table = 'users';

    protected $fillable = ['timezone'];

    public $timestamps = false;
}

it('Timezone::make creates a timezone field', function () {
    $field = Timezone::make('timezone');

    expect($field->attribute())->toBe('timezone')
        ->and($field->type())->toBe('timezone');
});

it('Timezone::groupedList groups by continent and sorts alphabetically', function () {
    $grouped = Timezone::groupedList();

    expect($grouped)->toBeArray()
        // Known groups that PHP always ships.
        ->toHaveKey('Europe')
        ->toHaveKey('America')
        ->toHaveKey('Africa')
        ->toHaveKey('Asia');

    // Group keys are sorted.
    $keys = array_keys($grouped);
    $sorted = $keys;
    sort($sorted);
    expect($keys)->toBe($sorted);

    // Each bucket is a sorted list of zone identifiers.
    expect($grouped['Europe'])->toBeArray()
        ->and(in_array('Europe/Lisbon', $grouped['Europe'], true))->toBeTrue();
});

it('Timezone::toArray emits grouped options for the frontend', function () {
    $field = Timezone::make('timezone');
    $arr = $field->toArray();

    expect($arr)->toHaveKey('type', 'timezone')
        ->toHaveKey('options');
    expect($arr['options'])->toBeArray()
        ->and($arr['options'])->toHaveKey('Europe');
});

it('Timezone::fill persists the chosen zone', function () {
    $model = new TimezoneTestModel;
    $field = Timezone::make('timezone');

    $field->fill($model, 'Europe/Lisbon');

    expect($model->getAttribute('timezone'))->toBe('Europe/Lisbon');
});

it('Timezone::fill does nothing when readonly', function () {
    $model = new TimezoneTestModel(['timezone' => 'Europe/Madrid']);
    $field = Timezone::make('timezone')->readonly();

    $field->fill($model, 'America/New_York');

    expect($model->getAttribute('timezone'))->toBe('Europe/Madrid');
});

it('Timezone::resolve returns the stored zone', function () {
    $model = new TimezoneTestModel(['timezone' => 'Asia/Tokyo']);
    $field = Timezone::make('timezone');

    expect($field->resolve($model))->toBe('Asia/Tokyo');
});
