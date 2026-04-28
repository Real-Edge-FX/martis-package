<?php

use Illuminate\Database\Eloquent\Model;
use Martis\Fields\BooleanGroup;

class BoolGroupTestModel extends Model
{
    protected $table = 'users';

    protected $fillable = ['permissions'];

    protected $casts = ['permissions' => 'array'];

    public $timestamps = false;
}

it('BooleanGroup::make creates a field with the right type', function () {
    $field = BooleanGroup::make('permissions');

    expect($field->type())->toBe('boolean_group')
        ->and($field->attribute())->toBe('permissions');
});

it('BooleanGroup options() registers the flag map', function () {
    $field = BooleanGroup::make('permissions')->options([
        'view' => 'View',
        'edit' => 'Edit',
    ]);

    expect($field->getOptions())->toBe(['view' => 'View', 'edit' => 'Edit'])
        ->and($field->toArray()['options'])->toBe(['view' => 'View', 'edit' => 'Edit']);
});

it('BooleanGroup labels() serialises the translated labels', function () {
    $field = BooleanGroup::make('permissions')
        ->options(['view' => 'View', 'edit' => 'Edit'])
        ->labels(['view' => 'Ver', 'edit' => 'Editar']);

    expect($field->toArray()['labels'])->toBe(['view' => 'Ver', 'edit' => 'Editar']);
});

it('BooleanGroup resolve normalises stored JSON into a bool map', function () {
    $model = new BoolGroupTestModel(['permissions' => ['view' => true, 'edit' => false]]);
    $field = BooleanGroup::make('permissions')->options([
        'view' => 'View',
        'edit' => 'Edit',
        'delete' => 'Delete',
    ]);

    expect($field->resolve($model))->toBe([
        'view' => true,
        'edit' => false,
        'delete' => false,
    ]);
});

it('BooleanGroup resolve returns all-false payload when the model attribute is null', function () {
    $model = new BoolGroupTestModel;
    $field = BooleanGroup::make('permissions')->options(['view' => 'V', 'edit' => 'E']);

    expect($field->resolve($model))->toBe(['view' => false, 'edit' => false]);
});

// ⭐ differentials

it('BooleanGroup grouped() registers the section map', function () {
    $field = BooleanGroup::make('permissions')
        ->options(['view' => 'V', 'edit' => 'E', 'delete' => 'D', 'billing' => 'B'])
        ->grouped([
            'Clients' => ['view', 'edit', 'delete'],
            'Billing' => ['billing'],
        ]);

    expect($field->getGroups())->toBe([
        'Clients' => ['view', 'edit', 'delete'],
        'Billing' => ['billing'],
    ])->and($field->toArray()['groups'])->toHaveKey('Clients');
});

it('BooleanGroup minChecked / maxChecked are exposed in the schema', function () {
    $field = BooleanGroup::make('permissions')
        ->options(['view' => 'V', 'edit' => 'E'])
        ->minChecked(1)
        ->maxChecked(1);

    expect($field->getMinChecked())->toBe(1)
        ->and($field->getMaxChecked())->toBe(1)
        ->and($field->toArray()['minChecked'])->toBe(1)
        ->and($field->toArray()['maxChecked'])->toBe(1);
});

it('BooleanGroup requireAny() compiles to minChecked(1)', function () {
    $field = BooleanGroup::make('permissions')
        ->options(['a' => 'A', 'b' => 'B'])
        ->requireAny();

    expect($field->getMinChecked())->toBe(1);
});

it('BooleanGroup requireAll() compiles to minChecked(count(options))', function () {
    $field = BooleanGroup::make('permissions')
        ->options(['a' => 'A', 'b' => 'B', 'c' => 'C'])
        ->requireAll();

    expect($field->getMinChecked())->toBe(3);
});

// -----------------------------------------------------------------------------
// buildRules() — backend enforcement of minChecked / maxChecked
//
// Pre-fix the constraint was only serialised to the frontend. A
// tampered payload sent directly to the API would happily save
// "permissions: {}" because no validator counted the checked flags.
// -----------------------------------------------------------------------------

function runBooleanGroupRules(BooleanGroup $field, mixed $value): array
{
    $messages = [];
    foreach ($field->buildRules() as $rule) {
        if (! ($rule instanceof Closure)) {
            continue;
        }
        $rule('permissions', $value, function (string $msg) use (&$messages): void {
            $messages[] = $msg;
        });
    }

    return $messages;
}

it('buildRules emits a closure that fails when fewer than minChecked are set', function () {
    $field = BooleanGroup::make('permissions')
        ->options(['a' => 'A', 'b' => 'B', 'c' => 'C'])
        ->minChecked(2);

    $errs = runBooleanGroupRules($field, ['a' => true, 'b' => false, 'c' => false]);

    expect($errs)->toHaveCount(1);
    expect($errs[0])->toContain('at least 2');
});

it('buildRules accepts the payload when checked count meets minChecked', function () {
    $field = BooleanGroup::make('permissions')
        ->options(['a' => 'A', 'b' => 'B', 'c' => 'C'])
        ->minChecked(2);

    $errs = runBooleanGroupRules($field, ['a' => true, 'b' => true, 'c' => false]);

    expect($errs)->toBeEmpty();
});

it('buildRules emits a closure that fails when checked count exceeds maxChecked', function () {
    $field = BooleanGroup::make('permissions')
        ->options(['a' => 'A', 'b' => 'B', 'c' => 'C'])
        ->maxChecked(1);

    $errs = runBooleanGroupRules($field, ['a' => true, 'b' => true, 'c' => false]);

    expect($errs)->toHaveCount(1);
    expect($errs[0])->toContain('at most 1');
});

it('buildRules requireAny() rejects the empty payload (regression for the playground bug)', function () {
    // The exact shape from the playground TeamMemberResource:
    // BooleanGroup::make('permissions')->options([...11 options...])->requireAny()
    // saved without any selection because no backend rule existed.
    $field = BooleanGroup::make('permissions')
        ->options([
            'clients.view' => 'a', 'clients.create' => 'b', 'clients.edit' => 'c',
            'clients.delete' => 'd', 'projects.view' => 'e', 'projects.create' => 'f',
            'projects.edit' => 'g', 'projects.archive' => 'h', 'billing.view' => 'i',
            'billing.edit' => 'j', 'admin.manage_team' => 'k',
        ])
        ->requireAny();

    // Empty submission (every flag false).
    $errs = runBooleanGroupRules($field, array_fill_keys(array_keys([
        'clients.view', 'clients.create', 'clients.edit', 'clients.delete',
        'projects.view', 'projects.create', 'projects.edit', 'projects.archive',
        'billing.view', 'billing.edit', 'admin.manage_team',
    ]), false));

    expect($errs)->toHaveCount(1);
    expect($errs[0])->toContain('at least 1');
});

it('buildRules treats non-array payloads as empty (string, null, missing)', function () {
    $field = BooleanGroup::make('permissions')
        ->options(['a' => 'A'])
        ->minChecked(1);

    foreach ([null, '', 'not an array', 42] as $bogus) {
        expect(runBooleanGroupRules($field, $bogus))->toHaveCount(1);
    }
});

it('buildRules adds NO closure when neither minChecked nor maxChecked is set', function () {
    $field = BooleanGroup::make('permissions')
        ->options(['a' => 'A']);

    $closures = array_filter($field->buildRules(), fn ($r) => $r instanceof Closure);

    expect($closures)->toBeEmpty();
});
