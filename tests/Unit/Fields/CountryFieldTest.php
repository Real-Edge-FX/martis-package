<?php

use Illuminate\Database\Eloquent\Model;
use Martis\Fields\Country;

class CountryTestModel extends Model
{
    protected $table = 'users';

    protected $fillable = ['country'];

    public $timestamps = false;
}

it('Country::make creates a country field', function () {
    $field = Country::make('country');

    expect($field->attribute())->toBe('country')
        ->and($field->label())->toBe('Country')
        ->and($field->type())->toBe('country');
});

it('Country::make accepts custom label', function () {
    $field = Country::make('country', 'Home Country');
    expect($field->label())->toBe('Home Country');
});

it('Country shows on all contexts by default', function () {
    $field = Country::make('country');

    expect($field->isVisibleForContext('index'))->toBeTrue()
        ->and($field->isVisibleForContext('detail'))->toBeTrue()
        ->and($field->isVisibleForContext('create'))->toBeTrue()
        ->and($field->isVisibleForContext('update'))->toBeTrue();
});

it('Country flags are disabled by default', function () {
    $field = Country::make('country');
    expect($field->hasFlags())->toBeFalse();
});

it('Country withFlags() enables flag display', function () {
    $field = Country::make('country')->withFlags();
    expect($field->hasFlags())->toBeTrue();
});

it('Country withoutFlags() disables flag display', function () {
    $field = Country::make('country')->withFlags()->withoutFlags();
    expect($field->hasFlags())->toBeFalse();
});

it('Country countryList returns a non-empty array of valid countries', function () {
    $list = Country::countryList();
    expect($list)->toBeArray()
        ->and(count($list))->toBeGreaterThan(190);

    $first = $list[0];
    expect($first)->toHaveKeys(['label', 'value', 'flag'])
        ->and(strlen($first['value']))->toBe(2);
});

it('Country resolveCountryName resolves code to name', function () {
    expect(Country::resolveCountryName('US'))->toBe('United States')
        ->and(Country::resolveCountryName('BR'))->toBe('Brazil')
        ->and(Country::resolveCountryName('PT'))->toBe('Portugal')
        ->and(Country::resolveCountryName('XX'))->toBeNull();
});

it('Country resolveCountryFlag resolves code to flag emoji', function () {
    expect(Country::resolveCountryFlag('BR'))->not->toBeNull()
        ->and(Country::resolveCountryFlag('XX'))->toBeNull();
});

it('Country resolves value from model', function () {
    $model = new CountryTestModel(['country' => 'BR']);
    $field = Country::make('country');
    expect($field->resolve($model))->toBe('BR');
});

it('Country fills value to model', function () {
    $model = new CountryTestModel;
    $field = Country::make('country');
    $field->fill($model, 'PT');
    expect($model->getAttribute('country'))->toBe('PT');
});

it('Country toArray contains countries and showFlags', function () {
    $field = Country::make('country')->withFlags();
    $arr = $field->toArray();

    expect($arr)->toHaveKeys(['attribute', 'label', 'type', 'countries', 'showFlags'])
        ->and($arr['type'])->toBe('country')
        ->and($arr['showFlags'])->toBeTrue()
        ->and($arr['countries'])->toBeArray();
});
