<?php

declare(strict_types=1);

namespace Martis\Support;

/**
 * The extension bundle URLs the SPA shell loads (`window.MartisConfig.extensions`).
 *
 * `martis:install` points `MARTIS_EXTENSIONS` at the bundle `npm run
 * build:extensions` writes, `/vendor/martis-user/extensions.js`. That bundle
 * is optional: an app that never builds extensions has no such file, and
 * importing it logged a 404 and a console error on every page. The
 * conventional URL is therefore emitted only while the file exists under
 * `public/`. Any other URL is emitted as configured, so a mistyped custom
 * path still fails visibly.
 *
 * A URL that is a root-relative path to a file that exists under `public/` gets
 * `?v=<content hash>` appended (v2.20.0), so the bundle can be served with a
 * long, immutable cache lifetime and a deploy still reaches every browser.
 * `martis.extensions_versioned_urls` turns that off. Absolute URLs, URLs that
 * already carry a query string or a fragment, and paths of missing files are
 * emitted as configured.
 */
final class ExtensionBundles
{
    public const CONVENTIONAL_URL = '/vendor/martis-user/extensions.js';

    /** @var array<string, string> content hash by path, size and modification time */
    private static array $hashes = [];

    /** @return list<string> */
    public static function urls(): array
    {
        $urls = [];
        $versioned = (bool) config('martis.extensions_versioned_urls', true);

        foreach ((array) config('martis.extensions', []) as $url) {
            if (! is_string($url) || $url === '') {
                continue;
            }

            if ($url === self::CONVENTIONAL_URL && ! is_file(public_path(ltrim(self::CONVENTIONAL_URL, '/')))) {
                continue;
            }

            $urls[] = $versioned ? self::versioned($url) : $url;
        }

        return $urls;
    }

    private static function versioned(string $url): string
    {
        if (! str_starts_with($url, '/') || str_starts_with($url, '//') || str_contains($url, '?') || str_contains($url, '#') || str_contains($url, '..')) {
            return $url;
        }

        $path = public_path(ltrim($url, '/'));

        if (! is_file($path)) {
            return $url;
        }

        $key = $path.'|'.filesize($path).'|'.filemtime($path);

        if (! isset(self::$hashes[$key])) {
            $hash = hash_file('xxh128', $path);

            if ($hash === false) {
                return $url;
            }

            self::$hashes[$key] = substr($hash, 0, 12);
        }

        return $url.'?v='.self::$hashes[$key];
    }
}
