<?php

use Illuminate\Support\Facades\File;

// ===========================================================================
// The panel shell links the configured favicon once, typed by its extension.
//
// Up to v2.12 resources/views/app.blade.php linked `brand.favicon` twice, as
// `image/x-icon` and as a 32x32 `image/png`, whatever the file was: an SVG
// favicon was announced as ICO and PNG, and a browser that trusts the `type`
// attribute could skip it.
// ===========================================================================

beforeEach(function () {
    $this->hotFile = public_path('vendor/martis/hot');
    File::ensureDirectoryExists(dirname($this->hotFile));
    File::put($this->hotFile, 'http://localhost:5173');
});

afterEach(function () {
    File::delete($this->hotFile);
});

/** Every `<link rel="icon">` tag of the login page. */
function faviconLinks(): array
{
    $html = test()->get('/martis/login')->assertOk()->getContent();
    preg_match_all('/<link\b[^>]*rel="icon"[^>]*>/', $html, $matches);

    return $matches[0];
}

it('types the configured favicon by its extension', function (string $path, string $type) {
    config()->set('martis.brand.favicon', $path);

    $links = faviconLinks();

    expect($links)->toHaveCount(1)
        ->and($links[0])->toContain('type="'.$type.'"')
        ->and($links[0])->toContain('href="'.asset($path).'"')
        ->and($links[0])->not->toContain('sizes=');
})->with([
    'svg' => ['/brand/icon.svg', 'image/svg+xml'],
    'png' => ['brand/favicon.png', 'image/png'],
    'ico' => ['favicon.ico', 'image/x-icon'],
    'upper-case extension' => ['brand/ICON.SVG', 'image/svg+xml'],
    'query string' => ['/brand/icon.svg?v=3', 'image/svg+xml'],
    'webp' => ['brand/icon.webp', 'image/webp'],
]);

it('omits the type of a favicon whose extension it does not know', function (string $path) {
    config()->set('martis.brand.favicon', $path);

    $links = faviconLinks();

    expect($links)->toHaveCount(1)
        ->and($links[0])->not->toContain('type=')
        ->and($links[0])->toContain('href="'.asset($path).'"');
})->with(['brand/icon.avif2', 'brand/favicon']);

it('keeps the default favicon link when none is configured', function () {
    config()->set('martis.brand.favicon', null);

    expect(faviconLinks())->toBe(['<link rel="icon" type="image/x-icon" href="/martis/favicon.ico">']);
});
