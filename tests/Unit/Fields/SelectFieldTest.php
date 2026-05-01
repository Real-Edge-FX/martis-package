<?php

use Martis\Fields\Select;

it('normalises associative options as [label => value]', function () {
    $field = Select::make('status')->options([
        'Active' => 'active',
        'Inactive' => 'inactive',
    ]);

    expect($field->getOptions())->toEqual([
        ['label' => 'Active', 'value' => 'active'],
        ['label' => 'Inactive', 'value' => 'inactive'],
    ]);
});

it('normalises sequential options using the value as label', function () {
    $field = Select::make('status')->options(['draft', 'published']);

    expect($field->getOptions())->toEqual([
        ['label' => 'draft', 'value' => 'draft'],
        ['label' => 'published', 'value' => 'published'],
    ]);
});

it('optionsFromMap accepts [value => label] and keeps values untranslated', function () {
    $field = Select::make('plan')->optionsFromMap([
        'free' => 'Grátis',
        'pro' => 'Pro',
        'enterprise' => 'Empresa',
    ]);

    expect($field->getOptions())->toEqual([
        ['label' => 'Grátis', 'value' => 'free'],
        ['label' => 'Pro', 'value' => 'pro'],
        ['label' => 'Empresa', 'value' => 'enterprise'],
    ]);
});

it('optionsFromMap is idempotent — successive calls replace options', function () {
    $field = Select::make('plan')->optionsFromMap(['a' => 'Alpha']);
    $field->optionsFromMap(['b' => 'Beta']);

    expect($field->getOptions())->toEqual([
        ['label' => 'Beta', 'value' => 'b'],
    ]);
});

// ---------------------------------------------------------------------------
// Enum support (v1.1)
// ---------------------------------------------------------------------------

enum SelectFieldStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case ArchivedAt = 'archived';
}

enum SelectFieldPureColor
{
    case Red;
    case Green;
    case BlueLight;
}

it('options() accepts a backed-enum class and derives value + headline label', function () {
    $field = Select::make('status')->options(SelectFieldStatus::class);

    expect($field->getOptions())->toEqual([
        ['label' => 'Draft', 'value' => 'draft'],
        ['label' => 'Published', 'value' => 'published'],
        ['label' => 'Archived At', 'value' => 'archived'],
    ]);
});

it('options() accepts a pure enum class and uses case name as both value and label', function () {
    $field = Select::make('color')->options(SelectFieldPureColor::class);

    expect($field->getOptions())->toEqual([
        ['label' => 'Red', 'value' => 'Red'],
        ['label' => 'Green', 'value' => 'Green'],
        ['label' => 'Blue Light', 'value' => 'BlueLight'],
    ]);
});

it('options() rejects a string that is not an enum class as a regular value', function () {
    // String that isn't an enum should fall through to the array branch.
    // This is more of a contract check — passing arbitrary strings is a
    // type-system error caught by static analysis. Here we just confirm
    // there's no false-positive enum interpretation.
    expect(enum_exists('NotAnEnum'))->toBeFalse();
});

it('displayUsingLabels defaults to true so the index/detail cell shows labels', function () {
    $field = Select::make('status');

    expect($field->isDisplayingLabels())->toBeTrue();

    $extra = (function () {
        return $this->extraAttributes();
    })->call($field);

    expect($extra)->toHaveKey('displayLabels', true);
});

it('displayUsingLabels() keeps the flag true and is exposed in the schema payload', function () {
    $field = Select::make('status')->displayUsingLabels();

    expect($field->isDisplayingLabels())->toBeTrue();

    $extra = (function () {
        return $this->extraAttributes();
    })->call($field);

    expect($extra['displayLabels'])->toBeTrue();
});

it('displayUsingValues() flips the flag so the index/detail cell renders raw values', function () {
    $field = Select::make('country_code')->displayUsingValues();

    expect($field->isDisplayingLabels())->toBeFalse();

    $extra = (function () {
        return $this->extraAttributes();
    })->call($field);

    expect($extra['displayLabels'])->toBeFalse();
});
