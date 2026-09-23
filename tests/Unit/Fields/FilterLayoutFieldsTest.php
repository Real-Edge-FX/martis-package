<?php

declare(strict_types=1);

use Martis\Contracts\FieldContract;
use Martis\Contracts\LayoutContract;
use Martis\FieldContext;
use Martis\Fields\Field;
use Martis\Fields\Text;
use Martis\Layout\Panel;
use Martis\Layout\Section;
use Martis\Layout\Tab;
use Martis\Layout\TabGroup;

// ===========================================================================
// Field::filterLayoutFields() (v1.38.0): drop fields from a field list and
// keep its layout, the containers left without fields dropped with them.
// ===========================================================================

/** A layout container that cannot rebuild itself (no FiltersFields). */
class FLFCustomContainer implements LayoutContract
{
    /** @param  list<FieldContract>  $fields */
    public function __construct(private array $fields) {}

    public function toArray(): array
    {
        return ['type' => 'custom', 'fields' => array_map(fn (FieldContract $f): array => $f->toArray(), $this->fields)];
    }

    public function filterForContext(FieldContext $context): ?static
    {
        return $this;
    }

    public function flattenFields(): array
    {
        return $this->fields;
    }
}

/** Keep every field but the ones named `secret*`. */
function flfKeep(): Closure
{
    return fn (FieldContract $field): bool => ! str_starts_with($field->attribute(), 'secret');
}

/**
 * @param  list<FieldContract|LayoutContract>  $items
 * @return list<array<string, mixed>>
 */
function flfSerialise(array $items): array
{
    return array_map(fn (FieldContract|LayoutContract $item): array => $item->toArray(), $items);
}

it('keeps the fields it accepts and their order', function () {
    $items = Field::filterLayoutFields([Text::make('a'), Text::make('secret_b'), Text::make('c')], flfKeep());

    expect(array_column(flfSerialise($items), 'attribute'))->toBe(['a', 'c']);
});

it('rebuilds a Panel and a Section with the fields they keep', function () {
    $panel = Panel::make('Pay', [Text::make('grade'), Text::make('secret_salary')]);
    $section = Section::make('Ids', [Text::make('secret_tax'), Text::make('badge')])->columns(2);

    [$keptPanel, $keptSection] = flfSerialise(Field::filterLayoutFields([$panel, $section], flfKeep()));

    expect($keptPanel['title'])->toBe('Pay')
        ->and(array_column($keptPanel['fields'], 'attribute'))->toBe(['grade'])
        ->and($keptSection['columns'])->toBe(2)
        ->and(array_column($keptSection['fields'], 'attribute'))->toBe(['badge'])
        // The containers given are left as they were.
        ->and(array_column($panel->toArray()['fields'], 'attribute'))->toBe(['grade', 'secret_salary']);
});

it('drops a container that keeps no field', function () {
    $items = Field::filterLayoutFields([
        Text::make('a'),
        Panel::make('Vault', [Text::make('secret_key')]),
        Section::make('Codes', [Text::make('secret_code')]),
        TabGroup::make([Tab::make('Hidden', [Text::make('secret_note')])]),
    ], flfKeep());

    expect(array_column(flfSerialise($items), 'attribute'))->toBe(['a'])
        ->and($items)->toHaveCount(1);
});

it('keeps the tabs of a TabGroup and the panels of a Tab that keep a field', function () {
    $group = TabGroup::make([
        Tab::make('Main', [
            Text::make('note'),
            Panel::make('Inner', [Text::make('secret_inner'), Text::make('open')]),
            Panel::make('Locked', [Text::make('secret_locked')]),
        ]),
        Tab::make('Restricted', [Text::make('secret_only')]),
    ]);

    [$kept] = flfSerialise(Field::filterLayoutFields([$group], flfKeep()));

    expect(array_column($kept['tabs'], 'title'))->toBe(['Main'])
        ->and(array_map(fn (array $item): string => $item['attribute'] ?? $item['title'], $kept['tabs'][0]['fields']))->toBe(['note', 'Inner'])
        ->and(array_column($kept['tabs'][0]['fields'][1]['fields'], 'attribute'))->toBe(['open']);
});

it('puts the fields a custom container keeps in its place', function () {
    $items = Field::filterLayoutFields([
        Text::make('a'),
        new FLFCustomContainer([Text::make('b'), Text::make('secret_c')]),
        Text::make('d'),
    ], flfKeep());

    expect(array_column(flfSerialise($items), 'attribute'))->toBe(['a', 'b', 'd']);
});
