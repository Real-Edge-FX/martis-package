<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;

// ===========================================================================
// The panel shell carries the request's CSP nonce (F101).
//
// resources/views/app.blade.php renders an inline <script> (window.MartisConfig
// and the pre-paint preference resolver), inline <style> blocks (logo heights,
// custom accents) and the module <script> / <link rel="stylesheet"> tags of
// the bundle. Without a nonce a Content-Security-Policy that drops
// 'unsafe-inline' blocks the boot script and the SPA never starts. The nonce
// comes from `Vite::useCspNonce()`; with none set the tags stay as they were.
// ===========================================================================

beforeEach(function () {
    $this->hotFile = public_path('vendor/martis/hot');
    File::ensureDirectoryExists(dirname($this->hotFile));
    File::put($this->hotFile, 'http://localhost:5173');

    config()->set('martis.theme.name', 'midnight');
    config()->set('martis.preferences.custom_accents', 'brand:#ff5500');
});

afterEach(function () {
    File::delete($this->hotFile);
});

/** Every opening tag of the given elements in `$html`. */
function shellTags(string $html, string $pattern): array
{
    preg_match_all($pattern, $html, $matches);

    return $matches[0];
}

it('stamps the nonce on every inline script and style and on the bundle tags', function () {
    Vite::useCspNonce('abc123nonce');

    $html = $this->get('/martis/login')->assertOk()->getContent();

    $scripts = shellTags($html, '/<script\b[^>]*>/');
    $styles = shellTags($html, '/<style\b[^>]*>/');
    $stylesheets = shellTags($html, '/<link\b[^>]*rel="stylesheet"[^>]*>/');

    // The inline boot script, and the two module scripts of the dev server.
    expect($scripts)->toHaveCount(3)
        // The logo heights, and the custom accents.
        ->and($styles)->toHaveCount(2)
        ->and($stylesheets)->toHaveCount(1);

    foreach ([...$scripts, ...$styles, ...$stylesheets] as $tag) {
        expect($tag)->toContain('nonce="abc123nonce"');
    }
});

it('stamps the nonce on the built bundle tags', function () {
    File::delete($this->hotFile);
    $manifest = public_path('vendor/martis/manifest.json');
    $hadManifest = File::exists($manifest);
    $previous = $hadManifest ? File::get($manifest) : null;
    File::put($manifest, json_encode([
        'resources/js/app.tsx' => ['file' => 'assets/app-abc.js', 'css' => ['assets/app-abc.css']],
    ]));

    try {
        Vite::useCspNonce('builtnonce');

        $html = $this->get('/martis/login')->assertOk()->getContent();

        $entry = shellTags($html, '/<script\b[^>]*type="module"[^>]*>/');
        $css = shellTags($html, '/<link\b[^>]*assets\/app-abc\.css[^>]*>/');

        expect($entry)->toHaveCount(1)
            ->and($entry[0])->toContain('nonce="builtnonce"')
            ->and($entry[0])->toContain('assets/app-abc.js')
            ->and($css)->toHaveCount(1)
            ->and($css[0])->toContain('nonce="builtnonce"');
    } finally {
        $hadManifest ? File::put($manifest, $previous) : File::delete($manifest);
    }
});

it('publishes the nonce in a meta tag for what the SPA injects at run time', function () {
    Vite::useCspNonce('metanonce');

    $this->get('/martis/login')->assertOk()->assertSee('<meta name="csp-nonce" content="metanonce">', false);
});

it('escapes the nonce', function () {
    Vite::useCspNonce('a"><script>alert(1)</script>');

    $html = $this->get('/martis/login')->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('nonce="a&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"');
});

it('emits no nonce attribute and no meta tag when the app sets none', function () {
    $html = $this->get('/martis/login')->assertOk()->getContent();

    expect($html)->not->toContain('nonce')
        ->and($html)->toContain('window.MartisConfig')
        ->and(shellTags($html, '/<script\b[^>]*>/'))->toHaveCount(3);
});
