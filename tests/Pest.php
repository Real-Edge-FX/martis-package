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
