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
 * Each copy it writes goes into a record next to the copies,
 * `public/vendor/martis/themes/.published.json`: the sha1 of the theme name
 * joined to the sha1 of the copy, so it names no theme in the web root and
 * a copy of theme `a` placed over theme `b` does not match. While the
 * theme's source exists, a published copy whose key is in the record (the
 * previous publish) or that matches the source can be replaced without
 * losing anything. Anything else a run would
 * delete or overwrite under `public/vendor/martis/themes/` (a copy edited in
 * place, as the 1.x `martis:theme` hint said to do, a theme without a
 * source, a font placed next to a theme, a hidden file) is copied to
 * `storage/app/martis/theme-backups/<run>/<path in the app>` first. A file
 * already backed up with the same content is not copied again. Pruning keeps
 * the oldest run, which holds what the first publish after the upgrade took
 * away, and the newest ones, KEEP_RUNS in all.
 *
 * A symlink is never backed up and never written through: removing or
 * replacing a link loses nothing, its target stays where it is. When
 * `public/vendor/martis/` or its `themes/` is itself a symlink (a deploy
 * tool's shared directory), it is replaced by a real directory holding a
 * copy of what the target holds, before anything is written.
 */
final class ThemePublisher
{
    /** A published copy that differs from its source and from the record. */
    public const EDITED = 'edited';

    /** A published `<name>.css` without a usable source. */
    public const NO_SOURCE = 'no-source';

    /** Any other file under the published directory. */
    public const NOT_GENERATED = 'not-generated';

    /** A symlink that the run replaces with the copy of a theme source. */
    public const LINK_REPLACED = 'replaced';

    /** A symlink that the run removes. */
    public const LINK_REMOVED = 'removed';

    /** How many backup runs are kept: the oldest one and the newest ones. */
    public const KEEP_RUNS = 10;

    /** @var array<string, true> record key => true, from the previous publish */
    private array $record;

    /** @var array<string, string> theme name => sha1 of the copy this run wrote */
    private array $written = [];

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
     * A copy is matched to its source by name, or, on a filesystem that
     * ignores case, by inode: `Brand.css` is then the copy of `brand.css`.
     * NO_SOURCE is only given to a `<name>.css` the panel could load, the
     * only files a source could be made for; the rest are NOT_GENERATED.
     *
     * @param  array<string, string>  $sources  theme name => source path
     * @return array<string, array{reason: string, source: string|null}> relative path => EDITED | NO_SOURCE | NOT_GENERATED, and the theme it is the copy of
     *
     * @throws RuntimeException when the published directory cannot be listed
     */
    public function filesAtRisk(array $sources, bool $removesOthers): array
    {
        $atRisk = [];
        $copies = $this->copiesByInode($sources, 'stat');

        foreach (ThemeFiles::publishedFiles() as $relative) {
            $path = ThemeFiles::publishedDirectory().'/'.$relative;

            if (is_link($path)) {
                continue;
            }

            $name = $this->sourceFor($relative, $path, $sources, $copies, 'stat');

            if ($name !== null) {
                if (! $this->isReproducible($name, $sources[$name], $path)) {
                    $atRisk[$relative] = ['reason' => self::EDITED, 'source' => $name];
                }
            } elseif ($removesOthers) {
                $atRisk[$relative] = [
                    'reason' => self::isThemeCopyName($relative) ? self::NO_SOURCE : self::NOT_GENERATED,
                    'source' => null,
                ];
            }
        }

        return $atRisk;
    }

    /** Whether a path under the published directory is a `<name>.css` the panel could load. */
    public static function isThemeCopyName(string $relative): bool
    {
        return preg_match('~^([^/]+)\.css$~', $relative, $match) === 1 && ThemeFiles::isValidName($match[1]);
    }

    /**
     * Symlinks under the published directory that a publish of `$sources`
     * replaces (a theme's copy) or removes (the rest, when `$removesOthers`),
     * relative to that directory.
     *
     * A link is matched to its theme by name, or by the inode of the link
     * itself on a filesystem that ignores case (`Brand.css` is the entry
     * `brand.css` opens): the publish replaces that entry, so removing it
     * afterwards would delete the new copy.
     *
     * @param  array<string, string>  $sources
     * @return array<string, array{fate: string, source: string|null}> relative path => LINK_REPLACED | LINK_REMOVED, and the theme that replaces it
     */
    public function linksAffected(array $sources, bool $removesOthers): array
    {
        $links = [];
        $copies = $this->copiesByInode($sources, 'lstat');

        foreach (ThemeFiles::publishedFiles() as $relative) {
            $path = ThemeFiles::publishedDirectory().'/'.$relative;

            if (! is_link($path)) {
                continue;
            }

            $name = $this->sourceFor($relative, $path, $sources, $copies, 'lstat');

            if ($name !== null) {
                $links[$relative] = ['fate' => self::LINK_REPLACED, 'source' => $name];
            } elseif ($removesOthers) {
                $links[$relative] = ['fate' => self::LINK_REMOVED, 'source' => null];
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

        // The record holds keys of name and content: a copy matching the
        // key of this theme is one this class wrote for it, and its source
        // can write it again.
        return isset($this->record[self::recordKey($name, $hash)]) || $this->hash($sourcePath) === $hash;
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
     * Delete the runs beyond the oldest one and the KEEP_RUNS - 1 newest,
     * sparing the ones this invocation wrote or pointed at. The oldest run is
     * kept because it holds what the first publish after the upgrade from 1.x
     * took away: later runs mostly back up the same files again. Only a run
     * that made a new backup prunes: a run with nothing to back up leaves the
     * backups alone. A run that cannot be deleted is reported, not thrown:
     * pruning never fails a publish.
     *
     * @return array{removed: list<string>, failed: array<string, string>} the run directories deleted, and the ones that could not be, with the reason
     */
    public function pruneRuns(): array
    {
        $result = ['removed' => [], 'failed' => []];

        if ($this->run === null) {
            return $result;
        }

        $runs = $this->runs();
        $kept = array_merge(array_slice($runs, 0, 1), array_slice($runs, -(self::KEEP_RUNS - 1)));

        foreach ($runs as $run) {
            if (in_array($run, $kept, true) || isset($this->usedRuns[$run])) {
                continue;
            }

            $directory = ThemeFiles::backupDirectory().'/'.$run;

            try {
                $deleted = $this->files->deleteDirectory($directory) && ! file_exists($directory);
                $reason = 'it could not be deleted';
            } catch (Throwable $e) {
                $deleted = false;
                $reason = $e->getMessage();
            }

            if ($deleted) {
                $result['removed'][] = $directory;
            } else {
                $result['failed'][$directory] = $reason;
            }
        }

        return $result;
    }

    /**
     * Replace `public/vendor/martis/` and then its `themes/` with real
     * directories when they are symlinks, so nothing is ever written into a
     * link's target (see LinkedDirectory). Replacing a link loses nothing:
     * the new directory holds a copy of what the target holds.
     *
     * @return list<string> the directories that were symlinks
     *
     * @throws RuntimeException when a link cannot be replaced
     */
    public function replaceDirectoryLinks(): array
    {
        $replaced = [];
        $linked = new LinkedDirectory($this->files);

        foreach ([ThemeFiles::assetsDirectory(), ThemeFiles::publishedDirectory()] as $directory) {
            if ($linked->replace($directory)) {
                $replaced[] = $directory;
            }
        }

        return $replaced;
    }

    /**
     * Copy a theme source to its published path and record the copy. A
     * symlink at that path is replaced, never written through.
     *
     * @throws RuntimeException when the copy cannot be written
     */
    public function publish(string $name, string $sourcePath): void
    {
        $this->replaceDirectoryLinks();
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

        $this->written[$name] = (string) $this->hash($target);
    }

    /**
     * Delete a file or symlink under the published directory, and the
     * directories it leaves empty below it.
     */
    public function remove(string $relative): void
    {
        $this->replaceDirectoryLinks();
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
     * them (in this run, or in an earlier one that recorded them), or delete
     * it when there are none.
     *
     * @throws RuntimeException when the record cannot be written
     */
    public function saveRecord(): void
    {
        $keys = [];

        // The copies this run wrote, while they are as it wrote them. A
        // copy's entry can keep another case than its theme's name (`Brand.css`
        // for `brand`) on a filesystem that ignores case, so they are keyed
        // by the theme name, not the file name.
        foreach ($this->written as $name => $hash) {
            $published = ThemeFiles::publishedPath($name);

            if (! is_link($published) && $this->hash($published) === $hash) {
                $keys[self::recordKey($name, $hash)] = true;
            }
        }

        // The copies an earlier run wrote that are still as it wrote them.
        foreach ((array) ThemeFiles::entries(ThemeFiles::publishedDirectory()) as $entry) {
            $published = ThemeFiles::publishedDirectory().'/'.$entry;

            if (self::isThemeCopyName($entry) && ! is_link($published) && ($hash = $this->hash($published)) !== null) {
                $key = self::recordKey(ThemeFiles::nameOf($entry), $hash);

                if (isset($this->record[$key])) {
                    $keys[$key] = true;
                }
            }
        }
        $hashes = array_keys($keys);
        sort($hashes);

        $path = ThemeFiles::recordPath();

        try {
            if ($hashes === []) {
                // Never delete through a themes directory that is a symlink.
                if (! ThemeFiles::publishedDirectoryIsLink() && is_file($path) && ! is_link($path)) {
                    $this->files->delete($path);
                }

                return;
            }

            $this->replaceDirectoryLinks();
            $this->files->ensureDirectoryExists(dirname($path));

            if (is_link($path)) {
                $this->files->delete($path);
            }

            $written = $this->files->put($path, json_encode([
                'note' => 'Written by martis:publish-assets and martis:theme: for each theme copy they published here, sha1(theme name + ":" + sha1 of the copy), so the next publish replaces it without a backup.',
                'keys' => $hashes,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), previous: $e);
        }

        if ($written === false) {
            throw new RuntimeException("{$path} could not be written");
        }
    }

    /**
     * The VCS placeholders at the top of the published directory, with their
     * contents, for a full publish to put back after its wipe.
     *
     * @return array<string, string> file name => contents
     */
    public function placeholders(): array
    {
        $placeholders = [];

        foreach (ThemeFiles::PLACEHOLDERS as $entry) {
            $path = ThemeFiles::publishedDirectory().'/'.$entry;

            if (! is_link($path) && is_file($path) && ($contents = @file_get_contents($path)) !== false) {
                $placeholders[$entry] = $contents;
            }
        }

        return $placeholders;
    }

    /**
     * @param  array<string, string>  $placeholders  file name => contents
     *
     * @throws RuntimeException when one cannot be written
     */
    public function restorePlaceholders(array $placeholders): void
    {
        foreach ($placeholders as $entry => $contents) {
            $path = ThemeFiles::publishedDirectory().'/'.$entry;

            try {
                $this->files->ensureDirectoryExists(dirname($path));
                $written = $this->files->put($path, $contents);
            } catch (Throwable $e) {
                throw new RuntimeException("{$entry}: ".$e->getMessage(), previous: $e);
            }

            if ($written === false) {
                throw new RuntimeException("{$path} could not be written");
            }
        }
    }

    /**
     * On a filesystem that does not tell names apart by case, a published
     * `Brand.css` is the copy of the source `brand.css`: both names open the
     * same entry. Map the copies by device and inode to find them: `stat`
     * for files, `lstat` for symlinks (the link itself, not its target).
     *
     * @param  array<string, string>  $sources
     * @param  'stat'|'lstat'  $stat
     * @return array<string, string> "device:inode" => theme name
     */
    private function copiesByInode(array $sources, string $stat): array
    {
        $copies = [];

        foreach (array_keys($sources) as $name) {
            $key = $this->inode(ThemeFiles::publishedPath($name), $stat);

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
     * @param  'stat'|'lstat'  $stat
     */
    private function sourceFor(string $relative, string $path, array $sources, array $copies, string $stat): ?string
    {
        if (preg_match('~^([^/]+)\.css$~', $relative, $match) === 1 && isset($sources[$match[1]])) {
            return $match[1];
        }

        $key = $this->inode($path, $stat);

        return $key !== null ? ($copies[$key] ?? null) : null;
    }

    /**
     * The device and inode of a regular file (`stat`) or of a symlink itself
     * (`lstat`), or null for anything else.
     *
     * @param  'stat'|'lstat'  $stat
     */
    private function inode(string $path, string $stat): ?string
    {
        if ($stat === 'lstat' ? ! is_link($path) : (is_link($path) || ! is_file($path))) {
            return null;
        }

        $info = $stat === 'lstat' ? @lstat($path) : @stat($path);

        return $info === false ? null : $info['dev'].':'.$info['ino'];
    }

    /**
     * The record's hashes, or none when it is missing or unreadable:
     * without a record, only a copy identical to its source is reproducible.
     *
     * @return array<string, true>
     */
    private function readRecord(): array
    {
        $path = ThemeFiles::recordPath();

        if (is_link($path) || ! is_file($path)) {
            return [];
        }

        try {
            $data = json_decode((string) $this->files->get($path), true);
        } catch (Throwable) {
            return [];
        }

        $record = [];
        $hashes = is_array($data) && is_array($data['keys'] ?? null) ? $data['keys'] : [];

        foreach ($hashes as $hash) {
            if (is_string($hash) && preg_match('/^[0-9a-f]{40}$/', $hash) === 1) {
                $record[$hash] = true;
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

    /**
     * The record key of a theme copy: it binds the content to the theme, and
     * the name is hashed so the record lists no theme in the web root.
     */
    private static function recordKey(string $name, string $hash): string
    {
        return sha1($name.':'.$hash);
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
