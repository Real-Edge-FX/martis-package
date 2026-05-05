<?php

namespace Martis\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Martis\Stubs\StubResolver;
use RuntimeException;

class InstallCommand extends Command
{
    protected $signature = 'martis:install
                            {--force : Overwrite existing scaffold files (vite config, shim files, index entry, generator stubs). Does NOT republish config/martis.php or app/Providers/MartisServiceProvider.php — pass --force-config and --force-provider for those.}
                            {--force-config : Republish config/martis.php, overwriting any consumer customisations. Separated from --force so refreshing the extension scaffold does not destroy the host app config.}
                            {--force-provider : Republish app/Providers/MartisServiceProvider.php, overwriting any consumer customisations (registered dashboards, menu, gates, cache layers). Separated from --force so refreshing the extension scaffold does not wipe host app dashboard wiring.}
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
        $this->publishServiceProvider();
        $this->publishExtensionsScaffold();
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
            '/Tools',
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
        $configExists = file_exists(config_path('martis.php'));
        $forceConfig = (bool) $this->option('force-config');

        if ($configExists && ! $forceConfig) {
            // v1.10+ separates --force (scaffold) from --force-config
            // (destructive config rewrite). The previous behaviour
            // had `--force` republish config too, which silently
            // stomped consumer customisations like `accent`,
            // `brandColor`, `theme`, etc. when the dev only wanted
            // to refresh the extension scaffold.
            $this->components->twoColumnDetail(
                '<fg=yellow>Skipping</> config',
                'already published (use --force-config to overwrite — destroys customisations)',
            );

            return;
        }

        $this->callSilent('vendor:publish', [
            '--tag' => 'martis-config',
            '--force' => $forceConfig,
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
            StubResolver::path('create_martis_action_events_table.php.stub'),
            'create_martis_action_events_table'
        );

        // User preferences (theme/accent/density/locale/reduced-motion).
        // The preferences resolver falls back to config defaults if this
        // migration is never run, so the table is core but not strictly
        // blocking for apps that disable the feature.
        $this->publishMigrationStub(
            StubResolver::path('create_user_preferences_table.php.stub'),
            'create_martis_user_preferences_table'
        );

        // v1.10.4 — `dashboards_layout` column add for installs that
        // already created the preferences table at an earlier release.
        // Idempotent: the migration short-circuits when the column is
        // already present (eg. fresh install where the create migration
        // above already added it). Publishing it unconditionally keeps
        // upgrade paths simple.
        $this->publishMigrationStub(
            StubResolver::path('add_dashboards_layout_to_user_preferences_table.php.stub'),
            'add_dashboards_layout_to_user_preferences_table'
        );

        // In-app notifications. Uses the standard Laravel
        // `notifications` table shape so any consumer-side Notification
        // class with the `database` channel delivers into the Martis
        // bell dropdown automatically. The migration is idempotent —
        // skipped when the table already exists (some apps already
        // ran `php artisan notifications:table`).
        $this->publishMigrationStub(
            StubResolver::path('create_martis_notifications_table.php.stub'),
            'create_notifications_table'
        );

        // Cache subsystem operational metadata. The version counter,
        // `cleared_at` timestamp, and runtime override flag live in a
        // dedicated table so they survive Cache::flush(),
        // redis-cli FLUSHDB, container restarts, and LRU eviction.
        // v1.8.8 — fixes a long-standing visibility bug where the
        // admin UI lost its "V N · cleared at Y" trail every time
        // the cache backend was wiped. Idempotent.
        $this->publishMigrationStub(
            StubResolver::path('create_martis_cache_state_table.php.stub'),
            'create_martis_cache_state_table'
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
                StubResolver::path('add_profile_picture_column.php.stub'),
                'add_martis_profile_picture_column_to_users_table',
                fn (string $stub): string => str_replace('profile_picture', $options['avatar_column'], $stub)
            );

            $this->components->twoColumnDetail('<fg=green>Profile</> support', "avatar column: {$options['avatar_column']}");
        }

        if ($options['two_factor_enabled']) {
            $this->publishMigrationStub(
                StubResolver::path('add_two_factor_columns.php.stub'),
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

    /**
     * Publish `app/Providers/MartisServiceProvider.php` and wire it
     * into the application's provider list. Idempotent: the file is
     * never overwritten without `--force`, and the bootstrap entry is
     * only appended when missing.
     *
     * The provider hosts host-app registrations that cannot live in
     * config — main menu, dashboards, cache layers, gate definitions.
     * Every section in the stub is commented-out, so an unmodified
     * stub registers nothing and Martis runs on its built-in defaults.
     */
    protected function publishServiceProvider(): void
    {
        $stubPath = StubResolver::path('MartisServiceProvider.php.stub');

        if (! file_exists($stubPath)) {
            $this->components->warn('MartisServiceProvider stub not found. Skipping.');

            return;
        }

        $target = app_path('Providers/MartisServiceProvider.php');
        $forceProvider = (bool) $this->option('force-provider');

        if (file_exists($target) && ! $forceProvider) {
            // v1.10.2+ separates --force (scaffold) from --force-provider
            // (provider). The default --force flag refreshes the
            // extension scaffold but never overwrites the host app's
            // provider, where dashboards, menu, gates, and cache
            // layers are registered. Re-publish only when the consumer
            // explicitly opts in with --force-provider.
            $this->components->twoColumnDetail(
                '<fg=yellow>Skipping</> provider',
                'app/Providers/MartisServiceProvider.php already exists (use --force-provider to overwrite — destroys customisations)',
            );
        } else {
            $existed = file_exists($target);
            $filesystem = new Filesystem;
            $filesystem->ensureDirectoryExists(dirname($target));
            $filesystem->put($target, (string) file_get_contents($stubPath));

            $action = $existed && $forceProvider ? '<fg=green>Updated</>' : '<fg=green>Created</>';
            $this->components->twoColumnDetail($action.' provider', 'app/Providers/MartisServiceProvider.php');
        }

        $this->registerProviderInBootstrap();
    }

    /**
     * Append the provider to `bootstrap/providers.php` (Laravel 11+)
     * or `config/app.php → providers` (Laravel 10) when missing.
     * No-op when the entry is already there.
     */
    protected function registerProviderInBootstrap(): void
    {
        $providerClass = 'App\\Providers\\MartisServiceProvider::class';

        // Laravel 11+ — bootstrap/providers.php is the canonical list.
        $bootstrapPath = base_path('bootstrap/providers.php');
        if (file_exists($bootstrapPath)) {
            $contents = (string) file_get_contents($bootstrapPath);
            if (str_contains($contents, 'MartisServiceProvider')) {
                return;
            }

            $updated = (string) preg_replace(
                '/return\s*\[\s*/',
                "return [\n    {$providerClass},\n",
                $contents,
                1,
            );

            if ($updated !== $contents) {
                file_put_contents($bootstrapPath, $updated);
                $this->components->twoColumnDetail('<fg=green>Registered</> provider', 'bootstrap/providers.php');
            }

            return;
        }

        // Laravel 10 fallback — config/app.php providers array.
        $configPath = config_path('app.php');
        if (! file_exists($configPath)) {
            return;
        }

        $contents = (string) file_get_contents($configPath);
        if (str_contains($contents, 'MartisServiceProvider')) {
            return;
        }

        $updated = (string) preg_replace(
            '/(App\\\\Providers\\\\AppServiceProvider::class,)/',
            "$1\n        {$providerClass},",
            $contents,
            1,
        );

        if ($updated !== $contents) {
            file_put_contents($configPath, $updated);
            $this->components->twoColumnDetail('<fg=green>Registered</> provider', 'config/app.php');
        }
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
            // v1.9.0+ runtime extension loader. The default URL points
            // at the bundle `npm run build:extensions` produces in
            // `public/vendor/martis-user/extensions.js`.
            'MARTIS_EXTENSIONS' => '/vendor/martis-user/extensions.js',
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

    /**
     * Publish the consumer-extension build scaffold (v1.9.0+).
     *
     * Drops `vite.extensions.config.ts`, `tsconfig.extensions.json`,
     * `resources/js/martis-extensions/index.ts`, and the four bucket
     * directories (`tools/`, `fields/`, `cards/`, `overrides/`) into
     * the consumer app. Also adds the `build:extensions` script to
     * `package.json` if a `package.json` exists in the project root.
     *
     * Idempotent: every existing file is left in place unless the
     * dev passes `--force`. Detection of a stale legacy `boot.ts`
     * (pre-v1.9 mechanism) emits a warning so the dev knows the file
     * is now ignored.
     */
    protected function publishExtensionsScaffold(): void
    {
        $filesystem = new Filesystem;
        $force = (bool) $this->option('force');

        // Legacy boot.ts detector — pre-v1.9 hook removed in v1.8.19.
        // We don't touch the file; we just warn the dev so they can
        // delete or migrate it.
        $legacyBoot = base_path('resources/js/martis/boot.ts');
        if ($filesystem->exists($legacyBoot)) {
            $this->components->warn('Detected legacy resources/js/martis/boot.ts — that mechanism was removed in v1.8.19.');
            $this->line('  The new auto-discovery loader picks up TSX from <fg=cyan>resources/js/martis-extensions/</> instead.');
            $this->line('  Move your component registrations there or delete boot.ts when ready.');
        }

        // Use packagePath() directly: the extension scaffold ships
        // as a tree (vite config, tsconfig, index entry, bucket
        // .gitkeeps), and `StubResolver::path()` only resolves single
        // files. Override individual stubs by publishing them under
        // `stubs/martis/extensions/<file>` per consumer if needed.
        $stubBase = StubResolver::packagePath('extensions');

        $files = [
            'vite.extensions.config.ts.stub' => 'vite.extensions.config.ts',
            'tsconfig.extensions.json.stub' => 'tsconfig.extensions.json',
            'index.ts.stub' => 'resources/js/martis-extensions/index.ts',
            // v1.9.3+ React shims that the consumer's vite alias
            // resolves at build time so the bundle does not emit
            // bare `import "react"` (which the browser refuses to
            // load with "Failed to resolve module specifier 'react'").
            'react-shim.mjs.stub' => 'resources/js/martis-extensions/.shims/react.mjs',
            'react-jsx-runtime-shim.mjs.stub' => 'resources/js/martis-extensions/.shims/react-jsx-runtime.mjs',
            // v1.10.0+ runtime shims. Together they let override
            // stubs `import {useAuth, AuthFrame, useNavigate, ...}`
            // from any of `@martis/runtime`, `react-router-dom`,
            // `react-i18next`, `@tanstack/react-query` without the
            // consumer needing to npm install those modules — the
            // host SPA bundles them and exposes the runtime surface
            // on `window.Martis.runtime`.
            'runtime-shim.mjs.stub' => 'resources/js/martis-extensions/.shims/runtime.mjs',
            'react-router-dom-shim.mjs.stub' => 'resources/js/martis-extensions/.shims/react-router-dom.mjs',
            'react-i18next-shim.mjs.stub' => 'resources/js/martis-extensions/.shims/react-i18next.mjs',
            'tanstack-react-query-shim.mjs.stub' => 'resources/js/martis-extensions/.shims/tanstack-react-query.mjs',
        ];

        foreach ($files as $stubName => $relativeTarget) {
            $source = $stubBase.'/'.$stubName;
            if (! $filesystem->exists($source)) {
                continue;
            }
            $target = base_path($relativeTarget);

            if ($filesystem->exists($target) && ! $force) {
                $this->components->twoColumnDetail('<fg=yellow>Skipping</> '.$relativeTarget, 'already exists (use --force to overwrite)');

                continue;
            }

            $filesystem->ensureDirectoryExists(dirname($target), 0755);
            $filesystem->put($target, (string) $filesystem->get($source));
            $this->components->twoColumnDetail('<fg=green>Published</> '.$relativeTarget, $stubName);
        }

        $buckets = ['tools', 'fields', 'cards', 'overrides'];
        foreach ($buckets as $bucket) {
            $bucketPath = base_path('resources/js/martis-extensions/'.$bucket);
            if (! is_dir($bucketPath)) {
                $filesystem->ensureDirectoryExists($bucketPath, 0755);
                $filesystem->put($bucketPath.'/.gitkeep', '');
                $this->components->twoColumnDetail('<fg=green>Creating</> directory', 'resources/js/martis-extensions/'.$bucket);
            }
        }

        $this->updatePackageJsonScripts($filesystem);
        $this->updatePackageJsonDeps($filesystem);
        $this->detectLegacyViteConfig($filesystem);
    }

    /**
     * Add the `build:extensions` script to the consumer's
     * `package.json` if not already present. Skipped when there is no
     * `package.json` (some setups do without npm) or when the script
     * is already wired up.
     */
    protected function updatePackageJsonScripts(Filesystem $filesystem): void
    {
        $path = base_path('package.json');
        if (! $filesystem->exists($path)) {
            return;
        }

        $contents = (string) $filesystem->get($path);
        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($contents, true);
        if (! is_array($decoded)) {
            $this->components->warn('Could not parse package.json — skipping the build:extensions script update.');

            return;
        }

        /** @var array<string, mixed> $scripts */
        $scripts = is_array($decoded['scripts'] ?? null) ? $decoded['scripts'] : [];

        if (isset($scripts['build:extensions'])) {
            return;
        }

        $scripts['build:extensions'] = 'vite build --config vite.extensions.config.ts';
        ksort($scripts);
        $decoded['scripts'] = $scripts;

        $filesystem->put($path, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $this->components->twoColumnDetail('<fg=green>Updated</> package.json', 'added build:extensions script');
    }

    /**
     * Minimum npm dependencies the consumer-extension build needs
     * (v1.10.0+). Versions track what the host package itself ships
     * to keep peer-dep compat predictable.
     *
     * `react-router-dom`, `react-i18next` and `@tanstack/react-query`
     * are intentionally NOT in this list — the package re-exposes
     * them via `window.Martis.runtime` and the consumer's vite
     * shims resolve bare imports without npm-installing them. See
     * `vite.extensions.config.ts.stub` for the alias map.
     *
     * @var array{dependencies: array<string, string>, devDependencies: array<string, string>}
     */
    private const EXTENSION_NPM_DEPS = [
        'dependencies' => [
            'react' => '^18 || ^19',
            'react-dom' => '^18 || ^19',
        ],
        'devDependencies' => [
            '@vitejs/plugin-react' => '^4 || ^5 || ^6',
            'vite' => '^5 || ^6 || ^7 || ^8',
            'typescript' => '^5 || ^6',
            '@types/react' => '^18 || ^19',
            '@types/react-dom' => '^18 || ^19',
            '@types/node' => '^20 || ^22 || ^25',
            '@phosphor-icons/react' => '^2',
        ],
    ];

    /**
     * Add the consumer-extension npm packages (`react`, `react-dom`,
     * `@vitejs/plugin-react`, `vite`, etc.) to `package.json` if they
     * are not already declared. Only writes the entries the consumer
     * lacks — never bumps an existing version, never touches an
     * unrelated key.
     *
     * Why this exists: prior to v1.10 `martis:install` published a
     * `vite.extensions.config.ts` that imports `@vitejs/plugin-react`
     * but did not add it as a devDependency. Fresh laravel apps
     * therefore failed the very first `npm run build:extensions` with
     * `Cannot find package '@vitejs/plugin-react'`. This method
     * closes that gap.
     */
    protected function updatePackageJsonDeps(Filesystem $filesystem): void
    {
        $path = base_path('package.json');
        if (! $filesystem->exists($path)) {
            return;
        }

        $contents = (string) $filesystem->get($path);
        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($contents, true);
        if (! is_array($decoded)) {
            $this->components->warn('Could not parse package.json — skipping dependency updates.');

            return;
        }

        $added = [];

        foreach (self::EXTENSION_NPM_DEPS as $section => $entries) {
            /** @var array<string, mixed> $current */
            $current = is_array($decoded[$section] ?? null) ? $decoded[$section] : [];

            foreach ($entries as $name => $constraint) {
                if (isset($current[$name])) {
                    continue;
                }
                $current[$name] = $constraint;
                $added[] = "{$section}:{$name}";
            }

            ksort($current);
            $decoded[$section] = $current;
        }

        if ($added === []) {
            return;
        }

        $filesystem->put($path, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $this->components->twoColumnDetail(
            '<fg=green>Updated</> package.json',
            'added '.count($added).' npm dep(s) for the extension build',
        );
        $this->line('  Run <fg=cyan>npm install</> to materialise them before the next <fg=cyan>npm run build:extensions</>.');
    }

    /**
     * Detect a legacy `vite.extensions.config.ts` that still uses the
     * v1.9.0–v1.9.2 broken approach (`rollupOptions.external` +
     * `output.globals`) and warn the dev to re-run install with
     * `--force` so the v1.9.3+ shim-based config replaces it.
     *
     * Skipped silently when no config exists yet, when `--force` is
     * passed (which already overwrote it), or when the config is
     * already on the new shape.
     */
    protected function detectLegacyViteConfig(Filesystem $filesystem): void
    {
        $configPath = base_path('vite.extensions.config.ts');
        if (! $filesystem->exists($configPath) || (bool) $this->option('force')) {
            return;
        }

        $contents = (string) $filesystem->get($configPath);

        // The v1.9.0–v1.9.2 stub used `external: ['react'`. The v1.9.3+
        // stub uses `alias: [` and a `reactShim` const. If we see the
        // legacy shape and not the new one, warn.
        $isLegacy = str_contains($contents, "external: ['react'") && ! str_contains($contents, 'reactShim');

        if (! $isLegacy) {
            return;
        }

        $this->components->warn('Detected legacy v1.9.0–v1.9.2 vite.extensions.config.ts.');
        $this->line('  The previous shape used `rollupOptions.output.globals` to externalise React, but');
        $this->line('  `globals` is silently ignored for ES module output — the resulting bundle ships');
        $this->line('  bare `import "react"` and the browser refuses to load it.');
        $this->line('  Re-run <fg=cyan>php artisan martis:install --force</> to publish the v1.10+ shim-based config.');
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
