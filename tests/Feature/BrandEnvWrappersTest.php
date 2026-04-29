<?php

declare(strict_types=1);

/*
 * Brand / footer / welcome env wrappers — pin the contract that the
 * package exposes single-string env vars for the host app's brand
 * surfaces (logo, footer text, welcome card heading + description).
 *
 * Env-driven values are intentionally locale-agnostic: they win over
 * every translation. Hosts that need per-locale copy should leave the
 * env vars unset and publish the lang files via
 * `vendor:publish --tag=martis-lang` instead. The trade-off is
 * documented in `docs/configuration.md` and in the `welcome` block of
 * `config/martis.php`.
 */

it('brand.logo is wired to MARTIS_BRAND_LOGO env var', function () {
    expect(config('martis.brand'))
        ->toBeArray()
        ->toHaveKey('logo');

    config()->set('martis.brand.logo', '/img/edgeflow-logo.svg');
    expect(config('martis.brand.logo'))->toBe('/img/edgeflow-logo.svg');

    config()->set('martis.brand.logo', null);
    expect(config('martis.brand.logo'))->toBeNull();
});

it('footer.text is wired to MARTIS_FOOTER_TEXT env var (single locale-agnostic string)', function () {
    expect(config('martis.footer'))
        ->toBeArray()
        ->toHaveKey('text');

    config()->set('martis.footer.text', '© 2026 EdgeFlow. All rights reserved.');
    expect(config('martis.footer.text'))->toBe('© 2026 EdgeFlow. All rights reserved.');
});

it('footer.text defaults to null so the bundled translation wins', function () {
    expect(config('martis.footer.enabled'))->toBeTrue();
    expect(config('martis.footer.text'))->toBeNull();
});

it('welcome block exposes heading and description env wrappers', function () {
    expect(config('martis.welcome'))
        ->toBeArray()
        ->toHaveKey('heading')
        ->toHaveKey('description');
});

it('welcome.heading and welcome.description default to null (translations win)', function () {
    expect(config('martis.welcome.heading'))->toBeNull();
    expect(config('martis.welcome.description'))->toBeNull();
});

it('welcome env values override the bundled heading and description translations', function () {
    config()->set('martis.welcome.heading', 'Welcome to EdgeFlow');
    config()->set('martis.welcome.description', 'NQ/ES/YM regime intelligence for futures traders.');

    expect(config('martis.welcome.heading'))->toBe('Welcome to EdgeFlow');
    expect(config('martis.welcome.description'))->toBe('NQ/ES/YM regime intelligence for futures traders.');
});
