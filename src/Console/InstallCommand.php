<?php

namespace Martis\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class InstallCommand extends Command
{
    protected $signature = 'martis:install
                            {--force : Overwrite existing assets}';

    protected $description = 'Install the Martis admin panel';

    /**
     * Handle.
     */
    public function handle(): int
    {
        $this->components->info('Installing Martis...');

        $this->createDirectories();
        $this->publishConfig();
        $this->publishAssets();
        $this->publishMigrations();
        $this->publishTranslations();
        $this->setupProfilePictures();
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

    /** Create the required application directories. */
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

    /** Publish the Martis configuration file. */
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

    /** Publish the Martis frontend assets. */
    protected function publishAssets(): void
    {
        $this->callSilent('vendor:publish', ['--tag' => 'martis-assets', '--force' => true]);
        $this->components->twoColumnDetail('<fg=green>Published</> assets', 'public/vendor/martis');
    }

    /** Publish the Martis database migrations. */
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

    /** Publish the Martis translation files. */
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

    /**
     * Interactive setup for profile pictures.
     *
     * Asks whether the user wants to enable profile pictures, checks if the
     * required column already exists, and optionally generates a migration.
     */
    protected function setupProfilePictures(): void
    {
        if (app()->runningUnitTests() || ! $this->input->isInteractive()) {
            return;
        }

        if (! $this->confirm('Enable profile pictures (avatar upload)?', true)) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> profile pictures', 'disabled');

            return;
        }

        $columnExists = $this->confirm('Does a profile picture column already exist on the users table?', false);

        if ($columnExists) {
            $column = $this->ask('Enter the existing column name', 'profile_picture');
        } else {
            $column = $this->ask('Enter the column name to create (snake_case)', 'profile_picture');
            $this->generateAvatarMigration((string) $column);
        }

        $column = $this->sanitizeColumnName((string) $column);

        $this->components->twoColumnDetail('<fg=green>Profile pictures</>', "column: {$column}");
        $this->line("  Tip: set <fg=cyan>MARTIS_AVATAR_COLUMN={$column}</> in your .env file.");
    }

    /**
     * Generate a migration for adding the profile picture column.
     */
    protected function generateAvatarMigration(string $column): void
    {
        $column = $this->sanitizeColumnName($column);
        $stubPath = __DIR__.'/../../stubs/add_profile_picture_column.php.stub';

        if (! file_exists($stubPath)) {
            $this->components->warn('Migration stub not found. Skipping migration generation.');

            return;
        }

        $stub = (string) file_get_contents($stubPath);
        $stub = str_replace('profile_picture', $column, $stub);

        $filename = date('Y_m_d_His').'_add_profile_picture_column.php';
        $target = database_path("migrations/{$filename}");

        file_put_contents($target, $stub);
        $this->components->twoColumnDetail('<fg=green>Created</> migration', "database/migrations/{$filename}");
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

    /** Run the published Martis migrations. */
    protected function runMigrations(): void
    {
        $this->call('migrate');
        $this->components->twoColumnDetail('<fg=green>Executed</> migrations', 'action_events table created');
    }
}
