<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
 * The sidebar layout's header-first order and the top-bar slots (v2.7.0) are
 * read by the SPA from the `layout` block app.blade.php inlines into
 * window.MartisConfig: `martis.layout.header_first` (env
 * MARTIS_LAYOUT_HEADER_FIRST) and the `topbar_start` / `topbar_end` keys of
 * `martis.layout.components`.
 */

beforeEach(function () {
    $this->hotFile = public_path('vendor/martis/hot');
    File::ensureDirectoryExists(dirname($this->hotFile));
    File::put($this->hotFile, 'http://localhost:5173');
});

afterEach(function () {
    File::delete($this->hotFile);
});

/** The `layout` object the shell hands the SPA. */
function shellLayoutConfig(string $html): array
{
    expect(preg_match('/\blayout: (\{.*\}),\n/', $html, $match))->toBe(1);

    return json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
}

it('keeps the sidebar first and the top-bar slots empty by default', function () {
    expect(config('martis.layout.header_first'))->toBeFalse()
        ->and(config('martis.layout.components'))->toMatchArray(['topbar_start' => null, 'topbar_end' => null]);

    $layout = shellLayoutConfig($this->get('/martis/login')->assertOk()->getContent());

    expect($layout['header_first'])->toBeFalse()
        ->and($layout['components'])->toHaveKeys(['topbar_start', 'topbar_end']);
});

it('hands the SPA header_first and the slot keys the config sets', function () {
    config()->set('martis.layout.header_first', true);
    config()->set('martis.layout.components.topbar_start', 'tenant-indicator');

    $layout = shellLayoutConfig($this->get('/martis/login')->assertOk()->getContent());

    expect($layout['header_first'])->toBeTrue()
        ->and($layout['components']['topbar_start'])->toBe('tenant-indicator')
        ->and($layout['components']['topbar_end'])->toBeNull();
});
