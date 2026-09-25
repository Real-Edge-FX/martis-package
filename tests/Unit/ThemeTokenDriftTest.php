<?php

declare(strict_types=1);

/*
 * The theme variables are listed in several places that must agree:
 *
 *  - `resources/css/martis.css` gives 160 of them a value, on `:root` (dark,
 *    and the ones that do not depend on the mode) and on `html:not(.dark)`;
 *  - `stubs/theme.css.stub` (what `martis:theme` writes) declares the same
 *    160, and carries the two brand logo heights, which come from the
 *    config, commented out;
 *  - `docs/theming.md` lists the 162 in groups, with the dark and light
 *    values of `martis.css`, a count per group, a count table and a total;
 *  - the README, the docs index, the differentials and the preferences page
 *    state the total.
 *
 * The docs said 94 while the CSS defined 160, so each check below compares
 * content, not only names: a changed value, a duplicated row, a wrong group
 * count or total, and a stale number elsewhere all fail. Comments are
 * stripped before reading a CSS file: it names variables in prose too.
 */

/** The variables the config (not a stylesheet) gives a value. */
const THEME_DRIFT_CONFIG_VARIABLES = ['--martis-brand-logo-height-auth', '--martis-brand-logo-height-menu'];

const THEME_DRIFT_NAME = '--martis-[A-Za-z0-9_-]+';

function themeDriftRead(string $relativePath): string
{
    $path = dirname(__DIR__, 2).'/'.$relativePath;
    expect(is_file($path))->toBeTrue("{$relativePath} is missing");

    $content = (string) file_get_contents($path);
    expect(trim($content))->not->toBe('', "{$relativePath} is empty");

    return $content;
}

function themeDriftCss(string $relativePath): string
{
    return (string) preg_replace('~/\*.*?\*/~s', '', themeDriftRead($relativePath));
}

/** @return list<string> Every variable declared anywhere in the file. */
function themeDriftDeclared(string $relativePath): array
{
    preg_match_all('/('.THEME_DRIFT_NAME.')\s*:/', themeDriftCss($relativePath), $matches);

    $names = array_values(array_unique($matches[1]));
    sort($names);

    return $names;
}

/**
 * The values the rules named `$selectors` declare, later rules winning, as
 * the cascade reads them. A nested rule is named by its selectors from the
 * outside in, joined with ` >> ` (`@media (prefers-reduced-motion: reduce) >> :root`).
 *
 * @param  list<string>  $selectors
 * @return array<string, string>
 */
function themeDriftValues(string $relativePath, array $selectors): array
{
    $css = themeDriftCss($relativePath);
    $values = [];
    $stack = [];
    $buffer = '';
    $length = strlen($css);

    for ($i = 0; $i < $length; $i++) {
        $char = $css[$i];
        if ($char === '{') {
            $stack[] = (string) preg_replace('/\s+/', ' ', trim($buffer));
            $buffer = '';

            continue;
        }
        if ($char === '}') {
            if (in_array(implode(' >> ', $stack), $selectors, true)) {
                preg_match_all('/('.THEME_DRIFT_NAME.')\s*:\s*([^;]+);/', $buffer, $declarations, PREG_SET_ORDER);
                foreach ($declarations as [, $name, $value]) {
                    $values[$name] = (string) preg_replace('/\s+/', ' ', trim($value));
                }
            }
            array_pop($stack);
            $buffer = '';

            continue;
        }
        $buffer .= $char;
    }

    return $values;
}

/**
 * Set inline by the React grid components, or optional theme hooks with a
 * fallback; each is named in docs/theming.md ("Not counted"). Kept in step
 * with `ALLOWED_UNDEFINED` in resources/js/themeTokenReads.test.ts.
 *
 * @return list<string>
 */
function themeDriftAllowedUndefined(): array
{
    return [
        '--martis-field-span', '--martis-field-span-md', '--martis-field-span-lg', '--martis-field-columns',
        '--martis-card-span', '--martis-card-span-md', '--martis-card-span-lg', '--martis-filter-span',
        '--martis-tooltip-bg', '--martis-tooltip-text',
    ];
}

/** @return array<string, string> */
function themeDriftDark(): array
{
    return themeDriftValues('resources/css/martis.css', [':root', ':root, html[data-density="comfortable"]']);
}

/** @return array<string, string> */
function themeDriftLight(): array
{
    return themeDriftValues('resources/css/martis.css', ['html:not(.dark)']);
}

/**
 * The Variable Reference of docs/theming.md: its groups (title, stated
 * count, rows) and its intro.
 *
 * @return array{intro: string, groups: list<array{title: string, count: int, rows: list<array{name: string, dark: string, light: string}>}>}
 */
function themeDriftReference(): array
{
    $doc = themeDriftRead('docs/theming.md');
    $start = strpos($doc, "\n## Variable Reference\n");
    $end = strpos($doc, "\n## Attribute-Driven Theming\n");
    expect($start)->not->toBeFalse('the Variable Reference heading is missing')
        ->and($end)->not->toBeFalse('the Attribute-Driven Theming heading is missing');

    $section = substr($doc, (int) $start, (int) $end - (int) $start);
    $parts = preg_split('/^### /m', $section) ?: [];
    $groups = [];

    foreach (array_slice($parts, 1) as $part) {
        expect(preg_match('/^\d+\. (.+) \((\d+) variables?\)$/m', $part, $heading))->toBe(1, 'a group heading does not read "N. Title (K variables)"');
        preg_match_all('/^\| `('.THEME_DRIFT_NAME.')` \| (.+?) \| (.+?) \| .* \|$/m', $part, $rows, PREG_SET_ORDER);
        $groups[] = [
            'title' => $heading[1],
            'count' => (int) $heading[2],
            'rows' => array_map(fn (array $row): array => ['name' => $row[1], 'dark' => $row[2], 'light' => $row[3]], $rows),
        ];
    }

    return ['intro' => $parts[0] ?? '', 'groups' => $groups];
}

/** A table cell as written, unwrapped from its backticks and unescaped. */
function themeDriftCell(string $cell): string
{
    return str_replace('\\|', '|', (string) preg_replace('/^`(.*)`$/', '$1', trim($cell)));
}

it('scaffolds exactly the variables the bundled CSS declares, with the config ones left commented', function () {
    $css = themeDriftDeclared('resources/css/martis.css');
    $stub = themeDriftDeclared('stubs/theme.css.stub');

    expect($stub)->toBe($css)
        ->and(array_intersect(THEME_DRIFT_CONFIG_VARIABLES, $css))->toBe([]);

    foreach (THEME_DRIFT_CONFIG_VARIABLES as $name) {
        expect(themeDriftRead('stubs/theme.css.stub'))->toContain($name.':');
    }
});

it('lists each variable once in the reference, the CSS ones and the config ones and nothing else', function () {
    $names = array_merge(...array_map(fn (array $group): array => array_column($group['rows'], 'name'), themeDriftReference()['groups']));
    $expected = array_merge(themeDriftDeclared('resources/css/martis.css'), THEME_DRIFT_CONFIG_VARIABLES);
    sort($expected);

    expect(array_values(array_diff_assoc($names, array_unique($names))))->toBe([])
        ->and(collect($names)->sort()->values()->all())->toBe($expected);
});

it('gives each variable of the reference its dark and light values from martis.css', function () {
    $dark = themeDriftDark();
    $light = themeDriftLight();

    foreach (themeDriftReference()['groups'] as $group) {
        foreach ($group['rows'] as $row) {
            if (in_array($row['name'], THEME_DRIFT_CONFIG_VARIABLES, true)) {
                continue;
            }

            expect($dark)->toHaveKey($row['name'])
                ->and(themeDriftCell($row['dark']))->toBe($dark[$row['name']], "dark value of {$row['name']}");

            $lightValue = $light[$row['name']] ?? null;
            expect(themeDriftCell($row['light']))->toBe(
                $lightValue === null || $lightValue === $dark[$row['name']] ? 'same' : $lightValue,
                "light value of {$row['name']}",
            );
        }
    }
});

it('states each group\'s count, the count table, the number of groups and the total as the rows add up', function () {
    $reference = themeDriftReference();
    $doc = themeDriftRead('docs/theming.md');
    $total = 0;

    foreach ($reference['groups'] as $group) {
        expect(count($group['rows']))->toBe($group['count'], "the \"{$group['title']}\" heading count");
        expect($doc)->toContain("| {$group['title']} | {$group['count']} |");
        $total += $group['count'];
    }

    preg_match_all('/^\| (?!Category|\*\*Total)[^|]+ \| \d+ \|$/m', substr($doc, (int) strpos($doc, '## Complete Variable Count')), $tableRows);

    expect($total)->toBe(count(themeDriftDeclared('resources/css/martis.css')) + count(THEME_DRIFT_CONFIG_VARIABLES))
        ->and(count($tableRows[0]))->toBe(count($reference['groups']))
        ->and($doc)->toContain("| **Total** | **{$total}** |")
        ->and($reference['intro'])->toContain("**{$total} CSS variables** in **".count($reference['groups']).' groups**');
});

it('states the same total everywhere the docs give it', function () {
    $total = count(themeDriftDeclared('resources/css/martis.css')) + count(THEME_DRIFT_CONFIG_VARIABLES);
    $groups = count(themeDriftReference()['groups']);

    $claims = [
        'README.md' => ['/(\d+) CSS variables across (\d+) groups/', '/all (\d+) CSS variables/'],
        'docs/README.md' => ['/(\d+)-variable design system/', '/(\d+)-token design system/'],
        'docs/differentials.md' => ['/### (\d+)-token theme system/'],
        'docs/preferences.md' => ['/the (\d+)-token design system/'],
    ];

    foreach ($claims as $file => $patterns) {
        $content = themeDriftRead($file);
        foreach ($patterns as $pattern) {
            expect(preg_match($pattern, $content, $match))->toBe(1, "{$file} no longer states the total ({$pattern})")
                ->and((int) $match[1])->toBe($total, "{$file}: {$pattern}");
            if (isset($match[2])) {
                expect((int) $match[2])->toBe($groups, "{$file}: the number of groups");
            }
        }
    }
});

it('reads no variable in martis.css that nothing defines, other than the inline layout variables and the documented hooks', function () {
    // The components' reads are checked by resources/js/themeTokenReads.test.ts,
    // with the TypeScript parser, against the same lists.
    $defined = array_merge(themeDriftDeclared('resources/css/martis.css'), THEME_DRIFT_CONFIG_VARIABLES);
    $allowed = themeDriftAllowedUndefined();

    preg_match_all('/var\(\s*('.THEME_DRIFT_NAME.')(?![$\w{-])/', themeDriftCss('resources/css/martis.css'), $matches);

    expect(array_values(array_unique(array_diff($matches[1], $defined, $allowed))))->toBe([]);

    $doc = themeDriftRead('docs/theming.md');
    foreach ($allowed as $name) {
        expect($doc)->toContain("`{$name}`");
    }
});

it('scaffolds each variable with the values martis.css gives it, per mode, accent, density and motion', function () {
    $contexts = [
        'dark' => [[':root', ':root, html[data-density="comfortable"]'], [':root', ':root, html.dark, html[data-theme="dark"]', ':root, html[data-density="comfortable"]']],
        'light' => [['html:not(.dark)'], ['html:not(.dark), html[data-theme="light"]']],
        'dense' => [['html[data-density="dense"], [data-density="dense"]'], ['html[data-density="dense"], [data-density="dense"]']],
        'reduced motion' => [['html[data-reduced-motion="true"]'], ['html[data-reduced-motion="true"]']],
        'prefers-reduced-motion' => [['@media (prefers-reduced-motion: reduce) >> :root'], ['@media (prefers-reduced-motion: reduce) >> :root']],
    ];
    foreach (['blue', 'teal', 'violet', 'amber'] as $accent) {
        $contexts["{$accent} dark"] = [["html.dark[data-accent=\"{$accent}\"]"], ["html.dark[data-accent=\"{$accent}\"], html[data-theme=\"dark\"][data-accent=\"{$accent}\"]"]];
        $contexts["{$accent} light"] = [["html:not(.dark)[data-accent=\"{$accent}\"]"], ["html:not(.dark)[data-accent=\"{$accent}\"], html[data-theme=\"light\"][data-accent=\"{$accent}\"]"]];
    }

    foreach ($contexts as $context => [$cssSelectors, $stubSelectors]) {
        $css = themeDriftValues('resources/css/martis.css', $cssSelectors);
        $stub = themeDriftValues('stubs/theme.css.stub', $stubSelectors);
        ksort($css);
        ksort($stub);

        expect($css)->not->toBe([], "martis.css declares nothing for {$context}")
            ->and($stub)->toBe($css, "the stub's {$context} values");
    }
});
