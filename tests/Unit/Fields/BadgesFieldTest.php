<?php

use Illuminate\Database\Eloquent\Model;
use Martis\FieldContext;
use Martis\Fields\Badges;

class BadgesFieldTestModel extends Model
{
    protected $table = 'users';

    protected $fillable = ['name'];

    public $timestamps = false;
}

it('Badges has the badges type and is hidden from forms by default', function () {
    $field = Badges::make('languages');

    expect($field->type())->toBe('badges')
        ->and($field->isVisibleForContext(FieldContext::INDEX))->toBeTrue()
        ->and($field->isVisibleForContext(FieldContext::DETAIL))->toBeTrue()
        ->and($field->isVisibleForContext(FieldContext::CREATE))->toBeFalse()
        ->and($field->isVisibleForContext(FieldContext::UPDATE))->toBeFalse();
});

it('Badges serialises items with label, type and tooltip', function () {
    $field = Badges::make('languages')->resolveUsing(fn () => [
        ['label' => 'EN', 'type' => 'success'],
        ['label' => 'Missing PT', 'type' => 'warning', 'tooltip' => 'No translation'],
    ]);

    expect($field->resolveForDisplay(new BadgesFieldTestModel))->toBe([
        ['label' => 'EN', 'type' => 'success', 'tooltip' => null],
        ['label' => 'Missing PT', 'type' => 'warning', 'tooltip' => 'No translation'],
    ]);
});

it('Badges accepts plain string items', function () {
    $field = Badges::make('languages')->resolveUsing(fn () => ['EN', 'PT']);

    expect($field->resolveForDisplay(new BadgesFieldTestModel))->toBe([
        ['label' => 'EN', 'type' => null, 'tooltip' => null],
        ['label' => 'PT', 'type' => null, 'tooltip' => null],
    ]);
});

it('Badges resolves null and empty values to an empty list', function () {
    expect(Badges::make('languages')->resolveUsing(fn () => null)->resolveForDisplay(new BadgesFieldTestModel))->toBe([])
        ->and(Badges::make('languages')->resolveUsing(fn () => [])->resolveForDisplay(new BadgesFieldTestModel))->toBe([]);
});

it('Badges reuses the Badge type and map options in its schema', function () {
    $field = Badges::make('languages')
        ->addTypes(['brand' => '#ff0000'])
        ->map(['ok' => 'success']);

    $schema = $field->toArray();

    expect($schema['type'])->toBe('badges')
        ->and($schema['types'])->toHaveKeys(['info', 'success', 'warning', 'danger', 'brand'])
        ->and($schema['map'])->toBe(['ok' => 'success']);
});

it('Badges throws naming the attribute on an invalid item', function (mixed $item) {
    $field = Badges::make('languages')->resolveUsing(fn () => [$item]);

    expect(fn () => $field->resolveForDisplay(new BadgesFieldTestModel))
        ->toThrow(InvalidArgumentException::class, '[languages]');
})->with([
    'integer' => [5],
    'array without label' => [['type' => 'success']],
    'non-string label' => [['label' => 12]],
    'non-string type' => [['label' => 'EN', 'type' => ['x']]],
]);

it('Badges throws when the value is not a list', function () {
    $field = Badges::make('languages')->resolveUsing(fn () => 'EN');

    expect(fn () => $field->resolveForDisplay(new BadgesFieldTestModel))
        ->toThrow(InvalidArgumentException::class, '[languages]');
});
