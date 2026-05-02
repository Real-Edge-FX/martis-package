<?php

declare(strict_types=1);

namespace Martis\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Martis\Stubs\StubResolver;
use Symfony\Component\Process\Process;

/**
 * `martis:sso <provider>` — scaffold an SSO provider in the host app.
 *
 * Concretely, the command:
 *
 *  1. Adds (or updates) the `auth.sso.providers.{name}` block in
 *     `config/martis.php`. Idempotent — re-running just refreshes
 *     the block.
 *  2. Stubs the matching env vars in `.env` and `.env.example`.
 *  3. Optionally publishes a migration adding the `{name}_group_name`
 *     column to the `roles` table (`--with-migration`).
 *  4. Optionally pre-sets the `permission_adapter` to `spatie`
 *     (`--with-spatie`).
 *  5. Prints the next manual steps (composer require, IdP portal).
 *
 * Currently ships scaffolding for `azure`, `google`, `github`. Custom
 * providers can be wired manually via `MartisSso::extend()` and run
 * `martis:sso <name> --custom`.
 */
class SsoMakeCommand extends Command
{
    protected $signature = 'martis:sso
                            {provider : Provider name (azure, google, github, or a custom name)}
                            {--with-spatie : Default the permission adapter to "spatie" + install spatie/laravel-permission if missing}
                            {--with-migration : Publish a migration adding {provider}_group_name to roles}
                            {--strategy=column : Default role mapping strategy (column|config|callable)}
                            {--no-auto-create-user : Disable auto user provisioning}
                            {--custom : Treat the provider as a custom one (no built-in scopes / driver)}
                            {--no-composer : Skip the composer require step (deps must already be installed)}
                            {--no-migrate : Skip the php artisan migrate step}
                            {--no-listener : Skip auto-registering the Socialite extension listener in AppServiceProvider}
                            {--no-publish-spatie : Skip publishing the Spatie permission config + migrations}
                            {--no-map : Skip the interactive role-mapping prompt}';

    protected $description = 'Scaffold an SSO provider end-to-end — composer deps, config, env, listener, migrations.';

    private const KNOWN_PROVIDERS = ['azure', 'google', 'github'];

    public function handle(): int
    {
        $name = strtolower((string) $this->argument('provider'));
        if ($name === '') {
            $this->error('Provider name is required.');

            return self::INVALID;
        }

        $isKnown = in_array($name, self::KNOWN_PROVIDERS, true);
        if (! $isKnown && ! $this->option('custom')) {
            $this->warn("Unknown provider '{$name}'. Pass --custom to scaffold a generic block.");

            return self::INVALID;
        }

        $this->components->info("Scaffolding SSO provider: {$name}");

        // 1. Composer dependencies (idempotent — skips installed packages).
        if (! $this->option('no-composer')) {
            $this->installComposerDependencies($name);
        }

        // 2. Config block + env stubs (already idempotent).
        $this->updateConfig($name);
        $this->updateEnv($name);

        // 3. Wire the Socialite extension listener (Azure-specific).
        if (! $this->option('no-listener') && $name === 'azure') {
            $this->registerSocialiteListener();
        }

        // 4. Publish Spatie's own config + migrations when --with-spatie.
        if ($this->option('with-spatie')) {
            $this->publishSpatieAssets();
        }

        // 5. Publish Martis migration (only when --with-migration).
        if ($this->option('with-migration')) {
            $this->publishMigration($name);
        }

        // 6. Run migrations (only when migration was published OR Spatie
        //    was just installed — interactive mode prompts; non-interactive
        //    mode runs unless --no-migrate).
        if (! $this->option('no-migrate') && $this->shouldRunMigrate()) {
            $this->runMigrations();
        }

        // 7. Interactive role mapping — for each existing Spatie role,
        //    prompt the operator for the matching Azure App Role display
        //    name. Skipped in non-interactive mode and via --no-map.
        if ($this->shouldOfferRoleMapping($name)) {
            $this->mapExistingRoles($name);
        }

        $this->printNextSteps($name);

        return self::SUCCESS;
    }

    /**
     * Run `composer require` for the packages a given provider needs,
     * skipping any that are already declared in the host app's
     * composer.json. Honours `--with-spatie` to also install
     * spatie/laravel-permission.
     */
    protected function installComposerDependencies(string $providerName): void
    {
        $composerJsonPath = base_path('composer.json');
        if (! file_exists($composerJsonPath)) {
            $this->components->warn('No composer.json found in the project root — skipping composer require step.');

            return;
        }

        $manifest = json_decode((string) file_get_contents($composerJsonPath), true);
        $required = array_merge(
            (array) ($manifest['require'] ?? []),
            (array) ($manifest['require-dev'] ?? []),
        );

        $packages = $this->packagesFor($providerName);

        $missing = array_values(array_filter(
            $packages,
            static fn (string $pkg): bool => ! array_key_exists($pkg, $required),
        ));

        if ($missing === []) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> composer', 'all required packages already installed');

            return;
        }

        $list = implode(' ', $missing);
        $this->components->twoColumnDetail('<fg=cyan>Installing</> composer', $list);

        // Run composer require. We surface stdout so the user sees
        // progress (composer downloads can take 30s+).
        $process = Process::fromShellCommandline(
            'composer require '.$list,
            base_path(),
            null,
            null,
            300, // 5 minute timeout
        );

        $process->run(function ($_, $line): void {
            $this->getOutput()->write($line);
        });

        if (! $process->isSuccessful()) {
            $this->components->error('composer require failed. Install the packages manually:  composer require '.$list);

            return;
        }

        $this->components->twoColumnDetail('<fg=green>Installed</> composer', $list);
    }

    /**
     * @return list<string>
     */
    protected function packagesFor(string $provider): array
    {
        $base = ['laravel/socialite'];

        $providerSpecific = match ($provider) {
            'azure' => ['socialiteproviders/microsoft'],
            'google' => [],   // Socialite ships google natively
            'github' => [],   // Socialite ships github natively
            default => [],
        };

        $spatie = $this->option('with-spatie') ? ['spatie/laravel-permission'] : [];

        return array_values(array_merge($base, $providerSpecific, $spatie));
    }

    /**
     * Add the SocialiteProviders extension listener to
     * `AppServiceProvider::boot()` if missing. Idempotent — bails out
     * when the listener line is already present.
     */
    protected function registerSocialiteListener(): void
    {
        $providerPath = app_path('Providers/AppServiceProvider.php');
        if (! file_exists($providerPath)) {
            $this->components->warn('app/Providers/AppServiceProvider.php not found — register the SocialiteProviders listener manually.');

            return;
        }

        $contents = (string) file_get_contents($providerPath);
        $needle = 'MicrosoftExtendSocialite';

        if (str_contains($contents, $needle)) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> listener', 'MicrosoftExtendSocialite already registered');

            return;
        }

        $listenerCode = "        \\Illuminate\\Support\\Facades\\Event::listen(\n".
            "            \\SocialiteProviders\\Manager\\SocialiteWasCalled::class,\n".
            "            [\\SocialiteProviders\\Microsoft\\MicrosoftExtendSocialite::class, 'handle'],\n".
            '        );';

        // Insert at the top of `boot()` — find the first `public function boot()` block.
        $updated = (string) preg_replace(
            '/(public function boot\(\)\s*:\s*void\s*\{)/',
            "$1\n".$listenerCode,
            $contents,
            1,
        );

        if ($updated === $contents) {
            $this->components->warn('Could not auto-register the listener — paste this block at the top of AppServiceProvider::boot():');
            $this->line($listenerCode);

            return;
        }

        file_put_contents($providerPath, $updated);
        $this->components->twoColumnDetail('<fg=green>Registered</> listener', 'AppServiceProvider::boot() (MicrosoftExtendSocialite)');
    }

    /**
     * Migrate is worth running when:
     *  - The user passed --with-migration (a new migration was published).
     *  - Spatie was just installed (its own published migrations need to run).
     *
     * Skipped when the host has uncommitted migrations the user might
     * be reviewing — non-interactive mode just runs.
     */
    protected function shouldRunMigrate(): bool
    {
        return (bool) $this->option('with-migration') || (bool) $this->option('with-spatie');
    }

    protected function runMigrations(): void
    {
        if ($this->input->isInteractive()
            && ! app()->runningUnitTests()
            && ! $this->confirm('Run pending migrations now?', true)) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> migrate', 'user declined');

            return;
        }

        $this->components->twoColumnDetail('<fg=cyan>Running</> migrate', 'php artisan migrate');
        $this->call('migrate', ['--force' => true]);
    }

    /**
     * Publish Spatie's permission config + migrations. Idempotent — the
     * Spatie command itself skips files that already exist unless
     * --force is passed.
     */
    protected function publishSpatieAssets(): void
    {
        if ($this->option('no-publish-spatie')) {
            return;
        }

        if (! class_exists('Spatie\\Permission\\PermissionServiceProvider')) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> spatie publish', 'spatie/laravel-permission not installed');

            return;
        }

        // Check if config + migration are already published — skip when both are.
        $configPublished = file_exists(config_path('permission.php'));
        $migrationPublished = (bool) glob(database_path('migrations/*_create_permission_tables.php'));

        if ($configPublished && $migrationPublished) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> spatie publish', 'config + migration already published');

            return;
        }

        $this->components->twoColumnDetail('<fg=cyan>Publishing</> spatie', 'config + migrations');
        $this->callSilent('vendor:publish', [
            '--provider' => 'Spatie\\Permission\\PermissionServiceProvider',
        ]);
        $this->components->twoColumnDetail('<fg=green>Published</> spatie', 'config + migrations');
    }

    /**
     * Offer to map existing Spatie roles to Azure App Role display
     * names interactively. We only run this when:
     *   - the operator passed --with-spatie (so Spatie is the target),
     *   - AND `roles` table exists with at least one row,
     *   - AND `roles.{column}` column exists (migration has run),
     *   - AND we're in interactive mode and not --no-map.
     */
    protected function shouldOfferRoleMapping(string $providerName): bool
    {
        if ($this->option('no-map')) {
            return false;
        }
        if (! $this->input->isInteractive() || app()->runningUnitTests()) {
            return false;
        }

        $cfg = config("martis.auth.sso.providers.{$providerName}", []);
        $strategy = $cfg['role_strategy'] ?? 'column';
        if ($strategy !== 'column') {
            return false;
        }

        try {
            return Schema::hasTable('roles');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Walk every row in the `roles` table and prompt the operator for
     * the matching Azure App Role display name. Empty input keeps the
     * existing value (or null). Idempotent — running twice with the
     * same answers is a no-op.
     */
    protected function mapExistingRoles(string $providerName): void
    {
        $cfg = config("martis.auth.sso.providers.{$providerName}", []);
        $column = $cfg['role_column'] ?? "{$providerName}_group_name";

        if (! Schema::hasColumn('roles', $column)) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> role mapping', "column `roles.{$column}` not present");

            return;
        }

        $roles = DB::table('roles')->select(['id', 'name', $column])->get();
        if ($roles->isEmpty()) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> role mapping', '`roles` table is empty');

            return;
        }

        $this->newLine();
        $this->line('<fg=cyan>Map your Spatie roles to Azure App Role display names</> (press Enter to keep current value):');
        $this->newLine();

        $providerLabel = ucfirst($providerName);
        $changes = 0;

        foreach ($roles as $role) {
            $current = $role->{$column};
            $prompt = "  → '{$role->name}' ({$providerLabel} display name)";

            $value = $this->ask($prompt, $current ?? '');
            $value = is_string($value) ? trim($value) : '';

            if ($value === '') {
                continue;
            }
            if ($value === ($current ?? '')) {
                continue;
            }

            DB::table('roles')
                ->where('id', $role->id)
                ->update([$column => $value]);
            $changes++;
        }

        if ($changes > 0) {
            $this->components->twoColumnDetail('<fg=green>Updated</> roles', "{$changes} role(s) mapped");
        } else {
            $this->components->twoColumnDetail('<fg=yellow>No changes</>', 'role mapping');
        }
    }

    /**
     * Add (or refresh) the `auth.sso.providers.{name}` block in
     * `config/martis.php`. Idempotent — re-running replaces the entry
     * cleanly without duplicating.
     */
    protected function updateConfig(string $name): void
    {
        $configPath = config_path('martis.php');
        if (! file_exists($configPath)) {
            $this->components->warn('config/martis.php not found — run php artisan martis:install first.');

            return;
        }

        $contents = (string) file_get_contents($configPath);
        $block = $this->renderConfigBlock($name);

        if (str_contains($contents, "'{$name}' => [")) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> config', "auth.sso.providers.{$name} already declared (edit manually)");

            return;
        }

        // Insert before the closing `],` of `auth.sso.providers`.
        $marker = "'providers' => [";
        $pos = strpos($contents, $marker);
        if ($pos === false) {
            $this->components->warn('Could not locate auth.sso.providers in config/martis.php — paste the block manually:');
            $this->line($block);

            return;
        }

        $insertAt = strpos($contents, "\n", $pos + strlen($marker));
        if ($insertAt === false) {
            return;
        }

        $contents = substr_replace($contents, "\n".$block, $insertAt + 1, 0);
        file_put_contents($configPath, $contents);

        $this->components->twoColumnDetail('<fg=green>Inserted</> config', "auth.sso.providers.{$name}");
    }

    protected function renderConfigBlock(string $name): string
    {
        $upperName = strtoupper($name);
        $strategy = (string) ($this->option('strategy') ?: 'column');
        $autoCreate = $this->option('no-auto-create-user') ? 'false' : 'true';
        $adapter = $this->option('with-spatie') ? "'spatie'" : "'auto'";

        $extras = match ($name) {
            'azure' => "                'role_source' => 'app_role_assignments',\n                'resource_id' => env('AZURE_RESOURCE_ID'),\n                'scopes' => ['openid', 'profile', 'email', 'GroupMember.Read.All', 'User.ReadBasic.All'],\n",
            'google' => "                'role_source' => 'callable',\n                'scopes' => ['openid', 'profile', 'email'],\n",
            'github' => "                'role_source' => 'callable',\n                'scopes' => ['user:email', 'read:org'],\n",
            default => "                'scopes' => [],\n",
        };

        $label = match ($name) {
            'azure' => 'Continue with Microsoft',
            'google' => 'Continue with Google',
            'github' => 'Continue with GitHub',
            default => 'Continue with '.ucfirst($name),
        };

        $icon = match ($name) {
            'azure' => 'microsoft-outlook-logo',
            'google' => 'google-logo',
            'github' => 'github-logo',
            default => 'lock-key',
        };

        return "            '{$name}' => [\n".
            "                'enabled' => env('MARTIS_SSO_".$upperName."_ENABLED', false),\n".
            "                'driver' => '{$name}',\n".
            "                'label' => '{$label}',\n".
            "                'icon' => '{$icon}',\n".
            $extras.
            "                'role_strategy' => '{$strategy}',\n".
            "                'role_column' => '{$name}_group_name',\n".
            "                'auto_create_user' => {$autoCreate},\n".
            "                'identity_match_attribute' => 'email',\n".
            "                'sync_user_attributes' => ['name', 'email'],\n".
            "                'sync_roles' => true,\n".
            "                'permission_adapter' => {$adapter},\n".
            "                'on_no_role_match' => 'deny',\n".
            "                'redirect_to' => null,\n".
            "            ],\n";
    }

    protected function updateEnv(string $name): void
    {
        $upperName = strtoupper($name);
        $entries = [
            'MARTIS_SSO_ENABLED' => 'true',
            "MARTIS_SSO_{$upperName}_ENABLED" => 'true',
        ];

        if ($name === 'azure') {
            $entries['AZURE_CLIENT_ID'] = '';
            $entries['AZURE_CLIENT_SECRET'] = '';
            $entries['AZURE_REDIRECT_URI'] = '';
            $entries['AZURE_RESOURCE_ID'] = '';
        }

        foreach ([base_path('.env'), base_path('.env.example')] as $envPath) {
            if (! file_exists($envPath)) {
                continue;
            }
            $contents = (string) file_get_contents($envPath);
            $changed = false;

            foreach ($entries as $key => $defaultValue) {
                if (! str_contains($contents, "{$key}=")) {
                    $contents .= "\n{$key}={$defaultValue}";
                    $changed = true;
                }
            }

            if ($changed) {
                file_put_contents($envPath, $contents);
                $this->components->twoColumnDetail('<fg=green>Updated</>', basename($envPath));
            }
        }
    }

    protected function publishMigration(string $name): void
    {
        $stubPath = StubResolver::path('add_provider_group_column_to_roles_table.php.stub');
        if (! file_exists($stubPath)) {
            $this->components->warn('Migration stub missing.');

            return;
        }

        $migrationName = "add_{$name}_group_name_to_roles_table";
        $existing = glob(database_path("migrations/*_{$migrationName}.php")) ?: [];
        if ($existing !== []) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> migration', "{$migrationName} already published");

            return;
        }

        $filesystem = new Filesystem;
        $filesystem->ensureDirectoryExists(database_path('migrations'));
        $target = database_path('migrations/'.date('Y_m_d_His')."_{$migrationName}.php");

        $stub = (string) file_get_contents($stubPath);
        $stub = str_replace('{{column_name}}', "{$name}_group_name", $stub);

        $filesystem->put($target, $stub);
        $this->components->twoColumnDetail('<fg=green>Created</> migration', basename($target));
    }

    protected function printNextSteps(string $name): void
    {
        $this->newLine();
        $this->line('<fg=cyan>Almost done — Azure portal steps remain (intrinsically manual):</>');

        if ($name === 'azure') {
            $this->line('  1. Register the app in https://portal.azure.com:');
            $this->line('     <fg=gray>- App Registrations → New registration</>');
            $this->line('     <fg=gray>- Redirect URI (Web): '.url('/'.config('martis.path', 'martis').'/sso/azure/callback').'</>');
            $this->line('     <fg=gray>- API permissions: openid, profile, email, GroupMember.Read.All, User.ReadBasic.All</>');
            $this->line('     <fg=gray>  → Grant admin consent</>');
            $this->line('     <fg=gray>- App roles: define one per local role (display name = roles.azure_group_name you set just now)</>');
            $this->line('     <fg=gray>- Enterprise applications → Users and groups → assign each user to an App Role</>');
            $this->newLine();
            $this->line('  2. Fill the .env values from the portal:');
            $this->line('     <fg=gray>AZURE_CLIENT_ID         the Application (client) ID</>');
            $this->line('     <fg=gray>AZURE_CLIENT_SECRET     the secret VALUE (not the Secret ID)</>');
            $this->line('     <fg=gray>AZURE_REDIRECT_URI      '.url('/'.config('martis.path', 'martis').'/sso/azure/callback').'</>');
            $this->line('     <fg=gray>AZURE_RESOURCE_ID       same as AZURE_CLIENT_ID</>');
        }

        $this->newLine();
        $this->line('  Reload <fg=cyan>'.url('/'.config('martis.path', 'martis').'/login').'</> — the "Continue with '.ucfirst($name).'" button is there.');
        $this->newLine();
    }
}
