<?php

namespace Martis\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;

class InstallCommand extends Command
{
    protected $signature = 'martis:install
                            {--force : Overwrite existing assets}
                            {--with-profile : Enable profile support}
                            {--with-2fa : Enable two-factor authentication support}
                            {--avatar-column= : Column on the users table that stores avatar paths}
                            {--existing-avatar-column : Use an existing avatar column instead of publishing a migration}';

    protected $description = 'Install the Martis admin panel';

    public function handle(): int
    {
        $this->components->info('Installing Martis...');

        $options = $this->resolveInstallOptions();

        $this->createDirectories();
        $this->publishConfig();
        $this->publishAssets();
        $this->publishCoreMigrations();
        $this->publishTranslations();
        $this->publishOptionalMigrations($options);
        $this->writeEnvironmentConfiguration($options);
        $this->clearConfigCache();
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

    /**
     * @return array{
     *     profile_enabled: bool,
     *     avatar_enabled: bool,
     *     avatar_column: string,
     *     publish_avatar_migration: bool,
     *     two_factor_enabled: bool
     * }
     */
    protected function resolveInstallOptions(): array
    {
        $interactive = $this->input->isInteractive() && ! app()->runningUnitTests();
        $profileEnabled = (bool) $this->option('with-profile');
        $twoFactorEnabled = (bool) $this->option('with-2fa');
        $avatarEnabled = false;
        $publishAvatarMigration = false;
        $avatarColumn = 'profile_picture';

        if (! $profileEnabled && ! $twoFactorEnabled && $interactive) {
            $profileEnabled = $this->confirm('Would you like to enable the Martis Profile feature?', true);
            $twoFactorEnabled = $this->confirm('Would you like to enable the Martis 2FA feature?', true);
        }

        if ($profileEnabled) {
            $avatarEnabled = true;

            $usingExistingAvatarColumn = (bool) $this->option('existing-avatar-column');
            $avatarColumnOption = $this->option('avatar-column');

            if ($interactive && ! $this->option('with-profile')) {
                $shouldCreateAvatarMigration = $this->confirm(
                    'Would you like Martis to publish a migration for the avatar column on the users table?',
                    true
                );

                if ($shouldCreateAvatarMigration) {
                    $publishAvatarMigration = true;
                    $avatarColumn = $this->resolveAvatarColumnFromOptionOrPrompt($avatarColumnOption, 'profile_picture');
                } else {
                    $usingExistingAvatarColumn = true;
                    $avatarColumn = $this->resolveExistingAvatarColumn($avatarColumnOption);
                }
            } else {
                if ($usingExistingAvatarColumn) {
                    $avatarColumn = $this->resolveExistingAvatarColumn($avatarColumnOption);
                    $publishAvatarMigration = false;
                } else {
                    $avatarColumn = $this->resolveAvatarColumnFromOptionOrPrompt($avatarColumnOption, 'profile_picture');
                    $publishAvatarMigration = true;
                }
            }
        }

        return [
            'profile_enabled' => $profileEnabled,
            'avatar_enabled' => $avatarEnabled,
            'avatar_column' => $avatarColumn,
            'publish_avatar_migration' => $publishAvatarMigration,
            'two_factor_enabled' => $twoFactorEnabled,
        ];
    }

    protected function createDirectories(): void
    {
        $filesystem = new Filesystem;
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
            '/Cards',
            '/Policies',
        ];

        foreach ($dirs as $dir) {
            $path = $base.$dir;

            if (! is_dir($path)) {
                $filesystem->ensureDirectoryExists($path, 0755);
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

            throw new RuntimeException('Martis frontend assets are missing from this package release.');
        }

        $this->callSilent('vendor:publish', [
            '--tag' => 'martis-assets',
            '--force' => true,
        ]);

        $this->components->twoColumnDetail('<fg=green>Published</> assets', 'public/vendor/martis');
    }

    protected function compiledAssetsAreAvailable(): bool
    {
        return file_exists(__DIR__.'/../../public/manifest.json');
    }

    protected function publishCoreMigrations(): void
    {
        $this->publishMigrationStub(
            __DIR__.'/../../stubs/create_martis_action_events_table.php.stub',
            'create_martis_action_events_table'
        );

        // User preferences (theme/accent/density/locale/reduced-motion).
        // The preferences resolver falls back to config defaults if this
        // migration is never run, so the table is core but not strictly
        // blocking for apps that disable the feature.
        $this->publishMigrationStub(
            __DIR__.'/../../stubs/create_user_preferences_table.php.stub',
            'create_martis_user_preferences_table'
        );

        // In-app notifications (Task 12). Uses the standard Laravel
        // `notifications` table shape so any consumer-side Notification
        // class with the `database` channel delivers into the Martis
        // bell dropdown automatically. The migration is idempotent —
        // skipped when the table already exists (some apps already
        // ran `php artisan notifications:table`).
        $this->publishMigrationStub(
            __DIR__.'/../../stubs/create_martis_notifications_table.php.stub',
            'create_notifications_table'
        );
    }

    /**
     * @param array{
     *     profile_enabled: bool,
     *     avatar_enabled: bool,
     *     avatar_column: string,
     *     publish_avatar_migration: bool,
     *     two_factor_enabled: bool
     * } $options
     */
    protected function publishOptionalMigrations(array $options): void
    {
        if ($options['publish_avatar_migration']) {
            $this->publishMigrationStub(
                __DIR__.'/../../stubs/add_profile_picture_column.php.stub',
                'add_martis_profile_picture_column_to_users_table',
                fn (string $stub): string => str_replace('profile_picture', $options['avatar_column'], $stub)
            );

            $this->components->twoColumnDetail('<fg=green>Profile</> support', "avatar column: {$options['avatar_column']}");
        }

        if ($options['two_factor_enabled']) {
            $this->publishMigrationStub(
                __DIR__.'/../../stubs/add_two_factor_columns.php.stub',
                'add_martis_two_factor_columns_to_users_table'
            );

            $this->components->twoColumnDetail('<fg=green>2FA</> support', 'two-factor migration published');
        }
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

    protected function resolveAvatarColumnFromOptionOrPrompt(mixed $optionValue, string $default): string
    {
        if (is_string($optionValue) && trim($optionValue) !== '') {
            return $this->sanitizeColumnName($optionValue);
        }

        if ($this->input->isInteractive() && ! app()->runningUnitTests()) {
            $value = $this->ask('Which users table column should Martis use for avatar paths?', $default);

            return $this->sanitizeColumnName((string) $value);
        }

        return $default;
    }

    protected function resolveExistingAvatarColumn(mixed $optionValue): string
    {
        if (is_string($optionValue) && trim($optionValue) !== '') {
            return $this->sanitizeColumnName($optionValue);
        }

        if ($this->input->isInteractive() && ! app()->runningUnitTests()) {
            $value = $this->ask('Which existing users table column should Martis use for avatar paths?', 'profile_picture');

            return $this->sanitizeColumnName((string) $value);
        }

        throw new RuntimeException('The --existing-avatar-column option requires --avatar-column=<column_name> in non-interactive mode.');
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

    protected function sanitizeColumnName(string $name): string
    {
        $name = Str::snake($name);
        $name = (string) preg_replace('/[^a-z0-9_]/', '', $name);

        return $name !== '' ? $name : 'profile_picture';
    }

    /**
     * @param array{
     *     profile_enabled: bool,
     *     avatar_enabled: bool,
     *     avatar_column: string,
     *     publish_avatar_migration: bool,
     *     two_factor_enabled: bool
     * } $options
     */
    protected function writeEnvironmentConfiguration(array $options): void
    {
        if ($this->laravel->runningUnitTests()) {
            return;
        }

        $pairs = [
            'MARTIS_PROFILE_ENABLED' => $options['profile_enabled'] ? 'true' : 'false',
            'MARTIS_AVATAR_ENABLED' => $options['avatar_enabled'] ? 'true' : 'false',
            'MARTIS_AVATAR_COLUMN' => $options['avatar_column'],
            'MARTIS_2FA_ENABLED' => $options['two_factor_enabled'] ? 'true' : 'false',
            'MARTIS_SHOW_PROFILE_MENU' => $options['profile_enabled'] ? 'true' : 'false',
        ];

        foreach ($pairs as $key => $value) {
            $this->writeEnvValue($key, $value);
        }

        $this->components->twoColumnDetail('<fg=green>Updated</> environment', '.env');
    }

    protected function writeEnvValue(string $key, string $value): void
    {
        $filesystem = new Filesystem;
        $envPath = $this->laravel->environmentFilePath();

        if (! $filesystem->exists($envPath)) {
            $filesystem->put($envPath, '');
        }

        $contents = (string) $filesystem->get($envPath);
        $escapedKey = preg_quote($key, '/');
        $escapedValue = $this->normalizeEnvValue($value);

        $pattern = "/^{$escapedKey}=.*$/m";
        $commentedPattern = "/^#\s*{$escapedKey}=.*$/m";

        if (preg_match($pattern, $contents) === 1) {
            $contents = (string) preg_replace($pattern, "{$key}={$escapedValue}", $contents);
        } elseif (preg_match($commentedPattern, $contents) === 1) {
            $contents = (string) preg_replace($commentedPattern, "{$key}={$escapedValue}", $contents);
        } else {
            $contents = rtrim($contents).PHP_EOL."{$key}={$escapedValue}".PHP_EOL;
        }

        $filesystem->put($envPath, $contents);
    }

    protected function normalizeEnvValue(string $value): string
    {
        if ($value === 'true' || $value === 'false' || is_numeric($value)) {
            return $value;
        }

        if (preg_match('/\s/', $value) === 1) {
            return '"'.str_replace('"', '\"', $value).'"';
        }

        return $value;
    }

    protected function clearConfigCache(): void
    {
        $this->callSilent('config:clear');
    }

    protected function runMigrations(): void
    {
        $this->call('migrate');
        $this->components->twoColumnDetail('<fg=green>Executed</> migrations', 'Martis database changes applied');
    }
}
