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
 */
final class ExtensionBundles
{
    public const CONVENTIONAL_URL = '/vendor/martis-user/extensions.js';

    /** @return list<string> */
    public static function urls(): array
    {
        $urls = [];

        foreach ((array) config('martis.extensions', []) as $url) {
            if (! is_string($url) || $url === '') {
                continue;
            }

            if ($url === self::CONVENTIONAL_URL && ! is_file(public_path(ltrim(self::CONVENTIONAL_URL, '/')))) {
                continue;
            }

            $urls[] = $url;
        }

        return $urls;
    }
}
