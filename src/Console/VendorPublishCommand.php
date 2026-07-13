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
                            {--force : Overwrite existing published files}
                            {--no-wipe : Skip the destination wipe before publishing assets (legacy merge-style behaviour)}';

    protected $description = 'Publish Martis package files (config, assets, views, lang)';

    /**
     * Handle.
     */
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
            // Delegate to `martis:publish-assets` — the canonical, hardened
            // asset path: a deterministic full-tree copy plus a post-publish
            // manifest check that fails loudly on an incomplete set (a partial
            // copy would render the admin as a black screen). Keeping a single
            // source of truth means both entry points share the guarantee.
            // `--force` is irrelevant here because the copy always overwrites;
            // `--no-wipe` is forwarded for the legacy merge-style behaviour.
            $assetOptions = [];
            if ($this->option('no-wipe')) {
                $assetOptions['--no-wipe'] = true;
            }

            if ($this->call('martis:publish-assets', $assetOptions) !== self::SUCCESS) {
                return self::FAILURE;
            }

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
}
