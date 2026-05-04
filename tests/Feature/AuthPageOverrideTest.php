<?php

use Illuminate\Filesystem\Filesystem;
use Martis\Console\ComponentMakeCommand;

// ---------------------------------------------------------------------------
// Each --type for an auth page must (v1.9.0+ zero-config convention):
//   1. Write a TSX file at resources/js/martis-extensions/overrides/{FixedFilename}.tsx.
//   2. Use the canonical filename per type (LoginPage / RegisterPage /
//      ForgotPasswordPage / ResetPasswordPage / EmailVerifyNoticePage).
//   3. The auto-discovery entry index.ts maps each filename to the
//      auth:{flow} registry key — no manual register call, no boot.ts.
// ---------------------------------------------------------------------------

beforeEach(function () {
    /** @var Filesystem $fs */
    $fs = new Filesystem;
    $extensionsDir = base_path('resources/js/martis-extensions');
    if ($fs->exists($extensionsDir)) {
        $fs->deleteDirectory($extensionsDir);
    }
});

afterAll(function () {
    /** @var Filesystem $fs */
    $fs = new Filesystem;
    $extensionsDir = base_path('resources/js/martis-extensions');
    if ($fs->exists($extensionsDir)) {
        $fs->deleteDirectory($extensionsDir);
    }
});

dataset('auth_pages', [
    ['login-page', 'LoginPage', 'auth:login'],
    ['register-page', 'RegisterPage', 'auth:register'],
    ['forgot-password-page', 'ForgotPasswordPage', 'auth:forgot-password'],
    ['reset-password-page', 'ResetPasswordPage', 'auth:reset-password'],
    ['email-verify-notice-page', 'EmailVerifyNoticePage', 'auth:email-verify-notice'],
]);

it('scaffolds the auth-page override TSX in the overrides bucket', function (string $type, string $expectedFilename, string $expectedKey) {
    // The user-supplied `name` is intentionally ignored for fixed
    // pieces — the canonical filename ALWAYS wins so the
    // auto-discovery key map (filename → key) stays consistent.
    $exitCode = $this->artisan('martis:component', [
        'name' => 'IgnoredName',
        '--type' => $type,
    ])->run();

    expect($exitCode)->toBe(0);

    $componentPath = base_path("resources/js/martis-extensions/overrides/{$expectedFilename}.tsx");
    expect(file_exists($componentPath))->toBeTrue();

    $content = (string) file_get_contents($componentPath);
    // Stub still uses the {{ class }} placeholder, replaced with the
    // canonical filename.
    expect($content)->toContain("export function {$expectedFilename}");
})->with('auth_pages');

it('does NOT touch boot.ts or any legacy registration file', function (string $type) {
    $this->artisan('martis:component', [
        'name' => 'IgnoredName',
        '--type' => $type,
    ])->run();

    // boot.ts is the pre-v1.9 mechanism. v1.9 must not create it.
    expect(file_exists(base_path('resources/js/martis-extensions/martis/boot.ts')))->toBeFalse();
    expect(file_exists(resource_path('martis-extensions/martis/boot.ts')))->toBeFalse();
})->with('auth_pages');

it('rejects unknown --type values', function () {
    $exitCode = $this->artisan('martis:component', [
        'name' => 'Foo',
        '--type' => 'not-a-real-type',
    ])->run();

    expect($exitCode)->not->toBe(0);
});

it('the auth-page key constants stay in sync with the type list', function () {
    $reflection = new ReflectionClass(ComponentMakeCommand::class);
    $authPages = $reflection->getReflectionConstant('AUTH_PAGES')->getValue();

    expect(array_keys($authPages))->toBe([
        'login-page',
        'register-page',
        'forgot-password-page',
        'reset-password-page',
        'email-verify-notice-page',
    ]);

    // The fixed-filename convention is structural — each entry must
    // expose `filename` + `key` + `stub` so the dispatcher in
    // ComponentMakeCommand::generateFixedPiece() can rely on them.
    foreach ($authPages as $type => $meta) {
        expect($meta)->toHaveKeys(['filename', 'key', 'stub']);
    }
});
