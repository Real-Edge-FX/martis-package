<?php

declare(strict_types=1);

/*
 * v2.6.0 brand surfaces: the authentication lockup, the theme-aware
 * loader logo read from the environment, and the optional brand <head>
 * tags (web app manifest, Apple touch icon, theme color).
 */

it('maps each new key to its environment variable', function (string $key, string $env) {
    $source = (string) file_get_contents(__DIR__.'/../../config/martis.php');

    expect($source)->toContain("'{$key}' => env('{$env}')");
})->with([
    ['auth_logo', 'MARTIS_BRAND_AUTH_LOGO'],
    ['auth_logo_dark', 'MARTIS_BRAND_AUTH_LOGO_DARK'],
    ['manifest', 'MARTIS_BRAND_MANIFEST'],
    ['apple_touch_icon', 'MARTIS_BRAND_APPLE_TOUCH_ICON'],
    ['theme_color', 'MARTIS_BRAND_THEME_COLOR'],
    ['logo', 'MARTIS_LOADER_LOGO'],
    ['logoDark', 'MARTIS_LOADER_LOGO_DARK'],
]);

it('defaults every new key to null', function () {
    foreach (['auth_logo', 'auth_logo_dark', 'manifest', 'apple_touch_icon', 'theme_color'] as $key) {
        expect(array_key_exists($key, (array) config('martis.brand')))->toBeTrue();
        expect(config("martis.brand.{$key}"))->toBeNull();
    }

    expect(array_key_exists('logoDark', (array) config('martis.loader')))->toBeTrue();
    expect(config('martis.loader.logo'))->toBeNull();
    expect(config('martis.loader.logoDark'))->toBeNull();
});

it('emits no manifest, apple-touch-icon or theme-color tag when they are not set', function () {
    $html = $this->get('/martis/login')->assertOk()->getContent();

    expect($html)
        ->not->toContain('rel="manifest"')
        ->not->toContain('rel="apple-touch-icon"')
        ->not->toContain('name="theme-color"');
});

it('emits the brand head tags when they are set', function () {
    config([
        'martis.brand.manifest' => '/brand/site.webmanifest',
        'martis.brand.apple_touch_icon' => 'https://cdn.example.com/apple-touch-icon.png',
        'martis.brand.theme_color' => '#0f172a',
    ]);

    $html = $this->get('/martis/login')->assertOk()->getContent();

    expect($html)
        ->toContain('<link rel="manifest" href="http://localhost/brand/site.webmanifest">')
        ->toContain('<link rel="apple-touch-icon" href="https://cdn.example.com/apple-touch-icon.png">')
        ->toContain('<meta name="theme-color" content="#0f172a">');
});

it('escapes the theme color it prints', function () {
    config(['martis.brand.theme_color' => '"><script>alert(1)</script>']);

    $html = $this->get('/martis/login')->assertOk()->getContent();

    expect($html)
        ->not->toContain('<script>alert(1)</script>')
        ->toContain('content="&quot;&gt;&lt;script&gt;');
});

it('hands the authentication lockup and the loader logo pair to the SPA', function () {
    config([
        'martis.brand.auth_logo' => '/brand/auth-light.svg',
        'martis.brand.auth_logo_dark' => '/brand/auth-dark.svg',
        'martis.loader.logo' => '/brand/mark-light.svg',
        'martis.loader.logoDark' => '/brand/mark-dark.svg',
    ]);

    $html = $this->get('/martis/login')->assertOk()->getContent();

    expect($html)
        ->toContain('authLogo: "\/brand\/auth-light.svg"')
        ->toContain('authLogoDark: "\/brand\/auth-dark.svg"')
        ->toContain('"logo":"\/brand\/mark-light.svg"')
        ->toContain('"logoDark":"\/brand\/mark-dark.svg"');
});
