<?php

use Martis\Enums\DrawerSlot;

it('exposes the four documented slot keys', function () {
    expect(DrawerSlot::Create->value)->toBe('create');
    expect(DrawerSlot::Update->value)->toBe('update');
    expect(DrawerSlot::Detail->value)->toBe('detail');
    expect(DrawerSlot::Quick->value)->toBe('quick');
});

it('round-trips via tryFrom for the documented values', function () {
    expect(DrawerSlot::tryFrom('create'))->toBe(DrawerSlot::Create);
    expect(DrawerSlot::tryFrom('update'))->toBe(DrawerSlot::Update);
    expect(DrawerSlot::tryFrom('detail'))->toBe(DrawerSlot::Detail);
    expect(DrawerSlot::tryFrom('quick'))->toBe(DrawerSlot::Quick);
});

it('rejects values outside the documented set', function () {
    expect(DrawerSlot::tryFrom('Create'))->toBeNull(); // case-sensitive
    expect(DrawerSlot::tryFrom('preview'))->toBeNull(); // distinct from peek/preview UX
    expect(DrawerSlot::tryFrom(''))->toBeNull();
});
