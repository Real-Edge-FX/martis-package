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

    /** Where the package assets are published: the directory the asset publish wipes. */
    public static function assetsDirectory(): string
    {
        return public_path('vendor/martis');
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
     * Files in the published directory that are not theme files: the publish
     * record, and the placeholders that keep an empty directory in version
     * control. They are never listed, backed up or reported, and a full
     * publish keeps the placeholders.
     */
    public const RECORD_FILE = '.published.json';

    public const PLACEHOLDERS = ['.gitkeep', '.gitignore', '.keep'];

    /**
     * The publish record: the sha1 of each copy the theme commands wrote.
     * It sits next to the copies, so it is written wherever they are (a
     * read-only storage/ does not lose it), and holds hashes only, so it
     * names no theme in the web root.
     */
    public static function recordPath(): string
    {
        return self::publishedDirectory().'/'.self::RECORD_FILE;
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
     *    that does not open), keyed by path with the reason, or the directory
     *    itself when it cannot be listed. A publish skips them with a warning,
     *    and stops when one is the active theme's source or its published
     *    copy would go.
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
     * Whether the published directory is itself a symlink (a deploy tool's
     * shared directory): the theme commands replace it with a real directory
     * rather than write into its target.
     */
    public static function publishedDirectoryIsLink(): bool
    {
        return is_link(self::assetsDirectory()) || is_link(self::publishedDirectory());
    }

    /**
     * Every entry under the published directory, relative to it and sorted:
     * files, hidden ones included, and symlinks, which are listed and never
     * followed. When the directory itself is a symlink, its target's entries
     * are listed: they are what the panel serves. The publish record and the
     * VCS placeholders at the top are left out.
     *
     * @return list<string>
     *
     * @throws RuntimeException when a directory in it cannot be listed
     */
    public static function publishedFiles(): array
    {
        $root = self::publishedDirectory();

        if (! is_dir($root)) {
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
                if ($relative === '' && self::isKept($entry)) {
                    continue;
                }

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

    /** Whether a top-level entry of the published directory is not a theme file. */
    public static function isKept(string $entry): bool
    {
        return $entry === self::RECORD_FILE || in_array($entry, self::PLACEHOLDERS, true);
    }

    /**
     * The names in a directory without `.` and `..`, sorted, or null when it
     * cannot be listed.
     *
     * @return list<string>|null
     */
    public static function entries(string $directory): ?array
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
