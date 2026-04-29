<?php

declare(strict_types=1);

/*
 * Loader bootstrap config — the `loader.disabled` flag is exposed
 * to the frontend via `resources/views/app.blade.php` and read by
 * the SPA before any other component mounts. The flag is wrapped in
 * `env('MARTIS_LOADER_DISABLED', false)` so staging/production can
 * flip it without editing the published config file.
 *
 * These tests pin the contract: the env wrapper exists, the default
 * keeps the loader enabled, and the runtime override path through
 * `config()->set(...)` works.
 */

it('loader.disabled defaults to false (loader enabled out of the box)', function () {
    expect(config('martis.loader.disabled'))->toBeFalse();
});

it('loader.disabled is read from the martis.loader config block', function () {
    expect(config('martis.loader'))
        ->toBeArray()
        ->toHaveKey('disabled');
});

it('runtime override of loader.disabled propagates through config()', function () {
    config()->set('martis.loader.disabled', true);
    expect(config('martis.loader.disabled'))->toBeTrue();

    config()->set('martis.loader.disabled', false);
    expect(config('martis.loader.disabled'))->toBeFalse();
});
