<?php

declare(strict_types=1);

namespace Martis\Support;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Throwable;

/**
 * Writes the published copies of the app's themes, and backs up whatever a
 * publish would destroy that it cannot write back.
 *
 * Each copy it writes goes into a record,
 * `public/vendor/martis/themes/.published.json` (theme name => sha1 of the
 * copy). A published copy that still matches the record, or matches its
 * source, can be replaced or removed without losing anything. Any other file
 * under `public/vendor/martis/themes/` (a copy edited in place, as the 1.x
 * `martis:theme` hint said to do, a theme with no source, a font placed next
 * to a theme) is copied to `storage/app/martis/theme-backups/<run>/` before
 * it goes. Without the record, the edit-and-publish loop would back up the
 * previous publish on every run.
 */
final class ThemePublisher
{
    public const RECORD = '.published.json';

    /** A published copy that differs from its source and from the record. */
    public const EDITED = 'edited';

    /** A published `<name>.css` without a usable source. */
    public const NO_SOURCE = 'no-source';

    /** Any other file under the published directory. */
    public const NOT_GENERATED = 'not-generated';

    /** @var array<string, string> */
    private array $record;

    private ?string $backupDirectory = null;

    public function __construct(private readonly Filesystem $files)
    {
        $this->record = $this->readRecord();
    }

    /**
     * Files under the published directory that a publish of `$sources` would
     * destroy and cannot write back, relative to that directory, with the
     * reason. A copy that has a source is replaced; the other files are only
     * at risk when `$removesOthers` (the wipe, or `--themes-only` without
     * `--no-wipe`) removes them.
     *
     * @param  array<string, string>  $sources  theme name => source path
     * @return array<string, string> relative path => EDITED | NO_SOURCE | NOT_GENERATED
     */
    public function filesAtRisk(array $sources, bool $removesOthers): array
    {
        $atRisk = [];

        foreach (ThemeFiles::publishedFiles($this->files) as $relative) {
            $name = preg_match('~^([^/]+)\.css$~', $relative, $match) === 1 ? $match[1] : null;

            if ($name !== null && isset($sources[$name])) {
                if (! $this->isReproducible($name, $sources[$name])) {
                    $atRisk[$relative] = self::EDITED;
                }
            } elseif ($removesOthers) {
                $atRisk[$relative] = $name !== null ? self::NO_SOURCE : self::NOT_GENERATED;
            }
        }

        return $atRisk;
    }

    /**
     * Whether the published copy of `$name` can be overwritten or removed
     * without losing anything: there is none, this class wrote it as it is
     * now, or it matches `$sourcePath`.
     */
    public function isReproducible(string $name, ?string $sourcePath): bool
    {
        $published = ThemeFiles::publishedPath($name);

        if (! is_file($published)) {
            return true;
        }

        $hash = $this->hash($published);

        if ($hash === null) {
            return false;
        }

        if (($this->record[$name] ?? null) === $hash) {
            return true;
        }

        return $sourcePath !== null && $this->hash($sourcePath) === $hash;
    }

    /**
     * Copy a file under the published directory into this run's backup
     * directory and return the backup's path. Every call of one run shares
     * one directory, which no earlier run used.
     *
     * @throws RuntimeException when the backup cannot be written
     */
    public function backUp(string $relative): string
    {
        $this->backupDirectory ??= $this->reserveBackupDirectory();
        $target = $this->backupDirectory.'/'.$relative;

        try {
            $this->files->ensureDirectoryExists(dirname($target));
            $copied = $this->files->copy(ThemeFiles::publishedDirectory().'/'.$relative, $target);
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), previous: $e);
        }

        if (! $copied) {
            throw new RuntimeException("the copy to {$target} failed");
        }

        return $target;
    }

    /**
     * Copy a theme source to its published path and record the copy.
     *
     * @throws RuntimeException when the copy cannot be written
     */
    public function publish(string $name, string $sourcePath): void
    {
        $target = ThemeFiles::publishedPath($name);

        try {
            $this->files->ensureDirectoryExists(dirname($target));
            $copied = $this->files->copy($sourcePath, $target);
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), previous: $e);
        }

        if (! $copied) {
            throw new RuntimeException("the copy to {$target} failed");
        }

        $this->record[$name] = (string) $this->hash($target);
    }

    /**
     * Delete a file under the published directory, and the directories it
     * leaves empty below it.
     */
    public function remove(string $relative): void
    {
        $root = ThemeFiles::publishedDirectory();
        $this->files->delete($root.'/'.$relative);

        for ($directory = dirname($relative); $directory !== '.'; $directory = dirname($directory)) {
            $path = $root.'/'.$directory;
            if (! $this->files->isDirectory($path) || $this->files->files($path, true) !== [] || $this->files->directories($path) !== []) {
                break;
            }
            $this->files->deleteDirectory($path);
        }
    }

    /**
     * Write the record for the copies that are still as this class wrote
     * them, or delete it when there are none.
     */
    public function saveRecord(): void
    {
        $themes = [];
        foreach ($this->record as $name => $hash) {
            if ($this->hash(ThemeFiles::publishedPath($name)) === $hash) {
                $themes[$name] = $hash;
            }
        }
        ksort($themes);

        $path = ThemeFiles::publishedDirectory().'/'.self::RECORD;

        if ($themes === []) {
            $this->files->delete($path);

            return;
        }

        $this->files->ensureDirectoryExists(ThemeFiles::publishedDirectory());
        $this->files->put($path, json_encode([
            'note' => 'Written by martis:publish-assets and martis:theme: the sha1 of each theme copy they published. Edit the themes in resources/css/martis/, not here.',
            'themes' => $themes,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * The record's theme hashes, or none when it is missing or unreadable:
     * without a record, only a copy identical to its source is reproducible.
     *
     * @return array<string, string>
     */
    private function readRecord(): array
    {
        $path = ThemeFiles::publishedDirectory().'/'.self::RECORD;

        if (! is_file($path)) {
            return [];
        }

        try {
            $data = json_decode((string) $this->files->get($path), true);
        } catch (Throwable) {
            return [];
        }

        $record = [];
        $themes = is_array($data) && is_array($data['themes'] ?? null) ? $data['themes'] : [];

        foreach ($themes as $name => $hash) {
            if (is_string($name) && is_string($hash)) {
                $record[$name] = $hash;
            }
        }

        return $record;
    }

    private function reserveBackupDirectory(): string
    {
        $base = storage_path('app/martis/theme-backups/'.now()->format('Ymd-His'));
        $directory = $base;

        for ($suffix = 2; $this->files->exists($directory); $suffix++) {
            $directory = $base.'-'.$suffix;
        }

        return $directory;
    }

    private function hash(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        try {
            $hash = sha1_file($path);
        } catch (Throwable) {
            return null;
        }

        return $hash === false ? null : $hash;
    }
}
