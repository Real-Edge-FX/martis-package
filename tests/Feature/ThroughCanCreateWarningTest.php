<?php

use Illuminate\Support\Facades\Log;
use Martis\Fields\HasManyThrough;
use Martis\Fields\HasOneThrough;

/*
 * canCreate(true) on a Through field has no effect. It stays callable, so a
 * 1.x resource keeps loading, and logs a warning naming the field, once per
 * field and request: fields() runs on every request, and an
 * E_USER_DEPRECATED notice (the first cut of this guard) went to Laravel's deprecations channel, which is
 * `null` by default, so nobody saw it.
 */

it('logs a warning naming the field when canCreate(true) is called on a Through field', function () {
    Log::spy();

    HasManyThrough::make('Projects', 'managedProjects')->canCreate();
    HasOneThrough::make('Manager', 'manager')->canCreate(true);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'HasManyThrough') && str_contains($message, 'managedProjects') && str_contains($message, 'canCreate()'))->once();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'HasOneThrough') && str_contains($message, '"manager"'))->once();
});

it('logs it once per field and request, however many times fields() runs', function () {
    Log::spy();

    foreach (range(1, 3) as $ignored) {
        HasManyThrough::make('Projects', 'managedProjects')->canCreate();
    }

    Log::shouldHaveReceived('warning')->once();
});

it('stays silent for canCreate(false), and Create stays off either way', function () {
    Log::spy();

    $field = HasManyThrough::make('Projects', 'managedProjects')->canCreate(false);

    Log::shouldNotHaveReceived('warning');
    expect($field->toArray()['hasManyMeta']['canCreate'])->toBeFalse();
});
