<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

// ---------------------------------------------------------------------------
// Each --type for an auth page must:
//   1. Write a TSX file at resources/martis-extensions/martis/components/{Name}.tsx
//   2. Add the matching import + componentRegistry.register() to boot.ts
//   3. Use the right registry key: auth:login / auth:register / auth:forgot-password
//      / auth:reset-password / auth:email-verify-notice
//
// Tests mirror PolicyMakeCommandTest's pattern: scratch dir, run command,
// assert file system state, clean up.
// ---------------------------------------------------------------------------

beforeEach(function () {
    /** @var Filesystem $fs */
    $fs = new Filesystem;
    $extensionsDir = resource_path('martis-extensions');
    if ($fs->exists($extensionsDir)) {
        $fs->deleteDirectory($extensionsDir);
    }
});

afterAll(function () {
    /** @var Filesystem $fs */
    $fs = new Filesystem;
    $extensionsDir = resource_path('martis-extensions');
    if ($fs->exists($extensionsDir)) {
        $fs->deleteDirectory($extensionsDir);
    }
});

dataset('auth_pages', [
    ['login-page', 'auth:login'],
    ['register-page', 'auth:register'],
    ['forgot-password-page', 'auth:forgot-password'],
    ['reset-password-page', 'auth:reset-password'],
    ['email-verify-notice-page', 'auth:email-verify-notice'],
]);

it('scaffolds the override TSX file', function (string $type, string $expectedKey) {
    $name = 'My'.Str::studly($type);

    $exitCode = $this->artisan('martis:component', [
        'name' => $name,
        '--type' => $type,
    ])->run();

    expect($exitCode)->toBe(0);

    $componentPath = resource_path("martis-extensions/martis/components/{$name}.tsx");
    expect(file_exists($componentPath))->toBeTrue();

    $content = (string) file_get_contents($componentPath);
    expect($content)->toContain("export function {$name}()");
})->with('auth_pages');

it('registers the component under the right registry key', function (string $type, string $expectedKey) {
    $name = 'My'.Str::studly($type);

    $this->artisan('martis:component', [
        'name' => $name,
        '--type' => $type,
    ])->run();

    $bootPath = resource_path('martis-extensions/martis/boot.ts');
    expect(file_exists($bootPath))->toBeTrue();

    $boot = (string) file_get_contents($bootPath);
    expect($boot)->toContain("import { {$name} } from './components/{$name}'");
    expect($boot)->toContain("componentRegistry.register('{$expectedKey}', {$name} as never)");
})->with('auth_pages');

it('rejects unknown --type values', function () {
    $exitCode = $this->artisan('martis:component', [
        'name' => 'Foo',
        '--type' => 'not-a-real-type',
    ])->run();

    // Symfony Console returns 1 (FAILURE) when our handle() returns FAILURE.
    expect($exitCode)->not->toBe(0);
});

it('the auth-page key constants stay in sync with the type list', function () {
    // Use reflection so a typo in either AUTH_PAGES or $allowedTypes
    // surfaces during CI rather than at runtime in a consumer app.
    $reflection = new ReflectionClass(\Martis\Console\ComponentMakeCommand::class);
    $authPages = $reflection->getReflectionConstant('AUTH_PAGES')->getValue();

    expect(array_keys($authPages))->toBe([
        'login-page',
        'register-page',
        'forgot-password-page',
        'reset-password-page',
        'email-verify-notice-page',
    ]);
});
