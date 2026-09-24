<?php

declare(strict_types=1);

namespace Martis\Support;

use Illuminate\Filesystem\Filesystem;

/**
 * Where a consumer theme lives.
 *
 * The app owns the source, `resources/css/martis/<name>.css`: the file
 * `martis:theme` scaffolds, the one to edit and commit, and the one
 * `martis:theme:diff` compares. The panel loads a copy from
 * `public/vendor/martis/themes/<name>.css` (see `app.blade.php`), which is
 * generated: `martis:publish-assets` deletes it with the rest of
 * `public/vendor/martis/` and writes it again from the source.
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
     * Every `.css` file directly inside the source directory, keyed by theme
     * name (the file name without `.css`). Other extensions and files in
     * subdirectories are not themes.
     *
     * @return array<string, string>
     */
    public static function sources(Filesystem $filesystem): array
    {
        $directory = self::sourceDirectory();

        if (! $filesystem->isDirectory($directory)) {
            return [];
        }

        $sources = [];
        foreach ($filesystem->files($directory) as $file) {
            if ($file->getExtension() === 'css') {
                $sources[$file->getBasename('.css')] = $file->getPathname();
            }
        }

        return $sources;
    }
}
