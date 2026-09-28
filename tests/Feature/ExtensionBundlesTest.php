<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Martis\Support\ExtensionBundles;

/*
 * martis:install points MARTIS_EXTENSIONS at the bundle `npm run
 * build:extensions` writes. Until an app builds it, importing it logged a
 * 404 and a console error on every page (consumer report, v2.1.0).
 */

beforeEach(function () {
    $this->bundle = public_path('vendor/martis-user/extensions.js');
    (new Filesystem)->delete($this->bundle);
});

afterEach(function () {
    (new Filesystem)->delete($this->bundle);
});

it('leaves out the conventional bundle while it is not built', function () {
    config()->set('martis.extensions', [ExtensionBundles::CONVENTIONAL_URL]);

    expect(ExtensionBundles::urls())->toBe([]);
});

it('keeps the conventional bundle once it is built', function () {
    config()->set('martis.extensions', [ExtensionBundles::CONVENTIONAL_URL]);
    (new Filesystem)->ensureDirectoryExists(dirname($this->bundle));
    file_put_contents($this->bundle, 'export {}');

    expect(ExtensionBundles::urls())->toBe([ExtensionBundles::CONVENTIONAL_URL]);
});

it('keeps a custom bundle whose file is missing, so a mistyped path still fails visibly', function () {
    config()->set('martis.extensions', ['/vendor/acme/missing.js', 'https://cdn.example.com/ext.js']);

    expect(ExtensionBundles::urls())->toBe(['/vendor/acme/missing.js', 'https://cdn.example.com/ext.js']);
});

it('keeps the order of the configured bundles', function () {
    config()->set('martis.extensions', ['/vendor/acme/a.js', ExtensionBundles::CONVENTIONAL_URL, '/vendor/acme/b.js']);

    expect(ExtensionBundles::urls())->toBe(['/vendor/acme/a.js', '/vendor/acme/b.js']);
});

it('emits the filtered list in the shell', function () {
    config()->set('martis.extensions', [ExtensionBundles::CONVENTIONAL_URL, '/vendor/acme/a.js']);

    $this->get('/martis/login')
        ->assertOk()
        ->assertSee('extensions: '.json_encode(['/vendor/acme/a.js']).',', false);
});
