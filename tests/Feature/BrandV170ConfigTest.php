<?php

declare(strict_types=1);

/*
 * v1.7.0 brand + preferences env wrappers. Pin the contract for:
 *
 *   • brand.logo_dark      ↔ MARTIS_BRAND_LOGO_DARK
 *   • brand.icon_dark      ↔ MARTIS_BRAND_ICON_DARK
 *   • brand.logo_height.menu ↔ MARTIS_BRAND_LOGO_HEIGHT_MENU
 *   • brand.logo_height.auth ↔ MARTIS_BRAND_LOGO_HEIGHT_AUTH
 *   • preferences.defaults.theme   ↔ MARTIS_DEFAULT_THEME
 *   • preferences.defaults.accent  ↔ MARTIS_DEFAULT_ACCENT
 *   • preferences.defaults.density ↔ MARTIS_DEFAULT_DENSITY
 *   • preferences.custom_accents   ↔ MARTIS_CUSTOM_ACCENTS
 *
 * All keys default to safe values so a host that never sets these
 * env vars keeps the v1.6.x behaviour bit-for-bit.
 */

it('brand.logo_dark and brand.icon_dark default to null', function () {
    expect(config('martis.brand'))
        ->toHaveKey('logo_dark')
        ->toHaveKey('icon_dark');
    expect(config('martis.brand.logo_dark'))->toBeNull();
    expect(config('martis.brand.icon_dark'))->toBeNull();
});
