<?php

namespace Martis\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class InstallCommand extends Command
{
    protected $signature = 'martis:install
                            {--force : Overwrite existing assets}
                            {--with-profile : Publish the optional Martis profile migration (avatar + 2FA columns)}
                            {--avatar-column= : Column on the users table that stores avatar paths}';

    protected $description = 'Install the Martis admin panel';

    public function handle(): int
    {
        $this->components->info('Installing Martis...');

        $this->createDirectories();
        $this->publishConfig();
        $this->publishAssets();
        $this->publishMigrations();
        $this->publishTranslations();
        $this->publishProfileMigration();
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
        if (! $this->compiledAssetsAreAvailable()) {
            $this->components->error('Martis frontend assets are missing from this package release.');
            $this->line('  Expected: <fg=cyan>public/manifest.json</>');
            $this->line('  Fix the package release by running <fg=cyan>npm install && npm run build</> before publishing.');

            self::fail();
        }

        $this->callSilent('vendor:publish', ['--tag' => 'martis-assets', '--force' => true]);
        $this->components->twoColumnDetail('<fg=green>Published</> assets', 'public/vendor/martis');
    }

    protected function compiledAssetsAreAvailable(): bool
    {
        return file_exists(__DIR__.'/../../public/manifest.json');
    }

    protected function publishMigrations(): void
    {
        $this->publishMigrationStub(
            __DIR__.'/../../database/migrations/create_action_events_table.php.stub',
            'create_action_events_table'
        );
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

    protected function publishProfileMigration(): void
    {
        if (! $this->shouldPublishProfileMigration()) {
            return;
        }

        $column = $this->resolveAvatarColumn();

        $this->publishMigrationStub(
            __DIR__.'/../../database/migrations/add_profile_columns.php.stub',
            'add_martis_profile_columns',
            fn (string $stub): string => str_replace('profile_picture', $column, $stub)
        );

        $this->components->twoColumnDetail('<fg=green>Profile</> support', "avatar column: {$column}");
        $this->line("  Tip: set <fg=cyan>MARTIS_AVATAR_COLUMN={$column}</> in your .env file if you are not using the default.");
    }

    protected function shouldPublishProfileMigration(): bool
    {
        if ((bool) $this->option('with-profile')) {
            return true;
        }

        if (app()->runningUnitTests() || ! $this->input->isInteractive()) {
            return false;
        }

        return $this->confirm('Publish the optional Martis profile migration (avatar + 2FA columns)?', true);
    }

    protected function resolveAvatarColumn(): string
    {
        $column = $this->option('avatar-column');

        if (! is_string($column) || trim($column) === '') {
            if ($this->input->isInteractive()) {
                $column = $this->ask('Which users table column should Martis use for avatar paths?', 'profile_picture');
            } else {
                $column = 'profile_picture';
            }
        }

        return $this->sanitizeColumnName($column);
    }

    protected function publishMigrationStub(string $stubPath, string $migrationName, ?callable $transform = null): void
    {
        if (! file_exists($stubPath)) {
            $this->components->warn("Migration stub not found for {$migrationName}. Skipping.");

            return;
        }

        $existing = glob(database_path("migrations/*_{$migrationName}.php")) ?: [];
        $force = (bool) $this->option('force');

        if ($existing !== [] && ! $force) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> migration', "{$migrationName} already published");

            return;
        }

        $filesystem = new Filesystem;
        $filesystem->ensureDirectoryExists(database_path('migrations'));

        $target = $existing[0] ?? database_path('migrations/'.$this->migrationFilename($migrationName));
        $stub = (string) file_get_contents($stubPath);
        $stub = $transform ? $transform($stub) : $stub;

        $filesystem->put($target, $stub);

        $action = $existing === [] ? '<fg=green>Created</> migration' : '<fg=green>Updated</> migration';
        $this->components->twoColumnDetail($action, 'database/migrations/'.basename($target));
    }

    protected function migrationFilename(string $migrationName): string
    {
        return date('Y_m_d_His')."_{$migrationName}.php";
    }

    /**
     * Sanitize a column name to snake_case alphanumeric + underscores.
     */
    protected function sanitizeColumnName(string $name): string
    {
        $name = Str::snake($name);
        $name = (string) preg_replace('/[^a-z0-9_]/', '', $name);

        return $name ?: 'profile_picture';
    }

    protected function runMigrations(): void
    {
        $this->call('migrate');
        $this->components->twoColumnDetail('<fg=green>Executed</> migrations', 'Martis database changes applied');
    }
}
