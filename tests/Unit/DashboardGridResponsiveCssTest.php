<?php

declare(strict_types=1);

/*
 * The dashboard cards grid used to be an inline `repeat(12, …)` container
 * whose cards wrote `grid-column: span {width}` inline at every viewport, so
 * `widthMd()` / `widthLg()` were serialised but never read and a `width(4)`
 * card was a third of a phone screen. Placement now belongs to
 * `.martis-dashboard-grid` in martis.css: the SPA only hands each card its
 * resolved spans as custom properties (lib/cardGridSpan.ts) and the
 * stylesheet owns the breakpoints, full row below md, `--martis-card-span-md`
 * from 768px and `--martis-card-span-lg` from 1024px. The Vitest CSS plugin
 * blanks every stylesheet import, so the guard reads the source here.
 */

beforeEach(function () {
    $this->css = file_get_contents(__DIR__.'/../../resources/css/martis.css');
});

it('declares the 12-column dashboard grid container', function () {
    expect($this->css)->toMatch('/\.martis-dashboard-grid\s*\{[^}]*display:\s*grid;[^}]*grid-template-columns:\s*repeat\(12,\s*minmax\(0,\s*1fr\)\);/s');
});

it('gives every card the full row below the md breakpoint', function () {
    preg_match('/^\.martis-dashboard-grid > \* \{([^}]*)\}/m', $this->css, $matches);

    expect($matches[1] ?? null)->not->toBeNull()
        ->and($matches[1])->toMatch('/min-width:\s*0;/')
        ->and($matches[1])->toMatch('/grid-column:\s*1 \/ -1;/');
});

it('reads the md span from 768px and the lg span from 1024px', function () {
    expect($this->css)->toMatch('/@media \(min-width: 768px\) \{\s*\.martis-dashboard-grid > \* \{\s*grid-column:\s*span var\(--martis-card-span-md, 12\);/s')
        ->and($this->css)->toMatch('/@media \(min-width: 1024px\) \{\s*\.martis-dashboard-grid > \* \{\s*grid-column:\s*span var\(--martis-card-span-lg, var\(--martis-card-span-md, 12\)\);/s');
});
