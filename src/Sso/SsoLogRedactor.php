<?php

declare(strict_types=1);

namespace Martis\Sso;

/**
 * Masks credential values in text that is about to be logged.
 *
 * HTTP client exceptions embed the request URL and the response body in their
 * message, so an OAuth failure can carry the authorization code, the `state`,
 * a client secret or a token. The values of these keys are replaced with
 * `[redacted]`, whether they appear as a query pair (`code=abc`) or a JSON pair
 * (`"access_token":"abc"`, also when the JSON is itself escaped inside a
 * string), and so is a `Bearer` credential.
 */
final class SsoLogRedactor
{
    private const KEYS = 'code|state|token|id_token|access_token|refresh_token|client_secret';

    public static function redact(string $text): string
    {
        $keys = self::KEYS;

        // JSON pair: "key" : "value", also as \"key\":\"value\" inside a string.
        $text = preg_replace('/((?:\\\\?")(?:'.$keys.')(?:\\\\?")\s*:\s*\\\\?")[^"\\\\]*/i', '$1[redacted]', $text) ?? $text;

        // Query pair: key=value, up to the next separator. The lookbehind keeps
        // `error_code=` or `passcode=` out: only the exact key is a credential.
        $text = preg_replace('/(?<![A-Za-z0-9_])((?:'.$keys.')=)[^&\s"\'<>`]*/i', '$1[redacted]', $text) ?? $text;

        // Authorization header value.
        return preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer [redacted]', $text) ?? $text;
    }
}
