<?php

declare(strict_types=1);

/*
 * The form and detail field grids (Section, Panel and Tab bodies) wrote
 * `grid-column: span {colSpan}` inline, so `colSpanMd()` / `colSpanLg()` were
 * serialised but never read, and only the Section grid collapsed on phones,
 * through an `!important` rule that existed to beat the inline style. The
 * filter panel fixed `repeat(12, …)` and `grid-column: span N` inline, so a
 * `span(3)` filter was a quarter of a phone screen. Placement now belongs to
 * `.martis-field-grid` and `.martis-filter-grid` in martis.css: the SPA only
 * hands each grid its track count and each item its resolved spans as custom
 * properties (lib/fieldGridSpan.ts, lib/filterGridSpan.ts), and the
 * stylesheet owns the breakpoints, full row below md, the md tier from 768px
 * and the lg tier from 1024px, with nothing a theme cannot override. The
 * Vitest CSS plugin blanks every stylesheet import, so the guard reads the
 * source here.
 */

beforeEach(function () {
    $this->css = file_get_contents(__DIR__.'/../../resources/css/martis.css');
});

it('declares the field grid tracks from the column count the grid carries', function () {
    expect($this->css)->toMatch('/^\.martis-field-grid\s*\{[^}]*display:\s*grid;[^}]*grid-template-columns:\s*repeat\(var\(--martis-field-columns,\s*12\),\s*minmax\(0,\s*1fr\)\);/m');
});

it('gives every field the full row below the md breakpoint, overridable by a theme', function () {
    preg_match('/^\.martis-field-grid > \* \{([^}]*)\}/m', $this->css, $matches);

    expect($matches[1] ?? null)->not->toBeNull()
        ->and($matches[1])->toMatch('/min-width:\s*0;/')
        ->and($matches[1])->toMatch('/grid-column:\s*1 \/ -1;/')
        ->and($matches[1])->not->toContain('!important');
});

it('reads the md span of a field from 768px and the lg span from 1024px', function () {
    expect($this->css)->toMatch('/@media \(min-width: 768px\) \{\s*\.martis-field-grid > \* \{\s*grid-column:\s*span var\(--martis-field-span-md, var\(--martis-field-columns, 12\)\);/s')
        ->and($this->css)->toMatch('/@media \(min-width: 1024px\) \{\s*\.martis-field-grid > \* \{\s*grid-column:\s*span var\(--martis-field-span-lg, var\(--martis-field-span-md, var\(--martis-field-columns, 12\)\)\);/s');
});

it('no longer places section fields with an !important rule', function () {
    expect($this->css)->not->toMatch('/\.martis-section-grid > \*\s*\{[^}]*grid-column/s');
});

it('declares the 12-column filter grid', function () {
    expect($this->css)->toMatch('/^\.martis-filter-grid\s*\{[^}]*display:\s*grid;[^}]*grid-template-columns:\s*repeat\(12,\s*minmax\(0,\s*1fr\)\);/m');
});

it('gives every filter the full row below md and its span from 768px', function () {
    preg_match('/^\.martis-filter-grid > \* \{([^}]*)\}/m', $this->css, $matches);

    expect($matches[1] ?? null)->not->toBeNull()
        ->and($matches[1])->toMatch('/grid-column:\s*1 \/ -1;/')
        ->and($matches[1])->not->toContain('!important')
        ->and($this->css)->toMatch('/@media \(min-width: 768px\) \{\s*\.martis-filter-grid > \* \{\s*grid-column:\s*span var\(--martis-filter-span, 3\);/s');
});
