<?php

declare(strict_types=1);

namespace Martis\Support;

use RuntimeException;
use Throwable;

/**
 * Where a consumer theme lives.
 *
 * The app owns the source, `resources/css/martis/<name>.css`: the file
 * `martis:theme` scaffolds, the one to edit and commit, and the one
 * `martis:theme:diff` compares. The panel loads a copy from
 * `public/vendor/martis/themes/<name>.css` (see `app.blade.php`), which is
 * generated: `martis:publish-assets` deletes it with the rest of
 * `public/vendor/martis/` and writes it again from the source
 * (`ThemePublisher` backs up whatever that would lose).
 *
 * Directories are listed with `scandir()`, not Symfony Finder: which
 * symlinks Finder's `files()` returns changed within 7.4 (7.4.19 drops broken
 * ones), and a broken symlink must be seen to be reported.
 */
final class ThemeFiles
{
    /**
     * Theme names the panel loads. `app.blade.php` ignores a
     * `martis.theme.name` that does not match this pattern.
     */
    public const NAME_PATTERN = '/^[a-zA-Z0-9_-]+$/';

    public static function sourceDirectory(): string
    {
        return resource_path('css/martis');
    }

    public static function sourcePath(string $name): string
    {
        return self::sourceDirectory().'/'.$name.'.css';
    }

    public static function publishedDirectory(): string
    {
        return public_path('vendor/martis/themes');
    }

    public static function publishedPath(string $name): string
    {
        return self::publishedDirectory().'/'.$name.'.css';
    }

    /**
     * The publish record: the sha1 of each copy the theme commands wrote.
     * It lives out of the web root, so it does not list the themes publicly.
     */
    public static function recordPath(): string
    {
        return storage_path('app/martis/published-themes.json');
    }

    /** One directory per run that backed something up, named after its time. */
    public static function backupDirectory(): string
    {
        return storage_path('app/martis/theme-backups');
    }

    public static function isValidName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /** The theme name a `<name>.<ext>` file stands for. */
    public static function nameOf(string $path): string
    {
        return pathinfo($path, PATHINFO_FILENAME);
    }

    /**
     * The theme sources the publish can use, keyed by theme name.
     *
     * @return array<string, string>
     */
    public static function sources(): array
    {
        return self::scanSources()['sources'];
    }

    /**
     * Every file directly inside the source directory that looks like a
     * theme (a `.css` extension, in any case):
     *
     *  - `sources`: the usable ones, keyed by theme name;
     *  - `skipped`: the ones the panel could not load (a `.CSS` extension, a
     *    name outside NAME_PATTERN), keyed by path with the reason;
     *  - `unreadable`: the ones that cannot be read (a broken symlink, a file
     *    that does not open), keyed by path with the reason. A publish stops
     *    on them: it cannot tell what the theme should be.
     *
     * Hidden files, subdirectories and other extensions are not themes and
     * appear in no list.
     *
     * @return array{sources: array<string, string>, skipped: array<string, string>, unreadable: array<string, string>}
     */
    public static function scanSources(): array
    {
        $scan = ['sources' => [], 'skipped' => [], 'unreadable' => []];
        $directory = self::sourceDirectory();

        if (! is_dir($directory)) {
            return $scan;
        }

        $entries = self::entries($directory);

        if ($entries === null) {
            $scan['unreadable'][$directory] = 'the directory cannot be listed';

            return $scan;
        }

        foreach ($entries as $entry) {
            $path = $directory.'/'.$entry;
            $extension = pathinfo($entry, PATHINFO_EXTENSION);

            if (str_starts_with($entry, '.') || strtolower($extension) !== 'css' || (! is_link($path) && is_dir($path))) {
                continue;
            }

            $name = self::nameOf($entry);

            if ($extension !== 'css') {
                $scan['skipped'][$path] = 'a theme source ends in .css, in lowercase';
            } elseif (! self::isValidName($name)) {
                $scan['skipped'][$path] = 'the panel only loads a theme named with letters, digits, dashes and underscores';
            } elseif (($reason = self::unreadableReason($path)) !== null) {
                $scan['unreadable'][$path] = $reason;
            } else {
                $scan['sources'][$name] = $path;
            }
        }

        return $scan;
    }

    /**
     * Why a file cannot be read, or null when it can. Readability is tested
     * by opening the file: `is_readable()` only asks for permission, which a
     * bind mount or network share can grant to root while the read fails.
     */
    public static function unreadableReason(string $path): ?string
    {
        if (is_link($path) && ! file_exists($path)) {
            return 'it is a broken symlink';
        }

        if (! is_file($path)) {
            return 'it is not a file';
        }

        try {
            $handle = @fopen($path, 'rb');
        } catch (Throwable) {
            $handle = false;
        }

        if ($handle === false) {
            return 'it does not open for reading';
        }

        fclose($handle);

        return null;
    }

    /**
     * Every entry under the published directory, relative to it and sorted:
     * files, hidden ones included, and symlinks, which are listed and never
     * followed.
     *
     * @return list<string>
     *
     * @throws RuntimeException when a directory in it cannot be listed
     */
    public static function publishedFiles(): array
    {
        $root = self::publishedDirectory();

        if (is_link($root) || ! is_dir($root)) {
            return [];
        }

        $files = [];
        $pending = [''];

        while ($pending !== []) {
            $relative = array_pop($pending);
            $directory = $relative === '' ? $root : $root.'/'.$relative;
            $entries = self::entries($directory);

            if ($entries === null) {
                throw new RuntimeException("public/vendor/martis/themes/{$relative} cannot be listed");
            }

            foreach ($entries as $entry) {
                $child = $relative === '' ? $entry : $relative.'/'.$entry;

                if (! is_link($root.'/'.$child) && is_dir($root.'/'.$child)) {
                    $pending[] = $child;
                } else {
                    $files[] = $child;
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The names in a directory without `.` and `..`, sorted, or null when it
     * cannot be listed.
     *
     * @return list<string>|null
     */
    private static function entries(string $directory): ?array
    {
        try {
            $entries = @scandir($directory);
        } catch (Throwable) {
            return null;
        }

        if ($entries === false) {
            return null;
        }

        return array_values(array_diff($entries, ['.', '..']));
    }
}
