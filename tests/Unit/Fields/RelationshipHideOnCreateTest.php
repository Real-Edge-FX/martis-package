<?php

declare(strict_types=1);

use Martis\FieldContext;
use Martis\Fields\BelongsToMany;
use Martis\Fields\HasMany;
use Martis\Fields\HasOne;
use Martis\Fields\MorphMany;
use Martis\Fields\MorphOne;
use Martis\Fields\MorphToMany;

/**
 * Relationship fields whose persistence requires the parent record to
 * already exist (its id is needed as a foreign key on the child or as
 * part of a pivot row) stay off the CREATE form: pickers only appear once
 * the parent has an id.
 *
 * v1.38.0 — BelongsToMany and MorphToMany follow Nova, which drops its
 * `ListableField`s from every creation form: no visibility call brings
 * them onto a create form (the create page, the create drawer, the
 * inline-create modal), while the update form keeps them.
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

it('MorphToMany is hidden on create by default', function () {
    $field = MorphToMany::make('Tags', 'tags');

    expect($field->toArray()['showOnCreate'])->toBeFalse();
});

dataset('many-to-many fields made visible on create', [
    'BelongsToMany showOnCreating()' => fn () => BelongsToMany::make('Permissions', 'permissions')->showOnCreating(),
    'BelongsToMany showOnForms()' => fn () => BelongsToMany::make('Permissions', 'permissions')->showOnForms(),
    'BelongsToMany onlyOnForms()' => fn () => BelongsToMany::make('Permissions', 'permissions')->onlyOnForms(),
    'MorphToMany showOnCreating()' => fn () => MorphToMany::make('Tags', 'tags')->showOnCreating(),
    'MorphToMany showOnForms()' => fn () => MorphToMany::make('Tags', 'tags')->showOnForms(),
    'MorphToMany onlyOnForms()' => fn () => MorphToMany::make('Tags', 'tags')->onlyOnForms(),
]);

it('keeps a many-to-many field off every create form, like Nova', function (BelongsToMany|MorphToMany $field) {
    expect($field->isVisibleForContext(FieldContext::CREATE))->toBeFalse()
        ->and($field->isVisibleForContext(FieldContext::INLINE_CREATE))->toBeFalse()
        ->and($field->toArray()['showOnCreate'])->toBeFalse();
})->with('many-to-many fields made visible on create');

it('still shows a many-to-many field on the update form', function (BelongsToMany|MorphToMany $field) {
    expect($field->isVisibleForContext(FieldContext::UPDATE))->toBeTrue();
})->with('many-to-many fields made visible on create');

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
