<?php

declare(strict_types=1);

namespace Martis\Support;

use Illuminate\Filesystem\Filesystem;
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

    public static function isValidName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /**
     * The theme sources the publish can use, keyed by theme name.
     *
     * @return array<string, string>
     */
    public static function sources(Filesystem $filesystem): array
    {
        return self::scanSources($filesystem)['sources'];
    }

    /**
     * Every file directly inside the source directory that looks like a
     * theme (a `.css` extension, in any case): the usable sources keyed by
     * theme name, and the others keyed by path with the reason they cannot
     * be published. Other extensions and files in subdirectories are not
     * themes and appear in neither list.
     *
     * @return array{sources: array<string, string>, skipped: array<string, string>}
     */
    public static function scanSources(Filesystem $filesystem): array
    {
        $scan = ['sources' => [], 'skipped' => []];
        $directory = self::sourceDirectory();

        if (! $filesystem->isDirectory($directory)) {
            return $scan;
        }

        foreach ($filesystem->files($directory) as $file) {
            $extension = $file->getExtension();
            if (strtolower($extension) !== 'css') {
                continue;
            }

            $path = $file->getPathname();
            $name = $file->getBasename('.'.$extension);

            if ($extension !== 'css') {
                $scan['skipped'][$path] = 'a theme source ends in .css, in lowercase';
            } elseif (! self::isValidName($name)) {
                $scan['skipped'][$path] = 'the panel only loads a theme named with letters, digits, dashes and underscores';
            } elseif (! is_file($path) || ! self::opens($path)) {
                // A broken symlink, or a file the process cannot read.
                $scan['skipped'][$path] = 'it is not a readable file';
            } else {
                $scan['sources'][$name] = $path;
            }
        }

        return $scan;
    }

    /**
     * Whether the file opens for reading. `is_readable()` only asks for
     * permission, which a bind mount or network share can grant to root
     * while the read itself fails.
     */
    private static function opens(string $path): bool
    {
        try {
            $handle = @fopen($path, 'rb');
        } catch (Throwable) {
            return false;
        }

        if ($handle === false) {
            return false;
        }

        fclose($handle);

        return true;
    }

    /**
     * Every file under the published directory, relative to it and sorted,
     * leaving out hidden files such as the publish record.
     *
     * @return list<string>
     */
    public static function publishedFiles(Filesystem $filesystem): array
    {
        $directory = self::publishedDirectory();

        if (! $filesystem->isDirectory($directory)) {
            return [];
        }

        $files = [];
        foreach ($filesystem->allFiles($directory) as $file) {
            $files[] = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
        }
        sort($files);

        return $files;
    }
}
