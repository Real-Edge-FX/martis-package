<?php

declare(strict_types=1);

use Martis\Sso\SignedToken\Urls;

// The URLs the browser is sent to in the signed-token flow (`authorize_url`
// and an audience's `callback_url`): https, or http on a local host only.
// Local hosts: localhost, `*.localhost`, `*.test`, and the loopback addresses
// 127.0.0.1 and [::1] (v2.21.0).

it('accepts https on any host and http on a local host', function (string $url) {
    expect(Urls::assertUsable($url, 'k'))->toBe($url);
})->with([
    'https://a.example/issue',
    'https://127.0.0.2/issue',
    'http://localhost:8000/cb',
    'http://LOCALHOST/cb',
    'http://a.localhost/cb',
    'http://panel-b.test/cb',
    'http://127.0.0.1/cb',
    'http://127.0.0.1:8198/martis/sso/panel_a/callback',
    'http://[::1]/cb',
    'http://[::1]:8199/martis/sso/issue/panel-b',
    'https://[::1]:8199/cb',
]);

it('refuses http on any other host, naming the key', function (string $url) {
    expect(fn () => Urls::assertUsable($url, 'martis.auth.sso.issuer.audiences.b.callback_url'))
        ->toThrow(InvalidArgumentException::class, 'martis.auth.sso.issuer.audiences.b.callback_url');
})->with([
    'http://a.example/cb',
    'http://localhost.example/cb',
    'http://example.localhost.evil.com/cb',
    'http://127.0.0.2/cb',
    'http://127.0.0.1.example/cb',
    'http://0.0.0.0/cb',
    'http://10.0.0.1/cb',
    'http://[::2]/cb',
    'http://[::ffff:127.0.0.1]/cb',
    'http://127.0.0.1@evil.example/cb',
    'http://user@127.0.0.1/cb',
    'http://127.0.0.1/cb#x',
    'http://127.0.0.1\\@evil.example/cb',
]);
