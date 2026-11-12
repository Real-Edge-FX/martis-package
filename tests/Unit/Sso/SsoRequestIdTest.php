<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Martis\Sso\SsoRequestId;

// v2.22.0: one bound for the request id of the SSO log lines and events.

function ssoRequestWith(?string $id): Request
{
    $request = Request::create('/x');
    if ($id !== null) {
        $request->headers->set('X-Request-Id', $id);
    }

    return $request;
}

it('keeps a printable ASCII request id as it is', function () {
    expect(SsoRequestId::from(ssoRequestWith('req-abc_123.X')))->toBe('req-abc_123.X');
});

it('generates a UUID when the header is missing or empty', function (?string $id) {
    expect(SsoRequestId::from(ssoRequestWith($id)))->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
})->with([null, '']);

it('cuts the id to 128 bytes', function () {
    expect(SsoRequestId::from(ssoRequestWith(str_repeat('r', 300))))->toBe(str_repeat('r', 128));
});

it('replaces control characters, escapes and bytes that are not ASCII with a question mark', function () {
    expect(SsoRequestId::from(ssoRequestWith("a\tb\nc\x1b[31md\xffe\u{202e}f")))->toBe('a?b?c?[31md?e???f');
});

it('bounds a multibyte id in bytes, not in characters', function () {
    $id = SsoRequestId::from(ssoRequestWith(str_repeat('é', 200)));

    expect(strlen($id))->toBe(128)->and($id)->toMatch('/^[\x20-\x7E]+$/');
});
