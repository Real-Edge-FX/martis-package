<?php

namespace Martis\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Martis\Support\LinkedDirectory;
use Martis\Support\ThemeFiles;
use Martis\Support\ThemePublisher;
use Throwable;

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
 * This command wipes `public/vendor/martis/` first, then copies the
 * package's compiled assets directly (`File::copyDirectory`) — a
 * deterministic full-tree copy, rather than delegating the 1400+ file
 * loop to `vendor:publish`'s Flysystem mount. It is the canonical entry
 * point for keeping the published frontend in sync with the installed
 * package version. `martis:vendor-publish --assets` shares the same flow.
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
 * Last, it publishes the app's themes: every `resources/css/martis/<name>.css`
 * is copied to `public/vendor/martis/themes/`, where the panel loads it. The
 * wipe deletes the previous copies with everything else, so the published
 * themes always match their sources (see `Martis\Support\ThemeFiles`).
 * Before it deletes or overwrites anything, it stops, changing nothing, when
 * the run would take away the theme `martis.theme.name` names, or remove the
 * copy of a theme source it cannot read (a source it cannot read that
 * nothing depends on is skipped with a warning); then it backs up every file
 * under `public/vendor/martis/themes/` it cannot write back (a copy edited in
 * place, a theme without a source, a font next to a theme) to
 * `storage/app/martis/theme-backups/<run>/` and says so, stopping with
 * nothing deleted if a backup fails (see `Martis\Support\ThemePublisher`).
 * A `public/vendor/martis/` or `themes/` that is a symlink is replaced by a
 * real directory first, and its target is never written. `--themes-only` runs that theme step alone,
 * for the edit-and-publish loop.
 */
class PublishAssetsCommand extends Command
{
    protected $signature = 'martis:publish-assets
                            {--no-wipe : Skip the destination wipe (legacy merge-style behaviour); published themes without a source stay}
                            {--themes-only : Only publish the app themes from resources/css/martis/, without the package assets}';

    protected $description = 'Republish Martis frontend assets and the app themes, wiping public/vendor/martis first';

    public function handle(Filesystem $filesystem): int
    {
        $themesOnly = (bool) $this->option('themes-only');
        $wipe = ! (bool) $this->option('no-wipe');

        if (! $themesOnly && ! $this->compiledAssetsAreAvailable()) {
            $this->components->error('Martis frontend assets are missing from this package release.');
            $this->line('  Expected: <fg=cyan>public/manifest.json</>');
            $this->line('  Fix the package release by running <fg=cyan>npm install && npm run build</> before publishing.');

            return self::FAILURE;
        }

        // Nothing is deleted or overwritten until the themes are safe: a run
        // that would take away the theme martis.theme.name names, or the copy
        // of a source it cannot read, stops here. Then every file under
        // public/vendor/martis/themes/ the run destroys and cannot write back
        // is backed up. Without --no-wipe, files there without a source go
        // too: the wipe deletes them, and --themes-only removes them.
        $themes = ThemeFiles::scanSources();
        $publisher = new ThemePublisher($filesystem);

        try {
            $atRisk = $publisher->filesAtRisk($themes['sources'], removesOthers: $wipe);
            $links = $publisher->linksAffected($themes['sources'], removesOthers: $wipe);
        } catch (Throwable $e) {
            $this->components->error('Could not read the published themes: '.$e->getMessage());
            $this->line('  Nothing was deleted or overwritten.');

            return self::FAILURE;
        }

        if (! $this->unreadableSourcesLoseNothing($themes['unreadable'], $atRisk, $links)) {
            return self::FAILURE;
        }

        if (! $this->activeThemeSurvives($themes, $atRisk, $links)) {
            return self::FAILURE;
        }

        // public/vendor/martis/ or its themes/ as a symlink (a deploy tool's
        // shared directory) is never written through: the wipe would empty
        // the target and the copies would land in it. Each link is replaced
        // by a real directory holding a copy of its target's files, before
        // the backups, since that loses nothing; a link that cannot be
        // replaced stops the run before any warning about a removal.
        $this->reportDirectoryLinks();

        try {
            $publisher->replaceDirectoryLinks();
        } catch (Throwable $e) {
            $this->components->error('Could not replace a symlinked directory under public/vendor/ with a real one: '.$e->getMessage());
            $this->line('  Nothing was deleted, and nothing was written into the link\'s target. Replace the link with a directory,');
            $this->line('  or make its parent directory writable, then run the command again.');

            return self::FAILURE;
        }

        if (! $this->backUpThemeFiles($publisher, $atRisk)) {
            return self::FAILURE;
        }

        $this->reportLinks($links, $themes['sources']);
        $this->reportPruning($publisher->pruneRuns());
        $replacesTree = ! $themesOnly && $wipe;

        // The wipe takes the VCS placeholders of the themes directory with it.
        $placeholders = $replacesTree ? $publisher->placeholders() : [];

        if (! $themesOnly) {
            $source = $this->packagePublicPath();
            $destination = public_path('vendor/martis');

            if ($wipe && $filesystem->exists($destination)) {
                $this->components->task(
                    'Wiping <fg=cyan>'.$this->relativePath($destination).'</>',
                    fn () => $filesystem->deleteDirectory($destination),
                );
            }

            // Deterministic full-tree copy — copies every file under the package's
            // public/ (assets + manifest.json), not a subset.
            $this->components->task(
                'Copying assets to <fg=cyan>'.$this->relativePath($destination).'</>',
                fn () => $filesystem->copyDirectory($source, $destination),
            );

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
        }

        try {
            $publisher->restorePlaceholders($placeholders);
        } catch (Throwable $e) {
            $this->components->warn('Could not put back a placeholder in public/vendor/martis/themes/: '.$e->getMessage());
        }

        // The wipe already removed what the run takes away; --themes-only
        // removes it here, except the copies a source replaces.
        $toRemove = [];
        if ($themesOnly && $wipe) {
            foreach ($atRisk as $relative => $file) {
                if ($file['reason'] !== ThemePublisher::EDITED) {
                    $toRemove[] = $relative;
                }
            }
            foreach ($links as $relative => $link) {
                if ($link['fate'] === ThemePublisher::LINK_REMOVED) {
                    $toRemove[] = $relative;
                }
            }
        }

        if (! $this->publishThemes($publisher, $themes, $toRemove)) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info($themesOnly ? 'Martis themes published successfully.' : 'Martis assets published successfully.');

        return self::SUCCESS;
    }

    protected function packagePublicPath(): string
    {
        return __DIR__.'/../../public';
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

    /**
     * A theme source that cannot be read stops the run when something
     * depends on it: it is the source of the theme `martis.theme.name`
     * names, or the run would remove its published copy, which may be the
     * only readable version left. A source directory that cannot be listed
     * stops it too. Any other unreadable source (a broken symlink nothing
     * uses) is skipped with a warning when the themes are published.
     *
     * @param  array<string, string>  $unreadable  path => reason
     * @param  array<string, array{reason: string, source: string|null}>  $atRisk
     * @param  array<string, array{fate: string, source: string|null}>  $links
     */
    protected function unreadableSourcesLoseNothing(array $unreadable, array $atRisk, array $links): bool
    {
        $active = config('martis.theme.name');
        $stops = false;
        $unlistable = false;

        foreach ($unreadable as $path => $reason) {
            $name = ThemeFiles::nameOf($path);
            $copy = $name.'.css';
            $source = $this->relativePath($path);

            if ($path === ThemeFiles::sourceDirectory()) {
                $this->components->error("Could not list {$source}: {$reason}.");
                $unlistable = true;
            } elseif ($active === $name) {
                $this->components->error("Could not read {$source}, the source of the active theme: {$reason}.");
            } elseif (isset($atRisk[$copy]) || ($links[$copy]['fate'] ?? null) === ThemePublisher::LINK_REMOVED) {
                $this->components->error("Could not read {$source}: {$reason}, and this publish would remove public/vendor/martis/themes/{$copy}.");
            } else {
                continue;
            }

            $stops = true;
        }

        if ($unlistable) {
            $this->line('  Nothing was deleted or overwritten. Give the user that runs the command permission to list');
            $this->line('  resources/css/martis/ (the theme sources), then run the command again.');
        } elseif ($stops) {
            $this->line('  Nothing was deleted or overwritten. Fix or remove the file, then run the command again.');
        }

        return ! $stops;
    }

    /**
     * The run stops when it would leave the panel without the theme
     * `martis.theme.name` names: that theme's source was skipped, or its
     * published copy (a file, or a symlink that resolves) has no source and
     * the run removes it.
     *
     * @param  array{sources: array<string, string>, skipped: array<string, string>, unreadable: array<string, string>}  $themes
     * @param  array<string, array{reason: string, source: string|null}>  $atRisk
     * @param  array<string, array{fate: string, source: string|null}>  $links
     */
    protected function activeThemeSurvives(array $themes, array $atRisk, array $links): bool
    {
        $name = config('martis.theme.name');

        if (! is_string($name) || ! ThemeFiles::isValidName($name) || isset($themes['sources'][$name])) {
            return true;
        }

        foreach ($themes['skipped'] as $path => $reason) {
            if (ThemeFiles::nameOf($path) === $name) {
                $this->components->error("martis.theme.name is \"{$name}\", but its source ".$this->relativePath($path)." is skipped: {$reason}.");
                $this->line('  Nothing was deleted or overwritten. Fix the source, or set martis.theme.name to another theme or null, then run the command again.');

                return false;
            }
        }

        $removesFile = ($atRisk[$name.'.css']['reason'] ?? null) === ThemePublisher::NO_SOURCE;
        $removesLink = ($links[$name.'.css']['fate'] ?? null) === ThemePublisher::LINK_REMOVED
            && file_exists(ThemeFiles::publishedPath($name));

        if ($removesFile || $removesLink) {
            $this->components->error("martis.theme.name is \"{$name}\", but resources/css/martis/{$name}.css does not exist, and this publish would remove public/vendor/martis/themes/{$name}.css, the copy the panel loads.");
            $this->line("  Nothing was deleted or overwritten. Move that copy to <fg=cyan>resources/css/martis/{$name}.css</> (see \"Upgrading from 1.x\" in the theming guide),");
            $this->line('  or set martis.theme.name to null, then run the command again.');

            return false;
        }

        return true;
    }

    /**
     * Back up each file at risk, then say what happens to each one. Every
     * backup is made before any warning is printed, so a failed backup never
     * follows a warning that a file is being removed. Returns false, after
     * an error, when a backup fails: the caller stops with nothing deleted.
     *
     * @param  array<string, array{reason: string, source: string|null}>  $atRisk  path under public/vendor/martis/themes/ => reason and source
     */
    protected function backUpThemeFiles(ThemePublisher $publisher, array $atRisk): bool
    {
        $backups = [];

        foreach ($atRisk as $relative => $reason) {
            try {
                $backups[$relative] = $publisher->backUp(ThemeFiles::publishedDirectory().'/'.$relative);
            } catch (Throwable $e) {
                $this->components->error("Could not back up public/vendor/martis/themes/{$relative}: ".$e->getMessage());
                $this->line('  Nothing was deleted or overwritten. Make the file readable and <fg=cyan>storage/app/martis/theme-backups/</> writable,');
                $this->line('  then run the command again.');

                return false;
            }
        }

        foreach ($backups as $relative => $backup) {
            $published = 'public/vendor/martis/themes/'.$relative;
            // A copy matched by inode names its real source, `brand` for a
            // `Brand.css`; NO_SOURCE is only given to a valid theme name.
            $name = $atRisk[$relative]['source'] ?? basename($relative, '.css');

            [$warning, $advice] = match ($atRisk[$relative]['reason']) {
                ThemePublisher::EDITED => [
                    "{$published} differs from its source, resources/css/martis/{$name}.css, and this publish replaces it.",
                    "If it holds edits you want, copy them into resources/css/martis/{$name}.css and publish again.",
                ],
                ThemePublisher::NO_SOURCE => [
                    "{$published} has no source in resources/css/martis/, so this publish removes it.",
                    "To keep the theme, copy it to resources/css/martis/{$name}.css and publish again.",
                ],
                default => [
                    "{$published} is not generated from resources/css/martis/, so this publish removes it.",
                    'Keep the files a theme uses outside public/vendor/martis/, for example in public/fonts/.',
                ],
            };

            $this->components->warn($warning);
            $this->line('  '.($backup['reused'] ? 'Already backed up to' : 'Backed up to').' <fg=cyan>'.$this->relativePath($backup['path'])."</>. {$advice}");
        }

        return true;
    }

    /**
     * Say which symlinks the run replaces or removes: their targets stay,
     * so there is nothing to back up.
     *
     * @param  array<string, array{fate: string, source: string|null}>  $links  path under public/vendor/martis/themes/ => fate and replacing theme
     * @param  array<string, string>  $sources
     */
    protected function reportLinks(array $links, array $sources): void
    {
        foreach ($links as $relative => $link) {
            $published = 'public/vendor/martis/themes/'.$relative;

            $this->components->warn($link['source'] !== null
                ? "{$published} is a symlink: this publish replaces the link with a copy of ".$this->relativePath($sources[$link['source']]).'.'
                : "{$published} is a symlink: this publish removes the link, not its target.");
        }
    }

    /** Say which of public/vendor/martis/ and its themes/ are symlinks, and what the run does with them. */
    protected function reportDirectoryLinks(): void
    {
        foreach ([ThemeFiles::assetsDirectory(), ThemeFiles::publishedDirectory()] as $directory) {
            $target = LinkedDirectory::target($directory);

            if ($target === null) {
                continue;
            }

            $relative = $this->relativePath($directory);

            $this->components->warn(is_dir($directory)
                ? "{$relative} is a symlink to {$target}: this publish replaces the link with a real directory holding a copy of its files, and never writes into {$target}."
                : "{$relative} is a broken symlink (to {$target}): this publish replaces it with a real directory.");
        }
    }

    /**
     * Say which old backup runs were deleted, and warn about the ones that
     * could not be: pruning never fails the run.
     *
     * @param  array{removed: list<string>, failed: array<string, string>}  $pruned
     */
    protected function reportPruning(array $pruned): void
    {
        foreach ($pruned['removed'] as $directory) {
            $this->line('  Removed the old backup <fg=cyan>'.$this->relativePath($directory).'</> (the oldest backup and the '.(ThemePublisher::KEEP_RUNS - 1).' newest are kept).');
        }

        foreach ($pruned['failed'] as $directory => $reason) {
            $this->components->warn('Could not remove the old backup '.$this->relativePath($directory).": {$reason}.");
        }
    }

    /**
     * Publish every usable theme source, warn about the skipped ones, remove
     * `$toRemove` (backed up already, or symlinks), save the publish record
     * and warn about `martis.theme.name`. Returns false, after an error, when
     * a copy cannot be written.
     *
     * @param  array{sources: array<string, string>, skipped: array<string, string>, unreadable: array<string, string>}  $themes
     * @param  list<string>  $toRemove  paths under public/vendor/martis/themes/
     */
    protected function publishThemes(ThemePublisher $publisher, array $themes, array $toRemove): bool
    {
        // The unreadable sources that reach this point are the ones nothing
        // depends on: the others stopped the run.
        foreach ($themes['skipped'] + $themes['unreadable'] as $path => $reason) {
            $this->components->warn('Skipped '.$this->relativePath($path).": {$reason}.");
        }

        foreach ($themes['sources'] as $name => $source) {
            try {
                $publisher->publish($name, $source);
            } catch (Throwable $e) {
                $this->components->error('Could not publish '.$this->relativePath($source).': '.$e->getMessage());
                $this->saveThemeRecord($publisher);

                return false;
            }

            $this->components->twoColumnDetail(
                '<fg=green>Published</> theme '.$name,
                $this->relativePath(ThemeFiles::publishedPath($name)),
            );
        }

        foreach ($toRemove as $relative) {
            $publisher->remove($relative);
        }

        $this->saveThemeRecord($publisher);
        $this->warnAboutActiveTheme($themes['sources']);

        return true;
    }

    /**
     * A missing record only makes the next publish back up the copies again,
     * so a failure to write it is a warning, not a failed run.
     */
    protected function saveThemeRecord(ThemePublisher $publisher): void
    {
        try {
            $publisher->saveRecord();
        } catch (Throwable $e) {
            $this->components->warn('Could not write '.$this->relativePath(ThemeFiles::recordPath()).': '.$e->getMessage());
            $this->line('  Without it, the next publish backs up a copy that differs from its source, as if it had been edited in place.');
        }
    }

    /**
     * Warn when `martis.theme.name` names a theme the panel may not load.
     * The cases that would take the theme away stopped the run earlier.
     *
     * @param  array<string, string>  $sources
     */
    protected function warnAboutActiveTheme(array $sources): void
    {
        $name = config('martis.theme.name');

        if (! is_string($name) || $name === '') {
            return;
        }

        if (! ThemeFiles::isValidName($name)) {
            $this->components->warn("martis.theme.name \"{$name}\" is not a theme name the panel loads (letters, digits, dashes and underscores), so the panel ignores it.");

            return;
        }

        if (isset($sources[$name])) {
            return;
        }

        foreach ($sources as $source => $path) {
            if (strcasecmp($source, $name) === 0) {
                $this->components->warn("martis.theme.name \"{$name}\" and ".$this->relativePath($path).' differ in case: the panel only finds the theme on a filesystem that ignores case, not on a Linux server.');

                return;
            }
        }

        $this->components->warn("martis.theme.name is \"{$name}\", but resources/css/martis/{$name}.css is not a theme source.");

        if (is_file(ThemeFiles::publishedPath($name))) {
            $this->line("  The panel still loads the published copy, which a publish without --no-wipe refuses to remove. Move it to <fg=cyan>resources/css/martis/{$name}.css</>.");
        } else {
            $this->line("  The panel will not find its stylesheet. Put the theme in <fg=cyan>resources/css/martis/{$name}.css</> and publish again, or set martis.theme.name to null.");
        }
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
