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

// ---------------------------------------------------------------------------
// ⭐ Differentials — closure-backed map/labels/types/icons + resolveBadgeUsing
// ---------------------------------------------------------------------------

it('Badge map() accepts a closure that returns an array', function () {
    $field = Badge::make('status')->map(fn () => ['draft' => 'warning', 'published' => 'success']);

    expect($field->getMap())->toBe(['draft' => 'warning', 'published' => 'success']);
});

it('Badge labels() accepts a closure — serialised shape matches the array form', function () {
    $fromClosure = Badge::make('status')->map(['a' => 'info'])->labels(fn () => ['a' => 'Alpha']);
    $fromArray = Badge::make('status')->map(['a' => 'info'])->labels(['a' => 'Alpha']);

    expect($fromClosure->toArray()['labels'])->toBe($fromArray->toArray()['labels']);
});

it('Badge types() accepts a closure', function () {
    $field = Badge::make('status')->types(fn () => ['vip' => '#ec4899']);

    expect($field->getTypes())->toBe(['vip' => '#ec4899']);
});

it('Badge icons() accepts a closure and enables withIcons', function () {
    $field = Badge::make('status')->icons(fn () => ['success' => 'check']);

    expect($field->getIcons())->toBe(['success' => 'check'])
        ->and($field->hasIcons())->toBeTrue();
});

it('Badge addTypes() merges even when types was set via closure', function () {
    $field = Badge::make('status')
        ->types(fn () => ['vip' => '#ec4899'])
        ->addTypes(['beta' => '#8b5cf6']);

    expect($field->getTypes())->toBe(['vip' => '#ec4899', 'beta' => '#8b5cf6']);
});

it('Badge serialisation omits labels when the closure returns an empty array', function () {
    $field = Badge::make('status')->map(['a' => 'info'])->labels(fn () => []);

    expect($field->toArray())->not->toHaveKey('labels');
});

it('Badge resolveBadgeUsing() wraps the value in a __martisBadge payload', function () {
    $model = new BadgeTestModel(['status' => 'active']);
    $field = Badge::make('status')
        ->map(['active' => 'success'])
        ->resolveBadgeUsing(fn (?string $value, $m) => [
            'type' => 'vip',
            'label' => 'VIP ⭐',
            'icon' => 'crown',
        ]);

    $payload = $field->resolveForDisplay($model);

    expect($payload)->toBeArray()
        ->and($payload['__martisBadge'])->toBeTrue()
        ->and($payload['value'])->toBe('active')
        ->and($payload['type'])->toBe('vip')
        ->and($payload['label'])->toBe('VIP ⭐')
        ->and($payload['icon'])->toBe('crown');
});

it('Badge resolveBadgeUsing() falls back to the raw value when the closure returns []', function () {
    $model = new BadgeTestModel(['status' => 'active']);
    $field = Badge::make('status')
        ->map(['active' => 'success'])
        ->resolveBadgeUsing(fn () => []);

    expect($field->resolveForDisplay($model))->toBe('active');
});

it('Badge resolveBadgeUsing() missing keys come back as null (frontend falls back to static maps)', function () {
    $model = new BadgeTestModel(['status' => 'active']);
    $field = Badge::make('status')
        ->map(['active' => 'success'])
        ->resolveBadgeUsing(fn () => ['label' => 'Ativo Agora']);

    $payload = $field->resolveForDisplay($model);

    expect($payload['type'])->toBeNull()
        ->and($payload['label'])->toBe('Ativo Agora')
        ->and($payload['icon'])->toBeNull();
});

it('Badge without resolveBadgeUsing returns the raw value unchanged', function () {
    $model = new BadgeTestModel(['status' => 'active']);
    $field = Badge::make('status')->map(['active' => 'success']);

    expect($field->resolveForDisplay($model))->toBe('active');
});

// ---------------------------------------------------------------------------
// ⭐ Per-value closures (one-arg) — runs per row
// ---------------------------------------------------------------------------

it('Badge labels() one-arg closure resolves the label per row', function () {
    $model = new BadgeTestModel(['status' => 'active']);
    $field = Badge::make('status')
        ->map(['active' => 'success'])
        ->labels(fn (string $value) => "Status: {$value}");

    $payload = $field->resolveForDisplay($model);

    expect($payload['__martisBadge'])->toBeTrue()
        ->and($payload['value'])->toBe('active')
        ->and($payload['label'])->toBe('Status: active')
        ->and($payload['type'])->toBeNull();
});

it('Badge map() one-arg closure resolves the badge type per row', function () {
    $model = new BadgeTestModel(['status' => 'paused']);
    $field = Badge::make('status')->map(fn (string $v) => match ($v) {
        'active' => 'success',
        'paused' => 'warning',
        default => 'info',
    });

    $payload = $field->resolveForDisplay($model);

    expect($payload['__martisBadge'])->toBeTrue()
        ->and($payload['type'])->toBe('warning');
});

it('Badge per-value closures are NOT serialised into the static schema', function () {
    $field = Badge::make('status')
        ->map(fn (string $v) => 'success')
        ->labels(fn (string $v) => "L: {$v}");

    $schema = $field->toArray();

    expect($schema)->not->toHaveKey('labels')
        ->and($schema['map'])->toBe([]);
});

it('Badge zero-arg closures DO serialise into the static schema', function () {
    $field = Badge::make('status')
        ->map(fn () => ['a' => 'info'])
        ->labels(fn () => ['a' => 'Alpha']);

    $schema = $field->toArray();

    expect($schema['map'])->toBe(['a' => 'info'])
        ->and($schema['labels'])->toBe(['a' => 'Alpha']);
});

it('Badge per-value map + per-value labels compose into a single payload', function () {
    $model = new BadgeTestModel(['status' => 'archived']);
    $field = Badge::make('status')
        ->map(fn (string $v) => $v === 'archived' ? 'danger' : 'info')
        ->labels(fn (string $v) => ucfirst($v));

    $payload = $field->resolveForDisplay($model);

    expect($payload['type'])->toBe('danger')
        ->and($payload['label'])->toBe('Archived')
        ->and($payload['value'])->toBe('archived');
});

it('Badge resolveBadgeUsing takes precedence over per-value closures when it provides a key', function () {
    $model = new BadgeTestModel(['status' => 'active']);
    $field = Badge::make('status')
        ->map(fn (string $v) => 'success')
        ->labels(fn (string $v) => 'From labels')
        ->resolveBadgeUsing(fn () => ['label' => 'From resolve']);

    $payload = $field->resolveForDisplay($model);

    // resolveBadgeUsing filled `label`; `type` was NOT filled so it
    // falls through to the per-value map() closure.
    expect($payload['label'])->toBe('From resolve')
        ->and($payload['type'])->toBe('success');
});
