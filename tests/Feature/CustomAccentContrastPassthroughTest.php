<?php

declare(strict_types=1);

// Every accent fill paints its text with `--martis-accent-contrast` since
// v1.34.0, so the SSR block that defines a named custom accent
// (MARTIS_CUSTOM_ACCENTS) must emit the token too: the optional third
// `name:hex:contrastHex` segment when given, else the luminance-derived
// value. The SPA payload carries it as well so the picker can show it.

it('emits --martis-accent-contrast for each custom accent in the SSR style block', function () {
    config()->set('martis.preferences.enabled', true);
    config()->set('martis.preferences.custom_accents', 'lime:#C6F135,navy:#1a3a8a:#F0F4FF');

    $response = $this->get('/martis/login')->assertStatus(200);

    // Derived: lime is bright → near-black navy text.
    $response->assertSee('--martis-accent-contrast: #0b1220;', false);
    // Explicit third segment wins verbatim (lowercased).
    $response->assertSee('--martis-accent-contrast: #f0f4ff;', false);
    // The accent itself still lands next to it.
    $response->assertSee('html[data-accent="lime"]', false);
    $response->assertSee('--martis-accent:          #c6f135;', false);
});

it('carries the contrast colour into the window.MartisConfig custom accents payload', function () {
    config()->set('martis.preferences.enabled', true);
    config()->set('martis.preferences.custom_accents', 'lime:#C6F135');

    $response = $this->get('/martis/login')->assertStatus(200);

    $response->assertSee('"customAccents":[{"name":"lime","color":"#c6f135","contrast":"#0b1220"}]', false);
});
