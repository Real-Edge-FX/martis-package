<?php

declare(strict_types=1);

namespace Martis\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Martis\Stubs\StubResolver;
use Symfony\Component\Process\Process;

/**
 * `martis:roles` — scaffold an admin UI for users + roles + permissions
 * backed by spatie/laravel-permission.
 *
 * The command is intentionally heavy: it installs Spatie if it is
 * missing, publishes the migration + config, runs the migration,
 * patches the User model with the `HasRoles` trait, scaffolds three
 * Resources (User / Role / Permission) into `app/Martis/Resources/`,
 * three policies into `app/Policies/`, registers them in
 * `AuthServiceProvider`, and emits a seeder that creates the `admin`
 * role used by every default policy. Each step is idempotent — running
 * the command twice is a no-op once the project is set up.
 *
 * The generated files live in the host app and belong to the host
 * app. Re-running the command will not overwrite them unless the
 * caller passes `--force`. Customise freely.
 *
 * Resources land in the System sidebar group via
 * `belongsToSystemSection() === true` so they appear next to the
 * audit log and the Cache admin link.
 */
class RolesScaffoldCommand extends Command
{
    protected $signature = 'martis:roles
                            {--user= : Fully-qualified User model class (default: App\\Models\\User)}
                            {--namespace= : Namespace for the generated resources (default: App\\Martis\\Resources)}
                            {--no-install : Assume spatie/laravel-permission is already installed}
                            {--no-migrate : Skip running migrations after publishing them}
                            {--no-publish-spatie : Skip publishing Spatie config + migrations}
                            {--force : Overwrite existing resource / policy files}';

    protected $description = 'Scaffold a Spatie-backed admin UI for users, roles, and permissions.';

    private const RESOURCE_NAMES = ['User', 'Role', 'Permission'];

    public function handle(Filesystem $files): int
    {
        $this->components->info('Scaffolding roles + permissions admin UI');

        // 1. Ensure Spatie is on the autoloader (composer require if missing).
        if (! $this->option('no-install')) {
            $this->ensureSpatieInstalled();
        }

        if (! class_exists('Spatie\\Permission\\PermissionServiceProvider')) {
            $this->components->error('spatie/laravel-permission is not installed. Re-run without --no-install or install it manually.');

            return self::FAILURE;
        }

        // 2. Publish Spatie config + migrations (idempotent).
        if (! $this->option('no-publish-spatie')) {
            $this->publishSpatieAssets();
        }

        // 3. Run pending migrations.
        if (! $this->option('no-migrate')) {
            $this->runMigrations();
        }

        // 4. Patch the User model with HasRoles.
        $userClass = $this->resolveUserClass();
        $this->patchUserModel($userClass);

        // 5. Scaffold the three resources.
        $namespace = $this->resolveResourceNamespace();
        foreach (self::RESOURCE_NAMES as $name) {
            $this->scaffoldResource($files, $namespace, $name, $userClass);
        }

        // 6. Scaffold the three policies (User / Role / Permission).
        $this->scaffoldPolicies($files, $userClass);

        // 7. Register the policies in AuthServiceProvider.
        $this->registerPolicies($userClass);

        // 8. Emit the admin-role seeder.
        $this->scaffoldSeeder($files);

        $this->newLine();
        $this->components->info('Done. Next steps:');
        $this->line('  • <fg=cyan>php artisan db:seed --class=MartisRolesSeeder</> — create the "admin" role');
        $this->line('  • Promote yourself: <fg=cyan>User::where(\'email\', \'you@example.com\')->first()->assignRole(\'admin\');</>');
        $this->line('  • Visit <fg=green>/martis/system</> in the sidebar — Users / Roles / Permissions live there.');

        return self::SUCCESS;
    }

    protected function ensureSpatieInstalled(): void
    {
        if (class_exists('Spatie\\Permission\\PermissionServiceProvider')) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> composer', 'spatie/laravel-permission already installed');

            return;
        }

        $this->components->twoColumnDetail('<fg=cyan>Installing</> composer', 'spatie/laravel-permission');

        $process = Process::fromShellCommandline(
            'composer require spatie/laravel-permission',
            base_path(),
            null,
            null,
            300,
        );

        $process->run(function ($_, $line): void {
            $this->getOutput()->write($line);
        });

        if (! $process->isSuccessful()) {
            $this->components->error('composer require spatie/laravel-permission failed. Install manually and re-run with --no-install.');
        }
    }

    protected function publishSpatieAssets(): void
    {
        $configPublished = file_exists(config_path('permission.php'));
        $migrationPublished = (bool) glob(database_path('migrations/*_create_permission_tables.php'));

        if ($configPublished && $migrationPublished) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> spatie publish', 'config + migration already published');

            return;
        }

        $this->components->twoColumnDetail('<fg=cyan>Publishing</> spatie', 'config + migrations');

        $this->call('vendor:publish', [
            '--provider' => 'Spatie\\Permission\\PermissionServiceProvider',
        ]);
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

    protected function resolveUserClass(): string
    {
        $supplied = $this->option('user');

        return is_string($supplied) && $supplied !== '' ? $supplied : 'App\\Models\\User';
    }

    protected function resolveResourceNamespace(): string
    {
        $supplied = $this->option('namespace');

        return is_string($supplied) && $supplied !== '' ? $supplied : 'App\\Martis\\Resources';
    }

    protected function patchUserModel(string $userClass): void
    {
        $relativePath = str_replace(['App\\', '\\'], ['', '/'], $userClass).'.php';
        $userPath = app_path($relativePath);

        if (! file_exists($userPath)) {
            $this->components->warn(sprintf('User model file not found at %s — add `use HasRoles;` manually.', $userPath));

            return;
        }

        $contents = (string) file_get_contents($userPath);

        if (str_contains($contents, 'Spatie\\Permission\\Traits\\HasRoles')) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> User model', 'HasRoles trait already imported');

            return;
        }

        $useImport = "use Spatie\\Permission\\Traits\\HasRoles;\n";

        // Inject the `use` statement after the namespace declaration's
        // last `use` line. Falls back to a manual notice if the file
        // doesn't match the standard Laravel User shape.
        $patched = (string) preg_replace(
            '/(namespace [^;]+;\s*\n(?:use [^;]+;\s*\n)*)/',
            "$1{$useImport}",
            $contents,
            1,
        );

        // Inject `HasRoles` into the trait list. Matches `use Notifiable;`
        // or any single trait import line right after the class brace.
        $patched = (string) preg_replace(
            '/(class \w+ extends [^\{]+\{\s*\n(?:\s*use\s+)([\w\\\\]+))/',
            '$1, HasRoles',
            $patched,
            1,
        );

        // If the regex above did not find a `use Trait;` line inside the class,
        // inject a fresh one right after the opening brace.
        if (! str_contains($patched, 'HasRoles')) {
            $patched = (string) preg_replace(
                '/(class \w+ extends [^\{]+\{)/',
                "$1\n    use HasRoles;\n",
                $patched,
                1,
            );
        }

        if ($patched === $contents) {
            $this->components->warn(sprintf('Could not auto-add `use HasRoles;` to %s — add it manually.', $userPath));

            return;
        }

        file_put_contents($userPath, $patched);
        $this->components->twoColumnDetail('<fg=green>Patched</> User model', $userPath);
    }

    protected function scaffoldResource(Filesystem $files, string $namespace, string $name, string $userClass): void
    {
        // Routes through `StubResolver` so a `php artisan martis:stubs`
        // override at `stubs/martis/roles-{name}-resource.stub` wins over
        // the bundled default.
        $stubFile = StubResolver::path('roles-'.strtolower($name).'-resource.stub');

        if (! file_exists($stubFile)) {
            $this->components->error(sprintf('Stub not found: %s', $stubFile));

            return;
        }

        $namespacePath = str_replace('\\', '/', $namespace);
        $targetDir = base_path(str_replace('App/', 'app/', $namespacePath));
        $files->ensureDirectoryExists($targetDir);

        $targetFile = $targetDir.'/'.$name.'Resource.php';

        if (file_exists($targetFile) && ! $this->option('force')) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> '.$name.'Resource', 'already exists (use --force to overwrite)');

            return;
        }

        $stub = (string) file_get_contents($stubFile);

        $rendered = strtr($stub, [
            '{{ namespace }}' => $namespace,
            '{{ class }}' => $name.'Resource',
            '{{ userModelImport }}' => ltrim($userClass, '\\'),
            '{{ userModelClass }}' => class_basename($userClass),
        ]);

        $files->put($targetFile, $rendered);
        $this->components->twoColumnDetail('<fg=green>Created</> '.$name.'Resource', $targetFile);
    }

    protected function scaffoldPolicies(Filesystem $files, string $userClass): void
    {
        $stubFile = StubResolver::path('roles-policy.stub');

        if (! file_exists($stubFile)) {
            $this->components->error('Policy stub not found.');

            return;
        }

        $stub = (string) file_get_contents($stubFile);

        $targets = [
            'User' => [
                'class' => 'UserPolicy',
                'modelImport' => ltrim($userClass, '\\'),
                'modelClass' => class_basename($userClass),
                'resourceName' => 'Users',
            ],
            'Role' => [
                'class' => 'RolePolicy',
                'modelImport' => 'Spatie\\Permission\\Models\\Role',
                'modelClass' => 'Role',
                'resourceName' => 'Roles',
            ],
            'Permission' => [
                'class' => 'PermissionPolicy',
                'modelImport' => 'Spatie\\Permission\\Models\\Permission',
                'modelClass' => 'Permission',
                'resourceName' => 'Permissions',
            ],
        ];

        $targetDir = app_path('Policies');
        $files->ensureDirectoryExists($targetDir);

        $userImport = ltrim($userClass, '\\');

        foreach ($targets as $config) {
            $targetFile = $targetDir.'/'.$config['class'].'.php';
            if (file_exists($targetFile) && ! $this->option('force')) {
                $this->components->twoColumnDetail('<fg=yellow>Skipping</> '.$config['class'], 'already exists');

                continue;
            }

            // Build the imports block. When the User model and the
            // policy's target model are the same class (UserPolicy
            // case), emit a single `use` line — emitting two would be
            // a "Cannot use X as Y because the name is already in use"
            // fatal at autoload time.
            $imports = $config['modelImport'] === $userImport
                ? sprintf('use %s;', $userImport)
                : sprintf("use %s;\nuse %s;", $userImport, $config['modelImport']);

            $rendered = strtr($stub, [
                '{{ namespace }}' => 'App\\Policies',
                '{{ class }}' => $config['class'],
                '{{ imports }}' => $imports,
                '{{ modelClass }}' => $config['modelClass'],
                '{{ userModelClass }}' => class_basename($userClass),
                '{{ resourceName }}' => $config['resourceName'],
            ]);

            $files->put($targetFile, $rendered);
            $this->components->twoColumnDetail('<fg=green>Created</> '.$config['class'], $targetFile);
        }
    }

    protected function registerPolicies(string $userClass): void
    {
        $providerPath = app_path('Providers/AuthServiceProvider.php');

        if (! file_exists($providerPath)) {
            $this->components->warn('app/Providers/AuthServiceProvider.php not found — register the policies manually:');
            $this->line('  Gate::policy(\\'.ltrim($userClass, '\\').'::class, \\App\\Policies\\UserPolicy::class);');
            $this->line('  Gate::policy(\\Spatie\\Permission\\Models\\Role::class, \\App\\Policies\\RolePolicy::class);');
            $this->line('  Gate::policy(\\Spatie\\Permission\\Models\\Permission::class, \\App\\Policies\\PermissionPolicy::class);');

            return;
        }

        $contents = (string) file_get_contents($providerPath);
        $marker = '/* martis:roles policies */';

        if (str_contains($contents, $marker)) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> AuthServiceProvider', 'policies already registered');

            return;
        }

        $block = "        {$marker}\n".
            '        \\Illuminate\\Support\\Facades\\Gate::policy(\\'.ltrim($userClass, '\\')."::class, \\App\\Policies\\UserPolicy::class);\n".
            "        \\Illuminate\\Support\\Facades\\Gate::policy(\\Spatie\\Permission\\Models\\Role::class, \\App\\Policies\\RolePolicy::class);\n".
            '        \\Illuminate\\Support\\Facades\\Gate::policy(\\Spatie\\Permission\\Models\\Permission::class, \\App\\Policies\\PermissionPolicy::class);';

        $patched = (string) preg_replace(
            '/(public function boot\(\)\s*:\s*void\s*\{)/',
            "$1\n".$block,
            $contents,
            1,
        );

        if ($patched === $contents) {
            $this->components->warn('Could not auto-register policies in AuthServiceProvider — paste this block into boot():');
            $this->line($block);

            return;
        }

        file_put_contents($providerPath, $patched);
        $this->components->twoColumnDetail('<fg=green>Registered</> policies', 'AuthServiceProvider::boot()');
    }

    protected function scaffoldSeeder(Filesystem $files): void
    {
        $stubFile = StubResolver::path('roles-seeder.stub');

        if (! file_exists($stubFile)) {
            $this->components->warn('Seeder stub not found — create the `admin` role manually.');

            return;
        }

        $targetFile = database_path('seeders/MartisRolesSeeder.php');
        if (file_exists($targetFile) && ! $this->option('force')) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> seeder', 'MartisRolesSeeder already exists');

            return;
        }

        $files->ensureDirectoryExists(dirname($targetFile));
        $files->put($targetFile, (string) file_get_contents($stubFile));
        $this->components->twoColumnDetail('<fg=green>Created</> seeder', $targetFile);
    }

}
