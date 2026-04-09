<?php

namespace Martis\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'martis:install
                            {--force : Overwrite existing assets}';

    protected $description = 'Install the Martis admin panel';

    public function handle(): int
    {
        $this->components->info('Installing Martis...');

        $this->createDirectories();
        $this->publishConfig();
        $this->publishAssets();
        $this->publishMigrations();
        $this->publishTranslations();
        $this->runMigrations();

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
        if (file_exists(config_path('martis.php')) && ! $this->option('force')) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> config', 'already published (use --force to overwrite)');

            return;
        }

        $this->callSilent('vendor:publish', [
            '--tag' => 'martis-config',
            '--force' => (bool) $this->option('force'),
        ]);
        $this->components->twoColumnDetail('<fg=green>Published</> config', 'config/martis.php');
    }

    protected function publishAssets(): void
    {
        $this->callSilent('vendor:publish', ['--tag' => 'martis-assets', '--force' => true]);
        $this->components->twoColumnDetail('<fg=green>Published</> assets', 'public/vendor/martis');
    }

    protected function publishMigrations(): void
    {
        $migrationPattern = database_path('migrations/*_create_action_events_table.php');
        $existing = glob($migrationPattern);

        if ($existing !== false && count($existing) > 0 && ! $this->option('force')) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> migrations', 'already published (use --force to overwrite)');

            return;
        }

        $this->callSilent('vendor:publish', [
            '--tag' => 'martis-migrations',
            '--force' => (bool) $this->option('force'),
        ]);
        $this->components->twoColumnDetail('<fg=green>Published</> migrations', 'database/migrations');
    }

    protected function publishTranslations(): void
    {
        $langPath = $this->laravel->langPath('vendor/martis');

        if (is_dir($langPath) && ! $this->option('force')) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> translations', 'already published (use --force to overwrite)');

            return;
        }

        $this->callSilent('vendor:publish', [
            '--tag' => 'martis-lang',
            '--force' => (bool) $this->option('force'),
        ]);
        $this->components->twoColumnDetail('<fg=green>Published</> translations', 'lang/vendor/martis');
    }

    protected function runMigrations(): void
    {
        $this->call('migrate');
        $this->components->twoColumnDetail('<fg=green>Executed</> migrations', 'action_events table created');
    }
}
