<?php

declare(strict_types=1);

namespace Martis\Sso;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The request id the SSO log lines and events carry (v2.22.0): the request's
 * `X-Request-Id` header, else a UUID generated for the request.
 *
 * The header is the caller's to write, so it is bounded before it reaches a
 * log context or an event listener: anything but printable ASCII (a control
 * character, an escape sequence, a byte of broken UTF-8) becomes `?`, and at
 * most 128 bytes are kept. A listener that stores it in a utf8mb4 column then
 * cannot be made to throw on it, and a log reader sees no escape sequence.
 */
final class SsoRequestId
{
    public const MAX_LENGTH = 128;

    public static function from(Request $request): string
    {
        $header = $request->headers->get('X-Request-Id');

        if (! is_string($header) || $header === '') {
            return (string) Str::uuid();
        }

        return substr((string) preg_replace('/[^\x20-\x7E]/', '?', $header), 0, self::MAX_LENGTH);
    }
}
