<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Martis\Stubs\ExtensionKey;

/**
 * The generators bind the PHP side of a consumer extension to the key the
 * extension entry registers the TSX file under (`pascalToKebab()` in
 * `stubs/extensions/index.ts.stub`); `resources/js/extensionEntry.test.tsx`
 * runs the entry itself against the same table of names.
 */
dataset('extension file names', [
    ['Charts', 'charts'],
    ['SystemHealth', 'system-health'],
    ['SEOReport', 'seo-report'],
    ['HTTPStatus', 'http-status'],
    ['OAuthClients', 'o-auth-clients'],
    ['ApiV2Keys', 'api-v2-keys'],
]);

it('derives the name the extension entry derives from a file name', function (string $name, string $kebab) {
    expect(ExtensionKey::kebab($name))->toBe($kebab);
})->with('extension file names');

/** Delete what a generator wrote, and the extension folders it left empty. */
function deleteGenerated(string ...$paths): void
{
    $fs = new Filesystem;
    foreach ($paths as $path) {
        $fs->delete($path);
    }
    foreach (['tools', 'cards', 'fields', 'overrides', ''] as $bucket) {
        $dir = rtrim(base_path('resources/js/martis-extensions/'.$bucket), '/');
        if (is_dir($dir) && count(scandir($dir) ?: []) === 2) {
            rmdir($dir);
        }
    }
}

it('martis:tool --with-component binds the Tool to the key the entry registers its TSX under', function () {
    $php = app_path('Martis/Tools/SEOReport.php');
    $tsx = base_path('resources/js/martis-extensions/tools/SEOReport.tsx');
    deleteGenerated($php, $tsx);

    try {
        $this->artisan('martis:tool', ['name' => 'SEOReport', '--with-component' => true])->assertSuccessful();

        expect((string) file_get_contents($php))
            ->toContain("withComponent('tool:seo-report')")
            ->toContain("'seo-report'")
            ->and(file_exists($tsx))->toBeTrue();
    } finally {
        deleteGenerated($php, $tsx);
    }
});

it('martis:tool --component-key with --with-component prints the registration the entry needs', function () {
    // The entry registers `tools/Reports.tsx` as `tool:reports`; the Tool binds
    // another key, which only a register() call in index.ts provides.
    $php = app_path('Martis/Tools/LedgerReports.php');
    $tsx = base_path('resources/js/martis-extensions/tools/LedgerReports.tsx');
    deleteGenerated($php, $tsx);

    try {
        $this->artisan('martis:tool', [
            'name' => 'LedgerReports',
            '--with-component' => true,
            '--component-key' => 'app:ledger-reports',
        ])
            ->expectsOutputToContain("componentRegistry.register('app:ledger-reports', LedgerReportsTool)")
            ->expectsOutputToContain("import LedgerReportsTool from './tools/LedgerReports'")
            ->assertSuccessful();

        expect((string) file_get_contents($php))->toContain("withComponent('app:ledger-reports')");
    } finally {
        deleteGenerated($php, $tsx);
    }
});

it('martis:card binds the card to the key the entry registers its TSX under', function () {
    // The dashboard resolves a card's component by its exact key, and the
    // entry registers `cards/SEOReport.tsx` as `card:seo-report`.
    $php = app_path('Martis/Cards/SEOReport.php');
    $tsx = base_path('resources/js/martis-extensions/cards/SEOReport.tsx');
    deleteGenerated($php, $tsx);

    try {
        $this->artisan('martis:card', ['name' => 'SEOReport'])->assertSuccessful();

        expect((string) file_get_contents($php))->toContain("componentKey('card:seo-report')")
            ->and((string) file_get_contents($tsx))->toContain('`card:seo-report`');
    } finally {
        deleteGenerated($php, $tsx);
    }
});

it('martis:field gives the field the type the entry registers its TSX as', function () {
    // The entry registers `fields/SEOScore.tsx` as the `seo-score` field type.
    $php = app_path('Martis/Fields/SEOScoreField.php');
    $tsx = base_path('resources/js/martis-extensions/fields/SEOScore.tsx');
    deleteGenerated($php, $tsx);

    try {
        $this->artisan('martis:field', ['name' => 'SEOScore'])->assertSuccessful();

        expect((string) file_get_contents($php))->toContain("return 'seo-score'")
            ->and(file_exists($tsx))->toBeTrue();
    } finally {
        deleteGenerated($php, $tsx);
    }
});

it('martis:component names the override key the entry derives from the file name', function () {
    $tsx = base_path('resources/js/martis-extensions/overrides/SEOBadge.tsx');
    deleteGenerated($tsx);

    try {
        $this->artisan('martis:component', ['name' => 'SEOBadge', '--type' => 'generic'])
            ->expectsOutputToContain("Auto-registered as 'seo-badge'")
            ->expectsOutputToContain("new Override('seo-badge')")
            ->assertSuccessful();
    } finally {
        deleteGenerated($tsx);
    }
});
