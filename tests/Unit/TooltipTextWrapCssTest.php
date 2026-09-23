<?php

declare(strict_types=1);

/*
 * The ref-based PrimeReact `<Tooltip>` (the one the docs recommend for rich
 * content) kept `white-space: nowrap; max-width: 300px` on
 * `.p-tooltip-text`, so a sentence ran out of the bubble while the global
 * `[data-pr-tooltip]` provider (MartisTooltip) already wrapped at 360px.
 * Both now wrap the same way. The Vitest CSS plugin blanks every
 * stylesheet import, so the guard reads the source here.
 */

beforeEach(function () {
    $this->css = file_get_contents(__DIR__.'/../../resources/css/martis.css');
});

it('wraps the text of the ref-based PrimeReact tooltip inside its bubble', function () {
    preg_match_all('/^\.p-tooltip \.p-tooltip-text \{([^}]*)\}/m', $this->css, $matches);
    $rules = implode("\n", $matches[1]);

    expect($matches[1])->not->toBeEmpty()
        ->and($rules)->not->toContain('nowrap')
        ->and($rules)->toMatch('/white-space:\s*pre-line;/')
        ->and($rules)->toMatch('/overflow-wrap:\s*anywhere;/')
        ->and($rules)->toMatch('/max-width:\s*min\(360px,\s*calc\(100vw - 16px\)\);/');
});
