<?php

use Illuminate\Filesystem\Filesystem;
use Martis\Console\InstallCommand;
use Martis\Stubs\StubResolver;

/**
 * Coverage for the v1.9.0 extension scaffold published by
 * `martis:install` — `vite.extensions.config.ts`,
 * `tsconfig.extensions.json`, the auto-discovery entry, the four
 * bucket directories, and the `package.json` `build:extensions`
 * script update.
 *
 * Drives the full `martis:install` command through `artisan(...)` so
 * the tests exercise the wiring exactly as a consumer would. Cleans
 * up afterwards so testbench's shared app dir does not leak state
 * between parallel workers.
 */
beforeEach(function () {
    $this->fs = new Filesystem;

    $this->paths = [
        'vite' => base_path('vite.extensions.config.ts'),
        'tsconfig' => base_path('tsconfig.extensions.json'),
        'index' => base_path('resources/js/martis-extensions/index.ts'),
        'tools_dir' => base_path('resources/js/martis-extensions/tools'),
        'fields_dir' => base_path('resources/js/martis-extensions/fields'),
        'cards_dir' => base_path('resources/js/martis-extensions/cards'),
        'overrides_dir' => base_path('resources/js/martis-extensions/overrides'),
        'extensions_root' => base_path('resources/js/martis-extensions'),
        'package_json' => base_path('package.json'),
    ];

    // Clean any pre-existing scaffold from prior runs / parallel workers.
    foreach (['vite', 'tsconfig'] as $f) {
        if ($this->fs->exists($this->paths[$f])) {
            $this->fs->delete($this->paths[$f]);
        }
    }
    if (is_dir($this->paths['extensions_root'])) {
        $this->fs->deleteDirectory($this->paths['extensions_root']);
    }
});

afterEach(function () {
    foreach ([$this->paths['vite'], $this->paths['tsconfig']] as $file) {
        if ($this->fs->exists($file)) {
            $this->fs->delete($file);
        }
    }
    if (is_dir($this->paths['extensions_root'])) {
        $this->fs->deleteDirectory($this->paths['extensions_root']);
    }
});

it('martis:install publishes the extension scaffold tree', function () {
    $this->artisan('martis:install', ['--force' => true])->assertSuccessful();

    expect($this->fs->exists($this->paths['vite']))->toBeTrue();
    expect($this->fs->exists($this->paths['tsconfig']))->toBeTrue();
    expect($this->fs->exists($this->paths['index']))->toBeTrue();

    foreach (['tools_dir', 'fields_dir', 'cards_dir', 'overrides_dir'] as $bucket) {
        expect(is_dir($this->paths[$bucket]))->toBeTrue();
    }

    $viteContents = (string) $this->fs->get($this->paths['vite']);
    expect($viteContents)
        ->toContain("outDir: 'public/vendor/martis-user'")
        // v1.9.3 swapped rollup `external`+`globals` for vite alias
        // shims because ES module output ignores `globals`. The
        // bundle now resolves React via window.Martis.react at build
        // time through the published .shims/ files. v1.9.4 pins the
        // alias array order so react/jsx-runtime is matched before
        // the bare `react` prefix.
        ->toContain('react.mjs')
        ->toContain('react-jsx-runtime.mjs')
        ->toContain("find: 'react/jsx-runtime'");

    expect($this->fs->exists(base_path('resources/js/martis-extensions/.shims/react.mjs')))->toBeTrue();
    expect($this->fs->exists(base_path('resources/js/martis-extensions/.shims/react-jsx-runtime.mjs')))->toBeTrue();

    $indexContents = (string) $this->fs->get($this->paths['index']);
    expect($indexContents)
        ->toContain('import.meta.glob')
        ->toContain('window.Martis')
        ->toContain('OVERRIDE_KEYS');
});

it('martis:install does not overwrite a customised vite.extensions.config.ts without --force', function () {
    $this->fs->ensureDirectoryExists(dirname($this->paths['vite']));
    $this->fs->put($this->paths['vite'], '// existing custom content — DO NOT TOUCH');

    // No --force: the existing file is left alone.
    $this->artisan('martis:install')->assertSuccessful();

    expect((string) $this->fs->get($this->paths['vite']))->toContain('DO NOT TOUCH');
});

it('martis:install adds the build:extensions script to package.json', function () {
    $original = $this->fs->exists($this->paths['package_json'])
        ? (string) $this->fs->get($this->paths['package_json'])
        : null;

    $this->fs->put($this->paths['package_json'], json_encode([
        'name' => 'consumer-test-app',
        'private' => true,
        'scripts' => [
            'build' => 'vite build',
            'dev' => 'vite',
        ],
    ], JSON_PRETTY_PRINT));

    try {
        $this->artisan('martis:install', ['--force' => true])->assertSuccessful();

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $this->fs->get($this->paths['package_json']), true);
        expect($decoded['scripts']['build:extensions'] ?? null)
            ->toBe('vite build --config vite.extensions.config.ts');
    } finally {
        if ($original !== null) {
            $this->fs->put($this->paths['package_json'], $original);
        } else {
            $this->fs->delete($this->paths['package_json']);
        }
    }
});

it('martis:install is a no-op for package.json when none exists', function () {
    $original = $this->fs->exists($this->paths['package_json']);
    $backup = null;
    if ($original) {
        $backup = (string) $this->fs->get($this->paths['package_json']);
        $this->fs->delete($this->paths['package_json']);
    }

    try {
        // Should not error when package.json is missing.
        $this->artisan('martis:install', ['--force' => true])->assertSuccessful();

        expect($this->fs->exists($this->paths['package_json']))->toBeFalse();
    } finally {
        if ($backup !== null) {
            $this->fs->put($this->paths['package_json'], $backup);
        }
    }
});

it('martis:install publishes the declarations of the shims next to them', function () {
    $this->artisan('martis:install', ['--force' => true])->assertSuccessful();

    foreach (['runtime', 'react-router-dom', 'react-i18next', 'tanstack-react-query'] as $shim) {
        $published = base_path("resources/js/martis-extensions/.shims/{$shim}.d.mts");

        expect($this->fs->exists($published))->toBeTrue()
            ->and((string) $this->fs->get($published))
            ->toBe((string) $this->fs->get(StubResolver::packagePath("extensions/{$shim}-shim.d.mts.stub")));
    }
});

it('the martis-extension-shims tag publishes every shim and its declarations, and nothing else', function () {
    $this->artisan('vendor:publish', ['--tag' => 'martis-extension-shims'])->assertSuccessful();

    // Every shim stub the package ships is in the list the tag and the installer share.
    $stubs = array_map('basename', glob(StubResolver::packagePath('extensions').'/*-shim.*.stub') ?: []);
    expect(array_keys(InstallCommand::EXTENSION_SHIMS))->toEqualCanonicalizing($stubs);

    foreach (InstallCommand::EXTENSION_SHIMS as $stub => $target) {
        expect((string) $this->fs->get(base_path($target)))
            ->toBe((string) $this->fs->get(StubResolver::packagePath('extensions/'.$stub)));
    }

    // The Vite config, the tsconfig and the entry stay the app's.
    expect($this->fs->exists($this->paths['vite']))->toBeFalse()
        ->and($this->fs->exists($this->paths['tsconfig']))->toBeFalse()
        ->and($this->fs->exists($this->paths['index']))->toBeFalse();
});

it('the martis-extension-shims tag rewrites an existing shim only with --force', function () {
    $runtime = base_path('resources/js/martis-extensions/.shims/runtime.mjs');
    $this->fs->ensureDirectoryExists(dirname($runtime));
    $this->fs->put($runtime, '// stale shim');

    $this->artisan('vendor:publish', ['--tag' => 'martis-extension-shims'])->assertSuccessful();
    expect((string) $this->fs->get($runtime))->toBe('// stale shim');

    $this->artisan('vendor:publish', ['--tag' => 'martis-extension-shims', '--force' => true])->assertSuccessful();
    expect((string) $this->fs->get($runtime))
        ->toBe((string) $this->fs->get(StubResolver::packagePath('extensions/runtime-shim.mjs.stub')));
});

it('martis:install publishes a tsconfig.json next to the extension sources for editors', function () {
    $this->artisan('martis:install', ['--force' => true])->assertSuccessful();

    $published = base_path('resources/js/martis-extensions/tsconfig.json');
    expect($this->fs->exists($published))->toBeTrue();

    /** @var array{extends: string, include: list<string>} $config */
    $config = json_decode((string) $this->fs->get($published), true);
    expect($config['extends'])->toBe('../../../tsconfig.extensions.json')
        ->and($config['include'])->toBe(['./**/*']);
});
