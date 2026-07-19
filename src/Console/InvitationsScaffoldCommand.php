<?php

declare(strict_types=1);

namespace Martis\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Martis\Stubs\StubResolver;

/**
 * `martis:invitations` — scaffold the consumer-owned admin UI for the
 * invitation workflow.
 *
 * The backend primitive (`Martis\Invitations\InvitationManager`, the
 * events + audit listener, the config block, the `martis-invite` gate,
 * the public accept routes, and the React accept screen) already ships
 * inside the package. This command adds ONLY the host-owned pieces that
 * cannot live in the package because they belong to the application's
 * namespace and are meant to be edited:
 *
 *   • an `InvitationResource` (System-section index of the invitations)
 *   • an `InviteUser` standalone action + `ResendInvitation` /
 *     `RevokeInvitation` row actions
 *   • an admin-only `InvitationPolicy`
 *   • an `App\Notifications\UserInvitation` mailable notification
 *
 * It also publishes the `create_invitations_table` migration (family
 * glob-skip so re-runs never double-publish) and registers the policy
 * in the host `AuthServiceProvider`. Every step is idempotent — running
 * the command twice is a no-op once the app is set up. Generated files
 * live in the host app and are never overwritten unless `--force` is
 * passed. Customise freely.
 *
 * The resource lands in the System sidebar group via
 * `belongsToSystemSection() === true`, and disappears entirely from
 * navigation + routing when `config('martis.invitations.enabled')` is
 * false (`displayInNavigation()`).
 */
class InvitationsScaffoldCommand extends Command
{
    protected $signature = 'martis:invitations
                            {--user= : Fully-qualified User model class (default: App\\Models\\User)}
                            {--namespace= : Namespace for the generated resource + actions (default: App\\Martis\\Resources)}
                            {--no-install : Skip the spatie/laravel-permission advisory for the role picker}
                            {--no-migrate : Skip running migrations after publishing them}
                            {--no-publish : Skip publishing the invitations migration}
                            {--force : Overwrite existing resource / action / policy / notification files}';

    protected $description = 'Scaffold the consumer-owned admin UI (resource, actions, policy, notification) for invitations.';

    /** @var array<string, string> Action class => stub filename. */
    private const ACTION_STUBS = [
        'InviteUser' => 'invitations-invite-action.stub',
        'ResendInvitation' => 'invitations-resend-action.stub',
        'RevokeInvitation' => 'invitations-revoke-action.stub',
    ];

    public function handle(Filesystem $files): int
    {
        $this->components->info('Scaffolding invitations admin UI');

        // 1. Optional advisory: the role picker reads Spatie roles. No
        // composer side-effects — invitations do not require Spatie.
        if (! $this->option('no-install')) {
            $this->adviseRolePickerDependency();
        }

        // 2. Publish the invitations migration (family glob-skip).
        if (! $this->option('no-publish')) {
            $this->publishInvitationsMigration($files);
        }

        $namespace = $this->resolveResourceNamespace();
        $userClass = $this->resolveUserClass();

        // 3. Scaffold the resource + the three actions.
        $this->scaffoldResource($files, $namespace);
        $this->scaffoldActions($files, $namespace);

        // 4. Scaffold the admin-only policy and wire it into AuthServiceProvider.
        $this->scaffoldPolicy($files, $userClass);
        $this->registerPolicy($userClass);

        // 5. Scaffold the consumer-owned notification.
        $this->scaffoldNotification($files);

        // 6. Emit the `invitations` config block if the host has published
        // config/martis.php without it (idempotent).
        $this->emitInvitationsConfig($files);

        // 7. Run pending migrations.
        if (! $this->option('no-migrate')) {
            $this->runMigrations();
        }

        $this->printNextSteps();

        return self::SUCCESS;
    }

    protected function resolveResourceNamespace(): string
    {
        $supplied = $this->option('namespace');

        return is_string($supplied) && $supplied !== '' ? $supplied : 'App\\Martis\\Resources';
    }

    protected function resolveUserClass(): string
    {
        $supplied = $this->option('user');

        return is_string($supplied) && $supplied !== '' ? $supplied : 'App\\Models\\User';
    }

    /**
     * Non-destructive heads-up: the InviteUser role picker pulls from
     * spatie/laravel-permission when present. Absent Spatie the picker
     * is simply empty and invitations carry no role.
     */
    protected function adviseRolePickerDependency(): void
    {
        if (class_exists('Spatie\\Permission\\Models\\Role')) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> role advisory', 'spatie/laravel-permission is installed');

            return;
        }

        $this->components->twoColumnDetail('<fg=cyan>Note</> role picker', 'spatie/laravel-permission not installed — the invite role picker will be empty');
    }

    /**
     * Publish the `create_invitations_table` migration. Skips silently
     * when an equivalent migration is already on disk so re-runs stay
     * idempotent even under `--force`.
     */
    protected function publishInvitationsMigration(Filesystem $files): void
    {
        $stubFile = StubResolver::path('create_invitations_table.php.stub');
        if (! file_exists($stubFile)) {
            $this->components->warn('Invitations migration stub not found — publish it manually.');

            return;
        }

        $migrationsDir = base_path('database/migrations');
        $files->ensureDirectoryExists($migrationsDir);

        // Match the family, not the exact filename, so a re-run does not
        // double-publish under a fresh timestamp.
        $existing = collect((array) glob($migrationsDir.'/*_create_invitations_table.php'));
        if ($existing->isNotEmpty()) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> invitations migration', 'already published');

            return;
        }

        $target = $migrationsDir.'/'.date('Y_m_d_His').'_create_invitations_table.php';
        $files->put($target, (string) file_get_contents($stubFile));
        $this->components->twoColumnDetail('<fg=green>Published</> invitations migration', $target);
    }

    protected function scaffoldResource(Filesystem $files, string $namespace): void
    {
        $stubFile = StubResolver::path('invitations-resource.stub');
        if (! file_exists($stubFile)) {
            $this->components->error(sprintf('Stub not found: %s', $stubFile));

            return;
        }

        $targetDir = $this->namespaceToPath($namespace);
        $files->ensureDirectoryExists($targetDir);

        $targetFile = $targetDir.'/InvitationResource.php';
        if (file_exists($targetFile) && ! $this->option('force')) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> InvitationResource', 'already exists (use --force to overwrite)');

            return;
        }

        $rendered = strtr((string) file_get_contents($stubFile), [
            '{{ namespace }}' => $namespace,
            '{{ class }}' => 'InvitationResource',
        ]);

        $files->put($targetFile, $rendered);
        $this->components->twoColumnDetail('<fg=green>Created</> InvitationResource', $targetFile);
    }

    protected function scaffoldActions(Filesystem $files, string $namespace): void
    {
        $actionsNamespace = $namespace.'\\Actions';
        $targetDir = $this->namespaceToPath($actionsNamespace);
        $files->ensureDirectoryExists($targetDir);

        foreach (self::ACTION_STUBS as $class => $stubName) {
            $stubFile = StubResolver::path($stubName);
            if (! file_exists($stubFile)) {
                $this->components->error(sprintf('Stub not found: %s', $stubFile));

                continue;
            }

            $targetFile = $targetDir.'/'.$class.'.php';
            if (file_exists($targetFile) && ! $this->option('force')) {
                $this->components->twoColumnDetail('<fg=yellow>Skipping</> '.$class, 'already exists (use --force to overwrite)');

                continue;
            }

            $rendered = strtr((string) file_get_contents($stubFile), [
                '{{ namespace }}' => $actionsNamespace,
            ]);

            $files->put($targetFile, $rendered);
            $this->components->twoColumnDetail('<fg=green>Created</> '.$class, $targetFile);
        }
    }

    protected function scaffoldPolicy(Filesystem $files, string $userClass): void
    {
        $stubFile = StubResolver::path('invitations-policy.stub');
        if (! file_exists($stubFile)) {
            $this->components->warn('Policy stub not found — create App\\Policies\\InvitationPolicy manually.');

            return;
        }

        $targetDir = app_path('Policies');
        $files->ensureDirectoryExists($targetDir);

        $targetFile = $targetDir.'/InvitationPolicy.php';
        if (file_exists($targetFile) && ! $this->option('force')) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> InvitationPolicy', 'already exists');

            return;
        }

        $rendered = strtr((string) file_get_contents($stubFile), [
            '{{ namespace }}' => 'App\\Policies',
            '{{ class }}' => 'InvitationPolicy',
            '{{ userModelImport }}' => ltrim($userClass, '\\'),
            '{{ userModelClass }}' => class_basename($userClass),
        ]);

        $files->put($targetFile, $rendered);
        $this->components->twoColumnDetail('<fg=green>Created</> InvitationPolicy', $targetFile);
    }

    /**
     * Wire `InvitationPolicy` to the `Invitation` model in the host
     * `AuthServiceProvider::boot()` so it governs the resource. Falls
     * back to a printed block when the provider is missing or its shape
     * is not the standard Laravel one. Idempotent via a marker comment.
     */
    protected function registerPolicy(string $userClass): void
    {
        $providerPath = app_path('Providers/AuthServiceProvider.php');
        $marker = '/* martis:invitations policy */';
        $registration = '\\Illuminate\\Support\\Facades\\Gate::policy(\\Martis\\Invitations\\Invitation::class, \\App\\Policies\\InvitationPolicy::class);';

        if (! file_exists($providerPath)) {
            $this->components->warn('app/Providers/AuthServiceProvider.php not found — register the policy manually:');
            $this->line('  Gate::policy(\\Martis\\Invitations\\Invitation::class, \\App\\Policies\\InvitationPolicy::class);');

            return;
        }

        $contents = (string) file_get_contents($providerPath);

        if (str_contains($contents, $marker)) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> AuthServiceProvider', 'invitation policy already registered');

            return;
        }

        $block = "        {$marker}\n        {$registration}";

        $patched = (string) preg_replace(
            '/(public function boot\(\)\s*:\s*void\s*\{)/',
            "$1\n".$block,
            $contents,
            1,
        );

        if ($patched === $contents) {
            $this->components->warn('Could not auto-register the invitation policy in AuthServiceProvider — paste this block into boot():');
            $this->line($block);

            return;
        }

        file_put_contents($providerPath, $patched);
        $this->components->twoColumnDetail('<fg=green>Registered</> policy', 'AuthServiceProvider::boot()');
    }

    protected function scaffoldNotification(Filesystem $files): void
    {
        $stubFile = StubResolver::path('invitations-notification.stub');
        if (! file_exists($stubFile)) {
            $this->components->warn('Notification stub not found — create App\\Notifications\\UserInvitation manually.');

            return;
        }

        $targetDir = app_path('Notifications');
        $files->ensureDirectoryExists($targetDir);

        $targetFile = $targetDir.'/UserInvitation.php';
        if (file_exists($targetFile) && ! $this->option('force')) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> UserInvitation', 'already exists (use --force to overwrite)');

            return;
        }

        // No placeholders — the notification always lands under the app's
        // conventional `App\Notifications` namespace.
        $files->put($targetFile, (string) file_get_contents($stubFile));
        $this->components->twoColumnDetail('<fg=green>Created</> UserInvitation', $targetFile);
    }

    /**
     * Emit the `invitations` config block into a published
     * config/martis.php that predates the feature. No-op when the file
     * is unpublished (prints a publish hint) or already carries the
     * block (the package config ships it, so a fresh publish is covered).
     */
    protected function emitInvitationsConfig(Filesystem $files): void
    {
        $configPath = config_path('martis.php');

        if (! $files->exists($configPath)) {
            $this->line('  <fg=gray>•</> Publish the Martis config to tune invitations: <fg=cyan>php artisan vendor:publish --tag=martis-config</>');

            return;
        }

        $contents = (string) $files->get($configPath);

        // The shipped block is keyed by `MARTIS_INVITATIONS_ENABLED`; a
        // marker comment covers a hand-inserted block too.
        if (str_contains($contents, 'MARTIS_INVITATIONS_ENABLED') || str_contains($contents, '/* martis:invitations config */')) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> config', "'invitations' block already present in config/martis.php");

            return;
        }

        $block = <<<'PHP'

    /* martis:invitations config */
    'invitations' => [
        'enabled' => env('MARTIS_INVITATIONS_ENABLED', false),
        'expires_after_hours' => (int) env('MARTIS_INVITATIONS_TTL_HOURS', 72),
        'single_use' => true,
        'resend_throttle_seconds' => (int) env('MARTIS_INVITATIONS_RESEND_THROTTLE', 60),
        'login_after_accept' => env('MARTIS_INVITATIONS_LOGIN_AFTER_ACCEPT', true),
        'redirect_after_accept' => env('MARTIS_INVITATIONS_REDIRECT', null),
        'signup_fields' => ['name', 'password'],
        'mark_email_verified_on_accept' => true,
    ],
PHP;

        // Insert just before the array's closing `];` at end of file.
        $patched = (string) preg_replace('/\n\];\s*$/', "\n{$block}\n];\n", $contents, 1);

        if ($patched === $contents) {
            $this->components->warn('Could not auto-insert the invitations config block — add it to config/martis.php manually:');
            $this->line($block);

            return;
        }

        $files->put($configPath, $patched);
        $this->components->twoColumnDetail('<fg=green>Added</> config', "'invitations' block → config/martis.php");
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

    protected function printNextSteps(): void
    {
        $this->newLine();
        $this->components->info('Done. Next steps:');
        $this->line('  <fg=gray>•</> Enable the feature: set <fg=cyan>MARTIS_INVITATIONS_ENABLED=true</> in your .env');
        $this->line('  <fg=gray>•</> Decide who may invite by defining the gate in <fg=cyan>App\\Providers\\AuthServiceProvider::boot()</>:');
        $this->line('      <fg=cyan>Gate::define(\'martis-invite\', fn ($user) => $user->hasRole(\'admin\'));</>');
        $this->line('  <fg=gray>•</> Visit <fg=green>/martis/system</> — Invitations lives in the System group.');
    }

    private function namespaceToPath(string $namespace): string
    {
        $namespacePath = str_replace('\\', '/', $namespace);

        return base_path(str_replace('App/', 'app/', $namespacePath));
    }
}
