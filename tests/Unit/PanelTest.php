<?php

use Martis\FieldContext;
use Martis\Fields\Boolean;
use Martis\Fields\Field;
use Martis\Fields\Number;
use Martis\Fields\Text;
use Martis\Fields\Textarea;
use Martis\Layout\Panel;

// ---------------------------------------------------------------------------
// Panel::make
// ---------------------------------------------------------------------------

it('Panel::make creates a panel with title and fields', function () {
    $panel = Panel::make('Basic Info', [
        Text::make('title'),
        Textarea::make('body'),
    ]);

    $arr = $panel->toArray();

    expect($arr['type'])->toBe('panel')
        ->and($arr['title'])->toBe('Basic Info')
        ->and($arr['collapsible'])->toBeFalse()
        ->and($arr['collapsedByDefault'])->toBeFalse()
        ->and($arr['limit'])->toBeNull()
        ->and($arr['fields'])->toHaveCount(2);
});

// ---------------------------------------------------------------------------
// collapsible / collapsedByDefault
// ---------------------------------------------------------------------------

it('Panel::collapsible marks the panel as collapsible', function () {
    $panel = Panel::make('Info', [Text::make('title')])->collapsible();

    expect($panel->toArray()['collapsible'])->toBeTrue()
        ->and($panel->toArray()['collapsedByDefault'])->toBeFalse();
});

it('Panel::collapsedByDefault implies collapsible and sets collapsed state', function () {
    $panel = Panel::make('Info', [Text::make('title')])->collapsedByDefault();

    expect($panel->toArray()['collapsible'])->toBeTrue()
        ->and($panel->toArray()['collapsedByDefault'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// limit()
// ---------------------------------------------------------------------------

it('Panel::limit sets the visible field limit', function () {
    $panel = Panel::make('Info', [
        Text::make('a'),
        Text::make('b'),
        Text::make('c'),
    ])->limit(2);

    expect($panel->toArray()['limit'])->toBe(2);
});

// ---------------------------------------------------------------------------
// Field serialization inside panel
// ---------------------------------------------------------------------------

it('Panel::toArray serializes nested fields', function () {
    $panel = Panel::make('Test', [
        Text::make('title', 'Title'),
        Number::make('count', 'Count'),
    ]);

    $arr = $panel->toArray();

    expect($arr['fields'][0]['attribute'])->toBe('title')
        ->and($arr['fields'][0]['type'])->toBe('text')
        ->and($arr['fields'][1]['attribute'])->toBe('count')
        ->and($arr['fields'][1]['type'])->toBe('number');
});

// ---------------------------------------------------------------------------
// filterForContext
// ---------------------------------------------------------------------------

it('Panel::filterForContext removes fields not visible in context', function () {
    $panel = Panel::make('Details', [
        Text::make('title')->showOnDetail(),
        Number::make('views')->hideFromDetail(),
    ]);

    $filtered = $panel->filterForContext(FieldContext::DETAIL);

    expect($filtered)->not->toBeNull()
        ->and($filtered->toArray()['fields'])->toHaveCount(1)
        ->and($filtered->toArray()['fields'][0]['attribute'])->toBe('title');
});

it('Panel::filterForContext returns null when all fields are hidden', function () {
    $panel = Panel::make('Hidden', [
        Text::make('title')->hideFromDetail(),
    ]);

    $result = $panel->filterForContext(FieldContext::DETAIL);

    expect($result)->toBeNull();
});

// ---------------------------------------------------------------------------
// flattenFields
// ---------------------------------------------------------------------------

it('Panel::flattenFields returns all nested fields', function () {
    $panel = Panel::make('Info', [
        Text::make('title'),
        Text::make('slug'),
        Number::make('count'),
    ]);

    $flat = $panel->flattenFields();

    expect($flat)->toHaveCount(3);
});

// ---------------------------------------------------------------------------
// Field::filterForContext handles Panels in mixed array
// ---------------------------------------------------------------------------

it('Field::filterForContext passes Panels through when they have visible fields', function () {
    $items = [
        Text::make('title'),
        Panel::make('Details', [
            Text::make('body'),
        ]),
    ];

    $filtered = Field::filterForContext($items, FieldContext::DETAIL);

    expect($filtered)->toHaveCount(2);
});

it('Field::filterForContext drops empty Panels', function () {
    $items = [
        Text::make('title'),
        Panel::make('Hidden Panel', [
            Text::make('body')->hideFromDetail(),
        ]),
    ];

    $filtered = Field::filterForContext($items, FieldContext::DETAIL);

    expect($filtered)->toHaveCount(1)
        ->and($filtered[0])->toBeInstanceOf(Text::class);
});

// ---------------------------------------------------------------------------
// Field::flattenLayoutFields
// ---------------------------------------------------------------------------

it('Field::flattenLayoutFields flattens Panel and plain fields', function () {
    $items = [
        Text::make('title'),
        Panel::make('Details', [
            Textarea::make('body'),
            Number::make('count'),
        ]),
        Boolean::make('active'),
    ];

    $flat = Field::flattenLayoutFields($items);

    expect($flat)->toHaveCount(4);
    $types = array_map(fn ($f) => $f->type(), $flat);
    expect($types)->toContain('text', 'textarea', 'number', 'boolean');
});
