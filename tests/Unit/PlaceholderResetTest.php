<?php

declare(strict_types=1);

/*
 * The bundled PrimeReact theme (lara-dark-indigo) ships unscoped placeholder
 * rules (`::-webkit-input-placeholder { color: rgba(255,255,255,.6) }`) that
 * are right for its dark palette and invisible on the light one. Martis used
 * to re-colour only the placeholders of its own controls, each by class, so a
 * bare `<input placeholder>` in a Tool page or an extension bundle inherited
 * the dark theme's white on a white surface. The package CSS must restore a
 * theme-aware default for every placeholder: unscoped, outside any cascade
 * layer (the theme lives in `@layer primereact`, so an unlayered rule wins
 * regardless of specificity) and after the theme import.
 */

beforeEach(function () {
    $this->css = file_get_contents(__DIR__.'/../../resources/css/martis.css');
    $this->themeImport = strpos($this->css, 'primereact/resources/themes/');

    preg_match('/^::placeholder\s*\{([^}]*)\}/m', $this->css, $matches, PREG_OFFSET_CAPTURE);

    $this->resetOffset = $matches[0][1] ?? null;
    $this->resetBody = $matches[1][0] ?? null;
});

it('declares an unscoped ::placeholder rule after the PrimeReact theme import', function () {
    expect($this->themeImport)->not->toBeFalse()
        ->and($this->resetOffset)->not->toBeNull()
        ->and($this->resetOffset)->toBeGreaterThan($this->themeImport);
});

it('keeps the global placeholder rule outside any cascade layer', function () {
    $before = preg_replace('~/\*.*?\*/~s', '', substr($this->css, 0, $this->resetOffset));

    expect($before)->not->toContain('@layer');
});

it('paints every placeholder with the theme-aware muted token at full opacity', function () {
    expect($this->resetBody)->toMatch('/color:\s*var\(--martis-text-muted\)/')
        ->and($this->resetBody)->toMatch('/opacity:\s*1\s*;/');
});
