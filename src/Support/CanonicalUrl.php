<?php

declare(strict_types=1);

namespace Martis\Support;

use Closure;
use Illuminate\Routing\UrlGenerator;
use RuntimeException;

/**
 * The URLs Martis puts in an email, built on `config('app.url')`, never on
 * the request (v2.4.0).
 *
 * Laravel's `route()` takes the scheme and host of an absolute URL from the
 * request that builds it: the `Host` header, or `X-Forwarded-Host` behind a
 * trusted proxy. A sign-in link, a password reset, an invitation or an
 * email-change confirmation requested with a forged `Host` was then mailed
 * to the victim pointing at the attacker's domain, and the token in it
 * reached the attacker when the victim clicked. An emailed token URL is
 * therefore generated with the scheme and root of `APP_URL`, so the request
 * cannot choose where it points. `APP_URL` must be the URL the panel is
 * served on.
 *
 * Only the URL of the mail is pinned: the generator goes back to the request
 * root as soon as the URL is built.
 */
final class CanonicalUrl
{
    /**
     * The URL of the named route, on the root of `APP_URL`.
     *
     * @param  array<string, mixed>|string  $parameters
     */
    public static function route(string $name, array|string $parameters = []): string
    {
        return self::using(static fn (UrlGenerator $url): string => $url->route($name, $parameters));
    }

    /**
     * The temporary signed URL of the named route, on the root of `APP_URL`.
     * The signature covers that root, so the signed route must be served on
     * the host `APP_URL` names (a request on another host fails the
     * `signed` middleware).
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function temporarySignedRoute(string $name, \DateTimeInterface|\DateInterval|int $expiration, array $parameters = []): string
    {
        return self::using(static fn (UrlGenerator $url): string => $url->temporarySignedRoute($name, $expiration, $parameters));
    }

    /**
     * `config('app.url')` without a trailing slash.
     *
     * @throws RuntimeException When `APP_URL` is not an absolute http(s) URL: a link
     *                          cannot be pinned to a root that is not there, and falling
     *                          back to the request would bring the forged host back.
     */
    public static function root(): string
    {
        $configured = config('app.url');
        $root = is_string($configured) ? rtrim(trim($configured), '/') : '';
        $scheme = strtolower((string) parse_url($root, PHP_URL_SCHEME));
        $host = (string) parse_url($root, PHP_URL_HOST);

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new RuntimeException(
                'Martis builds the links it emails (sign-in, password reset, invitation, email change) on APP_URL, '
                .'and APP_URL is not an absolute http(s) URL. Set APP_URL to the URL the panel is served on.'
            );
        }

        return $root;
    }

    /**
     * Run $callback with the URL generator pinned to the scheme and root of
     * `APP_URL`, then put back whatever the generator had.
     *
     * @template T
     *
     * @param  Closure(UrlGenerator): T  $callback
     * @return T
     */
    private static function using(Closure $callback): mixed
    {
        $root = self::root();

        /** @var UrlGenerator $url */
        $url = app('url');

        // The generator keeps no getter for what it was forced to.
        [$forcedRoot, $forcedScheme] = Closure::bind(
            fn (): array => [$this->forcedRoot, $this->forceScheme],
            $url,
            UrlGenerator::class,
        )();

        $url->forceRootUrl($root);
        $url->forceScheme((string) parse_url($root, PHP_URL_SCHEME));

        try {
            return $callback($url);
        } finally {
            $url->forceRootUrl(is_string($forcedRoot) ? $forcedRoot : null);
            $url->forceScheme(is_string($forcedScheme) ? rtrim($forcedScheme, ':/') : null);
        }
    }
}
