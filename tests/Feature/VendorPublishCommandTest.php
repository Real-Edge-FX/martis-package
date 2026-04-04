<?php

use Illuminate\Support\ServiceProvider;
use Martis\Console\VendorPublishCommand;
use Martis\MartisServiceProvider;

// martis:vendor-publish

it('martis:vendor-publish is registered in the service provider', function () {
    $commands = $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->all();
    expect($commands)->toHaveKey('martis:vendor-publish');
});

it('martis:vendor-publish runs successfully with no options (publishes config and assets)', function () {
    $this->artisan('martis:vendor-publish')->assertSuccessful();
});

it('martis:vendor-publish --config publishes config only', function () {
    $this->artisan('martis:vendor-publish', ['--config' => true])->assertSuccessful();
});

it('martis:vendor-publish --assets publishes assets only', function () {
    $this->artisan('martis:vendor-publish', ['--assets' => true])->assertSuccessful();
});

it('martis:vendor-publish --views publishes views', function () {
    $this->artisan('martis:vendor-publish', ['--views' => true])->assertSuccessful();
});

it('martis:vendor-publish --lang publishes language files', function () {
    $this->artisan('martis:vendor-publish', ['--lang' => true])->assertSuccessful();
});

it('martis:vendor-publish --force flag is accepted', function () {
    $this->artisan('martis:vendor-publish', ['--force' => true])->assertSuccessful();
});

it('VendorPublishCommand class is registered in the service provider', function () {
    $commands = $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->all();
    expect($commands)->toHaveKey('martis:vendor-publish');
    expect($commands['martis:vendor-publish'])->toBeInstanceOf(VendorPublishCommand::class);
});
