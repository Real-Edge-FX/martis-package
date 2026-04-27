<?php

use Illuminate\Filesystem\Filesystem;

function cleanupThemeArtifacts(string $name = 'test-theme'): void
{
    $fs = new Filesystem;
    $fs->delete(resource_path("css/martis/{$name}.css"));
    $fs->delete(public_path("vendor/martis/themes/{$name}.css"));
    $fs->deleteDirectory(resource_path('css/martis'));
    $fs->deleteDirectory(public_path('vendor/martis/themes'));
}

beforeEach(fn () => cleanupThemeArtifacts());
afterEach(fn () => cleanupThemeArtifacts());

it('generates the theme file in resources and public', function () {
    $this->artisan('martis:theme', ['name' => 'test-theme'])->assertSuccessful();

    expect(file_exists(resource_path('css/martis/test-theme.css')))->toBeTrue();
    expect(file_exists(public_path('vendor/martis/themes/test-theme.css')))->toBeTrue();
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

    $configPath = config_path('martis.php');
    file_put_contents($configPath, $original);

    try {
        $this->artisan('martis:theme', ['name' => 'test-theme'])->assertSuccessful();

        $after = (string) file_get_contents($configPath);

        // theme.name should be inserted / updated …
        expect($after)->toContain("'name' => 'test-theme'");

        // … and brand.name must be left intact.
        expect($after)->toContain("'name' => env('MARTIS_BRAND_NAME', 'Martis')");
    } finally {
        file_put_contents($configPath, $original);
    }
});
