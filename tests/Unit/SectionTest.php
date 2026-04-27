<?php

use Martis\FieldContext;
use Martis\Fields\Boolean;
use Martis\Fields\Date;
use Martis\Fields\Field;
use Martis\Fields\Number;
use Martis\Fields\Text;
use Martis\Layout\Section;

// ---------------------------------------------------------------------------
// Section::make
// ---------------------------------------------------------------------------

it('Section::make creates a section with title and fields', function () {
    $section = Section::make('Timeline', [
        Date::make('start_date')->span(6),
        Date::make('end_date')->span(6),
    ]);

    $arr = $section->toArray();

    expect($arr['type'])->toBe('section')
        ->and($arr['title'])->toBe('Timeline')
        ->and($arr['columns'])->toBe(12)
        ->and($arr['collapsible'])->toBeFalse()
        ->and($arr['collapsedByDefault'])->toBeFalse()
        ->and($arr['limit'])->toBeNull()
        ->and($arr['fields'])->toHaveCount(2);
});

it('Section::make with null title renders no header', function () {
    $section = Section::make(null, [Text::make('name')]);

    expect($section->toArray()['title'])->toBeNull();
});

// ---------------------------------------------------------------------------
// columns()
// ---------------------------------------------------------------------------

it('Section::columns sets custom grid column count', function () {
    $section = Section::make('Grid', [Text::make('a')])->columns(3);

    expect($section->toArray()['columns'])->toBe(3);
});

it('Section::columns enforces minimum of 1', function () {
    $section = Section::make('Grid', [Text::make('a')])->columns(0);

    expect($section->toArray()['columns'])->toBe(1);
});

it('Section::columns defaults to 12', function () {
    $section = Section::make('Grid', [Text::make('a')]);

    expect($section->toArray()['columns'])->toBe(12);
});

// ---------------------------------------------------------------------------
// collapsible / collapsedByDefault
// ---------------------------------------------------------------------------

it('Section::collapsible marks the section as collapsible', function () {
    $section = Section::make('Info', [Text::make('a')])->collapsible();

    expect($section->toArray()['collapsible'])->toBeTrue()
        ->and($section->toArray()['collapsedByDefault'])->toBeFalse();
});

it('Section::collapsedByDefault implies collapsible', function () {
    $section = Section::make('Info', [Text::make('a')])->collapsedByDefault();

    expect($section->toArray()['collapsible'])->toBeTrue()
        ->and($section->toArray()['collapsedByDefault'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// limit()
// ---------------------------------------------------------------------------

it('Section::limit sets field visibility limit', function () {
    $section = Section::make('Many', [
        Text::make('a'),
        Text::make('b'),
        Text::make('c'),
    ])->limit(2);

    expect($section->toArray()['limit'])->toBe(2);
});

// ---------------------------------------------------------------------------
// Field::span() serialization
// ---------------------------------------------------------------------------

it('Fields inside Section serialize their colSpan values', function () {
    $section = Section::make('Timeline', [
        Date::make('start_date')->span(6),
        Date::make('end_date')->span(6)->colSpanMd(4),
    ])->columns(12);

    $arr = $section->toArray();
    $fields = $arr['fields'];

    expect($fields[0]['colSpan'])->toBe(6)
        ->and($fields[1]['colSpan'])->toBe(6)
        ->and($fields[1]['colSpanMd'])->toBe(4);
});

// ---------------------------------------------------------------------------
// filterForContext
// ---------------------------------------------------------------------------

it('Section::filterForContext removes invisible fields', function () {
    $section = Section::make('Mixed', [
        Text::make('visible')->showOnCreating(),
        Text::make('hidden')->hideWhenCreating(),
    ]);

    $filtered = $section->filterForContext(FieldContext::CREATE);

    expect($filtered)->not->toBeNull()
        ->and($filtered->toArray()['fields'])->toHaveCount(1)
        ->and($filtered->toArray()['fields'][0]['attribute'])->toBe('visible');
});

it('Section::filterForContext returns null when all fields hidden', function () {
    $section = Section::make('Empty', [
        Text::make('hidden')->hideWhenCreating(),
    ]);

    $filtered = $section->filterForContext(FieldContext::CREATE);

    expect($filtered)->toBeNull();
});

// ---------------------------------------------------------------------------
// flattenFields
// ---------------------------------------------------------------------------

it('Section::flattenFields returns all nested fields', function () {
    $section = Section::make('Group', [
        Text::make('a'),
        Number::make('b'),
        Boolean::make('c'),
    ]);

    $flat = $section->flattenFields();

    expect($flat)->toHaveCount(3);
});

// ---------------------------------------------------------------------------
// Field::filterLayoutForContext with Section
// ---------------------------------------------------------------------------

it('filterLayoutForContext preserves Section structure', function () {
    $items = [
        Text::make('top_level'),
        Section::make('Contact', [
            Text::make('phone'),
            Text::make('email'),
        ])->columns(2),
    ];

    $result = Field::filterLayoutForContext($items, FieldContext::CREATE);

    expect($result)->toHaveCount(2)
        ->and($result[0]->toArray()['attribute'])->toBe('top_level')
        ->and($result[1]->toArray()['type'])->toBe('section')
        ->and($result[1]->toArray()['columns'])->toBe(2)
        ->and($result[1]->toArray()['fields'])->toHaveCount(2);
});

it('Field::flattenLayoutFields flattens sections', function () {
    $items = [
        Text::make('standalone'),
        Section::make('Grid', [
            Text::make('a'),
            Text::make('b'),
        ]),
    ];

    $flat = Field::flattenLayoutFields($items);

    expect($flat)->toHaveCount(3);
});
