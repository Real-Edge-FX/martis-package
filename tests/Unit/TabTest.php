<?php

use Martis\FieldContext;
use Martis\Fields\Field;
use Martis\Fields\Number;
use Martis\Fields\Text;
use Martis\Fields\Textarea;
use Martis\Layout\Panel;
use Martis\Layout\Tab;
use Martis\Layout\TabGroup;

// ---------------------------------------------------------------------------
// Tab::make
// ---------------------------------------------------------------------------

it('Tab::make creates a tab with title and fields', function () {
    $tab = Tab::make('General', [
        Text::make('title'),
        Textarea::make('body'),
    ]);

    $arr = $tab->toArray();

    expect($arr['type'])->toBe('tab')
        ->and($arr['title'])->toBe('General')
        ->and($arr['fields'])->toHaveCount(2);
});

// ---------------------------------------------------------------------------
// Tab with nested Panel
// ---------------------------------------------------------------------------

it('Tab::toArray serializes a nested Panel correctly', function () {
    $tab = Tab::make('Details', [
        Panel::make('Info', [
            Text::make('title'),
        ]),
        Text::make('slug'),
    ]);

    $arr = $tab->toArray();

    expect($arr['fields'])->toHaveCount(2)
        ->and($arr['fields'][0]['type'])->toBe('panel')
        ->and($arr['fields'][0]['title'])->toBe('Info')
        ->and($arr['fields'][1]['type'])->toBe('text');
});

// ---------------------------------------------------------------------------
// Tab::filterForContext
// ---------------------------------------------------------------------------

it('Tab::filterForContext removes invisible fields', function () {
    $tab = Tab::make('General', [
        Text::make('title'),
        Text::make('secret')->hideFromDetail(),
    ]);

    $filtered = $tab->filterForContext(FieldContext::DETAIL);

    expect($filtered)->not->toBeNull()
        ->and($filtered->toArray()['fields'])->toHaveCount(1);
});

it('Tab::filterForContext returns null when all content hidden', function () {
    $tab = Tab::make('Hidden', [
        Text::make('title')->hideFromDetail(),
    ]);

    expect($tab->filterForContext(FieldContext::DETAIL))->toBeNull();
});

// ---------------------------------------------------------------------------
// Tab::flattenFields
// ---------------------------------------------------------------------------

it('Tab::flattenFields returns all nested fields including from panels', function () {
    $tab = Tab::make('All', [
        Text::make('title'),
        Panel::make('Info', [
            Textarea::make('body'),
            Number::make('count'),
        ]),
    ]);

    $flat = $tab->flattenFields();

    expect($flat)->toHaveCount(3);
});

// ---------------------------------------------------------------------------
// TabGroup::make
// ---------------------------------------------------------------------------

it('TabGroup::make creates a group with tabs', function () {
    $group = TabGroup::make([
        Tab::make('A', [Text::make('title')]),
        Tab::make('B', [Text::make('body')]),
    ]);

    $arr = $group->toArray();

    expect($arr['type'])->toBe('tab_group')
        ->and($arr['tabs'])->toHaveCount(2)
        ->and($arr['tabs'][0]['title'])->toBe('A')
        ->and($arr['tabs'][1]['title'])->toBe('B');
});

// ---------------------------------------------------------------------------
// TabGroup::filterForContext
// ---------------------------------------------------------------------------

it('TabGroup::filterForContext drops tabs with no visible content', function () {
    $group = TabGroup::make([
        Tab::make('Visible', [Text::make('title')]),
        Tab::make('Hidden', [Text::make('secret')->hideFromDetail()]),
    ]);

    $filtered = $group->filterForContext(FieldContext::DETAIL);

    expect($filtered)->not->toBeNull()
        ->and($filtered->toArray()['tabs'])->toHaveCount(1)
        ->and($filtered->toArray()['tabs'][0]['title'])->toBe('Visible');
});

it('TabGroup::filterForContext returns null when all tabs are empty', function () {
    $group = TabGroup::make([
        Tab::make('A', [Text::make('title')->hideFromDetail()]),
    ]);

    expect($group->filterForContext(FieldContext::DETAIL))->toBeNull();
});

// ---------------------------------------------------------------------------
// TabGroup::flattenFields
// ---------------------------------------------------------------------------

it('TabGroup::flattenFields returns all fields across all tabs', function () {
    $group = TabGroup::make([
        Tab::make('Tab1', [
            Text::make('a'),
            Text::make('b'),
        ]),
        Tab::make('Tab2', [
            Panel::make('Panel', [
                Text::make('c'),
                Text::make('d'),
            ]),
        ]),
    ]);

    $flat = $group->flattenFields();

    expect($flat)->toHaveCount(4);
});

// ---------------------------------------------------------------------------
// Field::filterForContext handles TabGroup
// ---------------------------------------------------------------------------

it('Field::filterForContext passes TabGroup through', function () {
    $items = [
        Text::make('title'),
        TabGroup::make([
            Tab::make('General', [Text::make('body')]),
        ]),
    ];

    $filtered = Field::filterLayoutForContext($items, FieldContext::DETAIL);

    expect($filtered)->toHaveCount(2);
});

it('Field::filterForContext drops TabGroup when all content hidden', function () {
    $items = [
        Text::make('title'),
        TabGroup::make([
            Tab::make('Hidden', [Text::make('secret')->hideFromDetail()]),
        ]),
    ];

    $filtered = Field::filterLayoutForContext($items, FieldContext::DETAIL);

    expect($filtered)->toHaveCount(1)
        ->and($filtered[0])->toBeInstanceOf(Text::class);
});

// ---------------------------------------------------------------------------
// Tab composition: fields + panels + relationships
// ---------------------------------------------------------------------------

it('Tab::make accepts mixed content (fields and panels)', function () {
    $tab = Tab::make('Mixed', [
        Text::make('title'),
        Panel::make('Details', [
            Textarea::make('body'),
        ])->collapsible(),
        Number::make('count'),
    ]);

    $arr = $tab->toArray();

    expect($arr['fields'])->toHaveCount(3)
        ->and($arr['fields'][0]['type'])->toBe('text')
        ->and($arr['fields'][1]['type'])->toBe('panel')
        ->and($arr['fields'][1]['collapsible'])->toBeTrue()
        ->and($arr['fields'][2]['type'])->toBe('number');
});
