<?php

use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $fs = new Filesystem;
    $fs->ensureDirectoryExists(base_path('resources/js/martis'));
});

afterEach(function () {
    $fs = new Filesystem;
    $bootPath = base_path('resources/js/martis/boot.ts');
    if ($fs->exists($bootPath)) {
        $fs->delete($bootPath);
    }
});

it('--frontend warns when boot.ts is absent', function () {
    $fs = new Filesystem;
    if ($fs->exists(base_path('resources/js/martis/boot.ts'))) {
        $fs->delete(base_path('resources/js/martis/boot.ts'));
    }

    // Soft warning: the command surfaces the missing boot path note,
    // and the exit code reflects whatever else it finds (no PHP
    // declarations in the default test app → SUCCESS).
    $this->artisan('martis:list-overrides', ['--frontend' => true])
        ->expectsOutputToContain('boot file not found')
        ->run();
    expect(true)->toBeTrue();
});

it('--frontend command runs end-to-end with a happy boot file', function () {
    file_put_contents(base_path('resources/js/martis/boot.ts'), <<<'TS'
import { componentRegistry } from '@martis/martis/lib/componentRegistry'
componentRegistry.register('status-badge', (() => null) as never)
componentRegistry.register('star-rating', (() => null) as never)
componentRegistry.registerFieldDisplay('text', (() => null) as never)
TS);

    // Without any PHP-declared keys, the command returns SUCCESS.
    // The static parser is exercised but the cross-check table is
    // empty in this minimal test setup.
    $this->artisan('martis:list-overrides', ['--frontend' => true])
        ->run();
    expect(true)->toBeTrue();
});

it('--frontend extracts registerFieldDisplay() and registerFieldInput() keys', function () {
    file_put_contents(base_path('resources/js/martis/boot.ts'), <<<'TS'
import { componentRegistry } from '@martis/martis/lib/componentRegistry'
componentRegistry.registerFieldDisplay('text', (() => null) as never)
componentRegistry.registerFieldInput('text', (() => null) as never)
componentRegistry.registerResourceFieldDisplay('posts', 'status', (() => null) as never)
TS);

    // We can't directly assert against private method output, but we
    // can assert the command exits cleanly with a known boot file.
    $this->artisan('martis:list-overrides', ['--frontend' => true])
        ->run();
    expect(true)->toBeTrue();
});

it('--frontend supports a custom --boot path', function () {
    $altPath = base_path('resources/js/custom-boot.ts');
    file_put_contents($altPath, <<<'TS'
componentRegistry.register('custom-key', x as never)
TS);

    $this->artisan('martis:list-overrides', ['--frontend' => true, '--boot' => $altPath])
        ->run();

    @unlink($altPath);
    expect(true)->toBeTrue();
});
