<?php

declare(strict_types=1);

/*
 * Loader bootstrap config — the `loader.disabled` flag is exposed
 * to the frontend via `resources/views/app.blade.php` and read by
 * the SPA before any other component mounts. The flag is wrapped in
 * `env('MARTIS_LOADER_DISABLED', false)` so staging/production can
 * flip it without editing the published config file.
 *
 * This test pins the default (loader enabled out of the box); the
 * env wrapper itself is exercised in ConfigEnvWiringTest.
 */

it('loader.disabled defaults to false (loader enabled out of the box)', function () {
    expect(config('martis.loader.disabled'))->toBeFalse();
});
