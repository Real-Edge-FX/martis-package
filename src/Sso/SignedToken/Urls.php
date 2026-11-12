<?php

declare(strict_types=1);

namespace Martis\Sso\SignedToken;

use InvalidArgumentException;

/**
 * The URLs of the signed-token flow: where the browser is sent (the issuing
 * panel's authorize URL, the audience's callback URL). Both are config, never
 * a value of the request.
 */
final class Urls
{
    /**
     * An absolute https URL, or http on a local host only (localhost,
     * `*.localhost`, `*.test`, and the loopback addresses 127.0.0.1 and [::1]);
     * no credentials, no fragment (the token travels in the query), no
     * backslash, space or control character (a browser reads `\` as `/`).
     *
     * @throws InvalidArgumentException naming `$key`
     */
    public static function assertUsable(mixed $url, string $key): string
    {
        if (! is_string($url) || $url === '') {
            throw new InvalidArgumentException("The [{$key}] config value must be an absolute https URL, got ".get_debug_type($url).'.');
        }

        $parts = preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1 ? false : parse_url($url);

        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';

        $usable = is_array($parts)
            && $host !== ''
            && ! isset($parts['user'])
            && ! isset($parts['fragment'])
            && ! str_contains($url, '#')
            && ($scheme === 'https' || ($scheme === 'http' && self::isLocalHost($host)));

        if (! $usable) {
            throw new InvalidArgumentException(
                "The [{$key}] config value must be an absolute https URL without credentials or a fragment (http only on localhost, *.localhost, *.test, 127.0.0.1 and [::1] hosts).",
            );
        }

        return $url;
    }

    /**
     * `$url` with `$params` appended to its query string (RFC 3986 encoding).
     *
     * @param  array<string, string>  $params
     */
    public static function withQuery(string $url, array $params): string
    {
        $separator = match (true) {
            ! str_contains($url, '?') => '?',
            str_ends_with($url, '?') || str_ends_with($url, '&') => '',
            default => '&',
        };

        return $url.$separator.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private static function isLocalHost(string $host): bool
    {
        // `parse_url()` keeps the brackets of an IPv6 host. Only the two
        // loopback addresses, not the rest of 127.0.0.0/8 or an IPv4-mapped form.
        return $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test')
            || $host === '127.0.0.1'
            || $host === '[::1]';
    }
}
