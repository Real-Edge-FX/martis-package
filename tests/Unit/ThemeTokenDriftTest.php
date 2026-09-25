<?php

declare(strict_types=1);

/*
 * The theme variables have three lists that must agree: what the bundled
 * `resources/css/martis.css` defines, what the scaffolded theme
 * (`stubs/theme.css.stub`, written by `martis:theme`) defines, and the
 * reference in `docs/theming.md`. The docs said 94 while the CSS defined
 * 160 and the stub 162, so a variable added to the CSS must reach the stub
 * and the reference in the same change. Comments are stripped before
 * counting: the CSS mentions variables in prose too.
 */

function themeDriftRoot(): string
{
    return dirname(__DIR__, 2);
}

/** @return list<string> */
function themeDriftDefined(string $relativePath): array
{
    $css = (string) file_get_contents(themeDriftRoot().'/'.$relativePath);
    $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
    preg_match_all('/(--martis-[A-Za-z0-9-]+)[ \t\r\n]*:/', $css, $matches);

    $names = array_values(array_unique($matches[1]));
    sort($names);

    return $names;
}

/** @return list<string> The variables the Variable Reference tables list. */
function themeDriftDocumented(): array
{
    $doc = (string) file_get_contents(themeDriftRoot().'/docs/theming.md');
    $start = strpos($doc, '## Variable Reference');
    $end = strpos($doc, '## Attribute-Driven Theming');
    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();

    preg_match_all('/^\| `(--martis-[A-Za-z0-9-]+)` \|/m', substr($doc, (int) $start, (int) $end - (int) $start), $matches);

    $names = array_values(array_unique($matches[1]));
    sort($names);

    return $names;
}

it('scaffolds every variable the bundled CSS defines', function () {
    expect(array_values(array_diff(themeDriftDefined('resources/css/martis.css'), themeDriftDefined('stubs/theme.css.stub'))))->toBe([]);
});

it('documents every variable the bundled CSS or the scaffolded theme defines', function () {
    $defined = array_unique([...themeDriftDefined('resources/css/martis.css'), ...themeDriftDefined('stubs/theme.css.stub')]);

    expect(array_values(array_diff($defined, themeDriftDocumented())))->toBe([])
        ->and(array_values(array_diff(themeDriftDocumented(), $defined)))->toBe([]);
});

it('states the number of variables the scaffolded theme defines', function () {
    $count = count(themeDriftDefined('stubs/theme.css.stub'));
    $doc = (string) file_get_contents(themeDriftRoot().'/docs/theming.md');

    expect($doc)->toContain("**{$count} CSS variables**")
        ->and($doc)->toContain("| **Total** | **{$count}** |");
});
