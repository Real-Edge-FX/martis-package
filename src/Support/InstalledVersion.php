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
