<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

/*
 * The env wrappers of config/martis.php, exercised for real: each case sets
 * the environment variable, loads the config file the way the framework does
 * at boot and reads the value back from the array it returns. A renamed or
 * dropped env() call in the file fails here; setting a value through
 * config()->set() and reading it back would prove nothing about the file.
 */

/**
 * Load config/martis.php with the given environment variables set, then put
 * the environment back as it was.
 *
 * @param  array<string, string>  $env
 * @return array<string, mixed>
 */
function martisConfigLoadedWithEnv(array $env): array
{
    $previous = [];

    foreach ($env as $name => $value) {
        $previous[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    try {
        return require __DIR__.'/../../config/martis.php';
    } finally {
        foreach ($previous as $name => [$getenv, $fromEnv, $fromServer]) {
            putenv($getenv === false ? $name : "{$name}={$getenv}");

            if ($fromEnv === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $fromEnv;
            }

            if ($fromServer === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $fromServer;
            }
        }
    }
}

it('reads each brand, footer, welcome, preference and loader key from its environment variable', function (string $env, string $value, string $key, mixed $expected) {
    $config = martisConfigLoadedWithEnv([$env => $value]);

    expect(Arr::get($config, $key))->toBe($expected);
})->with([
    ['MARTIS_BRAND_LOGO', '/img/edgeflow-logo.svg', 'brand.logo', '/img/edgeflow-logo.svg'],
    ['MARTIS_BRAND_ICON', '/img/edgeflow-icon.png', 'brand.icon', '/img/edgeflow-icon.png'],
    ['MARTIS_BRAND_LOGO_DARK', '/img/logo-dark.svg', 'brand.logo_dark', '/img/logo-dark.svg'],
    ['MARTIS_BRAND_ICON_DARK', '/img/icon-dark.png', 'brand.icon_dark', '/img/icon-dark.png'],
    ['MARTIS_BRAND_LOGO_HEIGHT_MENU', '28', 'brand.logo_height.menu', '28'],
    ['MARTIS_BRAND_LOGO_HEIGHT_AUTH', '44', 'brand.logo_height.auth', '44'],
    ['MARTIS_FOOTER_TEXT', '© 2026 EdgeFlow. All rights reserved.', 'footer.text', '© 2026 EdgeFlow. All rights reserved.'],
    ['MARTIS_WELCOME_HEADING', 'Welcome to EdgeFlow', 'welcome.heading', 'Welcome to EdgeFlow'],
    ['MARTIS_WELCOME_DESCRIPTION', 'NQ/ES/YM regime intelligence for futures traders.', 'welcome.description', 'NQ/ES/YM regime intelligence for futures traders.'],
    ['MARTIS_DEFAULT_THEME', 'light', 'preferences.defaults.theme', 'light'],
    ['MARTIS_DEFAULT_ACCENT', 'teal', 'preferences.defaults.accent', 'teal'],
    ['MARTIS_DEFAULT_DENSITY', 'dense', 'preferences.defaults.density', 'dense'],
    ['MARTIS_CUSTOM_ACCENTS', 'edgeflow:#1a73e8', 'preferences.custom_accents', 'edgeflow:#1a73e8'],
    ['MARTIS_LOADER_DISABLED', 'true', 'loader.disabled', true],
]);

it('keeps the shipped defaults when none of those variables is set', function () {
    $config = martisConfigLoadedWithEnv([]);

    expect(Arr::get($config, 'brand.logo'))->toBeNull()
        ->and(Arr::get($config, 'brand.icon'))->toBeNull()
        ->and(Arr::get($config, 'footer.text'))->toBeNull()
        ->and(Arr::get($config, 'welcome.heading'))->toBeNull()
        ->and(Arr::get($config, 'preferences.defaults.theme'))->toBe('dark')
        ->and(Arr::get($config, 'preferences.defaults.density'))->toBe('comfortable')
        ->and(Arr::get($config, 'loader.disabled'))->toBeFalse();
});
