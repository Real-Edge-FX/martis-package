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
 * This command wipes `public/vendor/martis/` first, then republishes
 * the package's compiled assets. It is the canonical entry point for
 * keeping the published frontend in sync with the installed package
 * version. `martis:vendor-publish --assets` shares the same wipe-then-
 * publish flow.
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

        $destination = public_path('vendor/martis');
        $wipe = ! (bool) $this->option('no-wipe');

        if ($wipe && $filesystem->exists($destination)) {
            $this->components->task(
                'Wiping <fg=cyan>'.$this->relativePath($destination).'</>',
                fn () => $filesystem->deleteDirectory($destination),
            );
        }

        $this->callSilent('vendor:publish', [
            '--tag' => 'martis-assets',
            '--force' => true,
        ]);

        $this->components->twoColumnDetail(
            '<fg=green>Published</> martis-assets',
            $this->relativePath($destination),
        );

        $this->newLine();
        $this->components->info('Martis assets published successfully.');

        return self::SUCCESS;
    }

    protected function compiledAssetsAreAvailable(): bool
    {
        return file_exists(__DIR__.'/../../public/manifest.json');
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
