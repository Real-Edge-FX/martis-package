<?php

declare(strict_types=1);

/*
 * Tailwind's preflight is off (tailwind.config.ts), so nothing gives an
 * element a border style: a border martis.css draws states its own. A
 * shorthand that leaves the style out (`border-top: 1px var(--martis-border)`)
 * resets it to `none`, and the border is not drawn.
 * resources/js/themeTokenReads.test.ts checks the components' utilities and
 * inline styles the same way. A width longhand on its own
 * (`border-width: 0 8px 8px 8px`) is left alone: the arrows that set one take
 * their style from another rule for the same element, which a reading rule
 * by rule cannot see. The Vitest CSS plugin blanks every stylesheet import,
 * so the guard reads the source here.
 */

/** @return list<array{string, string}> Every `property: value` declared inside a block, comments stripped. */
function borderCssDeclarations(string $css): array
{
    $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
    $declarations = [];
    $buffer = '';

    foreach (str_split($css) as $char) {
        if ($char === '{') {
            $buffer = '';

            continue;
        }
        if ($char === ';' || $char === '}') {
            $statement = trim($buffer);
            $buffer = '';
            if ($statement !== '' && $statement[0] !== '@' && str_contains($statement, ':')) {
                [$property, $value] = array_map('trim', explode(':', $statement, 2));
                $declarations[] = [strtolower($property), $value];
            }

            continue;
        }
        $buffer .= $char;
    }

    return $declarations;
}

/** @return list<string> The border shorthands that set no style. */
function borderCssUnstyled(string $css): array
{
    $unstyled = [];
    foreach (borderCssDeclarations($css) as [$property, $value]) {
        if (! preg_match('/^border(-(top|right|bottom|left|inline|block)(-(start|end))?)?$/', $property)) {
            continue;
        }
        $bare = trim((string) preg_replace('/!important$/i', '', $value));
        if (preg_match('/\b(solid|dashed|dotted|double|groove|ridge|inset|outset|none|hidden)\b/i', $bare)
            || preg_match('/^(0|0px|inherit|initial|unset|revert|revert-layer)$/i', $bare)) {
            continue;
        }
        $unstyled[] = "{$property}: {$value}";
    }

    return $unstyled;
}

it('gives every border shorthand in martis.css a style, or none', function () {
    $css = (string) file_get_contents(__DIR__.'/../../resources/css/martis.css');

    expect(count(borderCssDeclarations($css)))->toBeGreaterThan(1000)
        ->and(borderCssUnstyled($css))->toBe([]);
});

it('refuses a shorthand with a width or a colour and no style, and accepts one with a style, none or 0', function () {
    $css = <<<'CSS'
        /* border-top: 1px red; in a comment */
        .a { border-top: 1px var(--martis-border); }
        .b:hover { border: var(--martis-border) !important }
        @media (max-width: 1024px) { .c { border-inline-start: 2px currentColor } }
        .d { border: 1px solid var(--martis-border); border-bottom: none; border-left: 0; }
        .e { border-right: 3px dashed red !important; border-width: 0 8px 8px 8px; border-color: red }
        CSS;

    expect(borderCssUnstyled($css))->toBe([
        'border-top: 1px var(--martis-border)',
        'border: var(--martis-border) !important',
        'border-inline-start: 2px currentColor',
    ]);
});
