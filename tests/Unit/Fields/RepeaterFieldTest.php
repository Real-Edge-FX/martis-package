<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Martis\Enums\RepeaterStorage;
use Martis\Fields\Number;
use Martis\Fields\Repeatable;
use Martis\Fields\Repeater;
use Martis\Fields\Text;

class RepeaterLineItem extends Repeatable
{
    public function fields(Request $request): array
    {
        return [
            Number::make('quantity', 'Qty'),
            Text::make('description', 'Description'),
        ];
    }
}

class RepeaterHeroBlock extends Repeatable
{
    public function fields(Request $request): array
    {
        return [
            Text::make('headline', 'Headline'),
        ];
    }
}

class RepeaterProjectModel extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['milestones' => 'array'];
}

it('Repeater defaults to JSON storage', function () {
    $field = Repeater::make('rows')->repeatables([RepeaterLineItem::make()]);

    $payload = $field->toArray();

    expect($payload['storage'])->toBe('json')
        ->and($payload['type'])->toBe('repeater')
        ->and($payload['repeatables'])->toHaveCount(1);
});

it('Repeater asHasMany() flips the storage discriminator', function () {
    $field = Repeater::make('rows')->asHasMany();

    expect($field->toArray()['storage'])->toBe('has_many');
});

it('Repeater asPolymorphic() exposes typeColumn + payloadColumn (⭐)', function () {
    $field = Repeater::make('blocks')->asPolymorphic('kind', 'body');

    $payload = $field->toArray();

    expect($payload['storage'])->toBe('polymorphic')
        ->and($payload['typeColumn'])->toBe('kind')
        ->and($payload['payloadColumn'])->toBe('body');
});

it('Repeater exposes cardinality + reorder + collapse flags (⭐ D2)', function () {
    $field = Repeater::make('rows')
        ->minRows(2)
        ->maxRows(5)
        ->collapsible()
        ->collapsedByDefault()
        ->reorderable(true, 'sort_order');

    $payload = $field->toArray();

    expect($payload['minRows'])->toBe(2)
        ->and($payload['maxRows'])->toBe(5)
        ->and($payload['collapsible'])->toBeTrue()
        ->and($payload['collapsedByDefault'])->toBeTrue()
        ->and($payload['reorderable'])->toBeTrue();
});

it('Repeater surfaces rowTemplates in the schema (⭐ D4)', function () {
    $field = Repeater::make('rows')
        ->repeatables([RepeaterLineItem::make()])
        ->rowTemplates([
            [
                'label' => 'Default widget',
                'type' => 'repeater-line-item',
                'fields' => ['quantity' => 1, 'description' => 'Widget'],
                'icon' => 'sparkle',
                'color' => 'info',
            ],
        ]);

    $payload = $field->toArray();

    expect($payload['rowTemplates'])->toHaveCount(1)
        ->and($payload['rowTemplates'][0]['label'])->toBe('Default widget')
        ->and($payload['rowTemplates'][0]['fields']['quantity'])->toBe(1)
        ->and($payload['rowTemplates'][0]['icon'])->toBe('sparkle');
});

it('Repeater dependsOn exposes parent attributes to field context (⭐ D1)', function () {
    $field = Repeater::make('rows')->dependsOn(['status', 'status', 'owner']);

    expect($field->toArray()['dependsOn'])->toBe(['status', 'owner']);
});

it('Repeater resolves JSON rows into canonical {id,type,fields} shape', function () {
    $field = Repeater::make('milestones')->repeatables([RepeaterLineItem::make()]);

    $model = new RepeaterProjectModel([
        'milestones' => [
            ['id' => 'a', 'type' => 'repeater-line-item', 'fields' => ['quantity' => 2, 'description' => 'Widget']],
            ['id' => 'b', 'type' => 'repeater-line-item', 'fields' => ['quantity' => 5, 'description' => 'Gadget']],
        ],
    ]);

    $resolved = $field->resolveForDisplay($model);

    expect($resolved)->toBeArray()
        ->and($resolved)->toHaveCount(2)
        ->and($resolved[0]['id'])->toBe('a')
        ->and($resolved[0]['fields']['quantity'])->toBe(2);
});

it('Repeater fills JSON rows with auto-generated UUID when the uniqueField is missing', function () {
    $field = Repeater::make('milestones')
        ->uniqueField('id')
        ->repeatables([RepeaterLineItem::make()]);

    $model = new RepeaterProjectModel;
    $field->fill($model, [
        ['type' => 'repeater-line-item', 'fields' => ['quantity' => 1, 'description' => 'Auto-id']],
    ]);

    $stored = $model->getAttribute('milestones');

    expect($stored)->toBeArray()
        ->and($stored)->toHaveCount(1)
        ->and($stored[0]['id'])->toBeString()
        ->and(strlen($stored[0]['id']))->toBeGreaterThan(10);
});

it('Repeatable toArray() emits shortName + header decorations (⭐ D3)', function () {
    $repeatable = RepeaterLineItem::make()
        ->icon('box')
        ->color('warning')
        ->title('{description} ({quantity})')
        ->badgeCount();

    $payload = $repeatable->toArray(Request::create('/'));

    expect($payload['shortName'])->toBe('repeater-line-item')
        ->and($payload['icon'])->toBe('box')
        ->and($payload['color'])->toBe('warning')
        ->and($payload['titleTemplate'])->toBe('{description} ({quantity})')
        ->and($payload['badgeCount'])->toBeTrue()
        ->and($payload['fields'])->toHaveCount(2);
});

it('Repeater with multiple repeatables emits both in toArray', function () {
    $field = Repeater::make('rows')->repeatables([
        RepeaterLineItem::make(),
        RepeaterHeroBlock::make(),
    ]);

    $types = array_column($field->toArray()['repeatables'], 'shortName');

    expect($types)->toBe(['repeater-line-item', 'repeater-hero-block']);
});

it('Repeater storage enum covers the 3 modes', function () {
    expect(RepeaterStorage::cases())
        ->toHaveCount(3)
        ->and(array_column(RepeaterStorage::cases(), 'value'))
        ->toBe(['json', 'has_many', 'polymorphic']);
});
