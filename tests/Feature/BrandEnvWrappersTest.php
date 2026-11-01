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

it('footer.text defaults to null so the bundled translation wins', function () {
    expect(config('martis.footer.enabled'))->toBeTrue();
    expect(config('martis.footer.text'))->toBeNull();
});

it('welcome.heading and welcome.description default to null (translations win)', function () {
    expect(config('martis.welcome.heading'))->toBeNull();
    expect(config('martis.welcome.description'))->toBeNull();
});
