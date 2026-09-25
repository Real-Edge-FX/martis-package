<?php

declare(strict_types=1);

namespace Martis\Support;

use Composer\InstalledVersions;
use Throwable;

/**
 * The version Composer installed for a package, as `composer show` reports
 * it: a tag such as `v2.0.0`, or a `dev-*` branch.
 *
 * Composer records it at `composer install` / `update` time, so it changes
 * with an upgrade. A path repository keeps the version it had at the last
 * install or update, whatever is edited in the linked directory since.
 */
final class InstalledVersion
{
    /**
     * Resolved versions, per package, for the life of the process.
     *
     * @var array<string, string|null>
     */
    private static array $versions = [];

    /**
     * Resolved commit references, per package, for the life of the process.
     *
     * @var array<string, string|null>
     */
    private static array $references = [];

    /**
     * The installed version of `$package`, or null when Composer has no
     * record of it (an ad-hoc autoloader) or its metadata cannot be read.
     * Never throws.
     */
    public static function of(string $package): ?string
    {
        if (! array_key_exists($package, self::$versions)) {
            self::$versions[$package] = self::resolve($package);
        }

        return self::$versions[$package];
    }

    /**
     * A value that changes whenever Composer installs new code of
     * `$package`, for cache keys: `stamp()` of its version and commit
     * reference. Null when Composer has no record of it. Never throws.
     */
    public static function fingerprint(string $package): ?string
    {
        $version = self::of($package);
        if ($version === null) {
            return null;
        }

        if (! array_key_exists($package, self::$references)) {
            self::$references[$package] = self::resolveReference($package);
        }

        return self::stamp($version, self::$references[$package]);
    }

    /**
     * A tagged version names its code on its own. A dev version keeps its
     * name across `composer update`, whether a branch (`dev-main`) or a
     * numbered branch or alias (`2.x-dev`, `2.0.x-dev`), so its commit
     * reference (shortened to 12 characters) is appended:
     * `dev-main@0123456789ab`.
     */
    public static function stamp(?string $version, ?string $reference): ?string
    {
        if ($version === null || $version === '') {
            return null;
        }

        $dev = str_starts_with($version, 'dev-') || str_ends_with($version, '-dev');

        if (! $dev || $reference === null || $reference === '') {
            return $version;
        }

        return $version.'@'.substr($reference, 0, 12);
    }

    private static function resolveReference(string $package): ?string
    {
        try {
            $reference = InstalledVersions::getReference($package);

            return is_string($reference) && $reference !== '' ? $reference : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function resolve(string $package): ?string
    {
        try {
            if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package)) {
                $pretty = InstalledVersions::getPrettyVersion($package);

                return is_string($pretty) && $pretty !== '' ? $pretty : null;
            }
        } catch (Throwable) {
            // InstalledVersions may throw when the package metadata is
            // incomplete (e.g. a path repository without a git tag): no
            // version rather than an error.
        }

        return null;
    }
}
