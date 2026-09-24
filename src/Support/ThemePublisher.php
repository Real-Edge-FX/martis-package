<?php

declare(strict_types=1);

namespace Martis\Support;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Throwable;

/**
 * Writes the published copies of the app's themes, and backs up whatever a
 * theme command would destroy that it cannot write back.
 *
 * Each copy it writes goes into a record, `storage/app/martis/
 * published-themes.json` (theme name => sha1 of the copy). While the theme's
 * source exists, a published copy that matches the record (the previous
 * publish) or matches the source can be replaced without losing anything.
 * Anything else a run would delete or overwrite under
 * `public/vendor/martis/themes/` (a copy edited in place, as the 1.x
 * `martis:theme` hint said to do, a theme without a source, a font placed
 * next to a theme, a hidden file) is copied to
 * `storage/app/martis/theme-backups/<run>/<path in the app>` first. A file
 * already backed up with the same content is not copied again, and only the
 * KEEP_RUNS newest runs are kept.
 *
 * A symlink is never backed up and never written through: removing or
 * replacing a link loses nothing, its target stays where it is.
 */
final class ThemePublisher
{
    /** A published copy that differs from its source and from the record. */
    public const EDITED = 'edited';

    /** A published `<name>.css` without a usable source. */
    public const NO_SOURCE = 'no-source';

    /** Any other file under the published directory. */
    public const NOT_GENERATED = 'not-generated';

    /** How many backup runs are kept: the newest ones. */
    public const KEEP_RUNS = 10;

    /** @var array<string, string> */
    private array $record;

    /** This invocation's backup run directory, created on the first backup. */
    private ?string $run = null;

    /** @var array<string, true> run names this invocation wrote or pointed at */
    private array $usedRuns = [];

    public function __construct(private readonly Filesystem $files)
    {
        $this->record = $this->readRecord();
    }

    /**
     * Files under the published directory that a publish of `$sources` would
     * destroy and cannot write back, relative to that directory, with the
     * reason. A copy that has a source is replaced; the other files are only
     * at risk when `$removesOthers` (the wipe, or `--themes-only` without
     * `--no-wipe`) removes them. Symlinks are never at risk.
     *
     * @param  array<string, string>  $sources  theme name => source path
     * @return array<string, string> relative path => EDITED | NO_SOURCE | NOT_GENERATED
     *
     * @throws RuntimeException when the published directory cannot be listed
     */
    public function filesAtRisk(array $sources, bool $removesOthers): array
    {
        $atRisk = [];
        $copies = $this->copiesByInode($sources);

        foreach (ThemeFiles::publishedFiles() as $relative) {
            $path = ThemeFiles::publishedDirectory().'/'.$relative;

            if (is_link($path)) {
                continue;
            }

            $name = $this->sourceFor($relative, $path, $sources, $copies);

            if ($name !== null) {
                if (! $this->isReproducible($name, $sources[$name], $path)) {
                    $atRisk[$relative] = self::EDITED;
                }
            } elseif ($removesOthers) {
                $atRisk[$relative] = preg_match('~^[^/]+\.css$~', $relative) === 1 ? self::NO_SOURCE : self::NOT_GENERATED;
            }
        }

        return $atRisk;
    }

    /**
     * Symlinks under the published directory that a publish of `$sources`
     * replaces (a theme's copy) or removes (the rest, when `$removesOthers`),
     * relative to that directory.
     *
     * @param  array<string, string>  $sources
     * @return array<string, string> relative path => 'replaced' | 'removed'
     */
    public function linksAffected(array $sources, bool $removesOthers): array
    {
        $links = [];

        foreach (ThemeFiles::publishedFiles() as $relative) {
            if (! is_link(ThemeFiles::publishedDirectory().'/'.$relative)) {
                continue;
            }

            if (preg_match('~^([^/]+)\.css$~', $relative, $match) === 1 && isset($sources[$match[1]])) {
                $links[$relative] = 'replaced';
            } elseif ($removesOthers) {
                $links[$relative] = 'removed';
            }
        }

        return $links;
    }

    /**
     * Whether the published copy at `$publishedPath` (by default the one of
     * `$name`) can be overwritten or removed without losing anything: there
     * is none, it is a symlink, or the source exists and the copy matches it
     * or the record. Without a source the record proves nothing: the copy
     * may be the only one left of the theme.
     */
    public function isReproducible(string $name, ?string $sourcePath, ?string $publishedPath = null): bool
    {
        $published = $publishedPath ?? ThemeFiles::publishedPath($name);

        if (is_link($published) || ! file_exists($published)) {
            return true;
        }

        if ($sourcePath === null || ! is_file($sourcePath)) {
            return false;
        }

        $hash = $this->hash($published);

        if ($hash === null) {
            return false;
        }

        return ($this->record[$name] ?? null) === $hash || $this->hash($sourcePath) === $hash;
    }

    /**
     * Back up a file of the app into this run's backup directory, at its path
     * in the app, unless a run already holds it with the same content.
     *
     * @return array{path: string, reused: bool} the backup, and whether it
     *                                           came from an earlier run
     *
     * @throws RuntimeException when the backup cannot be written
     */
    public function backUp(string $path): array
    {
        $relative = $this->pathInApp($path);
        $hash = $this->hash($path);

        if ($hash === null) {
            throw new RuntimeException('the file does not open for reading');
        }

        foreach (array_reverse($this->runs()) as $run) {
            $earlier = ThemeFiles::backupDirectory().'/'.$run.'/'.$relative;

            if (! is_link($earlier) && $this->hash($earlier) === $hash) {
                $this->usedRuns[$run] = true;

                return ['path' => $earlier, 'reused' => true];
            }
        }

        $this->run ??= $this->createRun();
        $target = $this->run.'/'.$relative;

        try {
            $this->files->ensureDirectoryExists(dirname($target));
            $copied = $this->files->copy($path, $target);
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), previous: $e);
        }

        if (! $copied) {
            throw new RuntimeException("the copy to {$target} failed");
        }

        return ['path' => $target, 'reused' => false];
    }

    /**
     * Delete the runs beyond the KEEP_RUNS newest, sparing the ones this
     * invocation wrote or pointed at. Only a run that made a new backup
     * prunes: a run with nothing to back up leaves the backups alone.
     */
    public function pruneRuns(): void
    {
        if ($this->run === null) {
            return;
        }

        $runs = $this->runs();
        $newest = array_slice($runs, -self::KEEP_RUNS);

        foreach ($runs as $run) {
            if (! in_array($run, $newest, true) && ! isset($this->usedRuns[$run])) {
                $this->files->deleteDirectory(ThemeFiles::backupDirectory().'/'.$run);
            }
        }
    }

    /**
     * Copy a theme source to its published path and record the copy. A
     * symlink at that path is replaced, never written through.
     *
     * @throws RuntimeException when the copy cannot be written
     */
    public function publish(string $name, string $sourcePath): void
    {
        $target = ThemeFiles::publishedPath($name);

        try {
            $this->files->ensureDirectoryExists(dirname($target));

            if (is_link($target)) {
                $this->files->delete($target);
            }

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
     * Delete a file or symlink under the published directory, and the
     * directories it leaves empty below it.
     */
    public function remove(string $relative): void
    {
        $root = ThemeFiles::publishedDirectory();
        $this->files->delete($root.'/'.$relative);

        for ($directory = dirname($relative); $directory !== '.'; $directory = dirname($directory)) {
            $path = $root.'/'.$directory;
            if (is_link($path) || ! $this->files->isDirectory($path) || $this->files->files($path, true) !== [] || $this->files->directories($path) !== []) {
                break;
            }
            $this->files->deleteDirectory($path);
        }
    }

    /**
     * Write the record for the copies that are still as this class wrote
     * them, or delete it when there are none.
     *
     * @throws RuntimeException when the record cannot be written
     */
    public function saveRecord(): void
    {
        $themes = [];
        foreach ($this->record as $name => $hash) {
            $published = ThemeFiles::publishedPath($name);

            if (! is_link($published) && $this->hash($published) === $hash) {
                $themes[$name] = $hash;
            }
        }
        ksort($themes);

        $path = ThemeFiles::recordPath();

        try {
            if ($themes === []) {
                if (is_file($path)) {
                    $this->files->delete($path);
                }

                return;
            }

            $this->files->ensureDirectoryExists(dirname($path));
            $written = $this->files->put($path, json_encode([
                'note' => 'Written by martis:publish-assets and martis:theme: the sha1 of each theme copy they published to public/vendor/martis/themes/.',
                'themes' => $themes,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), previous: $e);
        }

        if ($written === false) {
            throw new RuntimeException("{$path} could not be written");
        }
    }

    /**
     * On a filesystem that does not tell names apart by case, a published
     * `Brand.css` is the copy of the source `brand.css`: both names open the
     * same file. Map the copies by device and inode to find them.
     *
     * @param  array<string, string>  $sources
     * @return array<string, string> "device:inode" => theme name
     */
    private function copiesByInode(array $sources): array
    {
        $copies = [];

        foreach (array_keys($sources) as $name) {
            $key = $this->inode(ThemeFiles::publishedPath($name));

            if ($key !== null) {
                $copies[$key] = $name;
            }
        }

        return $copies;
    }

    /**
     * The source a published file is the copy of, or null.
     *
     * @param  array<string, string>  $sources
     * @param  array<string, string>  $copies
     */
    private function sourceFor(string $relative, string $path, array $sources, array $copies): ?string
    {
        if (preg_match('~^([^/]+)\.css$~', $relative, $match) === 1 && isset($sources[$match[1]])) {
            return $match[1];
        }

        $key = $this->inode($path);

        return $key !== null ? ($copies[$key] ?? null) : null;
    }

    private function inode(string $path): ?string
    {
        if (is_link($path) || ! is_file($path)) {
            return null;
        }

        $stat = @stat($path);

        return $stat === false ? null : $stat['dev'].':'.$stat['ino'];
    }

    /**
     * The record's theme hashes, or none when it is missing or unreadable:
     * without a record, only a copy identical to its source is reproducible.
     *
     * @return array<string, string>
     */
    private function readRecord(): array
    {
        $path = ThemeFiles::recordPath();

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

    /**
     * The existing backup runs, oldest first: their names are the time they
     * were created at, so they sort by time.
     *
     * @return list<string>
     */
    private function runs(): array
    {
        $root = ThemeFiles::backupDirectory();

        if (is_link($root) || ! is_dir($root)) {
            return [];
        }

        $runs = [];
        foreach ((array) @scandir($root) as $entry) {
            if (is_string($entry) && $entry !== '.' && $entry !== '..' && ! is_link($root.'/'.$entry) && is_dir($root.'/'.$entry)) {
                $runs[] = $entry;
            }
        }
        sort($runs);

        return $runs;
    }

    /**
     * Create this run's directory. mkdir() fails when the directory exists,
     * so two runs in the same second never share one: the second takes the
     * next suffix.
     */
    private function createRun(): string
    {
        $root = ThemeFiles::backupDirectory();

        try {
            $this->files->ensureDirectoryExists($root);
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), previous: $e);
        }

        if (is_link($root) || ! is_dir($root)) {
            throw new RuntimeException("{$root} is not a directory");
        }

        $base = now()->format('Ymd-His');

        for ($suffix = 1; $suffix <= 1000; $suffix++) {
            $name = $suffix === 1 ? $base : $base.'-'.$suffix;

            if (@mkdir($root.'/'.$name, 0755)) {
                $this->usedRuns[$name] = true;

                return $root.'/'.$name;
            }

            if (! file_exists($root.'/'.$name)) {
                throw new RuntimeException("{$root}/{$name} could not be created");
            }
        }

        throw new RuntimeException("no free backup directory for {$base} in {$root}");
    }

    private function pathInApp(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : ltrim($path, DIRECTORY_SEPARATOR);
    }

    private function hash(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        try {
            $hash = @sha1_file($path);
        } catch (Throwable) {
            return null;
        }

        return $hash === false ? null : $hash;
    }
}
