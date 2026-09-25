<?php

namespace Martis\Tests\Support;

use RuntimeException;

/**
 * Paths of the testbench skeleton a test file changes, captured before it
 * runs and put back exactly afterwards: file contents and modification
 * times, directories with everything in them, symlinks as links (never
 * followed, so the `vendor` link testbench creates is never walked). A path
 * that did not exist is removed again. The skeleton under vendor/ keeps
 * what every run leaves in it, so a test that changes it must restore it.
 */
final class SkeletonSnapshot
{
    /**
     * @param  array<string, array<int, mixed>|null>  $entries
     */
    private function __construct(private readonly array $entries) {}

    /**
     * @param  list<string>  $paths  Paths relative to $base.
     */
    public static function take(string $base, array $paths): self
    {
        $entries = [];

        foreach ($paths as $path) {
            $absolute = rtrim($base, '/').'/'.$path;
            $entries[$absolute] = self::capture($absolute);
        }

        return new self($entries);
    }

    public function restore(): void
    {
        foreach ($this->entries as $path => $entry) {
            self::remove($path);

            if ($entry !== null) {
                self::write($path, $entry);
            }
        }
    }

    /**
     * @return array<int, mixed>|null
     */
    private static function capture(string $path): ?array
    {
        if (is_link($path)) {
            return ['link', readlink($path)];
        }

        if (is_file($path)) {
            return ['file', file_get_contents($path), filemtime($path)];
        }

        if (is_dir($path)) {
            $children = [];

            foreach (scandir($path) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    $children[$name] = self::capture($path.'/'.$name);
                }
            }

            return ['dir', $children];
        }

        return null;
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            self::check(unlink($path), "remove {$path}");

            return;
        }

        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    self::remove($path.'/'.$name);
                }
            }

            self::check(rmdir($path), "remove {$path}");
        }
    }

    /**
     * @param  array<int, mixed>  $entry
     */
    private static function write(string $path, array $entry): void
    {
        $parent = dirname($path);
        self::check(is_dir($parent) || mkdir($parent, 0777, true), "create {$parent}");

        match ($entry[0]) {
            'link' => self::check(symlink((string) $entry[1], $path), "link {$path}"),
            'file' => self::check(
                file_put_contents($path, (string) $entry[1]) !== false && touch($path, (int) $entry[2]),
                "write {$path}",
            ),
            'dir' => self::writeDirectory($path, $entry[1]),
        };
    }

    /**
     * @param  array<string, array<int, mixed>|null>  $children
     */
    private static function writeDirectory(string $path, array $children): void
    {
        self::check(is_dir($path) || mkdir($path, 0777, true), "create {$path}");

        foreach ($children as $name => $child) {
            if ($child !== null) {
                self::write($path.'/'.$name, $child);
            }
        }
    }

    private static function check(bool $done, string $action): void
    {
        if (! $done) {
            throw new RuntimeException("Cannot {$action} while restoring the testbench skeleton.");
        }
    }
}
