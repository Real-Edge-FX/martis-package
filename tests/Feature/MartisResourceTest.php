<?php

use Martis\Http\Controllers\DashboardController;
use Martis\Http\Controllers\LoginController;
use Martis\Http\Controllers\MartisController;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\MartisServiceProvider;

it('service provider class exists', function () {
    expect(class_exists(MartisServiceProvider::class))->toBeTrue();
});

it('service provider is loaded in the application', function () {
    $loaded = array_keys($this->app->getLoadedProviders());
    expect($loaded)->toContain(MartisServiceProvider::class);
});

it('MartisController is abstract', function () {
    $ref = new ReflectionClass(MartisController::class);
    expect($ref->isAbstract())->toBeTrue();
});

it('DashboardController has index method', function () {
    expect(method_exists(DashboardController::class, 'index'))->toBeTrue();
});

it('LoginController has showLoginForm, login, and logout methods', function () {
    expect(method_exists(LoginController::class, 'showLoginForm'))->toBeTrue();
    expect(method_exists(LoginController::class, 'login'))->toBeTrue();
    expect(method_exists(LoginController::class, 'logout'))->toBeTrue();
});

it('MartisAuthenticate middleware class exists', function () {
    expect(class_exists(MartisAuthenticate::class))->toBeTrue();
});

it('martis config has expected keys', function () {
    $config = config('martis');
    expect($config)->toHaveKey('path');
    expect($config)->toHaveKey('guard');
    expect($config)->toHaveKey('middleware');
    expect($config)->toHaveKey('auth_middleware');
    expect($config)->toHaveKey('brand');
    expect($config)->toHaveKey('pagination');
    expect($config)->toHaveKey('storage');
});
