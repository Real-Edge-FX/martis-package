<?php

use Illuminate\Database\Eloquent\Model;
use Martis\FieldContext;
use Martis\Fields\Badge;
use Martis\Fields\Status;

// ---------------------------------------------------------------------------
// Test model fixture
// ---------------------------------------------------------------------------

class StatusTestModel extends Model
{
    protected $table = 'users';

    protected $fillable = ['status'];

    public $timestamps = false;
}

// ---------------------------------------------------------------------------
// Construction
// ---------------------------------------------------------------------------

it('Status::make creates a status field', function () {
    $field = Status::make('status');

    expect($field->attribute())->toBe('status')
        ->and($field->label())->toBe('Status')
        ->and($field->type())->toBe('status');
});

it('Status::make accepts custom label', function () {
    $field = Status::make('job_status', 'Job Status');

    expect($field->label())->toBe('Job Status');
});

it('Status is hidden from forms by default', function () {
    $field = Status::make('status');

    expect($field->isVisibleForContext(FieldContext::INDEX))->toBeTrue()
        ->and($field->isVisibleForContext(FieldContext::DETAIL))->toBeTrue()
        ->and($field->isVisibleForContext(FieldContext::CREATE))->toBeFalse()
        ->and($field->isVisibleForContext(FieldContext::UPDATE))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Status is semantically distinct from Badge
// ---------------------------------------------------------------------------

it('Status and Badge have different type() values', function () {
    $status = Status::make('status');
    $badge = Badge::make('status');

    expect($status->type())->not->toBe($badge->type())
        ->and($status->type())->toBe('status')
        ->and($badge->type())->toBe('badge');
});

// ---------------------------------------------------------------------------
// API configuration — loadingWhen / failedWhen
// ---------------------------------------------------------------------------

it('Status loadingWhen() sets loading state values', function () {
    $field = Status::make('status')->loadingWhen(['waiting', 'running', 'queued']);

    expect($field->getLoadingWhen())->toBe(['waiting', 'running', 'queued'])
        ->and($field->toArray()['loadingWhen'])->toBe(['waiting', 'running', 'queued']);
});

it('Status failedWhen() sets failed state values', function () {
    $field = Status::make('status')->failedWhen(['failed', 'errored', 'cancelled']);

    expect($field->getFailedWhen())->toBe(['failed', 'errored', 'cancelled'])
        ->and($field->toArray()['failedWhen'])->toBe(['failed', 'errored', 'cancelled']);
});

it('Status defaults to empty loadingWhen and failedWhen', function () {
    $field = Status::make('status');

    expect($field->getLoadingWhen())->toBe([])
        ->and($field->getFailedWhen())->toBe([]);
});

it('Status loadingWhen() normalizes values to strings', function () {
    $field = Status::make('status')->loadingWhen(['1', 'running']);

    expect($field->getLoadingWhen())->toContain('running');
});

// ---------------------------------------------------------------------------
// Resolve
// ---------------------------------------------------------------------------

it('Status resolves value from model', function () {
    $model = new StatusTestModel(['status' => 'running']);
    $field = Status::make('status');

    expect($field->resolve($model))->toBe('running');
});

it('Status resolves null from model', function () {
    $model = new StatusTestModel(['status' => null]);
    $field = Status::make('status');

    expect($field->resolve($model))->toBeNull();
});

// ---------------------------------------------------------------------------
// Fill
// ---------------------------------------------------------------------------

it('Status fill() writes value to model', function () {
    $model = new StatusTestModel;
    $field = Status::make('status');

    $field->fill($model, 'done');

    expect($model->getAttribute('status'))->toBe('done');
});

it('Status fill() does nothing when readonly', function () {
    $model = new StatusTestModel(['status' => 'running']);
    $field = Status::make('status')->readonly();

    $field->fill($model, 'done');

    expect($model->getAttribute('status'))->toBe('running');
});

// ---------------------------------------------------------------------------
// toArray
// ---------------------------------------------------------------------------

it('Status toArray contains loadingWhen and failedWhen', function () {
    $field = Status::make('status')
        ->loadingWhen(['waiting', 'running'])
        ->failedWhen(['failed']);

    $arr = $field->toArray();

    expect($arr)->toHaveKeys(['attribute', 'label', 'type', 'loadingWhen', 'failedWhen'])
        ->and($arr['type'])->toBe('status')
        ->and($arr['loadingWhen'])->toBe(['waiting', 'running'])
        ->and($arr['failedWhen'])->toBe(['failed']);
});

// ---------------------------------------------------------------------------
// Resolve callback
// ---------------------------------------------------------------------------

it('Status respects resolveUsing callback', function () {
    $model = new StatusTestModel(['status' => 'running']);
    $field = Status::make('status')->resolveUsing(fn ($v) => 'custom');

    expect($field->resolve($model))->toBe('custom');
});

// ---------------------------------------------------------------------------
// Context visibility
// ---------------------------------------------------------------------------

it('Status can be shown on forms when explicitly enabled', function () {
    $field = Status::make('status')->showOnForms();

    expect($field->isVisibleForContext(FieldContext::CREATE))->toBeTrue()
        ->and($field->isVisibleForContext(FieldContext::UPDATE))->toBeTrue();
});
