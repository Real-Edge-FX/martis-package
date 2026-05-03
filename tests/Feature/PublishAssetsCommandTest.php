<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Martis\Console\PublishAssetsCommand;

it('martis:publish-assets is registered in the service provider', function () {
    $commands = $this->app->make(Kernel::class)->all();
    expect($commands)->toHaveKey('martis:publish-assets');
    expect($commands['martis:publish-assets'])->toBeInstanceOf(PublishAssetsCommand::class);
});

it('wipes public/vendor/martis/ before republishing', function () {
    // Seed a stale chunk in the destination so we can prove the
    // command deleted it. The post-publish state should contain
    // the package's manifest.json but NOT this stale file.
    $fs = new Filesystem;
    $destination = public_path('vendor/martis');
    $stale = $destination.'/assets/Stale.es-DEADBEEF.js';

    $fs->ensureDirectoryExists(dirname($stale));
    $fs->put($stale, '// stale chunk from a prior package version');

    expect($fs->exists($stale))->toBeTrue();

    $this->artisan('martis:publish-assets')->assertSuccessful();

    expect($fs->exists($stale))->toBeFalse();
    expect($fs->exists($destination.'/manifest.json'))->toBeTrue();
});

it('--no-wipe keeps stale chunks (legacy merge behaviour)', function () {
    $fs = new Filesystem;
    $destination = public_path('vendor/martis');
    $stale = $destination.'/assets/StaleKeep.es-CAFEBABE.js';

    $fs->ensureDirectoryExists(dirname($stale));
    $fs->put($stale, '// preserved by --no-wipe');

    $this->artisan('martis:publish-assets', ['--no-wipe' => true])->assertSuccessful();

    // With --no-wipe the stale file survives.
    expect($fs->exists($stale))->toBeTrue();
});

it('martis:vendor-publish --assets also wipes by default', function () {
    $fs = new Filesystem;
    $destination = public_path('vendor/martis');
    $stale = $destination.'/assets/StaleVendor.es-FEEDFACE.js';

    $fs->ensureDirectoryExists(dirname($stale));
    $fs->put($stale, '// stale chunk');

    $this->artisan('martis:vendor-publish', ['--assets' => true])->assertSuccessful();

    expect($fs->exists($stale))->toBeFalse();
});

it('martis:vendor-publish --assets --no-wipe preserves stale files', function () {
    $fs = new Filesystem;
    $destination = public_path('vendor/martis');
    $stale = $destination.'/assets/StaleVendorKeep.es-BADC0FFE.js';

    $fs->ensureDirectoryExists(dirname($stale));
    $fs->put($stale, '// preserved');

    $this->artisan('martis:vendor-publish', ['--assets' => true, '--no-wipe' => true])
        ->assertSuccessful();

    expect($fs->exists($stale))->toBeTrue();
});
