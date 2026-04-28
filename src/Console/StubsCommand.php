<?php

namespace Martis\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Martis\Stubs\StubResolver;

/**
 * Publish Martis generator stubs into the consuming application.
 *
 * Copies every `.stub` file shipped inside the package into
 * `base_path('stubs/martis/')`. Once published, any subsequent
 * invocation of a `martis:*` generator (e.g. `martis:resource`,
 * `martis:action`, `martis:lens`) reads its template from the
 * project copy first, falling back to the package original only
 * when the override is absent. See {@see StubResolver}.
 *
 * Idempotent: existing files are skipped unless `--force` is set.
 */
class StubsCommand extends Command
{
    protected $signature = 'martis:stubs
        {--force : Overwrite stubs that already exist in stubs/martis}';

    protected $description = 'Publish all Martis generator stubs into stubs/martis for customisation';

    /**
     * Handle.
     */
    public function handle(Filesystem $files): int
    {
        $sourceDir = StubResolver::packageDirectory();

        if (! $files->isDirectory($sourceDir)) {
            $this->components->error("Package stub directory not found: {$sourceDir}");

            return self::FAILURE;
        }

        $targetDir = base_path('stubs/martis');

        if (! $files->isDirectory($targetDir)) {
            $files->makeDirectory($targetDir, 0755, true);
        }

        $force = (bool) $this->option('force');
        $copied = 0;
        $skipped = 0;

        foreach ($files->files($sourceDir) as $file) {
            $relative = $file->getFilename();
            $target = $targetDir.'/'.$relative;

            if ($files->exists($target) && ! $force) {
                $this->components->twoColumnDetail($relative, '<fg=yellow>SKIPPED</>');
                $skipped++;

                continue;
            }

            $files->copy($file->getPathname(), $target);
            $this->components->twoColumnDetail($relative, '<fg=green>'.($force ? 'OVERWRITTEN' : 'PUBLISHED').'</>');
            $copied++;
        }

        $this->newLine();
        $this->components->info(sprintf(
            '%d stub%s published; %d skipped. Edit them in stubs/martis/ to customise generator output.',
            $copied,
            $copied === 1 ? '' : 's',
            $skipped,
        ));

        return self::SUCCESS;
    }
}
