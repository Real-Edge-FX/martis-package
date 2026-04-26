<?php

declare(strict_types=1);

namespace Martis\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

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
                            {--with-spatie : Default the permission adapter to "spatie"}
                            {--with-migration : Publish a migration adding {provider}_group_name to roles}
                            {--strategy=column : Default role mapping strategy (column|config|callable)}
                            {--no-auto-create-user : Disable auto user provisioning}
                            {--custom : Treat the provider as a custom one (no built-in scopes / driver)}';

    protected $description = 'Scaffold an SSO provider — config block, env vars, optional migration.';

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

        $this->updateConfig($name);
        $this->updateEnv($name);

        if ($this->option('with-migration')) {
            $this->publishMigration($name);
        }

        $this->printNextSteps($name);

        return self::SUCCESS;
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
        $stubPath = __DIR__.'/../../stubs/add_provider_group_column_to_roles_table.php.stub';
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
        $this->line('<fg=cyan>Next steps:</>');
        $this->line('  1. Install the OAuth driver:');
        $this->line('     <fg=gray>composer require laravel/socialite</>');

        if ($name === 'azure') {
            $this->line('     <fg=gray>composer require socialiteproviders/microsoft</>');
            $this->newLine();
            $this->line('  2. Register the Microsoft Socialite extension in your AppServiceProvider boot():');
            $this->line('     <fg=gray>Event::listen(SocialiteWasCalled::class, [MicrosoftExtendSocialite::class.\'@handle\']);</>');
            $this->newLine();
            $this->line('  3. Create the Azure AD app registration in the Azure portal:');
            $this->line('     <fg=gray>- App Registrations → New registration</>');
            $this->line('     <fg=gray>- Redirect URI: '.url('/'.config('martis.path', 'martis').'/sso/azure/callback').'</>');
            $this->line('     <fg=gray>- Copy the Application (client) ID into AZURE_CLIENT_ID</>');
            $this->line('     <fg=gray>- Generate a client secret → AZURE_CLIENT_SECRET</>');
            $this->line('     <fg=gray>- The same Application ID also goes into AZURE_RESOURCE_ID</>');
        }

        $this->newLine();
        $this->line('  4. Fill the AZURE_* / SSO_* env vars in .env (the command stubbed empty placeholders).');
        $this->line('  5. Reload the page — the "Continue with '.ucfirst($name).'" button appears on /login.');
        $this->newLine();
    }
}
