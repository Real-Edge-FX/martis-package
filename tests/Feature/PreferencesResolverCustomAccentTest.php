<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Martis\Preferences\PreferencesResolver;

/*
 * `PreferencesResolver::normaliseAccent()` accepts:
 *   • Bundled enum values (martis, blue, teal, violet, amber, custom).
 *   • Custom names registered via `MARTIS_CUSTOM_ACCENTS`.
 *
 * Everything else degrades to `martis` (the safe default).
 */

beforeEach(function () {
    config()->set('martis.preferences.custom_accents', 'edgeflow:#1a73e8,sunset:#ff6b35');
});

it('accepts a bundled enum accent value verbatim', function () {
    config()->set('martis.preferences.defaults.accent', 'violet');
    $resolver = app(PreferencesResolver::class);
    $payload = $resolver->resolve(Request::create('/martis', 'GET'));

    expect($payload['accent'])->toBe('violet');
});

it('accepts a custom accent name registered via MARTIS_CUSTOM_ACCENTS', function () {
    config()->set('martis.preferences.defaults.accent', 'edgeflow');
    $resolver = app(PreferencesResolver::class);
    $payload = $resolver->resolve(Request::create('/martis', 'GET'));

    expect($payload['accent'])->toBe('edgeflow');
});

it('falls back to martis when the requested accent is neither bundled nor custom', function () {
    config()->set('martis.preferences.defaults.accent', 'no-such-accent');
    $resolver = app(PreferencesResolver::class);
    $payload = $resolver->resolve(Request::create('/martis', 'GET'));

    expect($payload['accent'])->toBe('martis');
});

it('falls back to martis when custom_accents is empty and an unknown name is requested', function () {
    config()->set('martis.preferences.custom_accents', null);
    config()->set('martis.preferences.defaults.accent', 'edgeflow');
    $resolver = app(PreferencesResolver::class);
    $payload = $resolver->resolve(Request::create('/martis', 'GET'));

    expect($payload['accent'])->toBe('martis');
});
