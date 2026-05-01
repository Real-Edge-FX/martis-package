<?php

declare(strict_types=1);

use Martis\Fields\BelongsToMany;
use Martis\Fields\HasMany;
use Martis\Fields\HasOne;
use Martis\Fields\MorphMany;
use Martis\Fields\MorphOne;
use Martis\Fields\MorphToMany;

/**
 * v1.8.4 — relationship fields whose persistence requires the parent
 * record to already exist (its id is needed as a foreign key on the
 * child or as part of a pivot row) are hidden from the CREATE form by
 * default. The visual contract matches Nova / Filament: pickers only
 * appear once the parent has an id.
 *
 * The override path stays open via `->showOnCreating()` for the rare
 * case the consumer drains the picker into a custom afterSave hook.
 */
it('BelongsToMany is hidden on create by default', function () {
    $field = BelongsToMany::make('Permissions', 'permissions');

    expect($field->toArray()['showOnCreate'])->toBeFalse();
});

it('BelongsToMany shows on update by default', function () {
    $field = BelongsToMany::make('Permissions', 'permissions');

    // showOnUpdate stays at its default (null = inherit from showOnForms = true)
    expect($field->toArray()['showOnUpdate'])->not->toBeFalse();
});

it('BelongsToMany respects showOnCreating override', function () {
    $field = BelongsToMany::make('Permissions', 'permissions')->showOnCreating();

    expect($field->toArray()['showOnCreate'])->toBeTrue();
});

it('MorphToMany is hidden on create by default', function () {
    $field = MorphToMany::make('Tags', 'tags');

    expect($field->toArray()['showOnCreate'])->toBeFalse();
});

it('MorphToMany respects showOnCreating override', function () {
    $field = MorphToMany::make('Tags', 'tags')->showOnCreating();

    expect($field->toArray()['showOnCreate'])->toBeTrue();
});

it('HasMany stays detail-only', function () {
    $field = HasMany::make('Posts', 'posts');
    $arr = $field->toArray();

    // hideFromForms() flips showOnForms; the controller treats false
    // there as "skip on create AND update", which is the canonical
    // detail-only contract.
    expect($arr['showOnForms'])->toBeFalse();
    expect($arr['showOnIndex'])->toBeFalse();
});

it('HasOne stays detail-only', function () {
    $field = HasOne::make('Profile', 'profile');
    $arr = $field->toArray();

    expect($arr['showOnForms'])->toBeFalse();
    expect($arr['showOnIndex'])->toBeFalse();
});

it('MorphMany stays detail-only', function () {
    $field = MorphMany::make('Comments', 'comments');

    expect($field->toArray()['showOnCreate'])->toBeFalse();
    expect($field->toArray()['showOnUpdate'])->toBeFalse();
});

it('MorphOne stays detail-only', function () {
    $field = MorphOne::make('Image', 'image');

    expect($field->toArray()['showOnCreate'])->toBeFalse();
    expect($field->toArray()['showOnUpdate'])->toBeFalse();
});
