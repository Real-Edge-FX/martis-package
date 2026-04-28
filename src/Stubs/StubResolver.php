<?php

namespace Martis\Stubs;

/**
 * Central resolver for Martis generator stubs.
 *
 * Every Martis make-command (e.g. `martis:resource`, `martis:action`,
 * `martis:lens`, ...) reads its template from a `.stub` file shipped
 * inside the package. To let consuming applications customise the
 * generated output (custom file headers, opinionated docblocks,
 * project-specific imports), the consumer can publish the stubs into
 * `base_path('stubs/martis/')` via the `martis:stubs` artisan command
 * and edit them in place.
 *
 * Resolution order:
 *   1. `base_path("stubs/martis/{$name}")` if it exists.
 *   2. `<package>/stubs/{$name}` (the original template shipped here).
 *
 * The lookup is performed at generator time, so editing a published
 * stub takes effect on the next run with no cache to clear.
 */
final class StubResolver
{
    /**
     * Resolve the absolute path of a stub by name.
     *
     * @param  string  $name  The stub filename including extension
     *                        (e.g. `resource.stub`, `metric.trend.stub`).
     */
    public static function path(string $name): string
    {
        if (function_exists('base_path')) {
            $userOverride = base_path('stubs/martis/'.$name);
            if (is_file($userOverride)) {
                return $userOverride;
            }
        }

        return self::packagePath($name);
    }

    /**
     * Absolute path of the stub shipped inside the package, ignoring
     * any user override. Useful for the `martis:stubs` publisher,
     * which copies the originals out without resolving overrides.
     */
    public static function packagePath(string $name): string
    {
        return dirname(__DIR__, 2).'/stubs/'.$name;
    }

    /**
     * Directory holding all the package's stubs (no trailing slash).
     */
    public static function packageDirectory(): string
    {
        return dirname(__DIR__, 2).'/stubs';
    }
}
