<?php

use Illuminate\Support\Facades\File;

// ---------------------------------------------------------------------------
// Favicon route — three-tier fallback:
//   1. config('martis.brand.favicon') → consumer-provided path
//   2. public_path('vendor/martis/favicon.ico') → published asset
//   3. Package default from resources/favicon.ico (always exists)
// ---------------------------------------------------------------------------

it('serves the package default favicon when nothing is configured or published', function () {
    config()->set('martis.brand.favicon', null);

    $publishedPath = public_path('vendor/martis/favicon.ico');
    $backup = null;
    if (File::exists($publishedPath)) {
        $backup = $publishedPath.'.bak';
        File::move($publishedPath, $backup);
    }

    try {
        $response = $this->get('/martis/favicon.ico');

        $response->assertOk();
        $file = $response->baseResponse->getFile();
        expect($file->getPathname())->toEndWith('resources/favicon.ico');
    } finally {
        if ($backup !== null) {
            File::move($backup, $publishedPath);
        }
    }
});

it('serves a configured favicon from public/ when present', function () {
    $custom = public_path('custom-favicon.ico');
    File::put($custom, 'CUSTOM_ICO_CONTENT');
    config()->set('martis.brand.favicon', 'custom-favicon.ico');

    try {
        $response = $this->get('/martis/favicon.ico');

        $response->assertOk();
        $file = $response->baseResponse->getFile();
        expect($file->getPathname())->toBe($custom);
    } finally {
        File::delete($custom);
    }
});

it('rejects traversal attempts in the configured favicon path', function () {
    config()->set('martis.brand.favicon', '../../../etc/passwd');

    $this->get('/martis/favicon.ico')->assertStatus(400);
});

it('rejects absolute paths in the configured favicon path', function () {
    config()->set('martis.brand.favicon', '/etc/passwd');

    $this->get('/martis/favicon.ico')->assertStatus(400);
});
