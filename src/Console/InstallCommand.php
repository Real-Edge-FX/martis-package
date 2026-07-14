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
                            {--with-profile : Enable profile support (publishes the avatar migration on the host users table)}
                            {--no-profile : Disable profile support, even when running interactively. Wins over --with-profile.}
                            {--with-2fa : Enable two-factor authentication support (publishes the two-factor migration on the host users table)}
                            {--no-2fa : Disable 2FA support, even when running interactively. Wins over --with-2fa.}
                            {--with-sessions : Publish the key-type-aware sessions table migration for the browser-sessions profile section (requires SESSION_DRIVER=database)}
                            {--no-sessions : Skip the sessions table migration, even when the sessions section is active. Wins over --with-sessions.}
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
        $this->preflightSessions();

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
     *     two_factor_enabled: bool,
     *     sessions_enabled: bool
     * }
     */
    protected function resolveInstallOptions(): array
    {
        // Two-layer interactivity gate. The Symfony default
        // `$this->input->isInteractive()` is true unless the operator
        // passed `--no-interaction`, but docker-compose / CI pipes
        // routinely strip the TTY without setting that flag. When that
        // happens, `confirm(..., true)` silently returns the destructive
        // default ("yes, alter the host users table"). Treat the command
        // as truly interactive only when BOTH Symfony agrees AND stdin
        // is an actual TTY. Tests stay in non-interactive land via the
        // runningUnitTests() escape hatch.
        $interactive = $this->input->isInteractive()
            && ! app()->runningUnitTests()
            && $this->stdinIsTty();

        // Explicit flags win over everything. --no-* trumps --with-* so
        // automation that previously set --with-profile to opt-in can
        // be safely overridden in a follow-up call without editing the
        // command line.
        $profileFlag = $this->option('with-profile');
        $profileOptOut = $this->option('no-profile');
        $twoFactorFlag = $this->option('with-2fa');
        $twoFactorOptOut = $this->option('no-2fa');

        // Config-as-source-of-truth. A consumer that set
        // MARTIS_PROFILE_ENABLED=false (or 2FA_ENABLED=false) is making
        // a deliberate "do not touch my users table" statement; honour
        // it even when running interactively.
        $profileConfigEnabled = (bool) config('martis.profile.enabled', true);
        $twoFactorConfigEnabled = (bool) config('martis.profile.two_factor.enabled', true);

        $profileEnabled = $this->resolveFeatureToggle(
            optOut: (bool) $profileOptOut,
            optIn: (bool) $profileFlag,
            interactive: $interactive,
            configEnabled: $profileConfigEnabled,
            prompt: 'Would you like to enable the Martis Profile feature?',
        );

        $twoFactorEnabled = $this->resolveFeatureToggle(
            optOut: (bool) $twoFactorOptOut,
            optIn: (bool) $twoFactorFlag,
            interactive: $interactive,
            configEnabled: $twoFactorConfigEnabled,
            // Defer to whatever Profile resolved to — 2FA without Profile
            // makes no sense in the published-migration sense (the 2FA
            // migration ALTERs the same users table the profile flow
            // does), but the boolean stays independent so apps that
            // wire their own profile UI can still opt into 2FA.
            prompt: 'Would you like to enable the Martis 2FA feature?',
        );

        $avatarEnabled = false;
        $publishAvatarMigration = false;
        $avatarColumn = 'profile_picture';

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

        // Browser-sessions provisioning. The 'sessions' section has no enable
        // flag of its own — membership in `profile.sections` is the switch — so
        // that membership is the config source of truth for the IMPLICIT case.
        // Explicit flags are absolute and bypass the section gate: --no-sessions
        // never provisions; --with-sessions always does (a host may want the
        // table even without the section). Otherwise fold into --with-profile
        // only when the section is active, so a host that removed 'sessions'
        // (keeping SESSION_DRIVER=file) is never handed an unwanted migration.
        $sessionsSectionActive = in_array('sessions', (array) config('martis.profile.sections', []), true);
        if ($this->option('no-sessions')) {
            $sessionsEnabled = false;
        } elseif ($this->option('with-sessions')) {
            $sessionsEnabled = true;
        } else {
            $sessionsEnabled = $this->resolveFeatureToggle(
                optOut: false,
                optIn: (bool) $profileFlag && $sessionsSectionActive,
                interactive: $interactive,
                configEnabled: $sessionsSectionActive,
                prompt: 'The browser-sessions profile section needs a `sessions` table. Publish the migration?',
            );
        }

        return [
            'profile_enabled' => $profileEnabled,
            'avatar_enabled' => $avatarEnabled,
            'avatar_column' => $avatarColumn,
            'publish_avatar_migration' => $publishAvatarMigration,
            'two_factor_enabled' => $twoFactorEnabled,
            'sessions_enabled' => $sessionsEnabled,
        ];
    }

    /**
     * True only when stdin is a real TTY. `docker compose exec -T` and
     * piped CI invocations strip the PTY without passing
     * `--no-interaction`, so Symfony's `$input->isInteractive()` alone
     * is unreliable for "should I block on a prompt?". Falling back to
     * `posix_isatty()` when `stream_isatty()` is unavailable keeps the
     * detection working on older or non-POSIX runtimes.
     */
    protected function stdinIsTty(): bool
    {
        if (! defined('STDIN')) {
            return false;
        }

        if (function_exists('stream_isatty')) {
            return @stream_isatty(STDIN);
        }

        if (function_exists('posix_isatty')) {
            return @posix_isatty(STDIN);
        }

        return false;
    }

    /**
     * Combine the four signals (explicit opt-out, explicit opt-in,
     * interactive prompt, and config default) into a single boolean
     * "should this feature be enabled?" Precedence, in order:
     *
     *   1. Explicit `--no-*` opt-out: always wins, returns false.
     *   2. Config disabled (e.g. `MARTIS_PROFILE_ENABLED=false`):
     *      a host that already told us "do not touch my users table"
     *      via env should not get the migration published, even when
     *      the operator is sitting at a TTY.
     *   3. Explicit `--with-*` opt-in: returns true.
     *   4. Interactive with no flag: prompt with default=true (the
     *      historical behaviour, preserved for human installs).
     *   5. Non-interactive with no flag: returns false — automation
     *      must opt into schema-altering features explicitly.
     */
    protected function resolveFeatureToggle(
        bool $optOut,
        bool $optIn,
        bool $interactive,
        bool $configEnabled,
        string $prompt,
    ): bool {
        if ($optOut) {
            return false;
        }

        if (! $configEnabled) {
            return false;
        }

        if ($optIn) {
            return true;
        }

        if ($interactive) {
            return (bool) $this->confirm($prompt, true);
        }

        return false;
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
        // Delegate to the hardened `martis:publish-assets` path: a deterministic
        // full-tree copy plus a post-publish manifest check that fails loudly on
        // an incomplete set (a partial copy renders the admin as a black screen).
        // The command guards the "assets missing from this release" case itself
        // and wipes the destination first, so a fresh install and a --force
        // reinstall both get the completeness guarantee without accumulating
        // stale Vite chunks from prior versions.
        if ($this->call('martis:publish-assets') !== self::SUCCESS) {
            throw new RuntimeException('Martis frontend assets did not publish successfully. See the errors above.');
        }
    }

    protected function publishCoreMigrations(): void
    {
        $this->publishMigrationStub(
            StubResolver::path('create_martis_action_events_table.php.stub'),
            'create_martis_action_events_table'
        );

        // v1.14.2 — convert the three polymorphic morph id columns on
        // `martis_action_events` from `bigint` to `string`. Hosts that
        // installed pre-v1.14.2 had the audit log silently drop every
        // row for UUID/ULID-keyed models; new installs already land
        // on string via the create stub above, so this migration is a
        // self-detected no-op for them.
        //
        // NB: the v1.14.2 release of this migration shipped with a
        // broken type-detection guard (Postgres returns `int8` /
        // `int4` / `int2` from the native introspector, which the
        // guard misclassified as "already string" and skipped). The
        // v1.14.3 follow-up below applies the actual conversion under
        // a NEW filename so consumers who already ran v1.14.2's
        // broken alter — which is now permanently recorded in their
        // `migrations` table — converge to the correct schema.
        $this->publishMigrationStub(
            StubResolver::path('alter_martis_action_events_morph_ids_to_string.php.stub'),
            'alter_martis_action_events_morph_ids_to_string'
        );

        // v1.14.3 — re-applies the morph id type conversion. Drops the
        // broken guard and ALTERs unconditionally (varchar→varchar is
        // a free no-op on every supported driver).
        $this->publishMigrationStub(
            StubResolver::path('fix_martis_action_events_morph_ids_string_v2.php.stub'),
            'fix_martis_action_events_morph_ids_string_v2'
        );

        // User preferences (theme/accent/density/locale/reduced-motion).
        // The preferences resolver falls back to config defaults if this
        // migration is never run, so the table is core but not strictly
        // blocking for apps that disable the feature.
        $this->publishMigrationStub(
            StubResolver::path('create_user_preferences_table.php.stub'),
            'create_martis_user_preferences_table'
        );

        // v1.10.5 — drop the retracted `dashboards_layout` column. Only
        // does work on hosts that ran the v1.10.4 column-add; otherwise
        // a no-op. Bundled into the standard install so v1.10.4 → v1.10.5
        // upgrades self-clean without manual `vendor:publish` calls.
        $this->publishMigrationStub(
            StubResolver::path('drop_dashboards_layout_from_user_preferences_table.php.stub'),
            'drop_dashboards_layout_from_user_preferences_table'
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
     *     two_factor_enabled: bool,
     *     sessions_enabled: bool
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

        if ($options['sessions_enabled']) {
            $this->publishMigrationStub(
                StubResolver::path('create_sessions_table.php.stub'),
                'create_sessions_table'
            );

            $this->components->twoColumnDetail('<fg=green>Sessions</> support', 'sessions migration published (SESSION_DRIVER=database)');
        }
    }

    /**
     * Surface the browser-sessions DB dependency at setup time instead of only
     * at runtime. When the 'sessions' profile section is active but the app is
     * not on the `database` session driver, the section silently degrades to a
     * `supported: false` empty state — warn (never fail) with the fix.
     */
    protected function preflightSessions(): void
    {
        $sessionsSectionActive = in_array('sessions', (array) config('martis.profile.sections', []), true);
        if (! $sessionsSectionActive) {
            return;
        }

        $driver = config('session.driver');
        if ($driver === 'database') {
            return;
        }

        $this->newLine();
        $this->components->warn(
            "The 'sessions' profile section requires SESSION_DRIVER=database and a sessions table. Current driver: "
            .(is_string($driver) ? $driver : 'unset').'.'
        );
        $this->line('  Run <fg=cyan>php artisan martis:install --with-sessions</> (or <fg=cyan>php artisan session:table</>) and set <fg=cyan>SESSION_DRIVER=database</>,');
        $this->line("  or remove 'sessions' from <fg=cyan>config/martis.php → profile.sections</> to hide the section.");
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
     *     two_factor_enabled: bool,
     *     sessions_enabled: bool
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
     * (v1.10.0+) that are NOT version-coupled to the host's Vite
     * major. Versions track what the host package itself ships to
     * keep peer-dep compat predictable.
     *
     * `react-router-dom`, `react-i18next` and `@tanstack/react-query`
     * are intentionally NOT in this list — the package re-exposes
     * them via `window.Martis.runtime` and the consumer's vite
     * shims resolve bare imports without npm-installing them. See
     * `vite.extensions.config.ts.stub` for the alias map.
     *
     * The `vite` + `@vitejs/plugin-react` pair is resolved separately
     * by {@see resolveVitePluginReactPair()} because the two are
     * peer-dep-coupled and we have to honour whichever Vite major
     * the host Laravel scaffold shipped.
     *
     * @var array{dependencies: array<string, string>, devDependencies: array<string, string>}
     */
    private const STABLE_NPM_DEPS = [
        'dependencies' => [
            'react' => '^18 || ^19',
            'react-dom' => '^18 || ^19',
        ],
        'devDependencies' => [
            'typescript' => '^5 || ^6',
            '@types/react' => '^18 || ^19',
            '@types/react-dom' => '^18 || ^19',
            '@types/node' => '^20 || ^22 || ^25',
            '@phosphor-icons/react' => '^2',
        ],
    ];

    /**
     * Maps the host's Vite major version to a known-compatible
     * `@vitejs/plugin-react` range. Each row reflects the
     * `peerDependencies.vite` declared by the plugin author on
     * the registry at the time the row was added.
     *
     * | plugin-react | peerDependencies.vite                    |
     * |--------------|-------------------------------------------|
     * | ^4           | ^4.2.0 \|\| ^5.0.0                         |
     * | ^5           | ^4.2.0 \|\| ^5.0.0 \|\| ^6.0.0 \|\| ^7.0.0 |
     * | ^6           | ^8.0.0                                    |
     *
     * Add a new row when a new pair stabilises. A Vite major missing
     * from the table makes {@see resolveVitePluginReactPair()} skip
     * the auto-bump and emit a loud warning with a snippet the user
     * can paste — never silently picks a wrong range.
     *
     * @var array<int, string>
     */
    private const VITE_COMPAT_TABLE = [
        4 => '^4',
        5 => '^4 || ^5',
        6 => '^5',
        7 => '^5',
        8 => '^6',
        9 => '^7',
    ];

    /**
     * Default Vite major used when the host `package.json` has no
     * `vite` entry at all (very fresh app, or non-Laravel scaffold).
     * Updated together with the active Laravel scaffold.
     */
    private const DEFAULT_VITE_MAJOR = 7;

    /**
     * Optional escape hatch. When the env var is set to a non-empty
     * semver range, the resolver uses that range for the
     * `@vitejs/plugin-react` entry verbatim, regardless of the
     * compat table. Lets operators unblock fresh installs against a
     * Vite major Martis does not yet know about, e.g.:
     *
     *   MARTIS_PLUGIN_REACT_RANGE='^7' php artisan martis:install
     */
    private const ENV_OVERRIDE_KEY = 'MARTIS_PLUGIN_REACT_RANGE';

    /**
     * Pure resolver for the `vite` + `@vitejs/plugin-react` pair.
     *
     * Returns a decision the caller can apply against a `package.json`:
     *
     * - `vite_range`         — the Vite range to write when the host
     *                          has none. Mirrors the host range when
     *                          present (we never overwrite vite).
     * - `plugin_react_range` — the `@vitejs/plugin-react` range to
     *                          write when the host has none, or null
     *                          when the resolver refuses to pick a
     *                          range (unknown vite major / invalid
     *                          env override).
     * - `source`             — `env`, `table`, `default`, `env-invalid`,
     *                          `unknown-vite`, or `parse-failed`. Tags
     *                          the decision so tests and callers can
     *                          assert behaviour without parsing prose.
     * - `warnings`           — list of human-readable warnings to
     *                          surface to the operator. Non-empty only
     *                          when the resolver is skipping the
     *                          plugin-react write or ignoring a
     *                          mal-formed env override.
     *
     * The function is static + pure (no I/O, no DB, no clock) so the
     * test matrix can cover every branch quickly.
     *
     * @return array{vite_range: string, plugin_react_range: ?string, source: string, warnings: list<string>}
     */
    public static function resolveVitePluginReactPair(
        ?string $hostViteRange,
        ?string $envOverride,
    ): array {
        $warnings = [];
        $defaultViteRange = '^'.self::DEFAULT_VITE_MAJOR;
        $hostHasVite = $hostViteRange !== null && trim($hostViteRange) !== '';
        $effectiveViteRange = $hostHasVite ? (string) $hostViteRange : $defaultViteRange;

        $envOverride = $envOverride === null ? null : trim($envOverride);
        if ($envOverride !== null && $envOverride !== '') {
            if (! self::looksLikeSemverRange($envOverride)) {
                $warnings[] = sprintf(
                    'Ignored %s=%s: not a valid semver range. Skipped adding @vitejs/plugin-react to package.json.',
                    self::ENV_OVERRIDE_KEY,
                    $envOverride,
                );

                return [
                    'vite_range' => $effectiveViteRange,
                    'plugin_react_range' => null,
                    'source' => 'env-invalid',
                    'warnings' => $warnings,
                ];
            }

            return [
                'vite_range' => $effectiveViteRange,
                'plugin_react_range' => $envOverride,
                'source' => 'env',
                'warnings' => $warnings,
            ];
        }

        if (! $hostHasVite) {
            return [
                'vite_range' => $defaultViteRange,
                'plugin_react_range' => self::VITE_COMPAT_TABLE[self::DEFAULT_VITE_MAJOR],
                'source' => 'default',
                'warnings' => $warnings,
            ];
        }

        $major = self::extractMajor((string) $hostViteRange);
        if ($major === null) {
            $warnings[] = sprintf(
                'Could not parse the host Vite constraint (%s). Skipped adding @vitejs/plugin-react. '
                .'Set %s explicitly (e.g. %s="^5") or pin a known Vite major in package.json.',
                $hostViteRange,
                self::ENV_OVERRIDE_KEY,
                self::ENV_OVERRIDE_KEY,
            );

            return [
                'vite_range' => $effectiveViteRange,
                'plugin_react_range' => null,
                'source' => 'parse-failed',
                'warnings' => $warnings,
            ];
        }

        if (! isset(self::VITE_COMPAT_TABLE[$major])) {
            $floor = min(array_keys(self::VITE_COMPAT_TABLE));
            $ceiling = max(array_keys(self::VITE_COMPAT_TABLE));
            $warnings[] = sprintf(
                'Vite ^%d is not in this Martis release\'s @vitejs/plugin-react compat table '
                .'(known range: ^%d..^%d). Skipped adding @vitejs/plugin-react to package.json. '
                .'To unblock without upgrading Martis, find a plugin-react release whose '
                .'peerDependencies.vite covers ^%d and re-run with %s set, e.g.: '
                .'%s="^7" php artisan martis:install',
                $major,
                $floor,
                $ceiling,
                $major,
                self::ENV_OVERRIDE_KEY,
                self::ENV_OVERRIDE_KEY,
            );

            return [
                'vite_range' => $effectiveViteRange,
                'plugin_react_range' => null,
                'source' => 'unknown-vite',
                'warnings' => $warnings,
            ];
        }

        return [
            'vite_range' => $effectiveViteRange,
            'plugin_react_range' => self::VITE_COMPAT_TABLE[$major],
            'source' => 'table',
            'warnings' => $warnings,
        ];
    }

    /**
     * Loose validation: accepts any string composed of digits, dots,
     * carets, tildes, comparison operators, pipes, spaces, hyphens
     * or the `x` wildcard. Designed to catch obvious typos while
     * letting npm itself reject anything subtler at install time.
     */
    private static function looksLikeSemverRange(string $range): bool
    {
        return (bool) preg_match('/^[\s\^~><=*\d.|\-x]+$/i', $range);
    }

    /**
     * Picks the major Vite version `npm install` would resolve the
     * host's range to. For caret OR chains (`^5 || ^6 || ^7`) the
     * highest caret wins, mirroring npm's "max satisfying" rule.
     * For other shapes (tildes, comparator pairs) we fall back to
     * the first integer in the string, which covers the realistic
     * cases (`~7.0.0`, `>=7 <8`).
     */
    private static function extractMajor(string $range): ?int
    {
        $carets = [];
        if (preg_match_all('/\^(\d+)/', $range, $matches) > 0) {
            $carets = array_map('intval', $matches[1]);
        }
        if ($carets !== []) {
            return max($carets);
        }
        if (preg_match('/(\d+)/', $range, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

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
     *
     * The `vite` + `@vitejs/plugin-react` pair is resolved through
     * {@see resolveVitePluginReactPair()} so the constraint we write
     * always matches the host's Vite major. When the host runs a
     * Vite major Martis does not know about, the resolver returns
     * `plugin_react_range: null` and we skip the auto-bump instead
     * of writing an incompatible range silently. Operators can
     * always force a range via the `MARTIS_PLUGIN_REACT_RANGE` env.
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

        foreach (self::STABLE_NPM_DEPS as $section => $entries) {
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

        // `devDependencies` is guaranteed to exist at this point: the
        // STABLE_NPM_DEPS loop above seeded the array even when the
        // host package.json had no `devDependencies` key.
        /** @var array<string, mixed> $devDeps */
        $devDeps = $decoded['devDependencies'];

        $hostViteRange = isset($devDeps['vite']) && is_string($devDeps['vite'])
            ? $devDeps['vite']
            : null;
        $envOverride = getenv(self::ENV_OVERRIDE_KEY);
        $envOverride = $envOverride === false ? null : $envOverride;

        $resolution = self::resolveVitePluginReactPair($hostViteRange, $envOverride);

        foreach ($resolution['warnings'] as $warning) {
            $this->components->warn($warning);
        }

        if (! isset($devDeps['vite'])) {
            $devDeps['vite'] = $resolution['vite_range'];
            $added[] = 'devDependencies:vite';
        }

        if ($resolution['plugin_react_range'] !== null && ! isset($devDeps['@vitejs/plugin-react'])) {
            $devDeps['@vitejs/plugin-react'] = $resolution['plugin_react_range'];
            $added[] = 'devDependencies:@vitejs/plugin-react';
        }

        ksort($devDeps);
        $decoded['devDependencies'] = $devDeps;

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
        // --force so the install never stalls on Laravel's "Do you really
        // wish to run this command?" prompt in production / CI / non-interactive
        // shells. martis:install is itself the confirmation step.
        $this->call('migrate', ['--force' => true]);
        $this->components->twoColumnDetail('<fg=green>Executed</> migrations', 'Martis database changes applied');
    }
}
