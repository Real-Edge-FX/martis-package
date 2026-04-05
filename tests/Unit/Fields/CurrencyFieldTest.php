<?php

use Illuminate\Database\Eloquent\Model;
use Martis\Fields\Currency;

class CurrencyTestModel extends Model
{
    protected $table = 'users';

    protected $fillable = ['price'];

    public $timestamps = false;
}

it('Currency::make creates a currency field', function () {
    $field = Currency::make('price');
    expect($field->attribute())->toBe('price')
        ->and($field->label())->toBe('Price')
        ->and($field->type())->toBe('currency');
});

it('Currency defaults to USD', function () {
    $field = Currency::make('price');
    expect($field->getCurrencyCode())->toBe('USD');
});

it('Currency currency() sets the code', function () {
    $field = Currency::make('price')->currency('BRL');
    expect($field->getCurrencyCode())->toBe('BRL');
});

it('Currency currency() is case-insensitive', function () {
    $field = Currency::make('price')->currency('eur');
    expect($field->getCurrencyCode())->toBe('EUR');
});

it('Currency locale() sets locale', function () {
    $field = Currency::make('price')->locale('fr');
    expect($field->getLocale())->toBe('fr');
});

it('Currency asMinorUnits() enables minor units', function () {
    $field = Currency::make('price')->asMinorUnits();
    expect($field->isMinorUnits())->toBeTrue();
});

it('Currency asMajorUnits() disables minor units', function () {
    $field = Currency::make('price')->asMinorUnits()->asMajorUnits();
    expect($field->isMinorUnits())->toBeFalse();
});

it('Currency inherits Number min/max/step', function () {
    $field = Currency::make('price')->min(0)->max(1000)->step(0.01);
    $arr = $field->toArray();
    expect($arr['min'])->toBe(0)
        ->and($arr['max'])->toBe(1000)
        ->and($arr['step'])->toBe(0.01);
});

it('Currency displayMode defaults to text', function () {
    $field = Currency::make('price');
    expect($field->getDisplayMode())->toBe('text');
});

it('Currency showBadge() sets badge mode', function () {
    $field = Currency::make('price')->showBadge();
    expect($field->getDisplayMode())->toBe('badge');
});

it('Currency showBadgeText() sets badge_text mode', function () {
    $field = Currency::make('price')->showBadgeText();
    expect($field->getDisplayMode())->toBe('badge_text');
});

it('Currency badgeColor() sets the color', function () {
    $field = Currency::make('price')->badgeColor('green');
    expect($field->getBadgeColor())->toBe('green');
});

it('Currency getCurrencyInfo returns correct info for known currencies', function () {
    $field = Currency::make('price')->currency('BRL');
    $info = $field->getCurrencyInfo();
    expect($info['symbol'])->toBe('R$')
        ->and($info['name'])->toBe('Real brasileiro')
        ->and($info['decimals'])->toBe(2);
});

it('Currency getCurrencyInfo returns fallback for unknown currency', function () {
    $field = Currency::make('price')->currency('XYZ');
    $info = $field->getCurrencyInfo();
    expect($info['symbol'])->toBe('XYZ')
        ->and($info['name'])->toBe('XYZ');
});

it('Currency resolves value from model', function () {
    $model = new CurrencyTestModel(['price' => 99.99]);
    $field = Currency::make('price');
    expect($field->resolve($model))->toBe(99.99);
});

it('Currency fills value to model', function () {
    $model = new CurrencyTestModel;
    $field = Currency::make('price');
    $field->fill($model, 42.50);
    expect($model->getAttribute('price'))->toBe(42.50);
});

it('Currency toArray contains currency attributes', function () {
    $field = Currency::make('price')->currency('EUR')->showBadgeText()->badgeColor('blue');
    $arr = $field->toArray();

    expect($arr)->toHaveKeys(['attribute', 'type', 'currencyCode', 'currencySymbol', 'currencyName', 'displayMode', 'badgeColor'])
        ->and($arr['type'])->toBe('currency')
        ->and($arr['currencyCode'])->toBe('EUR')
        ->and($arr['currencySymbol'])->toBe("\xe2\x82\xac")
        ->and($arr['displayMode'])->toBe('badge_text')
        ->and($arr['badgeColor'])->toBe('blue');
});
