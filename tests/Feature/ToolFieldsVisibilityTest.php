<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Martis\Concerns\ProvidesToolFields;
use Martis\Contracts\ProvidesFields;
use Martis\Facades\Martis;
use Martis\Fields\Repeatable;
use Martis\Fields\Repeater;
use Martis\Fields\Select;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Layout\Panel;
use Martis\Layout\Section;
use Martis\Layout\Tab;
use Martis\Layout\TabGroup;
use Martis\Tools\Tool;

// ===========================================================================
// The fields of a Tool and a field the user cannot see (v1.38.0).
//
// GET /api/tools/{uriKey}/fields serialised every field of Tool::fields(),
// those whose canSee() denies the user included, although the option
// search of such a field already answered like an undeclared one. It now
// leaves them out as the resource schema does, at every depth of the
// layout containers, and leaves out a container that holds no field left.
// ===========================================================================

class TFVLine extends Repeatable
{
    public function fields(Request $request): array
    {
        return [
            Text::make('label'),
            Text::make('cost')->canSee(fn () => false),
        ];
    }
}

class TFVTool extends Tool implements ProvidesFields
{
    use ProvidesToolFields;

    public function __construct()
    {
        parent::__construct(name: 'Tool Fields Visibility', uriKey: 'tfv-tool');
    }

    public function fields(Request $request): array
    {
        $hidden = fn () => false;

        return [
            Text::make('title'),
            Text::make('secret')->canSee($hidden),
            Select::make('unit')->options(['kg' => 'Kilogram'])->canSee($hidden),
            Section::make('Details', [
                Text::make('code'),
                Text::make('pin')->canSee($hidden),
            ]),
            Panel::make('Vault', [
                Text::make('vault_key')->canSee($hidden),
            ]),
            TabGroup::make([
                Tab::make('Main', [
                    Text::make('note'),
                    Text::make('note_secret')->canSee($hidden),
                    Panel::make('Inner', [
                        Text::make('inner_open'),
                        Text::make('inner_secret')->canSee($hidden),
                    ]),
                ]),
                Tab::make('Restricted', [
                    Text::make('restricted')->canSee($hidden),
                ]),
            ]),
            Repeater::make('lines', 'Lines')->repeatables([TFVLine::make()]),
            Repeater::make('secret_lines', 'Secret lines')->repeatables([TFVLine::make()])->canSee($hidden),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Martis::tools([new TFVTool]);
});

afterEach(function () {
    Martis::tools([]);
});

/**
 * The attribute of every field of a serialised field list, at every depth.
 *
 * @param  list<array<string, mixed>>  $items
 * @return list<string>
 */
function tfvAttributes(array $items): array
{
    $attributes = [];

    foreach ($items as $item) {
        foreach (['fields', 'tabs'] as $key) {
            if (isset($item[$key]) && is_array($item[$key])) {
                $attributes = [...$attributes, ...tfvAttributes($item[$key])];
            }
        }

        if (isset($item['attribute']) && is_string($item['attribute'])) {
            $attributes[] = $item['attribute'];
        }
    }

    return $attributes;
}

it('leaves the fields the user cannot see out of a Tool\'s fields', function () {
    $fields = $this->getJson('/martis/api/tools/tfv-tool/fields')->assertOk()->json('data.fields');

    expect(tfvAttributes($fields))->toEqualCanonicalizing(['title', 'code', 'note', 'inner_open', 'lines']);
});

it('keeps the layout of a Tool\'s fields without the containers left empty', function () {
    $fields = $this->getJson('/martis/api/tools/tfv-tool/fields')->assertOk()->json('data.fields');

    expect(array_column($fields, 'type'))->toBe(['text', 'section', 'tab_group', 'repeater'])
        ->and(array_column($fields[1]['fields'], 'attribute'))->toBe(['code'])
        ->and(array_column($fields[2]['tabs'], 'title'))->toBe(['Main'])
        ->and(array_map(fn (array $item): string => $item['attribute'] ?? $item['type'], $fields[2]['tabs'][0]['fields']))->toBe(['note', 'panel'])
        ->and(array_column($fields[2]['tabs'][0]['fields'][1]['fields'], 'attribute'))->toBe(['inner_open'])
        ->and(array_column($fields[3]['repeatables'][0]['fields'], 'attribute'))->toBe(['label']);
});
