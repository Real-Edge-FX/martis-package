<?php

namespace Martis\Console;

use Illuminate\Console\Command;

class VendorPublishCommand extends Command
{
    protected $signature = 'martis:vendor-publish
                            {--config : Publish the Martis configuration file}
                            {--assets : Publish the Martis frontend assets}
                            {--views : Publish the Martis view stubs}
                            {--lang : Publish the Martis language files}
                            {--force : Overwrite existing published files}';

    protected $description = 'Publish Martis package files (config, assets, views, lang)';

    public function handle(): int
    {
        $this->components->info('Publishing Martis files...');

        $published = false;
        $force = (bool) $this->option('force');

        if ($this->option('config') || $this->noneSelected()) {
            $this->publishTag('martis-config', 'config/martis.php', $force);
            $published = true;
        }

        if ($this->option('assets') || $this->noneSelected()) {
            if (! $this->compiledAssetsAreAvailable()) {
                $this->components->error('Martis frontend assets are missing from this package release.');
                $this->line('  Expected: <fg=cyan>public/manifest.json</>');
                $this->line('  Fix the package release by running <fg=cyan>npm install && npm run build</> before publishing.');

                return self::FAILURE;
            }

            $this->publishTag('martis-assets', 'public/vendor/martis', $force);
            $published = true;
        }

        if ($this->option('views')) {
            $this->publishTag('martis-views', 'resources/views/vendor/martis', $force);
            $published = true;
        }

        if ($this->option('lang')) {
            $this->publishTag('martis-lang', 'lang/vendor/martis', $force);
            $published = true;
        }

        if (! $published) {
            $this->components->warn('Nothing was published.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Martis files published successfully.');

        return self::SUCCESS;
    }

    /**
     * Determine whether no specific group was requested (publish all defaults).
     */
    protected function noneSelected(): bool
    {
        return ! $this->option('config')
            && ! $this->option('assets')
            && ! $this->option('views')
            && ! $this->option('lang');
    }

    /**
     * Publish a specific vendor tag.
     */
    protected function publishTag(string $tag, string $destination, bool $force): void
    {
        $options = ['--tag' => $tag];

        if ($force) {
            $options['--force'] = true;
        }

        $this->callSilent('vendor:publish', $options);
        $this->components->twoColumnDetail('<fg=green>Published</> '.$tag, $destination);
    }

    protected function compiledAssetsAreAvailable(): bool
    {
        return file_exists(__DIR__.'/../../public/manifest.json');
    }
}
