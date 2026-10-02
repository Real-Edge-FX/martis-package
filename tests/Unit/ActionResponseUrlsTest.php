<?php

use Martis\Actions\ActionResponse;

// ===========================================================================
// The URL of a redirect, a download and an open-in-new-tab answer (F104 / F121).
//
// The SPA hands the URL to the browser, where a `javascript:` URL runs script
// in the panel with the viewer's session, so the answer takes only an http(s)
// URL or a path and throws at the source for anything else. The SPA refuses
// the same values on its side.
// ===========================================================================

dataset('unsafe action urls', [
    'javascript' => ['javascript:alert(1)'],
    'upper case' => ['JaVaScRiPt:alert(1)'],
    'leading space' => [' javascript:alert(1)'],
    'leading control' => ["\x01javascript:alert(1)"],
    'tab inside the scheme' => ["java\tscript:alert(1)"],
    'newline inside the scheme' => ["java\nscript:alert(1)"],
    'data' => ['data:text/html,<script>alert(1)</script>'],
    'vbscript' => ['vbscript:msgbox(1)'],
    'file' => ['file:///etc/passwd'],
    'blob' => ['blob:https://example.com/0f0f0f0f'],
    'mailto' => ['mailto:someone@example.com'],
]);

dataset('safe action urls', [
    'https' => ['https://example.com/report'],
    'http' => ['http://example.com'],
    'upper case scheme' => ['HTTPS://EXAMPLE.COM'],
    'scheme relative' => ['//cdn.example.com/file.csv'],
    'absolute path' => ['/martis/resources/users'],
    'relative path' => ['exports/posts.csv'],
    'path with a query and a colon' => ['/search?q=a:b'],
    'fragment' => ['#section'],
    'empty' => [''],
]);

it('refuses a redirect to a URL that is not http(s) or a path', function (string $url) {
    ActionResponse::redirect($url);
})->with('unsafe action urls')->throws(InvalidArgumentException::class);

it('refuses an open-in-new-tab to a URL that is not http(s) or a path', function (string $url) {
    ActionResponse::openInNewTab($url);
})->with('unsafe action urls')->throws(InvalidArgumentException::class);

it('refuses a download from a URL that is not http(s) or a path', function (string $url) {
    ActionResponse::download('report.csv', $url);
})->with('unsafe action urls')->throws(InvalidArgumentException::class);

it('names the refused scheme in the exception', function () {
    expect(fn () => ActionResponse::redirect('JavaScript:alert(1)'))
        ->toThrow(InvalidArgumentException::class, 'ActionResponse::redirect() takes an http(s) URL or a path, not a "javascript:" URL.');
});

it('keeps a redirect to an http(s) URL or a path exactly as given', function (string $url) {
    expect(ActionResponse::redirect($url)->jsonSerialize())->toBe(['type' => 'redirect', 'data' => ['url' => $url]]);
})->with('safe action urls');

it('keeps an open-in-new-tab to an http(s) URL or a path exactly as given', function (string $url) {
    expect(ActionResponse::openInNewTab($url)->jsonSerialize())->toBe(['type' => 'openInNewTab', 'data' => ['url' => $url]]);
})->with('safe action urls');

it('keeps a download from an http(s) URL or a path exactly as given', function (string $url) {
    expect(ActionResponse::download('report.csv', $url)->jsonSerialize())
        ->toBe(['type' => 'download', 'data' => ['filename' => 'report.csv', 'url' => $url]]);
})->with('safe action urls');
