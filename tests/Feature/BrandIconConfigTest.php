<?php

declare(strict_types=1);

/*
 * `brand.icon` config knob (v1.6.3) — separate from `brand.logo` so a
 * consumer can ship a small square icon and a horizontal lockup at
 * the same time. The frontend prefers `logo` when both are set
 * (lockup hides the brand text); falls back to `icon + brand text`;
 * falls back to the bundled cube + brand text.
 *
 * The Pest layer pins the default (the env wrapper is exercised in
 * ConfigEnvWiringTest). The frontend resolution is exercised by the
 * Vitest suite.
 */

it('brand.icon defaults to null', function () {
    expect(config('martis.brand.icon'))->toBeNull();
});
