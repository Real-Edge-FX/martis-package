<?php

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
