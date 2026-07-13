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
 */
class PublishAssetsCommand extends Command
{
    protected $signature = 'martis:publish-assets
                            {--no-wipe : Skip the destination wipe (legacy merge-style behaviour)}';

    protected $description = 'Republish Martis frontend assets, wiping public/vendor/martis first';

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

        $this->newLine();
        $this->components->info('Martis assets published successfully.');

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

    protected function relativePath(string $absolute): string
    {
        $base = base_path();
        if (str_starts_with($absolute, $base)) {
            return ltrim(substr($absolute, strlen($base)), DIRECTORY_SEPARATOR);
        }

        return $absolute;
    }
}
