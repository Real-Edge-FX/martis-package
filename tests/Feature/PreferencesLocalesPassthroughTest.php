<?php

// The language picker on the login screen has no authenticated
// `/api/preferences` meta to read, so it relies on
// `window.MartisConfig.preferences.locales` bootstrapped by app.blade.php.
// Before v1.28.2 that key was never emitted, so `martis.preferences.locales`
// was inert and the login picker always showed the three bundled locales.

it('bootstraps the configured preferences.locales into the login shell payload', function () {
    config()->set('martis.preferences.enabled', true);
    config()->set('martis.preferences.locales', ['en', 'pt_PT']);

    $response = $this->get('/martis/login');

    $response->assertStatus(200);
    // Restricted list reaches window.MartisConfig.preferences.locales so the
    // login picker offers exactly en + pt_PT (not the bundled three).
    $response->assertSee('"locales":["en","pt_PT"]', false);
});

it('carries the full configured list through the passthrough', function () {
    config()->set('martis.preferences.enabled', true);
    config()->set('martis.preferences.locales', ['en', 'pt_PT', 'pt_BR']);

    $response = $this->get('/martis/login');

    $response->assertStatus(200);
    $response->assertSee('"locales":["en","pt_PT","pt_BR"]', false);
});
