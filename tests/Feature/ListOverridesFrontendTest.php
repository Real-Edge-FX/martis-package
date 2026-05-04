<?php

use Illuminate\Filesystem\Filesystem;
use Martis\Console\ListOverridesCommand;

/**
 * `martis:list-overrides --frontend` smoke specs (v1.10+ rewrite).
 *
 * The pre-v1.10 implementation parsed `resources/js/martis/boot.ts`
 * statically. Since v1.8.19 retired the `boot.ts` mechanism the
 * static parser was dead code; v1.10 rewrites the cross-check to
 * walk `resources/js/martis-extensions/{tools,fields,cards,overrides}/`
 * and derive the keys each `.tsx` file would auto-register through
 * the bundle entry's `import.meta.glob` loop. These specs cover the
 * happy path + the missing-directory case + the override key map.
 */
beforeEach(function () {
    $fs = new Filesystem;
    $extensionsRoot = base_path('resources/js/martis-extensions');
    if ($fs->exists($extensionsRoot)) {
        $fs->deleteDirectory($extensionsRoot);
    }
});

afterEach(function () {
    $fs = new Filesystem;
    $extensionsRoot = base_path('resources/js/martis-extensions');
    if ($fs->exists($extensionsRoot)) {
        $fs->deleteDirectory($extensionsRoot);
    }
});

it('--frontend warns when the extensions directory is absent', function () {
    // Default test app has no `resources/js/martis-extensions/` until
    // `martis:install` runs. The command should surface a soft warning
    // and still exit cleanly.
    $this->artisan('martis:list-overrides', ['--frontend' => true])
        ->expectsOutputToContain('extensions directory not found')
        ->run();

    expect(true)->toBeTrue();
});

it('--frontend discovers tool/field/card filenames in their respective buckets', function () {
    $fs = new Filesystem;
    foreach (['tools', 'fields', 'cards', 'overrides'] as $bucket) {
        $fs->ensureDirectoryExists(base_path("resources/js/martis-extensions/{$bucket}"));
    }
    file_put_contents(base_path('resources/js/martis-extensions/tools/Charts.tsx'), '// stub');
    file_put_contents(base_path('resources/js/martis-extensions/fields/Rating.tsx'), '// stub');
    file_put_contents(base_path('resources/js/martis-extensions/cards/RevenueGauge.tsx'), '// stub');
    file_put_contents(base_path('resources/js/martis-extensions/overrides/Sidebar.tsx'), '// stub');
    file_put_contents(base_path('resources/js/martis-extensions/overrides/LoginPage.tsx'), '// stub');

    // No PHP-declared keys in the test app, so the cross-check table
    // is empty and the command exits clean. The discovery itself is
    // covered by the unit assertion below.
    $this->artisan('martis:list-overrides', ['--frontend' => true])
        ->run();

    // Reflect into the command to exercise the discovery method
    // directly. Robust against future test-app additions that might
    // pollute the rows table.
    $command = new ListOverridesCommand;
    $reflection = new ReflectionMethod($command, 'discoverRegisteredKeys');
    $reflection->setAccessible(true);
    /** @var list<string> $keys */
    $keys = $reflection->invoke($command, base_path('resources/js/martis-extensions'));

    expect($keys)
        ->toContain('tool:charts')
        ->toContain('field:rating')
        ->toContain('card:revenue-gauge')
        ->toContain('layout:sidebar')
        ->toContain('auth:login');
});

it('--frontend derives keys for arbitrary override filenames (v1.10.1+)', function () {
    $fs = new Filesystem;
    $fs->ensureDirectoryExists(base_path('resources/js/martis-extensions/overrides'));
    file_put_contents(
        base_path('resources/js/martis-extensions/overrides/StatusBadge.tsx'),
        '// stub',
    );

    $command = new ListOverridesCommand;
    $reflection = new ReflectionMethod($command, 'discoverRegisteredKeys');
    $reflection->setAccessible(true);
    /** @var list<string> $keys */
    $keys = $reflection->invoke($command, base_path('resources/js/martis-extensions'));

    // v1.10.1+ derives `{kebab}` + `{kebab}-input` for any
    // non-canonical override filename. The bundle's auto-discovery
    // loop binds Display + Input named exports to those keys, and
    // single-default-export overrides to `{kebab}` alone.
    expect($keys)
        ->toContain('status-badge')
        ->toContain('status-badge-input');
});

it('--frontend supports a custom --extensions-dir path', function () {
    $alt = base_path('resources/js/custom-extensions');
    $fs = new Filesystem;
    $fs->ensureDirectoryExists($alt.'/tools');
    file_put_contents($alt.'/tools/Status.tsx', '// stub');

    try {
        $this->artisan('martis:list-overrides', [
            '--frontend' => true,
            '--extensions-dir' => $alt,
        ])->run();

        $command = new ListOverridesCommand;
        $reflection = new ReflectionMethod($command, 'discoverRegisteredKeys');
        $reflection->setAccessible(true);
        /** @var list<string> $keys */
        $keys = $reflection->invoke($command, $alt);

        expect($keys)->toContain('tool:status');
    } finally {
        $fs->deleteDirectory($alt);
    }
});
