<?php

declare(strict_types=1);

namespace Martis\Support;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Throwable;

/**
 * Replaces a directory that is a symlink with a real directory.
 *
 * The asset and theme commands own `public/vendor/martis/` and its
 * `themes/`: they wipe, overwrite and remove files there. When either is a
 * symlink (a deploy tool's shared directory), writing through it would
 * empty or rewrite the target, which may hold files the commands do not
 * own. They replace the link first: the new directory holds a copy of what
 * the target holds (files copied, symlinks recreated as symlinks, so a
 * link inside is never followed), and the target is left as it was. A
 * broken link becomes an empty directory.
 *
 * The copy is made next to the link and renamed into place, so a failure
 * leaves the link as it was.
 */
final class LinkedDirectory
{
    public function __construct(private readonly Filesystem $files) {}

    /**
     * @return bool whether a link was replaced
     *
     * @throws RuntimeException when the link cannot be replaced
     */
    public function replace(string $link): bool
    {
        if (! is_link($link)) {
            return false;
        }

        $temporary = dirname($link).'/.'.basename($link).'-'.getmypid().'-'.bin2hex(random_bytes(4));

        try {
            if (! @mkdir($temporary, 0755)) {
                throw new RuntimeException("{$temporary} could not be created");
            }

            if (is_dir($link)) {
                $this->copyTree($link, $temporary);
            }

            if (! @unlink($link)) {
                throw new RuntimeException("the symlink {$link} could not be removed");
            }
        } catch (Throwable $e) {
            if (is_dir($temporary)) {
                $this->files->deleteDirectory($temporary);
            }

            throw new RuntimeException($e->getMessage(), previous: $e);
        }

        if (! @rename($temporary, $link)) {
            throw new RuntimeException("{$temporary} could not be renamed to {$link}; it holds the files");
        }

        return true;
    }

    /**
     * Where a link points, as it was written, or null for anything else.
     */
    public static function target(string $path): ?string
    {
        if (! is_link($path)) {
            return null;
        }

        $target = @readlink($path);

        return $target === false ? '' : $target;
    }

    /**
     * Copy a directory tree, recreating symlinks as symlinks.
     *
     * @throws RuntimeException when an entry cannot be copied
     */
    private function copyTree(string $from, string $to): void
    {
        $entries = ThemeFiles::entries($from);

        if ($entries === null) {
            throw new RuntimeException("{$from} cannot be listed");
        }

        foreach ($entries as $entry) {
            $source = $from.'/'.$entry;
            $target = $to.'/'.$entry;

            if (is_link($source)) {
                $pointsTo = @readlink($source);
                $copied = $pointsTo !== false && @symlink($pointsTo, $target);
            } elseif (is_dir($source)) {
                $copied = @mkdir($target, 0755);
                if ($copied) {
                    $this->copyTree($source, $target);
                }
            } else {
                $copied = @copy($source, $target);
            }

            if (! $copied) {
                throw new RuntimeException("{$source} could not be copied");
            }
        }
    }
}
