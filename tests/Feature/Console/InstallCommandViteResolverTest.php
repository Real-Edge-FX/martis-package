<?php

declare(strict_types=1);

use Martis\Console\InstallCommand;

/**
 * Pure unit tests for `InstallCommand::resolveVitePluginReactPair()`.
 *
 * The resolver decides which `@vitejs/plugin-react` range
 * `martis:install` should write into the host `package.json`, given:
 *   - the host's existing `devDependencies.vite` constraint, if any
 *   - the operator-set `MARTIS_PLUGIN_REACT_RANGE` env override, if any
 *
 * It must never silently pick a range that conflicts with the host's
 * Vite major (the bug v1.12.0 introduced when it allowed
 * `plugin-react ^6` against a Vite 7 scaffold).
 */
it('uses the env override verbatim when set to a valid range', function () {
    $r = InstallCommand::resolveVitePluginReactPair('^7', '^7');

    expect($r['plugin_react_range'])->toBe('^7')
        ->and($r['source'])->toBe('env')
        ->and($r['warnings'])->toBe([]);
});

it('trims whitespace around env override values', function () {
    $r = InstallCommand::resolveVitePluginReactPair('^7', '  ^5  ');

    expect($r['plugin_react_range'])->toBe('^5')
        ->and($r['source'])->toBe('env');
});

it('treats a mal-formed env override as skip + warn', function () {
    $r = InstallCommand::resolveVitePluginReactPair('^7', 'not a semver range!!');

    expect($r['plugin_react_range'])->toBeNull()
        ->and($r['source'])->toBe('env-invalid')
        ->and($r['warnings'])->toHaveCount(1);
    expect($r['warnings'][0])->toContain('MARTIS_PLUGIN_REACT_RANGE');
});

it('writes the default pair when the host has no vite at all', function () {
    $r = InstallCommand::resolveVitePluginReactPair(null, null);

    expect($r['source'])->toBe('default')
        ->and($r['vite_range'])->toBe('^7')
        ->and($r['plugin_react_range'])->toBe('^5')
        ->and($r['warnings'])->toBe([]);
});

it('writes the default pair when the host vite range is empty', function () {
    $r = InstallCommand::resolveVitePluginReactPair('   ', null);

    expect($r['source'])->toBe('default')
        ->and($r['plugin_react_range'])->toBe('^5');
});

it('looks up the plugin-react range for each known vite major', function (int $major, string $expected) {
    $r = InstallCommand::resolveVitePluginReactPair('^'.$major, null);

    expect($r['source'])->toBe('table')
        ->and($r['vite_range'])->toBe('^'.$major)
        ->and($r['plugin_react_range'])->toBe($expected)
        ->and($r['warnings'])->toBe([]);
})->with([
    [4, '^4'],
    [5, '^4 || ^5'],
    [6, '^5'],
    [7, '^5'],
    [8, '^6'],
    [9, '^7'],
]);

it('picks the highest caret major from a caret-OR host range', function () {
    $r = InstallCommand::resolveVitePluginReactPair('^5 || ^6 || ^7', null);

    // npm resolves caret-OR to the top match, so we follow the same
    // rule — plugin-react must be compat with vite 7.
    expect($r['plugin_react_range'])->toBe('^5')
        ->and($r['source'])->toBe('table');
});

it('parses tilde ranges by their first integer', function () {
    $r = InstallCommand::resolveVitePluginReactPair('~7.0.3', null);

    expect($r['plugin_react_range'])->toBe('^5')
        ->and($r['source'])->toBe('table');
});

it('parses comparator pairs by their first integer', function () {
    $r = InstallCommand::resolveVitePluginReactPair('>=7 <8', null);

    expect($r['plugin_react_range'])->toBe('^5')
        ->and($r['source'])->toBe('table');
});

it('skips with a loud warning when the vite major is beyond the table', function () {
    $r = InstallCommand::resolveVitePluginReactPair('^10', null);

    expect($r['plugin_react_range'])->toBeNull()
        ->and($r['source'])->toBe('unknown-vite')
        ->and($r['vite_range'])->toBe('^10')
        ->and($r['warnings'])->toHaveCount(1);
    expect($r['warnings'][0])
        ->toContain('Vite ^10')
        ->and($r['warnings'][0])->toContain('MARTIS_PLUGIN_REACT_RANGE')
        ->and($r['warnings'][0])->toContain('martis:install');
});

it('lets the env override beat an unknown-vite skip', function () {
    $r = InstallCommand::resolveVitePluginReactPair('^10', '^7');

    expect($r['plugin_react_range'])->toBe('^7')
        ->and($r['source'])->toBe('env')
        ->and($r['warnings'])->toBe([]);
});

it('falls through with a warn when the host vite range has no digits', function () {
    $r = InstallCommand::resolveVitePluginReactPair('latest', null);

    expect($r['plugin_react_range'])->toBeNull()
        ->and($r['source'])->toBe('parse-failed')
        ->and($r['warnings'])->toHaveCount(1);
});

it('keeps the host vite range untouched when one is present', function () {
    $r = InstallCommand::resolveVitePluginReactPair('^7.2.1', null);

    expect($r['vite_range'])->toBe('^7.2.1');
});
