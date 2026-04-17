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
