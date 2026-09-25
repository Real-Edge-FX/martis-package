<?php

namespace Martis\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Republish Martis frontend assets cleanly.
 *
 * Laravel's stock `vendor:publish --tag=martis-assets --force` is a
 * merge-style copy: new files overwrite, but stale ones are never
 * deleted. Combined with Vite's hashed output names, every Martis
 * upgrade adds another generation of chunks while the previous ones
 * linger. After a few upgrades, `public/vendor/martis/` typically
 * grows past 50,000 files; on macOS Docker that's enough bind-mount
 * load to add 5–10 seconds to every PHP-FPM request.
 *
 * This command first deletes what the package published before (every
 * top-level entry of the package's `public/`, the `assets/` chunks and
 * `manifest.json`, plus a Vite `hot` file), then copies the package's
 * compiled assets directly (`File::copyDirectory`), a deterministic
 * full-tree copy, rather than delegating the 1400+ file loop to
 * `vendor:publish`'s Flysystem mount. It is the canonical entry point
 * for keeping the published frontend in sync with the installed package
 * version. `martis:vendor-publish --assets` and `martis:install` share
 * the same flow.
 *
 * Everything else under `public/vendor/martis/` stays, the app's themes
 * first: `martis:theme` writes `themes/<name>.css` there and the app edits
 * it in place, so `themes/` is never deleted. The deletion never follows a
 * symlink: a symlinked `public/vendor/martis/` is replaced by a real
 * directory holding a copy of its target's other entries (the target
 * keeps its files), and a symlinked entry is unlinked. When
 * `public/vendor/martis/` resolves to the package's own `public/` (a link
 * or a mount to it), the assets are already in place: nothing is deleted
 * or copied.
 *
 * After copying it VERIFIES the result: every file the package's
 * (known-good) `manifest.json` references (the app entry bundle + its
 * CSS + every chunk), plus the `manifest.json` itself, must exist and be
 * readable in the destination. If anything is missing or the destination
 * manifest is unparseable, the command fails loudly with the count,
 * instead of leaving a silently-incomplete asset set that renders the
 * admin as a black screen (the app entry bundle 404s, or Vite can't
 * resolve it, and the SPA never mounts). Expectations are derived from
 * the SOURCE manifest, so a missing or corrupt destination manifest fails
 * closed rather than passing silently.
 *
 * Last, a theme source `resources/css/martis/<name>.css` whose published
 * copy `public/vendor/martis/themes/<name>.css` is missing is copied there
 * (a fresh deploy, where `public/vendor/` is not committed), so the panel
 * does not 404 its stylesheet. A published copy is never overwritten: the
 * app may have edited it.
 */
class PublishAssetsCommand extends Command
{
    protected $signature = 'martis:publish-assets
                            {--no-wipe : Skip the deletion of the previously published assets (legacy merge-style behaviour)}';

    protected $description = 'Republish Martis frontend assets, deleting the previously published ones first (the app themes are kept)';

    /**
     * Entries of public/vendor/martis/ the publish deletes besides the
     * top-level entries of the package's public/: the Vite dev server's
     * `hot` file, which a package built in dev mode can leave behind.
     */
    private const ALWAYS_REPLACED = ['assets', 'manifest.json', 'hot'];

    /** The app's themes (`martis:theme`): never deleted by a publish. */
    private const THEMES = 'themes';

    public function handle(Filesystem $filesystem): int
    {
        if (! $this->compiledAssetsAreAvailable()) {
            $this->components->error('Martis frontend assets are missing from this package release.');
            $this->line('  Expected: <fg=cyan>public/manifest.json</>');
            $this->line('  Fix the package release by running <fg=cyan>npm install && npm run build</> before publishing.');

            return self::FAILURE;
        }

        $source = $this->packagePublicPath();
        $destination = public_path('vendor/martis');
        $wipe = ! (bool) $this->option('no-wipe');

        if ($this->isPackagePublicDirectory($destination, $source)) {
            // Deleting here would delete the package's own compiled assets,
            // and copying would copy every file onto itself.
            $this->components->twoColumnDetail(
                '<fg=yellow>Skipped</> the delete and the copy',
                $this->relativePath($destination).' is the package\'s own public/ directory',
            );
        } else {
            if ($wipe && is_link($destination)) {
                $this->components->task(
                    'Replacing the symlink <fg=cyan>'.$this->relativePath($destination).'</> with a directory',
                    fn () => $this->replaceLinkWithDirectory($filesystem, $destination, $source),
                );
            } elseif ($wipe && $filesystem->isDirectory($destination)) {
                $this->components->task(
                    'Deleting the previously published assets in <fg=cyan>'.$this->relativePath($destination).'</>',
                    fn () => $this->deletePublishedEntries($filesystem, $destination, $source),
                );
            }

            // Deterministic full-tree copy: copies every file under the package's
            // public/ (assets + manifest.json), not a subset.
            $this->components->task(
                'Copying assets to <fg=cyan>'.$this->relativePath($destination).'</>',
                fn () => $filesystem->copyDirectory($source, $destination),
            );
        }

        // Verify the published set is complete: every file the package's
        // (known-good) manifest references — plus the manifest itself — must
        // exist and be readable in the destination. Catches a partial copy
        // (interrupted/constrained environment) before it becomes a black
        // screen at runtime. Expectations come from the SOURCE manifest, so
        // a missing/corrupt destination manifest fails closed.
        $missing = $this->missingPublishedFiles($source, $destination);

        if ($missing !== []) {
            $this->newLine();
            $this->components->error(
                'Published asset set is INCOMPLETE — '.count($missing).' file(s) are missing or unreadable.'
            );
            foreach (array_slice($missing, 0, 5) as $file) {
                $this->line('  <fg=red>missing:</> '.$file);
            }
            if (count($missing) > 5) {
                $this->line('  <fg=red>… and '.(count($missing) - 5).' more.</>');
            }
            $this->line('  The admin would render a black screen. Re-run <fg=cyan>php artisan martis:publish-assets</> (check disk space / permissions).');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail(
            '<fg=green>Published</> martis-assets',
            $this->relativePath($destination),
        );

        $this->restoreMissingThemes($filesystem, $destination);

        $this->newLine();
        $this->components->info('Martis assets published successfully.');

        return self::SUCCESS;
    }

    protected function packagePublicPath(): string
    {
        return __DIR__.'/../../public';
    }

    /**
     * The entries of public/vendor/martis/ the package publishes, which the
     * publish deletes before copying: the top-level entries of its public/
     * and `ALWAYS_REPLACED`, never `themes`.
     *
     * @return list<string>
     */
    protected function publishedEntries(string $source): array
    {
        $entries = array_diff(scandir($source) ?: [], ['.', '..']);

        return array_values(array_diff(
            array_unique([...self::ALWAYS_REPLACED, ...$entries]),
            [self::THEMES],
        ));
    }

    /**
     * Delete the entries the package published in `$destination`, and
     * nothing else. A symlinked entry is unlinked, never followed, and
     * `Filesystem::deleteDirectory()` unlinks the links it meets inside a
     * directory, so no link's target loses a file.
     */
    protected function deletePublishedEntries(Filesystem $filesystem, string $destination, string $source): bool
    {
        $deleted = true;

        foreach ($this->publishedEntries($source) as $entry) {
            $path = $destination.DIRECTORY_SEPARATOR.$entry;

            if (is_link($path)) {
                $deleted = $this->removeLink($path) && $deleted;
            } elseif (is_dir($path)) {
                $deleted = $filesystem->deleteDirectory($path) && $deleted;
            } elseif (file_exists($path)) {
                $deleted = $filesystem->delete($path) && $deleted;
            }
        }

        return $deleted;
    }

    /**
     * Replace a symlinked public/vendor/martis/ with a real directory, so
     * the publish never writes into the link's target: the link is removed,
     * and the target's entries the package does not publish (the themes
     * first) are copied into the new directory, so the panel keeps serving
     * them. The target itself is left as it was.
     */
    protected function replaceLinkWithDirectory(Filesystem $filesystem, string $link, string $source): bool
    {
        $target = realpath($link);

        if (! $this->removeLink($link) || ! $filesystem->makeDirectory($link, 0755, true, true)) {
            return false;
        }

        if ($target === false || ! is_dir($target)) {
            return true;
        }

        $copied = true;
        $published = $this->publishedEntries($source);

        foreach (array_diff(scandir($target) ?: [], ['.', '..']) as $entry) {
            if (in_array($entry, $published, true)) {
                continue;
            }

            $from = $target.DIRECTORY_SEPARATOR.$entry;
            $to = $link.DIRECTORY_SEPARATOR.$entry;

            try {
                $copied = (is_dir($from) ? $filesystem->copyDirectory($from, $to) : $filesystem->copy($from, $to)) && $copied;
            } catch (\Throwable) {
                // A dangling link or an unreadable file: keep going, the
                // task reports the failure.
                $copied = false;
            }
        }

        return $copied;
    }

    /** Remove a symlink itself (a file or a directory link), never its target. */
    protected function removeLink(string $path): bool
    {
        if (! @unlink($path)) {
            // A directory link on Windows is removed with rmdir().
            @rmdir($path);
        }

        clearstatcache(true, $path);

        return ! is_link($path) && ! file_exists($path);
    }

    /**
     * Whether `$destination` is the package's own public/ directory (a
     * symlink or a mount to it): the same real path, or the same inode on
     * the same device.
     */
    protected function isPackagePublicDirectory(string $destination, string $source): bool
    {
        $destinationPath = realpath($destination);
        $sourcePath = realpath($source);

        if ($destinationPath === false || $sourcePath === false) {
            return false;
        }

        if ($destinationPath === $sourcePath) {
            return true;
        }

        $destinationStat = @stat($destinationPath);
        $sourceStat = @stat($sourcePath);

        // Some platforms report no inode (0): only a real one compares.
        return is_array($destinationStat) && is_array($sourceStat)
            && $destinationStat['ino'] > 0
            && $destinationStat['ino'] === $sourceStat['ino']
            && $destinationStat['dev'] === $sourceStat['dev'];
    }

    /**
     * Publish each theme source `resources/css/martis/<name>.css` whose copy
     * `public/vendor/martis/themes/<name>.css` is missing (a fresh deploy,
     * or a copy an earlier publish deleted), so the panel does not 404 its
     * stylesheet. A published copy is never overwritten, nor written
     * through a symlink.
     */
    protected function restoreMissingThemes(Filesystem $filesystem, string $destination): void
    {
        $sources = glob(resource_path('css/martis').DIRECTORY_SEPARATOR.'*.css') ?: [];
        $themes = $destination.DIRECTORY_SEPARATOR.self::THEMES;

        foreach ($sources as $sourceFile) {
            if (! is_file($sourceFile)) {
                continue;
            }

            $name = basename($sourceFile);
            $published = $themes.DIRECTORY_SEPARATOR.$name;

            if (file_exists($published) || is_link($published)) {
                continue;
            }

            if ((is_link($themes) && ! is_dir($themes)) || (file_exists($themes) && ! is_dir($themes))) {
                $this->components->warn("Could not restore public/vendor/martis/themes/{$name}: public/vendor/martis/themes is not a directory.");

                return;
            }

            $filesystem->ensureDirectoryExists($themes);

            if (@copy($sourceFile, $published)) {
                $this->components->twoColumnDetail(
                    '<fg=green>Restored</> theme',
                    "public/vendor/martis/themes/{$name}",
                );
            } else {
                $this->components->warn("Could not copy resources/css/martis/{$name} to public/vendor/martis/themes/{$name}.");
            }
        }
    }

    protected function compiledAssetsAreAvailable(): bool
    {
        return file_exists($this->packagePublicPath().'/manifest.json');
    }

    /**
     * Files the package's compiled manifest references (every entry's `file`
     * + `css`), plus `manifest.json` itself, that did not land — or landed
     * unreadable — in the destination. Returns [] when the published set is
     * complete.
     *
     * Expectations are derived from the SOURCE manifest (guaranteed present
     * by compiledAssetsAreAvailable()), never the destination one, so a
     * missing or corrupt *destination* manifest cannot make this pass open:
     * the destination manifest is itself a required file, and a
     * present-but-unparseable one is reported too. Both are as fatal as a
     * missing app bundle — Laravel's Vite resolver can't parse them and the
     * SPA never mounts.
     *
     * @return list<string>
     */
    protected function missingPublishedFiles(string $source, string $destination): array
    {
        $expected = ['manifest.json' => true];

        /** @var mixed $manifest */
        $manifest = json_decode((string) file_get_contents($source.'/manifest.json'), true);
        if (is_array($manifest)) {
            foreach ($manifest as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                if (isset($entry['file']) && is_string($entry['file'])) {
                    $expected[$entry['file']] = true;
                }
                foreach ((array) ($entry['css'] ?? []) as $css) {
                    if (is_string($css)) {
                        $expected[$css] = true;
                    }
                }
            }
        }

        $missing = [];
        foreach (array_keys($expected) as $relative) {
            if (! is_file($destination.'/'.$relative)) {
                $missing[] = $relative;
            }
        }

        // A present-but-corrupt destination manifest is as fatal as a missing
        // one — the existence loop above cannot see truncation, so parse it.
        if (! in_array('manifest.json', $missing, true)) {
            /** @var mixed $published */
            $published = json_decode((string) file_get_contents($destination.'/manifest.json'), true);
            if (! is_array($published) || $published === []) {
                $missing[] = 'manifest.json (unreadable)';
            }
        }

        return $missing;
    }

    protected function relativePath(string $absolute): string
    {
        $base = base_path();
        if (str_starts_with($absolute, $base)) {
            return ltrim(substr($absolute, strlen($base)), DIRECTORY_SEPARATOR);
        }

        return $absolute;
    }
}
