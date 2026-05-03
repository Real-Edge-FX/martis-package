<?php

use Martis\DrawerOverride;
use Martis\Enums\DrawerPosition;

it('DrawerOverride::create resolves to martis:drawer-create', function () {
    $override = DrawerOverride::create();
    expect($override->component())->toBe('martis:drawer-create');
});

it('DrawerOverride::update resolves to martis:drawer-update', function () {
    $override = DrawerOverride::update();
    expect($override->component())->toBe('martis:drawer-update');
});

it('DrawerOverride::detail resolves to martis:drawer-detail', function () {
    $override = DrawerOverride::detail();
    expect($override->component())->toBe('martis:drawer-detail');
});

it('DrawerOverride::quick resolves to martis:drawer-quick with a narrower default width', function () {
    $override = DrawerOverride::quick();
    expect($override->component())->toBe('martis:drawer-quick');
    // The bundled quick drawer ships at 480px by default — narrower
    // than create/update/detail so it reads as "snapshot".
    expect($override->params())->toHaveKey('width');
    expect($override->params()['width'])->toBe('480px');
});

it('DrawerOverride methods are chainable and round-trip via toArray()', function () {
    $override = DrawerOverride::quick()
        ->width('560px')
        ->subtitle('Inline preview')
        ->showIcon('eye')
        ->iconColor('#22c55e')
        ->position(DrawerPosition::Right);

    $payload = $override->toArray();

    expect($payload['component'])->toBe('martis:drawer-quick');
    expect($payload['params']['width'])->toBe('560px');           // chained override wins
    expect($payload['params']['subtitle'])->toBe('Inline preview');
    expect($payload['params']['icon'])->toBe('eye');
    expect($payload['params']['iconColor'])->toBe('#22c55e');
    expect($payload['params']['position'])->toBe('right');
});
