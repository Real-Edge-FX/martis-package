<?php

declare(strict_types=1);

namespace Martis\Support;

use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The links Martis emails with a one-time credential in them (password
 * reset, invitation, magic-link sign-in) carry that credential in the URL
 * fragment, never in the path or the query string (v2.6.0).
 *
 * A browser never sends the fragment to the server, so the token stays out
 * of the request line that reverse proxies, web servers and APM layers log
 * by default. The page the link opens reads the fragment and sends the
 * token in the body of its POST.
 *
 * Example: `https://example.com/martis/reset-password#token=…&email=…`.
 */
final class TokenLink
{
    /**
     * The emailed URL: the named route on the root of `APP_URL`, with
     * $parameters in its fragment.
     *
     * @param  array<string, string>  $parameters
     */
    public static function url(string $routeName, array $parameters): string
    {
        return CanonicalUrl::route($routeName).'#'.self::fragment($parameters);
    }

    /**
     * Send a link of an older shape (token in the path or the query string)
     * on to the page with $parameters in the fragment, so a link already in
     * an inbox keeps working.
     *
     * @param  array<string, string>  $parameters
     */
    public static function redirect(string $routeName, array $parameters): RedirectResponse
    {
        return self::keepPrivate(redirect(route($routeName, [], false).'#'.self::fragment($parameters)));
    }

    /**
     * Keep a response that carries, or leads to, a token out of caches and
     * out of the `Referer` of the requests the page makes.
     *
     * @template T of Response
     *
     * @param  T  $response
     * @return T
     */
    public static function keepPrivate(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    /** @param  array<string, string>  $parameters */
    private static function fragment(array $parameters): string
    {
        return http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }
}
