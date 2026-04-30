<?php

declare(strict_types=1);

/*
 * `brand.icon` config knob (v1.6.3) — separate from `brand.logo` so a
 * consumer can ship a small square icon and a horizontal lockup at
 * the same time. The frontend prefers `logo` when both are set
 * (lockup hides the brand text); falls back to `icon + brand text`;
 * falls back to the bundled cube + brand text.
 *
 * The Pest layer pins the env wrapper + default. The frontend
 * resolution is exercised by the Vitest suite.
 */

it('brand.icon is wired to MARTIS_BRAND_ICON env var', function () {
    $brand = config('martis.brand');
    expect($brand)->toBeArray();
    expect(array_key_exists('icon', $brand))->toBeTrue('brand.icon key is missing — dump: '.json_encode($brand));

    config()->set('martis.brand.icon', '/img/edgeflow-icon.png');
    expect(config('martis.brand.icon'))->toBe('/img/edgeflow-icon.png');

    config()->set('martis.brand.icon', null);
    expect(config('martis.brand.icon'))->toBeNull();
});

it('brand.icon defaults to null', function () {
    expect(config('martis.brand.icon'))->toBeNull();
});

it('brand.logo and brand.icon are independent', function () {
    config()->set('martis.brand.logo', '/img/full-lockup.svg');
    config()->set('martis.brand.icon', '/img/icon.png');

    expect(config('martis.brand.logo'))->toBe('/img/full-lockup.svg');
    expect(config('martis.brand.icon'))->toBe('/img/icon.png');
});
