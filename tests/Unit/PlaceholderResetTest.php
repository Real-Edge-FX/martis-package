<?php

declare(strict_types=1);

/*
 * The PrimeReact theme ships unscoped placeholder rules
 * (`::-webkit-input-placeholder { ... }`). When Martis bundled the precompiled
 * lara-dark-indigo theme they painted `rgba(255,255,255,.6)`, invisible on the
 * light palette, so a bare `<input placeholder>` in a Tool page or an
 * extension bundle showed white on white. The theme is now compiled with the
 * Martis tokens, and the package CSS still restores a theme-aware default for
 * every placeholder: unscoped, outside any cascade layer (the theme lives in
 * `@layer primereact`, so an unlayered rule wins regardless of specificity)
 * and loaded after the theme.
 */

beforeEach(function () {
    $this->css = file_get_contents(__DIR__.'/../../resources/css/martis.css');
    $this->entry = file_get_contents(__DIR__.'/../../resources/js/app.tsx');

    preg_match('/^::placeholder\s*\{([^}]*)\}/m', $this->css, $matches, PREG_OFFSET_CAPTURE);

    $this->resetOffset = $matches[0][1] ?? null;
    $this->resetBody = $matches[1][0] ?? null;
});

it('declares an unscoped ::placeholder rule in the stylesheet loaded after the PrimeReact theme', function () {
    $theme = strpos($this->entry, "import '../sass/primereact/theme.scss'");
    $stylesheet = strpos($this->entry, "import '../css/martis.css'");

    expect($this->resetOffset)->not->toBeNull()
        ->and($theme)->not->toBeFalse()
        ->and($stylesheet)->not->toBeFalse()
        ->and($stylesheet)->toBeGreaterThan($theme);
});

it('keeps the global placeholder rule outside any cascade layer', function () {
    $before = preg_replace('~/\*.*?\*/~s', '', substr($this->css, 0, $this->resetOffset));

    expect($before)->not->toContain('@layer');
});

it('paints every placeholder with the theme-aware muted token at full opacity', function () {
    expect($this->resetBody)->toMatch('/color:\s*var\(--martis-text-muted\)/')
        ->and($this->resetBody)->toMatch('/opacity:\s*1\s*;/');
});
