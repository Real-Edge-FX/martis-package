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
    ['password-change-page', 'PasswordChangePage', 'auth:password-change'],
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
    // v1.10+ stubs use `export default function` (so the auto-discovery
    // loop reads `mod.default` and registers them) and import package
    // internals from `@martis/runtime` instead of `@/contexts/...`.
    expect($content)
        ->toContain("export default function {$expectedFilename}")
        ->toContain("from '@martis/runtime'")
        ->not->toContain("from '@/contexts/")
        ->not->toContain("from '@/lib/");
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
        'password-change-page',
    ]);

    // The fixed-filename convention is structural — each entry must
    // expose `filename` + `key` + `stub` so the dispatcher in
    // ComponentMakeCommand::generateFixedPiece() can rely on them.
    foreach ($authPages as $type => $meta) {
        expect($meta)->toHaveKeys(['filename', 'key', 'stub']);
    }
});

/*
 * An app keeps its own resources/js/martis-extensions/index.ts: refreshing
 * the shims never rewrites it. When it predates a fixed-key type (an app
 * installed before v2.3.0 has no PasswordChangePage in OVERRIDE_KEYS), the
 * override builds but registers under its derived key and never renders.
 * The generator says so and names the line to add.
 */
function authPageOverrideIndex(?callable $edit = null): void
{
    $stub = (string) file_get_contents(__DIR__.'/../../stubs/extensions/index.ts.stub');
    $path = base_path('resources/js/martis-extensions/index.ts');
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, $edit === null ? $stub : $edit($stub));
}

it('fails loudly, naming the line to add, when the app index.ts does not map the fixed key', function () {
    authPageOverrideIndex(fn (string $stub) => str_replace("  PasswordChangePage: 'auth:password-change',\n", '', $stub));

    $this->artisan('martis:component', ['--type' => 'password-change-page'])
        ->expectsOutputToContain("PasswordChangePage: 'auth:password-change',")
        ->assertFailed();

    // The component is written all the same: only the wiring is missing.
    expect(file_exists(base_path('resources/js/martis-extensions/overrides/PasswordChangePage.tsx')))->toBeTrue();
});

it('passes when the app index.ts maps the fixed key (control)', function (string $type) {
    authPageOverrideIndex();

    $this->artisan('martis:component', ['--type' => $type])->assertSuccessful();
})->with(['password-change-page', 'login-page', 'sidebar']);

it('ships an index.ts that maps every fixed-filename type the generator writes', function () {
    $stub = (string) file_get_contents(__DIR__.'/../../stubs/extensions/index.ts.stub');
    $reflection = new ReflectionClass(ComponentMakeCommand::class);
    $pieces = [
        ...$reflection->getReflectionConstant('SHELL_PIECES')->getValue(),
        ...$reflection->getReflectionConstant('AUTH_PAGES')->getValue(),
    ];

    foreach ($pieces as $piece) {
        expect($stub)->toContain("{$piece['filename']}: '{$piece['key']}'");
    }
});
