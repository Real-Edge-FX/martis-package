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
    (new Filesystem)->delete(public_path('vendor/martis-user/other.js'));
});

function buildBundle(string $path, string $contents = 'export {}'): void
{
    (new Filesystem)->ensureDirectoryExists(dirname($path));
    file_put_contents($path, $contents);
}

it('leaves out the conventional bundle while it is not built', function () {
    config()->set('martis.extensions', [ExtensionBundles::CONVENTIONAL_URL]);

    expect(ExtensionBundles::urls())->toBe([]);
});

it('keeps the conventional bundle once it is built', function () {
    config()->set('martis.extensions', [ExtensionBundles::CONVENTIONAL_URL]);
    (new Filesystem)->ensureDirectoryExists(dirname($this->bundle));
    file_put_contents($this->bundle, 'export {}');

    expect(ExtensionBundles::urls())->toBe([ExtensionBundles::CONVENTIONAL_URL.'?v='.substr(hash_file('xxh128', $this->bundle), 0, 12)]);
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

it('appends a content hash to a built bundle, which changes with the content', function () {
    config()->set('martis.extensions', [ExtensionBundles::CONVENTIONAL_URL]);

    buildBundle($this->bundle, 'export const a = 1');
    $first = ExtensionBundles::urls();

    buildBundle($this->bundle, 'export const a = 22');
    clearstatcache();
    $second = ExtensionBundles::urls();

    expect($first[0])->toMatch('#^/vendor/martis-user/extensions\.js\?v=[0-9a-f]{12}$#')
        ->and($second[0])->toMatch('#\?v=[0-9a-f]{12}$#')
        ->and($second)->not->toBe($first);
});

it('leaves a URL with a query string untouched', function () {
    buildBundle($this->bundle);
    config()->set('martis.extensions', [ExtensionBundles::CONVENTIONAL_URL.'?build=7']);

    expect(ExtensionBundles::urls())->toBe([ExtensionBundles::CONVENTIONAL_URL.'?build=7']);
});

it('leaves absolute and protocol-relative URLs untouched', function () {
    config()->set('martis.extensions', ['https://cdn.example.com/ext.js', '//cdn.example.com/ext.js']);

    expect(ExtensionBundles::urls())->toBe(['https://cdn.example.com/ext.js', '//cdn.example.com/ext.js']);
});

it('leaves a path that climbs out of public untouched', function () {
    config()->set('martis.extensions', ['/vendor/../composer.json']);

    expect(ExtensionBundles::urls())->toBe(['/vendor/../composer.json']);
});

it('versions each built bundle by its own content', function () {
    $other = public_path('vendor/martis-user/other.js');
    buildBundle($this->bundle, 'export const a = 1');
    buildBundle($other, 'export const b = 2');
    config()->set('martis.extensions', [ExtensionBundles::CONVENTIONAL_URL, '/vendor/martis-user/other.js']);

    [$first, $second] = ExtensionBundles::urls();

    expect($first)->not->toBe($second)
        ->and($second)->toStartWith('/vendor/martis-user/other.js?v=');
});

it('emits the URLs as configured when versioning is off', function () {
    buildBundle($this->bundle);
    config()->set('martis.extensions', [ExtensionBundles::CONVENTIONAL_URL]);
    config()->set('martis.extensions_versioned_urls', false);

    expect(ExtensionBundles::urls())->toBe([ExtensionBundles::CONVENTIONAL_URL]);
});

it('emits the versioned URL and the stale-chunk switch in the shell', function () {
    buildBundle($this->bundle);
    config()->set('martis.extensions', [ExtensionBundles::CONVENTIONAL_URL]);

    $this->get('/martis/login')
        ->assertOk()
        ->assertSee('extensionsReloadOnStale: true,', false)
        ->assertSee(substr(json_encode(ExtensionBundles::CONVENTIONAL_URL), 1, -1).'?v=', false);

    config()->set('martis.extensions_reload_on_stale', false);

    $this->get('/martis/login')->assertSee('extensionsReloadOnStale: false,', false);
});
