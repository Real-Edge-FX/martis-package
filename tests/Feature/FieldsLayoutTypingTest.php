<?php

use Illuminate\Http\Request;
use Martis\Fields\Field;
use Martis\Layout\Panel;
use Martis\Layout\Section;
use Martis\Tests\StaticAnalysis\GridResource;

// Runtime companion to the PHPStan type guard (tests/StaticAnalysis/GridResource):
// a Resource may return layout wrappers (Section/Panel) from fields() and
// detailSidebar(), and the engine flattens them to their nested fields.

it('flattens a Section returned from fields() into its nested fields', function () {
    $items = (new GridResource)->fields(new Request);

    // The list mixes a layout wrapper and a bare field — exactly what the
    // widened `list<FieldContract|LayoutContract>` type now permits.
    expect($items[0])->toBeInstanceOf(Section::class);

    $flat = Field::flattenLayoutFields($items);
    $attributes = array_map(fn ($f) => $f->attribute(), $flat);

    expect($attributes)->toBe(['name', 'email', 'created_at']);
});

it('accepts a layout wrapper in detailSidebar() and flattens it', function () {
    $sidebar = (new GridResource)->detailSidebar(new Request);

    expect($sidebar[0])->toBeInstanceOf(Panel::class);

    $flat = Field::flattenLayoutFields($sidebar);

    expect(array_map(fn ($f) => $f->attribute(), $flat))->toBe(['updated_at']);
});
