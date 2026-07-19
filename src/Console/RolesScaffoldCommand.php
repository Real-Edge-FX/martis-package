<?php

declare(strict_types=1);

namespace Martis\Console;

use Composer\Autoload\ClassLoader;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
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
                            {--with-categories : Add a `category` column on permissions and surface it as a field + filter on PermissionResource (handy for apps with 50+ permissions)}
                            {--promote= : Email of the user to seed + promote to admin (skips the post-install manual step). Pass `first` to auto-pick the lowest-id existing user.}
                            {--no-seed : Skip running MartisRolesSeeder after scaffolding (default: seed runs automatically when the seeder file lands)}
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
            $this->components->error(
                'spatie/laravel-permission could not be loaded. If Composer just installed it above, this process\'s autoloader is stale — re-run the same command and it will be picked up. Otherwise install it manually and re-run with --no-install.'
            );

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

        // 5b. Scaffold the BulkAssignRole action wired into the
        // generated UserResource. v1.8.8.
        $this->scaffoldBulkAssignRoleAction($files, $namespace);

        // 5c. With `--with-categories`, publish the migration that
        // adds the `category` column to permissions. The PermissionResource
        // stub above already wired the field + filter through the
        // {{ categoryField }} / {{ categoryFilters }} placeholders.
        if ((bool) $this->option('with-categories')) {
            $this->publishCategoryMigration($files);
            $this->scaffoldPermissionCategoryFilter($files);
        }

        // 6. Scaffold the three policies (User / Role / Permission).
        $this->scaffoldPolicies($files, $userClass);

        // 7. Register the policies in AuthServiceProvider.
        $this->registerPolicies($userClass);

        // 8. Emit the admin-role seeder.
        $this->scaffoldSeeder($files);

        // 8b. Run the seeder so the `admin` role exists immediately. The
        // seeder is idempotent (firstOrCreate), so re-running martis:roles
        // doesn't create duplicates. Skip when `--no-seed` is passed for
        // CI / scripted setups that handle seeding separately.
        if (! $this->option('no-seed')) {
            $this->runRolesSeeder();
        }

        // 8c. Promote a user to admin so the operator can immediately
        // see the System sidebar without dropping into tinker. `--promote=`
        // accepts an email (exact match, case-insensitive) or the literal
        // `first` to grab the lowest-id user — handy for fresh installs
        // where there's exactly one account.
        $promote = $this->option('promote');
        if (is_string($promote) && $promote !== '') {
            /** @var class-string $userClass */
            $this->promoteUserToAdmin($userClass, $promote);
        }

        $this->newLine();
        $this->components->info('Done. Next steps:');
        if ($this->option('no-seed')) {
            $this->line('  • <fg=cyan>php artisan db:seed --class=MartisRolesSeeder</> — create the "admin" role');
        }
        if ($promote === null || $promote === '') {
            $this->line('  • Promote yourself: <fg=cyan>User::where(\'email\', \'you@example.com\')->first()->assignRole(\'admin\');</>');
            $this->line('    (or re-run <fg=cyan>php artisan martis:roles --promote=you@example.com</>)');
        }
        $this->line('  • Visit <fg=green>/martis/system</> in the sidebar — Users / Roles / Permissions live there.');

        return self::SUCCESS;
    }

    /**
     * Run the just-scaffolded `MartisRolesSeeder` so the `admin` role
     * exists in the database without an extra step. Idempotent: the
     * seeder uses `firstOrCreate`.
     */
    protected function runRolesSeeder(): void
    {
        try {
            $this->callSilent('db:seed', ['--class' => 'MartisRolesSeeder', '--force' => true]);
            $this->components->twoColumnDetail('<fg=green>Seeded</> role', '"admin" via MartisRolesSeeder');
        } catch (\Throwable $e) {
            $this->components->warn('Could not auto-run MartisRolesSeeder: '.$e->getMessage());
            $this->components->warn('Run it manually: php artisan db:seed --class=MartisRolesSeeder');
        }
    }

    /**
     * Promote a user model to the `admin` role.
     *
     * The `--promote` option accepts an email or the literal `first`,
     * which pulls the lowest-id user (typical fresh-install scenario:
     * one account exists). When the user is missing or the role hasn't
     * been seeded yet, the command warns but does not fail — the
     * operator can re-run with the right value later.
     *
     * @param  class-string  $userClass
     */
    protected function promoteUserToAdmin(string $userClass, string $hint): void
    {
        if (! class_exists($userClass)) {
            $this->components->warn("--promote skipped: User class {$userClass} not found.");

            return;
        }

        try {
            /** @var Model|null $user */
            $user = $hint === 'first'
                ? $userClass::query()->orderBy('id')->first()
                : $userClass::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($hint)])->first();
        } catch (\Throwable $e) {
            $this->components->warn('--promote skipped: '.$e->getMessage());

            return;
        }

        if ($user === null) {
            $hintLabel = $hint === 'first' ? 'no users in the database' : "user with email \"{$hint}\" not found";
            $this->components->warn("--promote skipped: {$hintLabel}.");

            return;
        }

        if (! method_exists($user, 'assignRole')) {
            $this->components->warn('--promote skipped: User model does not use Spatie\\Permission\\Traits\\HasRoles.');

            return;
        }

        try {
            /** @phpstan-ignore-next-line dynamic Spatie trait method */
            $user->assignRole('admin');
            $email = (string) ($user->getAttribute('email') ?? '#'.$user->getKey());
            $this->components->twoColumnDetail('<fg=green>Promoted</> to admin', $email);
        } catch (\Throwable $e) {
            $this->components->warn('--promote: assignRole failed — '.$e->getMessage());
            $this->components->warn('Make sure the seeder ran (admin role must exist) and the User model uses HasRoles.');
        }
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

            return;
        }

        $this->refreshAutoloader();
    }

    /**
     * Re-apply Composer's freshly-written PSR-4 map to this process's
     * already booted ClassLoader. `composer require` (a subprocess)
     * rewrites the autoload files on disk, but this parent process cached
     * its loader at boot; without this, class_exists() for the
     * just-installed package evaluates the stale pre-install map and
     * wrongly reports it missing.
     */
    protected function refreshAutoloader(): void
    {
        $autoloadPath = base_path('vendor/autoload.php');
        $psr4Path = base_path('vendor/composer/autoload_psr4.php');
        if (! is_file($autoloadPath) || ! is_file($psr4Path)) {
            return;
        }

        $loader = require $autoloadPath; // returns the cached ClassLoader instance
        if (! $loader instanceof ClassLoader) {
            return;
        }

        /** @var array<string, list<string>> $psr4 */
        $psr4 = require $psr4Path; // freshly written by the composer subprocess
        foreach ($psr4 as $prefix => $paths) {
            $loader->setPsr4($prefix, $paths);
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

        // Skip only when the trait is genuinely applied in the class
        // body. A bare top-of-file `use …HasRoles…;` import does NOT
        // count — that was the silent partial-failure this method used
        // to print "Patched" for while `$user->hasRole()` still threw.
        if (self::classBodyUsesHasRolesTrait($contents)) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> User model', 'HasRoles trait already applied');

            return;
        }

        $patched = self::applyHasRolesTrait($contents);

        // Null means the file shape could not be patched (the trait was
        // not actually injected into the class body). Do NOT write and
        // do NOT print "Patched" — the success message must stay honest.
        if ($patched === null) {
            $this->components->warn(sprintf('Could not auto-add `use HasRoles;` to %s — add it manually.', $userPath));

            return;
        }

        file_put_contents($userPath, $patched);
        $this->components->twoColumnDetail('<fg=green>Patched</> User model', $userPath);
    }

    /**
     * Whether the class BODY (after the class opening brace) applies a
     * `use …HasRoles…;` trait statement — as opposed to merely importing
     * the `HasRoles` symbol at the top of the file (which must NOT count).
     */
    public static function classBodyUsesHasRolesTrait(string $contents): bool
    {
        if (! preg_match('/class\s+\w+[^{]*\{(.*)$/s', $contents, $m)) {
            return false;
        }

        // A trait-use statement: `use` then identifiers (no `(`/`{` —
        // excludes closure `use (...)` and the docblock's
        // `@use Foo<Bar>`), containing HasRoles, ending in `;`. Anchored
        // to the START of a line (only horizontal whitespace before
        // `use`) so a docblock `* @use HasRoles<Foo>` / `/** @use … */`
        // line — which is preceded by `*` or `/**`, not line-start — can
        // never match. Without the anchor, a docblock-only mention wrongly
        // reports the trait as applied and patchUserModel() skips patching.
        return (bool) preg_match('/^[ \t]*use\s+[^;(){}]*\bHasRoles\b[^;(){}]*;/m', $m[1]);
    }

    /**
     * Return $contents with the Spatie HasRoles trait applied (import +
     * a class-body `use …HasRoles…;`), or null if the file shape could
     * not be patched. Pure (no IO) so it is directly unit-testable.
     * Tolerates a leading docblock / attribute / comment between the
     * class brace and the first trait-use line (Laravel 12's default
     * shape).
     */
    public static function applyHasRolesTrait(string $contents): ?string
    {
        $patched = $contents;

        // 1. Import (only if not already imported by a REAL `use …;`
        //    statement — a bare mention of the FQCN in a comment or
        //    method body must not suppress the import, or the class-body
        //    `use HasRoles;` we add below resolves to the wrong,
        //    unqualified `HasRoles` (App\Models\HasRoles) and fatals with
        //    "Trait not found".
        if (! preg_match('/^\s*use\s+Spatie\\\\Permission\\\\Traits\\\\HasRoles\s*;/m', $patched)) {
            $patched = (string) preg_replace(
                '/(namespace [^;]+;\s*\n(?:use [^;]+;\s*\n)*)/',
                "$1use Spatie\\Permission\\Traits\\HasRoles;\n",
                $patched,
                1,
            );
        }

        // Idempotent guard: if the class body already applies the trait,
        // stop here. Injecting again would emit `use HasRoles, …,
        // HasRoles;` — a fatal duplicate-trait use. (patchUserModel also
        // skips in this case; this keeps the pure function safe under
        // direct / repeated invocation.)
        if (self::classBodyUsesHasRolesTrait($patched)) {
            return $patched;
        }

        // 2. Inject HasRoles into the FIRST class-body trait-use, skipping
        //    a leading docblock / line-comment / attribute after the brace.
        $injected = (string) preg_replace(
            '/(class\s+\w+[^{]*\{\s*(?:\/\*\*.*?\*\/\s*|\/\/[^\n]*\n\s*|#\[[^\]]*\]\s*)*use\s+)([A-Za-z_][\w\\\\]*(?:\s*,\s*[A-Za-z_][\w\\\\]*)*\s*;)/s',
            '$1HasRoles, $2',
            $patched,
            1,
            $count,
        );

        if ($count > 0) {
            $patched = $injected;
        } else {
            // 3. No class-body trait-use to extend — add a fresh one
            //    after the brace.
            $patched = (string) preg_replace(
                '/(class\s+\w+[^{]*\{)/',
                "$1\n    use HasRoles;\n",
                $patched,
                1,
            );
        }

        // Assert the postcondition the caller's success message claims.
        return self::classBodyUsesHasRolesTrait($patched) ? $patched : null;
    }

    /**
     * v1.8.8 — publish the `add_category_column_to_permissions_table`
     * migration when the operator passed `--with-categories`. Skips
     * silently when an equivalent migration is already on disk so the
     * step stays idempotent under re-runs.
     */
    protected function publishCategoryMigration(Filesystem $files): void
    {
        $stubFile = StubResolver::path('add_category_column_to_permissions_table.php.stub');
        if (! file_exists($stubFile)) {
            return;
        }

        $migrationsDir = base_path('database/migrations');
        $files->ensureDirectoryExists($migrationsDir);

        // Skip when an equivalent migration already exists. Match the
        // family rather than the exact filename so re-runs do not
        // double-publish under a fresh timestamp.
        $existing = collect((array) glob($migrationsDir.'/*_add_category_column_to_permissions_table.php'));
        if ($existing->isNotEmpty()) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> category migration', 'already published');

            return;
        }

        $target = $migrationsDir.'/'.date('Y_m_d_His').'_add_category_column_to_permissions_table.php';
        $files->put($target, (string) file_get_contents($stubFile));
        $this->components->twoColumnDetail('<fg=green>Published</> category migration', $target);
    }

    /**
     * v1.8.8 — emit the `BulkAssignRole` action used by the generated
     * UserResource. Lives under `{namespace}\Actions\BulkAssignRole`,
     * idempotent under `--force` like every other scaffold step.
     */
    protected function scaffoldBulkAssignRoleAction(Filesystem $files, string $namespace): void
    {
        $stubFile = StubResolver::path('roles-bulk-assign-role-action.stub');
        if (! file_exists($stubFile)) {
            return;
        }

        $actionsNamespace = $namespace.'\\Actions';
        $namespacePath = str_replace('\\', '/', $actionsNamespace);
        $targetDir = base_path(str_replace('App/', 'app/', $namespacePath));
        $files->ensureDirectoryExists($targetDir);

        $targetFile = $targetDir.'/BulkAssignRole.php';
        if (file_exists($targetFile) && ! $this->option('force')) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> BulkAssignRole', 'already exists (use --force to overwrite)');

            return;
        }

        $stub = (string) file_get_contents($stubFile);
        $rendered = strtr($stub, [
            '{{ namespace }}' => $actionsNamespace,
        ]);

        $files->put($targetFile, $rendered);
        $this->components->twoColumnDetail('<fg=green>Created</> BulkAssignRole', $targetFile);
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

        // v1.8.8 — Permission resource picks up an optional `category`
        // field + filter when `--with-categories` is set. Keeps the
        // baseline scaffold unchanged for apps with a small permission
        // catalogue and wires the grouping for apps with 50+.
        [$categoryField, $categoryFilters] = $this->renderCategorySnippets($name);

        $rendered = strtr($stub, [
            '{{ namespace }}' => $namespace,
            '{{ class }}' => $name.'Resource',
            '{{ userModelImport }}' => ltrim($userClass, '\\'),
            '{{ userModelClass }}' => class_basename($userClass),
            '{{ categoryField }}' => $categoryField,
            '{{ categoryFilters }}' => $categoryFilters,
        ]);

        $files->put($targetFile, $rendered);
        $this->components->twoColumnDetail('<fg=green>Created</> '.$name.'Resource', $targetFile);
    }

    /**
     * @return array{0: string, 1: string} `[fieldsBlock, filtersMethod]` ready
     *                                     for stub interpolation. Both
     *                                     strings are empty when the
     *                                     `--with-categories` flag is off,
     *                                     so the rendered stub is
     *                                     byte-identical to pre-v1.8.8.
     */
    protected function renderCategorySnippets(string $resourceName): array
    {
        if (! (bool) $this->option('with-categories') || $resourceName !== 'Permission') {
            return ['', ''];
        }

        $field = "\n            \\Martis\\Fields\\Text::make('Category', 'category')\n"
            ."                ->sortable()\n"
            ."                ->rules(['nullable', 'string', 'max:64'])\n"
            ."                ->help('Free-form group label — drives the filter on the index. Leave blank to keep the row uncategorised.'),\n";

        // The filter has to be a concrete subclass of `SelectFilter`
        // (abstract). Inline `new SelectFilter(...)` instantiation —
        // shipped pre-v1.8.17 — threw `Cannot instantiate abstract
        // class` the first time the operator opened the permissions
        // index. Reference a sibling class that the scaffolder writes
        // alongside the resource (see `scaffoldPermissionCategoryFilter`).
        $filters = "\n    public function filters(Request \$request): array\n"
            ."    {\n"
            ."        return [\n"
            ."            new \\App\\Martis\\Filters\\PermissionCategoryFilter,\n"
            ."        ];\n"
            ."    }\n";

        return [$field, $filters];
    }

    /**
     * Scaffold `app/Martis/Filters/PermissionCategoryFilter.php` —
     * the concrete `SelectFilter` subclass referenced by the
     * `--with-categories` PermissionResource. Idempotent: skipped
     * when the file already exists unless `--force` is set.
     */
    protected function scaffoldPermissionCategoryFilter(Filesystem $files): void
    {
        if (! (bool) $this->option('with-categories')) {
            return;
        }

        $target = app_path('Martis/Filters/PermissionCategoryFilter.php');
        if ($files->exists($target) && ! (bool) $this->option('force')) {
            $this->components->twoColumnDetail('<fg=yellow>Skipping</> filter', 'PermissionCategoryFilter already exists');

            return;
        }

        $files->ensureDirectoryExists(dirname($target));

        $contents = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Martis\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Martis\Filters\SelectFilter;
use Spatie\Permission\Models\Permission;

/**
 * Index filter for `App\Martis\Resources\PermissionResource`.
 *
 * Pulls the distinct list of `category` values currently in use so
 * the dropdown reflects the live data — adding a new category to a
 * permission row immediately surfaces it as a filter option, no
 * re-deploy needed. Customise freely; this file is yours, not the
 * package's.
 */
class PermissionCategoryFilter extends SelectFilter
{
    public function __construct()
    {
        // Filter base class requires `__construct(string $name,
        // ?string $uriKey)`. Pass the user-facing label here so the
        // SelectFilter ctor chain wires up correctly; without this the
        // first instantiation throws ArgumentCountError.
        parent::__construct(name: 'Category', uriKey: 'category');
    }

    /** @return array<string, string> */
    public function options(Request $request): array
    {
        /** @var array<string, string> $rows */
        $rows = Permission::query()
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category', 'category')
            ->all();

        return $rows;
    }

    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query->where('category', (string) $value);
    }
}

PHP;

        $files->put($target, $contents);
        $this->components->twoColumnDetail('<fg=green>Created</> filter', 'app/Martis/Filters/PermissionCategoryFilter.php');
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
