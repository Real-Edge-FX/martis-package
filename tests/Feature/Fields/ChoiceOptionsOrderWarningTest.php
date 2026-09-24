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

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'ChoiceOrderWarningModel #12 stores "draft" in Select [status], which matches an option label and no option value.')
        && str_contains($message, 'See docs/upgrading.md.'));
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
