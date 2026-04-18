<?php

use Martis\Enums\AggregateFunction;
use Martis\Fields\HasManyThrough;
use Martis\Fields\HasOne;
use Martis\Fields\HasOneOfMany;
use Martis\Fields\HasOneThrough;
use Martis\Fields\MorphOne;
use Martis\Fields\MorphOneOfMany;

// ── HasOneOfMany + factory ────────────────────────────────────────────

it('HasOne::ofMany returns a HasOneOfMany field with the correct relationship', function () {
    $field = HasOne::ofMany('Latest Invoice', 'latestInvoice', class_exists(\App\Martis\Resources\InvoiceResource::class) ? \App\Martis\Resources\InvoiceResource::class : \stdClass::class);

    expect($field)->toBeInstanceOf(HasOneOfMany::class)
        ->and($field->type())->toBe('has_one_of_many')
        ->and($field->getRelationship())->toBe('latestInvoice');
});

it('HasOneOfMany::make builds the field directly (dual entry point)', function () {
    $field = HasOneOfMany::make('Latest Invoice', 'latestInvoice');

    expect($field->type())->toBe('has_one_of_many');
});

it('HasOneOfMany latestByTimestamp + aggregateVia are exposed in the schema', function () {
    $field = HasOneOfMany::make('Latest Invoice', 'latestInvoice')
        ->latestByTimestamp('paid_at')
        ->aggregateVia(AggregateFunction::Sum, 'amount');

    $schema = $field->toArray();

    expect($field->getRuntimeScope())->not->toBeNull()
        ->and($field->getAggregateFunction())->toBe(AggregateFunction::Sum)
        ->and($field->getAggregateColumn())->toBe('amount')
        ->and($schema['ofManyMeta']['aggregate'])->toBe(['fn' => 'sum', 'column' => 'amount']);
});

it('HasOneOfMany oldestByTimestamp sets a runtime scope', function () {
    $field = HasOneOfMany::make('Oldest Invoice', 'oldestInvoice')->oldestByTimestamp();

    expect($field->getRuntimeScope())->not->toBeNull();
});

it('HasOneOfMany without aggregate serialises aggregate as null', function () {
    $schema = HasOneOfMany::make('X', 'x')->toArray();

    expect($schema['ofManyMeta']['aggregate'])->toBeNull();
});

// ── MorphOneOfMany + factory ──────────────────────────────────────────

it('MorphOne::ofMany returns a MorphOneOfMany field', function () {
    $field = MorphOne::ofMany('Latest Note', 'latestNote', \stdClass::class);

    expect($field)->toBeInstanceOf(MorphOneOfMany::class)
        ->and($field->type())->toBe('morph_one_of_many');
});

it('MorphOneOfMany latestByTimestamp + aggregateVia pipe through', function () {
    $field = MorphOneOfMany::make('Latest Note', 'latestNote')
        ->latestByTimestamp()
        ->aggregateVia(AggregateFunction::Count, '*');

    expect($field->getRuntimeScope())->not->toBeNull()
        ->and($field->getAggregateFunction())->toBe(AggregateFunction::Count);
});

// ── HasOneThrough — read-only defaults + differentials ────────────────

it('HasOneThrough defaults Create/Update/Delete to false (read-only traversal)', function () {
    $schema = HasOneThrough::make('Manager', 'manager')->toArray();

    expect($schema['hasOneMeta']['canCreate'])->toBeFalse()
        ->and($schema['hasOneMeta']['canUpdate'])->toBeFalse()
        ->and($schema['hasOneMeta']['canDelete'])->toBeFalse();
});

it('HasOneThrough type is has_one_through', function () {
    expect(HasOneThrough::make('Manager', 'manager')->type())->toBe('has_one_through');
});

it('HasOneThrough throughBreadcrumb flag round-trips', function () {
    $field = HasOneThrough::make('Manager', 'manager')->throughBreadcrumb();

    expect($field->hasThroughBreadcrumb())->toBeTrue()
        ->and($field->toArray()['throughBreadcrumb'])->toBeTrue();
});

// ── HasManyThrough — read-only + count badge ──────────────────────────

it('HasManyThrough defaults Create/Update/Delete to false', function () {
    $schema = HasManyThrough::make('Projects', 'managedProjects')->toArray();

    expect($schema['hasManyMeta']['canCreate'])->toBeFalse()
        ->and($schema['hasManyMeta']['canUpdate'])->toBeFalse()
        ->and($schema['hasManyMeta']['canDelete'])->toBeFalse();
});

it('HasManyThrough countBadge defaults to true', function () {
    $field = HasManyThrough::make('Projects', 'managedProjects');

    expect($field->hasCountBadge())->toBeTrue()
        ->and($field->toArray()['countBadge'])->toBeTrue();
});

it('HasManyThrough countBadge can be disabled', function () {
    $field = HasManyThrough::make('Projects', 'managedProjects')->countBadge(false);

    expect($field->hasCountBadge())->toBeFalse()
        ->and($field->toArray()['countBadge'])->toBeFalse();
});

it('HasManyThrough throughBreadcrumb round-trips', function () {
    $field = HasManyThrough::make('Projects', 'managedProjects')->throughBreadcrumb();

    expect($field->toArray()['throughBreadcrumb'])->toBeTrue();
});

// ── ResolvesRelatableOptions trait — parity gap fix ───────────────────

it('HasOne now exposes withSubtitles / peekable / relatableQueryUsing (parity gap closed)', function () {
    $field = HasOne::make('Profile', 'profile')
        ->withSubtitles()
        ->subtitleAttribute('company')
        ->peekable(true);

    $schema = $field->toArray();

    expect($field->getWithSubtitles())->toBeTrue()
        ->and($field->isPeekable())->toBeTrue()
        ->and($schema['peekable'])->toBeTrue()
        ->and($schema['withSubtitles'])->toBeTrue()
        ->and($schema['subtitleAttribute'])->toBe('company');
});

it('HasOne noPeeking() turns the peek affordance off', function () {
    $field = HasOne::make('Profile', 'profile')->noPeeking();

    expect($field->isPeekable())->toBeFalse()
        ->and($field->toArray()['peekable'])->toBeFalse();
});

it('HasOne relatableQueryUsing stores a Closure for server-side filtering', function () {
    $field = HasOne::make('Profile', 'profile')->relatableQueryUsing(fn ($req, $q) => $q->whereNotNull('email'));

    expect($field->getRelatableQueryClosure())->not->toBeNull();
});
