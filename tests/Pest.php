<?php

use Illuminate\Filesystem\Filesystem;
use Martis\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

if (! function_exists('rmtree')) {
    /**
     * Recursively remove a directory and its contents. Test-only helper
     * shared by the agents / MCP support tests; defined here so any
     * single-file pest run can use it.
     */
    function rmtree(string $path): void
    {
        if (! is_dir($path)) {
            if (is_file($path)) {
                unlink($path);
            }

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path.'/'.$entry;
            is_dir($full) ? rmtree($full) : unlink($full);
        }
        rmdir($path);
    }
}

if (! function_exists('themeBackups')) {
    /**
     * The files martis:publish-assets and martis:theme backed up in the
     * testbench app, keyed by their path under
     * storage/app/martis/theme-backups/ (sorted), with their contents.
     *
     * @return array<string, string>
     */
    function themeBackups(): array
    {
        $root = storage_path('app/martis/theme-backups');
        if (! is_dir($root)) {
            return [];
        }

        $backups = [];
        foreach ((new Filesystem)->allFiles($root, true) as $file) {
            $backups[str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname())] = $file->getContents();
        }
        ksort($backups);

        return $backups;
    }
}

if (! function_exists('removeThemeState')) {
    /**
     * Remove what the theme commands keep in the testbench app's
     * storage/app/martis/ (the backups, whether a spec left them as a file
     * or a directory), then the directory itself when nothing else is in it.
     * The publish record lives next to the copies, in
     * public/vendor/martis/themes/, which the theme specs delete.
     */
    function removeThemeState(): void
    {
        $files = new Filesystem;

        foreach (['app/martis/theme-backups'] as $relative) {
            $path = storage_path($relative);
            $files->isDirectory($path) ? $files->deleteDirectory($path) : $files->delete($path);
        }

        $directory = storage_path('app/martis');
        if ($files->isDirectory($directory) && $files->files($directory, true) === [] && $files->directories($directory) === []) {
            $files->deleteDirectory($directory);
        }
    }
}

if (! function_exists('preservePublishedMartisConfig')) {
    /**
     * Snapshot the testbench app's config/martis.php and return a callback
     * that puts it back, or removes it when there was none. martis:theme
     * writes theme.name into a published config, and a testbench app that
     * already ran martis:install holds one: without the restore, every
     * later spec reads the scaffolded theme name.
     */
    function preservePublishedMartisConfig(): Closure
    {
        $path = config_path('martis.php');
        $contents = is_file($path) ? (string) file_get_contents($path) : null;

        return function () use ($path, $contents): void {
            if ($contents !== null) {
                file_put_contents($path, $contents);
            } elseif (is_file($path)) {
                unlink($path);
            }
        };
    }
}
