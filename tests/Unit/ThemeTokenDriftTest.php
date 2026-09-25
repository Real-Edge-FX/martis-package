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
 * The values the top-level rules named `$selectors` declare, later rules
 * winning, as the cascade reads them.
 *
 * @param  list<string>  $selectors
 * @return array<string, string>
 */
function themeDriftValues(string $relativePath, array $selectors): array
{
    $css = themeDriftCss($relativePath);
    $values = [];
    $depth = 0;
    $selector = '';
    $body = '';
    $length = strlen($css);

    for ($i = 0; $i < $length; $i++) {
        $char = $css[$i];
        if ($char === '{') {
            if ($depth === 0) {
                $selector = trim($body);
                $body = '';
            }
            $depth++;

            continue;
        }
        if ($char === '}') {
            $depth--;
            if ($depth === 0) {
                if (in_array(preg_replace('/\s+/', ' ', $selector), $selectors, true)) {
                    preg_match_all('/('.THEME_DRIFT_NAME.')\s*:\s*([^;]+);/', $body, $declarations, PREG_SET_ORDER);
                    foreach ($declarations as [, $name, $value]) {
                        $values[$name] = (string) preg_replace('/\s+/', ' ', trim($value));
                    }
                }
                $body = '';
            }

            continue;
        }
        $body .= $char;
    }

    return $values;
}

/**
 * `$source` without its comments, its strings left intact: a `/*` inside a
 * string (`accept="image/*"`) does not open a comment, so the reads after it
 * stay visible.
 */
function themeDriftStripComments(string $source): string
{
    $out = '';
    $quote = null;
    $length = strlen($source);

    for ($i = 0; $i < $length; $i++) {
        $char = $source[$i];
        if ($quote !== null) {
            $out .= $char;
            if ($char === '\\' && $i + 1 < $length) {
                $out .= $source[++$i];
            } elseif ($char === $quote) {
                $quote = null;
            }

            continue;
        }
        if ($char === '"' || $char === "'" || $char === '`') {
            $quote = $char;
            $out .= $char;

            continue;
        }
        $next = $source[$i + 1] ?? '';
        if ($char === '/' && $next === '*') {
            $end = strpos($source, '*/', $i + 2);
            $i = $end === false ? $length : $end + 1;

            continue;
        }
        if ($char === '/' && $next === '/') {
            $end = strpos($source, "\n", $i + 2);
            $i = $end === false ? $length : $end - 1;

            continue;
        }
        $out .= $char;
    }

    return $out;
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

it('reads no variable that nothing defines, other than the inline layout variables and the documented hooks', function () {
    $defined = array_merge(themeDriftDeclared('resources/css/martis.css'), THEME_DRIFT_CONFIG_VARIABLES);
    // Set inline by the React grid components, or optional theme hooks
    // with a fallback; each is named in docs/theming.md ("Not counted").
    $allowed = [
        '--martis-field-span', '--martis-field-span-md', '--martis-field-span-lg', '--martis-field-columns',
        '--martis-card-span', '--martis-card-span-md', '--martis-card-span-lg', '--martis-filter-span',
        '--martis-tooltip-bg', '--martis-tooltip-text',
    ];

    $root = dirname(__DIR__, 2);
    $files = array_merge(
        ['resources/css/martis.css'],
        array_map(
            fn (SplFileInfo $file): string => substr($file->getPathname(), strlen($root) + 1),
            array_filter(
                iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/resources/js', FilesystemIterator::SKIP_DOTS))),
                fn (SplFileInfo $file): bool => (bool) preg_match('/\.tsx?$/', $file->getFilename()) && ! str_contains($file->getFilename(), '.test.'),
            ),
        ),
    );

    $undefined = [];
    foreach ($files as $file) {
        // Comments (block, and line comments in a component) name
        // variables in prose.
        $source = str_ends_with($file, '.css')
            ? themeDriftCss($file)
            : themeDriftStripComments(themeDriftRead($file));
        // A name completed at runtime (`--martis-avatar-${n}`) is not a read.
        preg_match_all('/var\(\s*('.THEME_DRIFT_NAME.')(?![$\w{-])/', $source, $matches);
        foreach (array_diff($matches[1], $defined, $allowed) as $name) {
            $undefined[] = "{$name} in {$file}";
        }
    }

    expect(array_values(array_unique($undefined)))->toBe([]);

    $doc = themeDriftRead('docs/theming.md');
    foreach ($allowed as $name) {
        expect($doc)->toContain("`{$name}`");
    }
});

it('keeps the strings when it strips the comments of a component', function () {
    $source = "<input accept=\"image/*\" />\nconst read = 'var(--martis-read-after-a-glob)' // var(--martis-in-a-line-comment)\n/* var(--martis-in-a-block-comment) */";

    expect(themeDriftStripComments($source))->toContain('var(--martis-read-after-a-glob)')
        ->not->toContain('--martis-in-a-line-comment')
        ->not->toContain('--martis-in-a-block-comment');
});

it('scaffolds each variable with the values martis.css gives it, per mode, accent, density and motion', function () {
    $contexts = [
        'dark' => [[':root', ':root, html[data-density="comfortable"]'], [':root', ':root, html.dark, html[data-theme="dark"]', ':root, html[data-density="comfortable"]']],
        'light' => [['html:not(.dark)'], ['html:not(.dark), html[data-theme="light"]']],
        'dense' => [['html[data-density="dense"], [data-density="dense"]'], ['html[data-density="dense"], [data-density="dense"]']],
        'reduced motion' => [['html[data-reduced-motion="true"]'], ['html[data-reduced-motion="true"]']],
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
