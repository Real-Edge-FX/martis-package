<?php

use Illuminate\Database\Eloquent\Model;
use Martis\FieldContext;
use Martis\Fields\Badge;

// ---------------------------------------------------------------------------
// Test model fixture
// ---------------------------------------------------------------------------

class BadgeTestModel extends Model
{
    protected $table = 'users';

    protected $fillable = ['status'];

    public $timestamps = false;
}

// ---------------------------------------------------------------------------
// Construction
// ---------------------------------------------------------------------------

it('Badge::make creates a badge field', function () {
    $field = Badge::make('status');

    expect($field->attribute())->toBe('status')
        ->and($field->label())->toBe('Status')
        ->and($field->type())->toBe('badge');
});

it('Badge::make accepts custom label', function () {
    $field = Badge::make('status', 'Post Status');

    expect($field->label())->toBe('Post Status');
});

it('Badge is hidden from forms by default', function () {
    $field = Badge::make('status');

    expect($field->isVisibleForContext(FieldContext::INDEX))->toBeTrue()
        ->and($field->isVisibleForContext(FieldContext::DETAIL))->toBeTrue()
        ->and($field->isVisibleForContext(FieldContext::CREATE))->toBeFalse()
        ->and($field->isVisibleForContext(FieldContext::UPDATE))->toBeFalse();
});

// ---------------------------------------------------------------------------
// API configuration
// ---------------------------------------------------------------------------

it('Badge map() maps values to types', function () {
    $field = Badge::make('status')->map([
        'draft' => 'warning',
        'published' => 'success',
        'archived' => 'danger',
    ]);

    expect($field->getMap())->toBe([
        'draft' => 'warning',
        'published' => 'success',
        'archived' => 'danger',
    ]);
});

it('Badge types() replaces the full type map', function () {
    $field = Badge::make('status')->types([
        'active' => 'success',
        'inactive' => 'danger',
    ]);

    expect($field->getTypes())->toBe([
        'active' => 'success',
        'inactive' => 'danger',
    ]);
});

it('Badge addTypes() merges with existing types', function () {
    $field = Badge::make('status')->addTypes(['custom' => 'info']);

    $types = $field->getTypes();

    // Default types still present
    expect($types)->toHaveKey('success')
        ->and($types)->toHaveKey('warning')
        ->and($types)->toHaveKey('danger')
        ->and($types)->toHaveKey('info')
        ->and($types['custom'])->toBe('info');
});

it('Badge withIcons() enables icon rendering', function () {
    $field = Badge::make('status')->withIcons();

    expect($field->hasIcons())->toBeTrue()
        ->and($field->toArray()['withIcons'])->toBeTrue();
});

it('Badge icons() maps types to icon names and enables icons', function () {
    $field = Badge::make('status')->icons([
        'success' => 'check-circle',
        'danger' => 'x-circle',
    ]);

    expect($field->hasIcons())->toBeTrue()
        ->and($field->getIcons())->toBe([
            'success' => 'check-circle',
            'danger' => 'x-circle',
        ]);
});

it('Badge defaults have four standard types', function () {
    $field = Badge::make('status');

    $types = $field->getTypes();
    expect($types)->toHaveKey('info')
        ->and($types)->toHaveKey('success')
        ->and($types)->toHaveKey('warning')
        ->and($types)->toHaveKey('danger');
});

// ---------------------------------------------------------------------------
// Resolve (display-only, reads model value)
// ---------------------------------------------------------------------------

it('Badge resolves value from model', function () {
    $model = new BadgeTestModel(['status' => 'published']);
    $field = Badge::make('status');

    expect($field->resolve($model))->toBe('published');
});

it('Badge resolves null from model', function () {
    $model = new BadgeTestModel(['status' => null]);
    $field = Badge::make('status');

    expect($field->resolve($model))->toBeNull();
});

// ---------------------------------------------------------------------------
// Fill (Badge is display-only — should still support fill for consistency)
// ---------------------------------------------------------------------------

it('Badge fill() writes to model when not readonly', function () {
    $model = new BadgeTestModel;
    $field = Badge::make('status');

    $field->fill($model, 'published');

    expect($model->getAttribute('status'))->toBe('published');
});

// ---------------------------------------------------------------------------
// toArray
// ---------------------------------------------------------------------------

it('Badge toArray contains map and types', function () {
    $field = Badge::make('status')
        ->map(['draft' => 'warning', 'published' => 'success'])
        ->addTypes(['archived' => 'danger']);

    $arr = $field->toArray();

    expect($arr)->toHaveKeys(['attribute', 'label', 'type', 'map', 'types'])
        ->and($arr['type'])->toBe('badge')
        ->and($arr['map'])->toBe(['draft' => 'warning', 'published' => 'success'])
        ->and($arr['types'])->toHaveKey('archived');
});

it('Badge toArray includes withIcons when enabled', function () {
    $field = Badge::make('status')->withIcons()->icons(['success' => 'check']);

    $arr = $field->toArray();

    expect($arr['withIcons'])->toBeTrue()
        ->and($arr['icons'])->toBe(['success' => 'check']);
});

// ---------------------------------------------------------------------------
// Resolve callback
// ---------------------------------------------------------------------------

it('Badge respects resolveUsing callback', function () {
    $model = new BadgeTestModel(['status' => 'draft']);
    $field = Badge::make('status')->resolveUsing(fn ($v) => 'overridden');

    expect($field->resolve($model))->toBe('overridden');
});

// ---------------------------------------------------------------------------
// Context visibility
// ---------------------------------------------------------------------------

it('Badge can be made visible on forms when explicitly enabled', function () {
    $field = Badge::make('status')->showOnForms();

    expect($field->isVisibleForContext(FieldContext::CREATE))->toBeTrue()
        ->and($field->isVisibleForContext(FieldContext::UPDATE))->toBeTrue();
});
