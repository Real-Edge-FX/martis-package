<?php

declare(strict_types=1);

use Martis\Fields\Text;
use Martis\Layout\Panel;
use Martis\Layout\Tab;
use Martis\Layout\TabGroup;

/*
 * The field grids read `colSpan` / `colSpanMd` / `colSpanLg` as a mobile-first
 * cascade (lib/fieldGridSpan.ts): a null tier inherits the one below it. This
 * pins the serialised contract the cascade relies on: the base span defaults
 * to the full row, the two responsive tiers stay null until declared, and
 * every tier is clamped to the 12-column grid. Panel and Tab bodies carry the
 * same values as Section bodies.
 */

it('serialises a full-row base span and no responsive tiers until they are declared', function () {
    $field = Text::make('name')->toArray();

    expect($field['colSpan'])->toBe(12)
        ->and($field['colSpanMd'])->toBeNull()
        ->and($field['colSpanLg'])->toBeNull();
});

it('serialises every declared tier, clamped to the 12-column grid', function () {
    $field = Text::make('name')->span(0)->colSpanMd(6)->colSpanLg(20)->toArray();

    expect($field['colSpan'])->toBe(1)
        ->and($field['colSpanMd'])->toBe(6)
        ->and($field['colSpanLg'])->toBe(12);
});

it('keeps the tiers of fields in a Panel and in a Tab', function () {
    $panel = Panel::make('Audit', [Text::make('reviewer')->colSpanMd(6)->colSpanLg(4)])->toArray();
    $tabs = TabGroup::make([
        Tab::make('General', [Text::make('status')->span(6)->colSpanLg(3)]),
    ])->toArray();

    expect($panel['fields'][0])->toMatchArray(['colSpan' => 12, 'colSpanMd' => 6, 'colSpanLg' => 4])
        ->and($tabs['tabs'][0]['fields'][0])->toMatchArray(['colSpan' => 6, 'colSpanMd' => null, 'colSpanLg' => 3]);
});
