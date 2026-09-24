<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Martis\Fields\MultiSelect;
use Martis\Fields\Select;

class ChoiceOrderWarningModel extends Model
{
    protected $guarded = [];

    public $timestamps = false;
}

function choiceOrderModel(array $attributes, int $id = 1): ChoiceOrderWarningModel
{
    $model = new ChoiceOrderWarningModel($attributes);
    $model->setAttribute('id', $id);

    return $model;
}

beforeEach(function () {
    Log::spy();
});

it('warns once when a stored value matches an option label and no option value, with app.debug off too', function () {
    config(['app.debug' => false]);
    // Written label first, as before v2.0.0: value "Draft", label "draft".
    $field = Select::make('status')->options(['Draft' => 'draft', 'Published' => 'published']);

    $field->resolve(choiceOrderModel(['status' => 'draft'], 12));
    $field->resolve(choiceOrderModel(['status' => 'published'], 13));

    Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
        return str_contains($message, 'ChoiceOrderWarningModel #12 stores "draft" in Select [status], which matches an option label and no option value.')
            && str_contains($message, 'See docs/upgrading.md.')
            && $context === [
                'model' => ChoiceOrderWarningModel::class,
                'key' => 12,
                'field' => Select::class,
                'attribute' => 'status',
                'value' => 'draft',
            ];
    });
});

it('stays silent when the stored value is an option value', function () {
    Select::make('status')->options(['draft' => 'Draft'])->resolve(choiceOrderModel(['status' => 'draft']));

    Log::shouldNotHaveReceived('warning');
});

it('warns for a MultiSelect element that matches a label', function () {
    $field = MultiSelect::make('tags')->options(['PHP' => 'php', 'Go' => 'go']);

    $field->resolve(choiceOrderModel(['tags' => '["php"]'], 5));

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, '#5 stores "php" in MultiSelect [tags]'));
});

it('warns once per model class and field in a request, across field instances', function () {
    Select::make('status')->options(['Draft' => 'draft'])->resolve(choiceOrderModel(['status' => 'draft'], 1));
    Select::make('status')->options(['Draft' => 'draft'])->resolve(choiceOrderModel(['status' => 'draft'], 2));

    Log::shouldHaveReceived('warning')->once();
});

it('never runs a closure just for the check', function () {
    $calls = 0;
    $field = Select::make('status')->options(function () use (&$calls): array {
        $calls++;

        return ['Draft' => 'draft'];
    });

    foreach (range(1, 3) as $id) {
        $field->resolve(choiceOrderModel(['status' => 'draft'], $id));
    }

    expect($calls)->toBe(0);
    Log::shouldNotHaveReceived('warning');
});

it('checks the new options after options() replaces them', function () {
    $field = Select::make('status')->options(['draft' => 'Draft']);
    $field->resolve(choiceOrderModel(['status' => 'draft'], 1));

    $field->options(['Draft' => 'draft']);
    $field->resolve(choiceOrderModel(['status' => 'draft'], 2));

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, '#2 stores "draft"'));
});

// ---------------------------------------------------------------------------
// Numeric lists: v1.x stored the numbers themselves, v2.0 stores positions
// ---------------------------------------------------------------------------

it('warns when a list of numbers holds a record whose stored number is the label of another position', function () {
    // v1.x stored 1, 2, 3; v2.0 reads the list as 0 => "1", 1 => "2", 2 => "3".
    // The stored 1 is also a valid value (it now shows "2"), so only the list
    // check sees it.
    $field = Select::make('rating')->options([1, 2, 3]);

    $field->resolve(choiceOrderModel(['rating' => 1], 4));

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => str_contains($message, '#4 stores "1" in Select [rating]')
        && str_contains($message, 'the label of another option of a list, so it shows as "2"')
        && $context['value'] === '1');
});

it('warns for range(1, 12) and for a MultiSelect list of numbers', function () {
    Select::make('month')->options(range(1, 12))->resolve(choiceOrderModel(['month' => 5], 1));
    MultiSelect::make('ratings')->options([1, 2, 3])->resolve(choiceOrderModel(['ratings' => '[2]'], 2));

    Log::shouldHaveReceived('warning')->twice();
});

it('stays silent for a list of words and for range(0, n), whose labels equal their own values', function () {
    Select::make('size')->options(['Small', 'Large'])->resolve(choiceOrderModel(['size' => 0], 1));
    Select::make('size')->options(['Small', 'Large'])->resolve(choiceOrderModel(['size' => 1], 2));
    Select::make('slot')->options(range(0, 5))->resolve(choiceOrderModel(['slot' => 3], 3));
    MultiSelect::make('slots')->options(range(0, 5))->resolve(choiceOrderModel(['slots' => '[0, 4]'], 4));

    Log::shouldNotHaveReceived('warning');
});

it('stays silent for a map whose key differs from a numeric label, since only lists shifted', function () {
    // [value => label] written on purpose: 10 shows "1", stored 1 is not an option.
    Select::make('code')->options([10 => '1', 1 => 'One'])->resolve(choiceOrderModel(['code' => 1], 1));

    Log::shouldNotHaveReceived('warning');
});

// ---------------------------------------------------------------------------
// The stored value is checked, not what resolveUsing() or computed() yields
// ---------------------------------------------------------------------------

it('checks the stored value, not the one resolveUsing() turns into a label', function () {
    $field = Select::make('status')->options(['draft' => 'Draft'])->resolveUsing(fn ($value) => ucfirst((string) $value));

    expect($field->resolve(choiceOrderModel(['status' => 'draft'])))->toBe('Draft');
    Log::shouldNotHaveReceived('warning');
});

it('still warns on a stored label that resolveUsing() hides', function () {
    Select::make('status')->options(['Draft' => 'draft'])->resolveUsing(fn ($value) => strtoupper((string) $value))
        ->resolve(choiceOrderModel(['status' => 'draft'], 3));

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, '#3 stores "draft"'));
});

it('checks the stored MultiSelect values, not the ones resolveUsing() returns', function () {
    MultiSelect::make('tags')->options(['php' => 'PHP'])
        ->resolveUsing(fn ($value) => array_map('strtoupper', json_decode((string) $value, true)))
        ->resolve(choiceOrderModel(['tags' => '["php"]']));

    Log::shouldNotHaveReceived('warning');
});

it('never checks a computed field, which stores nothing', function () {
    Select::make('status')->options(['draft' => 'Draft'])->computed(fn () => 'Draft')
        ->resolve(choiceOrderModel([]));
    MultiSelect::make('tags')->options(['php' => 'PHP'])->computed(fn () => ['PHP'])
        ->resolve(choiceOrderModel([]));

    Log::shouldNotHaveReceived('warning');
});

it('never checks a Select that accepts custom values, where a typed label is a legitimate value', function () {
    Select::make('status')->options(['draft' => 'Draft'])->allowCustomValues()
        ->resolve(choiceOrderModel(['status' => 'Draft']));

    Log::shouldNotHaveReceived('warning');
});
