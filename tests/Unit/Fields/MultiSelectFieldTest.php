<?php

use Illuminate\Database\Eloquent\Model;
use Martis\FieldContext;
use Martis\Fields\MultiSelect;

// ---------------------------------------------------------------------------
// Test model fixture
// ---------------------------------------------------------------------------

class MultiSelectTestModel extends Model
{
    protected $table = 'users';

    protected $fillable = ['labels'];

    public $timestamps = false;
}

// ---------------------------------------------------------------------------
// Construction
// ---------------------------------------------------------------------------

it('MultiSelect::make creates a multi_select field', function () {
    $field = MultiSelect::make('labels');

    expect($field->attribute())->toBe('labels')
        ->and($field->label())->toBe('Labels')
        ->and($field->type())->toBe('multi_select');
});

it('MultiSelect::make accepts custom label', function () {
    $field = MultiSelect::make('labels', 'Post Labels');

    expect($field->label())->toBe('Post Labels');
});

it('MultiSelect is visible in all contexts by default', function () {
    $field = MultiSelect::make('labels');

    expect($field->isVisibleForContext(FieldContext::INDEX))->toBeTrue()
        ->and($field->isVisibleForContext(FieldContext::DETAIL))->toBeTrue()
        ->and($field->isVisibleForContext(FieldContext::CREATE))->toBeTrue()
        ->and($field->isVisibleForContext(FieldContext::UPDATE))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Options — simple formats
// ---------------------------------------------------------------------------

it('MultiSelect options() accepts sequential array', function () {
    $field = MultiSelect::make('labels')->options(['php', 'laravel', 'react']);

    $opts = $field->getOptions();
    expect($opts)->toHaveCount(3)
        ->and($opts[0])->toBe(['label' => 'php', 'value' => 'php'])
        ->and($opts[1])->toBe(['label' => 'laravel', 'value' => 'laravel']);
});

it('MultiSelect options() accepts associative array', function () {
    $field = MultiSelect::make('labels')->options(['PHP' => 'php', 'Laravel' => 'laravel']);

    $opts = $field->getOptions();
    expect($opts)->toHaveCount(2)
        ->and($opts[0])->toBe(['label' => 'PHP', 'value' => 'php'])
        ->and($opts[1])->toBe(['label' => 'Laravel', 'value' => 'laravel']);
});

it('MultiSelect options() accepts grouped array', function () {
    $field = MultiSelect::make('labels')->options([
        'Backend' => ['PHP' => 'php', 'Go' => 'go'],
        'Frontend' => ['React' => 'react'],
    ]);

    $opts = $field->getOptions();
    expect($opts)->toHaveCount(3)
        ->and($opts[0])->toBe(['label' => 'PHP', 'value' => 'php', 'group' => 'Backend'])
        ->and($opts[2])->toBe(['label' => 'React', 'value' => 'react', 'group' => 'Frontend']);
});

it('MultiSelect displayUsingLabels() enables label display', function () {
    $field = MultiSelect::make('labels')->displayUsingLabels();

    expect($field->isDisplayingLabels())->toBeTrue()
        ->and($field->toArray()['displayLabels'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// Resolve (deserialization)
// ---------------------------------------------------------------------------

it('MultiSelect resolves null as empty array', function () {
    $model = new MultiSelectTestModel(['labels' => null]);
    $field = MultiSelect::make('labels');

    expect($field->resolve($model))->toBe([]);
});

it('MultiSelect resolves JSON string to array', function () {
    $model = new MultiSelectTestModel(['labels' => '["php","laravel","react"]']);
    $field = MultiSelect::make('labels');

    expect($field->resolve($model))->toBe(['php', 'laravel', 'react']);
});

it('MultiSelect resolves plain array from model', function () {
    $model = new MultiSelectTestModel(['labels' => ['php', 'go']]);
    $field = MultiSelect::make('labels');

    expect($field->resolve($model))->toBe(['php', 'go']);
});

it('MultiSelect resolves empty string as empty array', function () {
    $model = new MultiSelectTestModel(['labels' => '']);
    $field = MultiSelect::make('labels');

    expect($field->resolve($model))->toBe([]);
});

// ---------------------------------------------------------------------------
// Fill (serialization)
// ---------------------------------------------------------------------------

it('MultiSelect fill() accepts array and stores JSON', function () {
    $model = new MultiSelectTestModel;
    $field = MultiSelect::make('labels');

    $field->fill($model, ['php', 'laravel']);

    $stored = $model->getAttribute('labels');
    expect(json_decode($stored, true))->toBe(['php', 'laravel']);
});

it('MultiSelect fill() accepts JSON string', function () {
    $model = new MultiSelectTestModel;
    $field = MultiSelect::make('labels');

    $field->fill($model, '["php","laravel"]');

    $stored = $model->getAttribute('labels');
    expect(json_decode($stored, true))->toBe(['php', 'laravel']);
});

it('MultiSelect fill() stores null for empty array', function () {
    $model = new MultiSelectTestModel;
    $field = MultiSelect::make('labels');

    $field->fill($model, []);

    expect($model->getAttribute('labels'))->toBeNull();
});

it('MultiSelect fill() stores null for null', function () {
    $model = new MultiSelectTestModel;
    $field = MultiSelect::make('labels');

    $field->fill($model, null);

    expect($model->getAttribute('labels'))->toBeNull();
});

it('MultiSelect fill() does nothing when readonly', function () {
    $model = new MultiSelectTestModel(['labels' => '["original"]']);
    $field = MultiSelect::make('labels')->readonly();

    $field->fill($model, ['new']);

    expect($model->getAttribute('labels'))->toBe('["original"]');
});

it('MultiSelect preserves multiple values correctly', function () {
    $model = new MultiSelectTestModel;
    $field = MultiSelect::make('labels');

    $field->fill($model, ['backend', 'frontend', 'devops', 'testing']);

    $stored = $model->getAttribute('labels');
    expect(json_decode($stored, true))->toBe(['backend', 'frontend', 'devops', 'testing']);
});

// ---------------------------------------------------------------------------
// toArray
// ---------------------------------------------------------------------------

it('MultiSelect toArray contains options and displayLabels', function () {
    $field = MultiSelect::make('labels')
        ->options(['PHP' => 'php', 'Go' => 'go'])
        ->displayUsingLabels();

    $arr = $field->toArray();

    expect($arr)->toHaveKeys(['attribute', 'label', 'type', 'options', 'displayLabels'])
        ->and($arr['type'])->toBe('multi_select')
        ->and($arr['options'])->toHaveCount(2)
        ->and($arr['displayLabels'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// Resolve callback
// ---------------------------------------------------------------------------

it('MultiSelect respects resolveUsing callback', function () {
    $model = new MultiSelectTestModel(['labels' => '["php"]']);
    $field = MultiSelect::make('labels')->resolveUsing(fn ($v) => ['overridden']);

    expect($field->resolve($model))->toBe(['overridden']);
});

it('MultiSelect respects fillUsing callback', function () {
    $model = new MultiSelectTestModel;
    $captured = null;

    $field = MultiSelect::make('labels')->fillUsing(function ($m, $value, $attr) use (&$captured) {
        $captured = $value;
    });

    $field->fill($model, ['php', 'go']);

    expect($captured)->toBe(['php', 'go']);
});
