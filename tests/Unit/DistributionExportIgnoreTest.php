<?php

declare(strict_types=1);

/**
 * Guards what the Composer dist leaves out. Composer builds the dist zip with
 * `git archive`, which drops every path `.gitattributes` marks
 * `export-ignore`; a repo-only file without that mark is installed into every
 * app's vendor/martis/martis/. Up to v1.39.2 the maintainer tooling, the
 * PHPStan baseline, AGENTS.md and a stale PHPUnit cache shipped.
 */

/** @return list<string> The root paths `.gitattributes` marks export-ignore. */
function exportIgnoredPaths(): array
{
    $lines = file(dirname(__DIR__, 2).'/.gitattributes', FILE_IGNORE_NEW_LINES) ?: [];
    $paths = [];

    foreach ($lines as $line) {
        $fields = preg_split('/\s+/', trim($line)) ?: [];

        if (count($fields) >= 2 && ! str_starts_with($fields[0], '#') && in_array('export-ignore', array_slice($fields, 1), true)) {
            $paths[] = $fields[0];
        }
    }

    return $paths;
}

it('keeps the repo-only files out of the Composer dist', function (string $path) {
    expect(exportIgnoredPaths())->toContain($path);
})->with([
    'the maintainer tooling' => ['/.tooling'],
    'the PHPStan baseline' => ['/phpstan-baseline.neon'],
    'the PHPUnit result cache' => ['/.phpunit.result.cache'],
    'the agent rules of the repository' => ['/AGENTS.md'],
    'the tests' => ['/tests'],
]);
