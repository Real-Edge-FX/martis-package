<?php

namespace Martis\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'martis:install';

    protected $description = 'Install the Martis admin panel';

    public function handle(): int
    {
        $this->components->info('Installing Martis...');

        $this->createDirectories();
        $this->publishConfig();
        $this->publishAssets();

        $this->newLine();
        $this->components->info('Martis installed successfully.');
        $this->newLine();
        $this->line('  Next steps:');
        $this->line('  - Run <fg=cyan>php artisan martis:user</> to create an admin account.');

        /** @var string $panelUrl */
        $panelUrl = url((string) config('martis.path', 'martis'));
        $this->line("  - Visit <fg=cyan>{$panelUrl}</> to access the panel.");
        $this->newLine();

        return self::SUCCESS;
    }

    protected function createDirectories(): void
    {
        $base = app_path('Martis');

        $dirs = [
            '',
            '/Resources',
            '/Fields',
            '/Actions',
            '/Filters',
            '/Lenses',
            '/Dashboards',
            '/Metrics',
        ];

        foreach ($dirs as $dir) {
            $path = $base.$dir;

            if (! is_dir($path)) {
                mkdir($path, 0755, true);
                $this->components->twoColumnDetail('<fg=green>Creating</> directory', "app/Martis{$dir}");
            }
        }
    }

    protected function publishConfig(): void
    {
        if (file_exists(config_path('martis.php'))) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> config', 'already published');

            return;
        }

        $this->callSilent('vendor:publish', ['--tag' => 'martis-config']);
        $this->components->twoColumnDetail('<fg=green>Published</> config', 'config/martis.php');
    }

    protected function publishAssets(): void
    {
        $this->callSilent('vendor:publish', ['--tag' => 'martis-assets', '--force' => true]);
        $this->components->twoColumnDetail('<fg=green>Published</> assets', 'public/vendor/martis');
    }
}
