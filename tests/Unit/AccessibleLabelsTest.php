<?php

/*
 * Labels a screen reader or a hover reads: the one-of-many tile's column
 * sentence in the three shipped locales, and the tooltips of the published
 * component stubs, which use the global `data-pr-tooltip` pattern and never
 * a native `title` (the two show at once, and `title` is not themed).
 */

$root = dirname(__DIR__, 2);

it('translates the one-of-many aggregate column sentence in every shipped locale', function (string $locale, string $expected) use ($root) {
    $messages = require "{$root}/resources/lang/{$locale}/messages.php";

    expect($messages['ofmany_aggregate_column'] ?? null)->toBe($expected);
})->with([
    'en' => ['en', 'Aggregated column: :column'],
    'pt_PT' => ['pt_PT', 'Coluna agregada: :column'],
    'pt_BR' => ['pt_BR', 'Coluna agregada: :column'],
]);

it('gives the topbar stub\'s icon buttons global tooltips', function () use ($root) {
    $stub = (string) file_get_contents("{$root}/stubs/component-topbar.tsx.stub");

    expect($stub)
        ->toContain("data-pr-tooltip={t('open_sidebar', 'Menu')}")
        ->toContain("data-pr-tooltip={sidebarCollapsed ? t('expand_sidebar') : t('collapse_sidebar')}");
});

it('uses no native title attribute in any published stub', function () use ($root) {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/stubs", FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (preg_match('/(?<![\w-])title=[{"\']/', (string) file_get_contents($file->getPathname())) === 1) {
            $offenders[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }

    expect($offenders)->toBe([]);
});
