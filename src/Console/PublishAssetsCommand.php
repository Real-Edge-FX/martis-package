<?php

namespace Martis\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
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
 * Before it deletes or overwrites anything, it backs up every file under
 * `public/vendor/martis/themes/` it cannot write back (a copy edited in
 * place, a theme without a source, a font next to a theme) to
 * `storage/app/martis/theme-backups/<run>/` and says so; if a backup fails,
 * it stops with nothing deleted (see `Martis\Support\ThemePublisher`).
 * `--themes-only` runs that theme step alone, for the edit-and-publish loop.
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

        // Before anything is deleted or overwritten, back up every file under
        // public/vendor/martis/themes/ this run destroys and cannot write back.
        // Without --no-wipe, files there without a source go too: the wipe
        // deletes them, and --themes-only removes them to match the sources.
        $publisher = new ThemePublisher($filesystem);
        $themes = ThemeFiles::scanSources($filesystem);
        $atRisk = $publisher->filesAtRisk($themes['sources'], removesOthers: $wipe);

        if (! $this->backUpThemeFiles($publisher, $atRisk)) {
            return self::FAILURE;
        }

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

        // The wipe already removed the files at risk; --themes-only removes
        // the ones a source does not replace itself.
        if (! $this->publishThemes($publisher, $themes, $themesOnly && $wipe ? $atRisk : [])) {
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
     * Copy every theme source to `public/vendor/martis/themes/`.
     *
     * The source is the theme. After the wipe a copy edited in place is
     * replaced and one whose source is gone is not published again; with
     * `--no-wipe` a stale copy is overwritten from its source, and one
     * without a source stays, like any other stale file.
     */
    /**
     * Publish every usable theme source, warn about the skipped ones, remove
     * `$toRemove` (backed up already) and save the publish record. Returns
     * false, after an error, when a copy cannot be written.
     *
     * @param  array{sources: array<string, string>, skipped: array<string, string>}  $themes
     * @param  array<string, string>  $toRemove  path under public/vendor/martis/themes/ => reason
     */
    protected function publishThemes(ThemePublisher $publisher, array $themes, array $toRemove): bool
    {
        foreach ($themes['skipped'] as $path => $reason) {
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

        foreach ($toRemove as $relative => $reason) {
            if ($reason !== ThemePublisher::EDITED) {
                $publisher->remove($relative);
            }
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
            $this->components->warn('Could not write public/vendor/martis/themes/'.ThemePublisher::RECORD.': '.$e->getMessage());
            $this->line('  Without it, the next publish backs up a copy that differs from its source, as if it had been edited in place.');
        }
    }

    /**
     * Back up each file at risk and say what happens to it. Returns false,
     * after an error, as soon as a backup fails: the caller stops before
     * anything is deleted or overwritten.
     *
     * @param  array<string, string>  $atRisk  path under public/vendor/martis/themes/ => reason
     */
    protected function backUpThemeFiles(ThemePublisher $publisher, array $atRisk): bool
    {
        foreach ($atRisk as $relative => $reason) {
            $published = 'public/vendor/martis/themes/'.$relative;

            try {
                $backup = $this->relativePath($publisher->backUp($relative));
            } catch (Throwable $e) {
                $this->components->error("Could not back up {$published}: ".$e->getMessage());
                $this->line('  Nothing was deleted or overwritten. Make the file readable and <fg=cyan>storage/app/martis/theme-backups/</> writable,');
                $this->line('  then run the command again.');

                return false;
            }

            $name = basename($relative, '.css');
            [$warning, $advice] = match ($reason) {
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
            $this->line("  Backed up to <fg=cyan>{$backup}</>. {$advice}");
        }

        return true;
    }

    /**
     * Warn when `martis.theme.name` names a theme the panel cannot load.
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

        $this->components->warn("martis.theme.name is \"{$name}\", but resources/css/martis/{$name}.css is not a theme source.");

        if (is_file(ThemeFiles::publishedPath($name))) {
            $this->line("  The panel still loads the published copy, which a publish without --no-wipe removes. Move it to <fg=cyan>resources/css/martis/{$name}.css</>.");
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
