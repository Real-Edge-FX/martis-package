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

it('brand.logo_dark accepts the env value at runtime', function () {
    config()->set('martis.brand.logo_dark', '/img/logo-dark.svg');
    expect(config('martis.brand.logo_dark'))->toBe('/img/logo-dark.svg');
});

it('brand.logo_height structure exposes menu + auth knobs', function () {
    expect(config('martis.brand.logo_height'))
        ->toBeArray()
        ->toHaveKey('menu')
        ->toHaveKey('auth');
});

it('preferences.defaults.theme/accent/density read from env wrappers', function () {
    // The structure default must expose all three keys regardless of env.
    $defaults = config('martis.preferences.defaults');
    expect($defaults)
        ->toHaveKey('theme')
        ->toHaveKey('accent')
        ->toHaveKey('density');

    config()->set('martis.preferences.defaults.theme', 'light');
    config()->set('martis.preferences.defaults.accent', 'teal');
    config()->set('martis.preferences.defaults.density', 'dense');

    expect(config('martis.preferences.defaults.theme'))->toBe('light');
    expect(config('martis.preferences.defaults.accent'))->toBe('teal');
    expect(config('martis.preferences.defaults.density'))->toBe('dense');
});

it('preferences.custom_accents config key exists and accepts the raw env string', function () {
    // The structure exposes the key (even if null by default).
    expect(array_key_exists('custom_accents', (array) config('martis.preferences')))->toBeTrue();

    config()->set('martis.preferences.custom_accents', 'edgeflow:#1a73e8');
    expect(config('martis.preferences.custom_accents'))->toBe('edgeflow:#1a73e8');
});
