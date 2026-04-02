<?php

use Martis\Http\Controllers\MartisController;
use Martis\MartisServiceProvider;

it('service provider class exists', function () {
    expect(class_exists(MartisServiceProvider::class))->toBeTrue();
});

it('service provider is loaded in the application', function () {
    $loaded = array_keys($this->app->getLoadedProviders());
    expect($loaded)->toContain(MartisServiceProvider::class);
});

it('martis controller class exists', function () {
    expect(class_exists(MartisController::class))->toBeTrue();
});

it('martis controller has index method', function () {
    expect(method_exists(MartisController::class, 'index'))->toBeTrue();
});

it('martis config has expected keys', function () {
    $config = config('martis');
    expect($config)->toHaveKey('path');
    expect($config)->toHaveKey('guard');
    expect($config)->toHaveKey('middleware');
    expect($config)->toHaveKey('storage_driver');
});
