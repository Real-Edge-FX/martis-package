<?php

use Illuminate\Filesystem\Filesystem;

function cleanupThemeArtifacts(string $name = 'test-theme'): void
{
    $fs = new Filesystem;
    $fs->delete(resource_path("css/martis/{$name}.css"));
    $fs->delete(public_path("vendor/martis/themes/{$name}.css"));
    $fs->deleteDirectory(resource_path('css/martis'));
    $fs->deleteDirectory(public_path('vendor/martis/themes'));
    removeThemeState();
}

beforeEach(function () {
    cleanupThemeArtifacts();

    // Every martis:theme run writes theme.name into a published
    // config/martis.php; the config specs below write a fixture there.
    $this->restoreMartisConfig = preservePublishedMartisConfig();
});

afterEach(function () {
    cleanupThemeArtifacts();

    ($this->restoreMartisConfig)();
});

it('generates the theme file in resources and public', function () {
    $this->artisan('martis:theme', ['name' => 'test-theme'])->assertSuccessful();

    expect(file_exists(resource_path('css/martis/test-theme.css')))->toBeTrue();
    expect(file_exists(public_path('vendor/martis/themes/test-theme.css')))->toBeTrue();
});

it('tells the user to edit the source and publish it', function () {
    // The published copy is regenerated from the source on every
    // martis:publish-assets, so the hint must never point at the copy.
    $this->artisan('martis:theme', ['name' => 'test-theme'])
        ->expectsOutputToContain('Edit CSS variables in resources/css/martis/test-theme.css')
        ->expectsOutputToContain('php artisan martis:publish-assets --themes-only')
        ->doesntExpectOutputToContain('Edit CSS variables in public/vendor/martis/themes/test-theme.css')
        ->assertSuccessful();
});

it('writes a source whose header says to publish it after editing', function () {
    $this->artisan('martis:theme', ['name' => 'test-theme'])->assertSuccessful();

    $contents = (string) file_get_contents(resource_path('css/martis/test-theme.css'));

    expect($contents)->toContain('php artisan martis:publish-assets --themes-only');
    expect($contents)->not->toContain('no rebuild required');
});

it('backs up a published copy edited in place before --force overwrites it', function () {
    $this->artisan('martis:theme', ['name' => 'test-theme'])->assertSuccessful();
    file_put_contents(public_path('vendor/martis/themes/test-theme.css'), ':root { --martis-accent: #abcdef; }');

    $this->artisan('martis:theme', ['name' => 'test-theme', '--force' => true])
        ->expectsOutputToContain('public/vendor/martis/themes/test-theme.css differs from its source')
        ->expectsOutputToContain('Backed up to storage/app/martis/theme-backups/')
        ->assertSuccessful();

    expect(array_values(themeBackups()))->toBe([':root { --martis-accent: #abcdef; }']);
});

it('backs up a published theme without a source before scaffolding over it', function () {
    $fs = new Filesystem;
    $fs->ensureDirectoryExists(public_path('vendor/martis/themes'));
    $fs->put(public_path('vendor/martis/themes/test-theme.css'), ':root { --martis-accent: #abcdef; }');

    $this->artisan('martis:theme', ['name' => 'test-theme'])
        ->expectsOutputToContain('public/vendor/martis/themes/test-theme.css has no source in resources/css/martis/')
        ->assertSuccessful();

    expect(array_values(themeBackups()))->toBe([':root { --martis-accent: #abcdef; }']);
});

it('backs up the published copy when its source is gone, even if the record matches it', function () {
    // After a publish the record holds the copy's hash; once the source is
    // deleted, that copy is the only one left of the theme.
    $this->artisan('martis:theme', ['name' => 'test-theme'])->assertSuccessful();
    file_put_contents(resource_path('css/martis/test-theme.css'), ':root { --martis-accent: #abcdef; }');
    $this->artisan('martis:publish-assets', ['--themes-only' => true])->assertSuccessful();
    unlink(resource_path('css/martis/test-theme.css'));

    $this->artisan('martis:theme', ['name' => 'test-theme'])
        ->expectsOutputToContain('public/vendor/martis/themes/test-theme.css has no source in resources/css/martis/')
        ->assertSuccessful();

    expect(array_values(themeBackups()))->toBe([':root { --martis-accent: #abcdef; }']);
});

it('backs up the source it overwrites with --force', function () {
    // After the edit-and-publish loop the source holds the edits and the
    // published copy matches it: --force would leave no copy of them.
    $this->artisan('martis:theme', ['name' => 'test-theme'])->assertSuccessful();
    file_put_contents(resource_path('css/martis/test-theme.css'), ':root { --martis-accent: #abcdef; }');
    $this->artisan('martis:publish-assets', ['--themes-only' => true])->assertSuccessful();

    $this->artisan('martis:theme', ['name' => 'test-theme', '--force' => true])
        ->expectsOutputToContain('resources/css/martis/test-theme.css differs from the scaffold')
        ->assertSuccessful();

    $backups = themeBackups();
    expect($backups)->toHaveCount(1)
        ->and((string) array_key_first($backups))->toEndWith('/resources/css/martis/test-theme.css')
        ->and(array_values($backups))->toBe([':root { --martis-accent: #abcdef; }']);
});

it('warns, and still succeeds, when the publish record cannot be written', function () {
    (new Filesystem)->ensureDirectoryExists(storage_path('app/martis/published-themes.json'));

    $this->artisan('martis:theme', ['name' => 'test-theme'])
        ->expectsOutputToContain('Could not write storage/app/martis/published-themes.json')
        ->assertSuccessful();

    expect(public_path('vendor/martis/themes/test-theme.css'))->toBeFile();
});

it('makes no backup when --force overwrites a published copy nobody edited', function () {
    $this->artisan('martis:theme', ['name' => 'test-theme'])->assertSuccessful();

    $this->artisan('martis:theme', ['name' => 'test-theme', '--force' => true])
        ->doesntExpectOutputToContain('Backed up')
        ->assertSuccessful();

    expect(themeBackups())->toBe([]);
});

it('fills the {{ name }} placeholder in the stub header', function () {
    $this->artisan('martis:theme', ['name' => 'brand-x'])->assertSuccessful();

    $contents = (string) file_get_contents(resource_path('css/martis/brand-x.css'));

    expect($contents)->not->toContain('{{ name }}');
    expect($contents)->toContain('Martis Theme: brand-x');
    expect($contents)->toContain('martis:theme brand-x');
});

it('ships every token category the runtime consumes', function () {
    $this->artisan('martis:theme', ['name' => 'test-theme'])->assertSuccessful();

    $contents = (string) file_get_contents(resource_path('css/martis/test-theme.css'));

    // 13 token categories from the design system.
    $expected = [
        // Surfaces
        '--martis-bg', '--martis-surface', '--martis-surface-alt', '--martis-sidebar',
        '--martis-topbar', '--martis-card', '--martis-input-bg',
        // Text & borders
        '--martis-text', '--martis-text-muted', '--martis-border',
        // Accent
        '--martis-accent', '--martis-accent-hover', '--martis-accent-active',
        '--martis-accent-bg-light', '--martis-accent-bg', '--martis-focus-ring',
        // Semantic solid
        '--martis-success', '--martis-warning', '--martis-danger', '--martis-info',
        // Semantic bg+text
        '--martis-success-bg', '--martis-warning-bg', '--martis-danger-bg', '--martis-info-bg',
        // Interactive
        '--martis-hover', '--martis-active', '--martis-search-bg', '--martis-search-border',
        // Overlays & shadows
        '--martis-overlay', '--martis-shadow-sm', '--martis-shadow-md', '--martis-shadow-lg',
        '--martis-peek-shadow',
        // DataTable
        '--martis-row-even', '--martis-row-hover', '--martis-table-header-bg',
        // Radius
        '--martis-radius-sm', '--martis-radius-md', '--martis-radius-lg',
        '--martis-radius-xl', '--martis-radius-full',
        // Typography
        '--martis-font-sans', '--martis-font-mono', '--martis-font-heading',
        '--martis-text-xs', '--martis-text-sm', '--martis-text-base',
        '--martis-weight-regular', '--martis-weight-medium',
        '--martis-leading-tight', '--martis-leading-normal',
        // Chart palette
        '--martis-chart-1', '--martis-chart-5', '--martis-chart-10',
        // File icons
        '--martis-file-icon-pdf', '--martis-file-icon-default',
        // Badge variants
        '--martis-badge-info-bg', '--martis-badge-danger-border',
        // Motion
        '--martis-dur-ultra', '--martis-dur-slow',
        '--martis-ease-standard', '--martis-ease-spring',
        // Density
        '--martis-row-h', '--martis-nav-item-h', '--martis-input-h', '--martis-btn-h',
        '--martis-pad-x', '--martis-pad-y', '--martis-gap',
    ];

    foreach ($expected as $token) {
        expect($contents)->toContain($token);
    }
});

it('wires accent variants for every swatch exposed by the preferences panel', function () {
    $this->artisan('martis:theme', ['name' => 'test-theme'])->assertSuccessful();

    $contents = (string) file_get_contents(resource_path('css/martis/test-theme.css'));

    foreach (['blue', 'teal', 'violet', 'amber'] as $accent) {
        expect($contents)->toContain("[data-accent=\"{$accent}\"]");
    }
});

it('supports both legacy `.dark` and new `[data-theme]` selectors', function () {
    $this->artisan('martis:theme', ['name' => 'test-theme'])->assertSuccessful();

    $contents = (string) file_get_contents(resource_path('css/martis/test-theme.css'));

    expect($contents)->toContain('html.dark');
    expect($contents)->toContain('[data-theme="dark"]');
    expect($contents)->toContain('html:not(.dark)');
    expect($contents)->toContain('[data-theme="light"]');
});

it('clamps motion tokens under reduced-motion preference', function () {
    $this->artisan('martis:theme', ['name' => 'test-theme'])->assertSuccessful();

    $contents = (string) file_get_contents(resource_path('css/martis/test-theme.css'));

    expect($contents)->toContain('@media (prefers-reduced-motion: reduce)');
    expect($contents)->toContain('[data-reduced-motion="true"]');
});

it('--force flag overwrites an existing theme without prompting', function () {
    // Create the theme file first.
    $this->artisan('martis:theme', ['name' => 'test-theme'])->assertSuccessful();

    // Re-run with --force — must not block on an interactive prompt and must succeed.
    $this->artisan('martis:theme', ['name' => 'test-theme', '--force' => true])
        ->assertSuccessful();

    expect(file_exists(resource_path('css/martis/test-theme.css')))->toBeTrue();
});

it('aborts without --force in non-interactive mode when the file already exists', function () {
    $this->artisan('martis:theme', ['name' => 'test-theme'])->assertSuccessful();

    // Second run without --force in test (non-interactive) environment aborts.
    $this->artisan('martis:theme', ['name' => 'test-theme'])
        ->assertFailed();
});

it('updates theme.name when the theme block contains a nested sub-array', function () {
    $original = <<<'PHP'
<?php
return [
    'brand' => [
        'name' => env('MARTIS_BRAND_NAME', 'Martis'),
    ],
    'theme' => [
        'default' => 'dark',
        'allowToggle' => true,
        'accents' => ['blue', 'red'],
    ],
];
PHP;

    // afterEach restores whatever was published before (or removes this
    // fixture): leaving it behind poisons every later test that reads
    // config('martis.brand') in the same environment.
    $configPath = config_path('martis.php');
    file_put_contents($configPath, $original);

    $this->artisan('martis:theme', ['name' => 'nested-test'])->assertSuccessful();

    $after = (string) file_get_contents($configPath);

    // theme.name must be inserted despite the nested array.
    expect($after)->toContain("'name' => 'nested-test'");

    // brand.name must be untouched.
    expect($after)->toContain("'name' => env('MARTIS_BRAND_NAME', 'Martis')");
});

it('updates theme.name without touching brand.name in config/martis.php', function () {
    $original = <<<'PHP'
<?php
return [
    'brand' => [
        'name' => env('MARTIS_BRAND_NAME', 'Martis'),
        'logo' => null,
    ],
    'theme' => [
        'default' => 'dark',
        'allowToggle' => true,
    ],
];
PHP;

    // afterEach restores whatever was published before (or removes this
    // fixture): leaving it behind poisons every later test that reads
    // config('martis.brand') in the same environment.
    $configPath = config_path('martis.php');
    file_put_contents($configPath, $original);

    $this->artisan('martis:theme', ['name' => 'test-theme'])->assertSuccessful();

    $after = (string) file_get_contents($configPath);

    // theme.name should be inserted / updated …
    expect($after)->toContain("'name' => 'test-theme'");

    // … and brand.name must be left intact.
    expect($after)->toContain("'name' => env('MARTIS_BRAND_NAME', 'Martis')");
});
