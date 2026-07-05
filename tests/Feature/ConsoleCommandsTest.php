<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

function cleanupMartisInstallArtifacts(): void
{
    $filesystem = new Filesystem;

    // Service provider stub published by martis:install lives in
    // app_path('Providers'). Remove between tests so each run starts
    // from a clean slate. Same for the bootstrap registration.
    $providerPath = app_path('Providers/MartisServiceProvider.php');
    if ($filesystem->exists($providerPath)) {
        try {
            $filesystem->delete($providerPath);
        } catch (Throwable) {
            // Ignore parallel-worker race.
        }
    }

    $bootstrapPath = base_path('bootstrap/providers.php');
    if ($filesystem->exists($bootstrapPath)) {
        try {
            $contents = (string) $filesystem->get($bootstrapPath);
            $stripped = preg_replace(
                '/\s*App\\\\Providers\\\\MartisServiceProvider::class,\n?/',
                '',
                $contents,
            ) ?? '';
            $filesystem->put($bootstrapPath, $stripped);
        } catch (Throwable) {
            // Ignore parallel-worker race.
        }
    }

    $patterns = [
        'migrations/*_create_martis_action_events_table.php',
        'migrations/*_add_martis_profile_picture_column_to_users_table.php',
        'migrations/*_add_martis_two_factor_columns_to_users_table.php',
    ];

    // Parallel-safe — multiple Pest workers share the testbench
    // database path, and another worker may have already unlinked the
    // file between glob() and delete(). Guard each delete.
    foreach ($patterns as $pattern) {
        collect(glob(database_path($pattern)) ?: [])->each(function (string $path) use ($filesystem): void {
            try {
                if ($filesystem->exists($path)) {
                    $filesystem->delete($path);
                }
            } catch (Throwable) {
                // Another worker beat us to it — ignore.
            }
        });
    }

    $envPath = app()->environmentFilePath();
    if ($filesystem->exists($envPath)) {
        $contents = (string) $filesystem->get($envPath);
        $stripped = preg_replace('/^MARTIS_[A-Z0-9_]+=.*$\n?/m', '', $contents) ?? '';
        $filesystem->put($envPath, $stripped);
    }
}

beforeEach(function () {
    cleanupMartisInstallArtifacts();
});

afterEach(function () {
    cleanupMartisInstallArtifacts();
});

// ---------------------------------------------------------------------------
// martis:install
// ---------------------------------------------------------------------------

it('martis:install is registered and runs successfully', function () {
    $this->artisan('martis:install')->assertSuccessful();
});

it('martis:install publishes the frontend manifest', function () {
    $this->artisan('martis:install')->assertSuccessful();

    expect(file_exists(public_path('vendor/martis/manifest.json')))->toBeTrue();
})->afterEach(function () {
    (new Filesystem)->deleteDirectory(public_path('vendor/martis'));
    (new Filesystem)->deleteDirectory(app_path('Martis'));
});

it('martis:install publishes the action events migration once', function () {
    $this->artisan('martis:install', ['--no-interaction' => true])->assertSuccessful();
    $this->artisan('martis:install', ['--no-interaction' => true])->assertSuccessful();

    $migrations = glob(database_path('migrations/*_create_martis_action_events_table.php')) ?: [];

    expect($migrations)->toHaveCount(1);
});

it('martis:install --no-interaction skips the optional profile migration by default', function () {
    $this->artisan('martis:install', ['--no-interaction' => true])->assertSuccessful();

    $migrations = glob(database_path('migrations/*_add_martis_profile_picture_column_to_users_table.php')) ?: [];

    expect($migrations)->toHaveCount(0);
});

it('martis:install in non-TTY without --no-interaction still skips host-table migrations', function () {
    // v1.14.3 bug fix. `docker compose exec -T` and CI pipes strip the
    // TTY without setting `--no-interaction`. Symfony's
    // $input->isInteractive() stayed true, the confirm() defaults
    // resolved to "yes" silently, and the host `users` table was
    // ALTERed (profile_picture + two_factor_*) without the operator
    // ever being prompted. The fix gates the interactive branch
    // behind both `isInteractive()` AND a real TTY check; the test
    // process here is naturally non-TTY, so the bug-fix path is
    // exercised even without explicit `--no-interaction`.
    $this->artisan('martis:install')->assertSuccessful();

    $profile = glob(database_path('migrations/*_add_martis_profile_picture_column_to_users_table.php')) ?: [];
    $twoFactor = glob(database_path('migrations/*_add_martis_two_factor_columns_to_users_table.php')) ?: [];

    expect($profile)->toHaveCount(0, 'profile migration must not auto-publish in non-TTY contexts');
    expect($twoFactor)->toHaveCount(0, '2FA migration must not auto-publish in non-TTY contexts');
});

it('martis:install respects MARTIS_PROFILE_ENABLED=false even with --with-profile', function () {
    config()->set('martis.profile.enabled', false);

    $this->artisan('martis:install', [
        '--no-interaction' => true,
        '--with-profile' => true,
    ])->assertSuccessful();

    $migrations = glob(database_path('migrations/*_add_martis_profile_picture_column_to_users_table.php')) ?: [];

    expect($migrations)->toHaveCount(0, 'config-disabled profile must win over the explicit --with-profile flag so a host with MARTIS_PROFILE_ENABLED=false cannot have its users table altered');
});

it('martis:install --no-profile blocks profile even when interactive defaults would say yes', function () {
    // The --no-profile flag wins over both --with-profile and the
    // interactive confirm default. Combined with config-respect
    // above, it gives operators a deterministic opt-out path.
    $this->artisan('martis:install', [
        '--no-interaction' => true,
        '--with-profile' => true,
        '--no-profile' => true,
    ])->assertSuccessful();

    $migrations = glob(database_path('migrations/*_add_martis_profile_picture_column_to_users_table.php')) ?: [];

    expect($migrations)->toHaveCount(0);
});

it('martis:install can publish the optional profile migration without duplication', function () {
    $this->artisan('martis:install', [
        '--no-interaction' => true,
        '--with-profile' => true,
        '--avatar-column' => 'avatar_path',
    ])->assertSuccessful();

    $this->artisan('martis:install', [
        '--no-interaction' => true,
        '--with-profile' => true,
        '--avatar-column' => 'avatar_path',
    ])->assertSuccessful();

    $migrations = glob(database_path('migrations/*_add_martis_profile_picture_column_to_users_table.php')) ?: [];

    expect($migrations)->toHaveCount(1);
    expect(file_get_contents($migrations[0]))->toContain('"avatar_path"');
});

it('martis:install creates the expected Martis directories', function () {
    $base = app_path('Martis');

    $this->artisan('martis:install')->assertSuccessful();

    expect(is_dir($base))->toBeTrue();
    expect(is_dir($base.'/Resources'))->toBeTrue();
    expect(is_dir($base.'/Fields'))->toBeTrue();
    expect(is_dir($base.'/Actions'))->toBeTrue();
    expect(is_dir($base.'/Filters'))->toBeTrue();
    expect(is_dir($base.'/Lenses'))->toBeTrue();
    expect(is_dir($base.'/Dashboards'))->toBeTrue();
    expect(is_dir($base.'/Metrics'))->toBeTrue();
})->afterEach(function () {
    (new Filesystem)->deleteDirectory(app_path('Martis'));
});

it('martis:install skips config publish when config already exists', function () {
    // Pre-create the config file to simulate already-published state
    (new Filesystem)->ensureDirectoryExists(config_path());
    (new Filesystem)->put(config_path('martis.php'), '<?php return [];');

    $this->artisan('martis:install')->assertSuccessful();

    // Config file is still the stub (not overwritten)
    expect(file_get_contents(config_path('martis.php')))->toBe('<?php return [];');
})->afterEach(function () {
    (new Filesystem)->delete(config_path('martis.php'));
    (new Filesystem)->deleteDirectory(app_path('Martis'));
});

it('InstallCommand class is registered in the service provider', function () {
    $commands = $this->app->make(Kernel::class)->all();
    expect($commands)->toHaveKey('martis:install');
});

it('martis:install publishes the host MartisServiceProvider stub', function () {
    $providerPath = app_path('Providers/MartisServiceProvider.php');
    expect(file_exists($providerPath))->toBeFalse();

    $this->artisan('martis:install', ['--no-interaction' => true])->assertSuccessful();

    expect(file_exists($providerPath))->toBeTrue();
    $contents = (string) file_get_contents($providerPath);
    expect($contents)->toContain('class MartisServiceProvider');
    expect($contents)->toContain('Martis::dashboards');
    expect($contents)->toContain('Martis::mainMenu');
    expect($contents)->toContain('MartisCache::extend');
    expect($contents)->toContain('manage-martis-cache');
})->afterEach(function () {
    cleanupMartisInstallArtifacts();
});

it('martis:install does not overwrite an existing host MartisServiceProvider', function () {
    $providerPath = app_path('Providers/MartisServiceProvider.php');
    (new Filesystem)->ensureDirectoryExists(dirname($providerPath));
    (new Filesystem)->put($providerPath, '<?php // custom user content');

    $this->artisan('martis:install', ['--no-interaction' => true])->assertSuccessful();

    expect(file_get_contents($providerPath))->toBe('<?php // custom user content');
})->afterEach(function () {
    cleanupMartisInstallArtifacts();
});

it('martis:install --force preserves an existing host MartisServiceProvider (v1.10.2+)', function () {
    // v1.10.2 split: `--force` refreshes the extension scaffold but no
    // longer overwrites the host app's provider, where dashboards,
    // menus, gates, and cache layers are registered. Republishing the
    // provider now requires `--force-provider`.
    $providerPath = app_path('Providers/MartisServiceProvider.php');
    (new Filesystem)->ensureDirectoryExists(dirname($providerPath));
    (new Filesystem)->put($providerPath, '<?php // custom user content');

    $this->artisan('martis:install', ['--no-interaction' => true, '--force' => true])->assertSuccessful();

    expect(file_get_contents($providerPath))->toBe('<?php // custom user content');
})->afterEach(function () {
    cleanupMartisInstallArtifacts();
});

it('martis:install --force-provider overwrites an existing host MartisServiceProvider', function () {
    $providerPath = app_path('Providers/MartisServiceProvider.php');
    (new Filesystem)->ensureDirectoryExists(dirname($providerPath));
    (new Filesystem)->put($providerPath, '<?php // custom user content');

    $this->artisan('martis:install', ['--no-interaction' => true, '--force-provider' => true])->assertSuccessful();

    expect(file_get_contents($providerPath))->toContain('class MartisServiceProvider');
})->afterEach(function () {
    cleanupMartisInstallArtifacts();
});

it('martis:install registers the host MartisServiceProvider in bootstrap/providers.php', function () {
    $bootstrapPath = base_path('bootstrap/providers.php');
    if (! file_exists($bootstrapPath)) {
        // Some testbench setups don't have this file. Skip.
        return;
    }

    // Reset to a clean providers.php with no Martis entry.
    (new Filesystem)->put($bootstrapPath, "<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n];\n");

    $this->artisan('martis:install', ['--no-interaction' => true])->assertSuccessful();

    $contents = (string) file_get_contents($bootstrapPath);
    expect($contents)->toContain('App\\Providers\\MartisServiceProvider::class');

    // Re-running install does not duplicate the entry.
    $this->artisan('martis:install', ['--no-interaction' => true])->assertSuccessful();

    $occurrences = substr_count((string) file_get_contents($bootstrapPath), 'App\\Providers\\MartisServiceProvider::class');
    expect($occurrences)->toBe(1);
})->afterEach(function () {
    cleanupMartisInstallArtifacts();
});

// ---------------------------------------------------------------------------
// martis:resource
// ---------------------------------------------------------------------------

it('martis:resource generates a Resource class file', function () {
    $path = app_path('Martis/Resources/PostResource.php');

    (new Filesystem)->ensureDirectoryExists(app_path('Martis/Resources'));

    try {
        $this->artisan('martis:resource', ['name' => 'Post'])->assertSuccessful();

        expect(file_exists($path))->toBeTrue();

        $contents = file_get_contents($path);
        expect($contents)
            ->toContain('class PostResource extends Resource')
            ->toContain('namespace App\\Martis\\Resources')
            ->toContain('return Post::class');
    } finally {
        // NOTE: chaining ->afterEach() on an it() call does not register a
        // real Pest hook (Pest\PendingCalls\TestCall::__call swallows it as
        // a higher-order-test chain), so cleanup must happen inline. A
        // leftover file here is auto-discovered by ResourceDiscovery on
        // every later test's app boot and pollutes the resource registry
        // process-wide (see ResourceRoutableTest for the fallout).
        (new Filesystem)->delete($path);
    }
});

it('martis:resource does not duplicate the Resource suffix', function () {
    $path = app_path('Martis/Resources/CommentResource.php');

    (new Filesystem)->ensureDirectoryExists(app_path('Martis/Resources'));

    try {
        $this->artisan('martis:resource', ['name' => 'CommentResource'])->assertSuccessful();

        expect(file_exists($path))->toBeTrue();
        expect(file_exists(app_path('Martis/Resources/CommentResourceResource.php')))->toBeFalse();
    } finally {
        // See note above: ->afterEach() chained on it() is a no-op in Pest.
        (new Filesystem)->delete($path);
    }
});

it('ResourceMakeCommand is registered in the service provider', function () {
    $commands = $this->app->make(Kernel::class)->all();
    expect($commands)->toHaveKey('martis:resource');
});

// ---------------------------------------------------------------------------
// martis:field
// ---------------------------------------------------------------------------

it('martis:field generates a PHP Field class', function () {
    $phpPath = app_path('Martis/Fields/RatingField.php');

    $this->artisan('martis:field', ['name' => 'Rating'])->assertSuccessful();

    expect(file_exists($phpPath))->toBeTrue();

    $contents = file_get_contents($phpPath);
    expect($contents)
        ->toContain('class RatingField extends Field')
        ->toContain("return 'rating'");
})->afterEach(function () {
    (new Filesystem)->delete(app_path('Martis/Fields/RatingField.php'));
    (new Filesystem)->delete(base_path('resources/js/martis-extensions/fields/Rating.tsx'));
});

it('martis:field drops the TSX in the auto-discovery fields/ bucket', function () {
    // v1.9 convention: filename is the bare class basename (no
    // "Field" suffix) inside resources/js/martis-extensions/fields/.
    // Auto-discovery derives the registry key from the filename:
    // Rating.tsx → "field:rating".
    $tsxPath = base_path('resources/js/martis-extensions/fields/Rating.tsx');

    $this->artisan('martis:field', ['name' => 'Rating'])->assertSuccessful();

    expect(file_exists($tsxPath))->toBeTrue();

    $contents = (string) file_get_contents($tsxPath);
    expect($contents)
        // Auto-discovery looks for named exports `Display` / `Input`.
        ->toContain('export function Display')
        ->toContain('export function Input')
        // Stub is now self-contained — no @martis/types import.
        ->not->toContain('@martis/types')
        ->not->toContain('componentRegistry.register');
})->afterEach(function () {
    (new Filesystem)->delete(app_path('Martis/Fields/RatingField.php'));
    (new Filesystem)->delete(base_path('resources/js/martis-extensions/fields/Rating.tsx'));
});

it('martis:field does not duplicate Field suffix', function () {
    $phpPath = app_path('Martis/Fields/ColorField.php');

    $this->artisan('martis:field', ['name' => 'ColorField'])->assertSuccessful();

    expect(file_exists($phpPath))->toBeTrue();
    expect(file_exists(app_path('Martis/Fields/ColorFieldField.php')))->toBeFalse();
})->afterEach(function () {
    (new Filesystem)->delete(app_path('Martis/Fields/ColorField.php'));
    (new Filesystem)->delete(base_path('resources/js/martis-extensions/fields/Color.tsx'));
});

it('FieldMakeCommand is registered in the service provider', function () {
    $commands = $this->app->make(Kernel::class)->all();
    expect($commands)->toHaveKey('martis:field');
});

// ---------------------------------------------------------------------------
// martis:user
// ---------------------------------------------------------------------------

beforeEach(function () {
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }
});

it('martis:user creates a user in the database', function () {
    $this->artisan('martis:user', [
        '--name' => 'Test Admin',
        '--email' => 'admin@martis.test',
        '--password' => 'secret1234',
    ])->assertSuccessful();

    $user = User::where('email', 'admin@martis.test')->first();

    expect($user)->not->toBeNull();
    expect($user->name)->toBe('Test Admin');
    expect(Hash::check('secret1234', $user->password))->toBeTrue();
});

it('martis:user fails when password is empty', function () {
    $this->artisan('martis:user', [
        '--name' => 'Test Admin',
        '--email' => 'admin2@martis.test',
        '--password' => '',
    ])->assertFailed();
});

it('UserCommand is registered in the service provider', function () {
    $commands = $this->app->make(Kernel::class)->all();
    expect($commands)->toHaveKey('martis:user');
});

// ---------------------------------------------------------------------------
// martis:value / trend / partition / progress / activity-feed / endpoint-table
//
// These metric generators all share the same `GeneratorCommand` recipe —
// the regression that prompted this coverage was a wrong `__DIR__`
// chain in the v0.6 cycle that resolved their stub paths to
// `vendor/martis/martis/../../stubs/...` (outside the package), so
// every invocation threw `FileNotFoundException`. The matrix below
// fires each generator with a synthetic name, asserts the file lands,
// the namespace is correct, and the produced class extends the right
// parent. Each test cleans up its own artefact in `afterEach`.
// ---------------------------------------------------------------------------

dataset('metric_generators', [
    // [command, name, parentClass, expectedClassDeclaration, namespacePart]
    ['martis:value',          'TotalUsers',          'ValueMetric',          'class TotalUsers extends ValueMetric',          'App\\Martis\\Metrics'],
    ['martis:trend',          'UsersPerDay',         'TrendMetric',          'class UsersPerDay extends TrendMetric',         'App\\Martis\\Metrics'],
    ['martis:partition',      'UsersByRole',         'PartitionMetric',      'class UsersByRole extends PartitionMetric',     'App\\Martis\\Metrics'],
    ['martis:progress',       'MonthlyGoal',         'ProgressMetric',       'class MonthlyGoal extends ProgressMetric',      'App\\Martis\\Metrics'],
    ['martis:activity-feed',  'RecentDeploys',       'ActivityFeedMetric',   'class RecentDeploys extends ActivityFeedMetric', 'App\\Martis\\Metrics'],
    ['martis:endpoint-table', 'TopEndpoints',        'EndpointTableMetric',  'class TopEndpoints extends EndpointTableMetric', 'App\\Martis\\Metrics'],
]);

it('metric generators write the expected class file', function (
    string $command,
    string $name,
    string $parent,
    string $declaration,
    string $namespacePart,
) {
    $path = app_path("Martis/Metrics/{$name}.php");
    (new Filesystem)->ensureDirectoryExists(app_path('Martis/Metrics'));

    $this->artisan($command, ['name' => $name])->assertSuccessful();

    expect(file_exists($path))->toBeTrue();

    $contents = file_get_contents($path);
    expect($contents)
        ->toContain($declaration)
        ->toContain("namespace {$namespacePart}");
})->with('metric_generators')->afterEach(function () {
    foreach (['TotalUsers', 'UsersPerDay', 'UsersByRole', 'MonthlyGoal', 'RecentDeploys', 'TopEndpoints'] as $cls) {
        (new Filesystem)->delete(app_path("Martis/Metrics/{$cls}.php"));
    }
});

it('metric generator commands are registered in the service provider', function () {
    $commands = $this->app->make(Kernel::class)->all();
    expect($commands)
        ->toHaveKey('martis:value')
        ->toHaveKey('martis:trend')
        ->toHaveKey('martis:partition')
        ->toHaveKey('martis:progress')
        ->toHaveKey('martis:activity-feed')
        ->toHaveKey('martis:endpoint-table');
});

// ---------------------------------------------------------------------------
// martis:dashboard
// ---------------------------------------------------------------------------

it('martis:dashboard generates a Dashboard class file', function () {
    $path = app_path('Martis/Dashboards/SalesDashboard.php');
    (new Filesystem)->ensureDirectoryExists(app_path('Martis/Dashboards'));

    $this->artisan('martis:dashboard', ['name' => 'SalesDashboard'])->assertSuccessful();

    expect(file_exists($path))->toBeTrue();

    $contents = file_get_contents($path);
    expect($contents)
        ->toContain('class SalesDashboard extends Dashboard')
        ->toContain('namespace App\\Martis\\Dashboards');
})->afterEach(function () {
    (new Filesystem)->delete(app_path('Martis/Dashboards/SalesDashboard.php'));
});

it('DashboardMakeCommand is registered in the service provider', function () {
    $commands = $this->app->make(Kernel::class)->all();
    expect($commands)->toHaveKey('martis:dashboard');
});

// ---------------------------------------------------------------------------
// martis:tool (v0.10)
// ---------------------------------------------------------------------------

it('martis:tool generates a Tool class with the documented stub shape', function () {
    $path = app_path('Martis/Tools/SystemHealth.php');
    (new Filesystem)->ensureDirectoryExists(app_path('Martis/Tools'));

    $this->artisan('martis:tool', ['name' => 'SystemHealth'])->assertSuccessful();

    expect(file_exists($path))->toBeTrue();

    $contents = (string) file_get_contents($path);
    expect($contents)
        ->toContain('class SystemHealth extends Tool')
        ->toContain('namespace App\\Martis\\Tools')
        ->toContain("uriKey: 'system-health'")
        ->toContain("withComponent('tool:system-health')")
        ->toContain("withIcon('wrench')");
})->afterEach(function () {
    (new Filesystem)->delete(app_path('Martis/Tools/SystemHealth.php'));
});

it('martis:tool --use-bundled binds to the package SystemStatusDemo component key', function () {
    $path = app_path('Martis/Tools/Quick.php');
    (new Filesystem)->ensureDirectoryExists(app_path('Martis/Tools'));

    $this->artisan('martis:tool', ['name' => 'Quick', '--use-bundled' => true])->assertSuccessful();

    $contents = (string) file_get_contents($path);
    expect($contents)
        ->toContain("withComponent('martis:tool:system-status-demo')");
})->afterEach(function () {
    (new Filesystem)->delete(app_path('Martis/Tools/Quick.php'));
});

it('martis:tool --component-key honours the explicit React component key', function () {
    $path = app_path('Martis/Tools/Reports.php');
    (new Filesystem)->ensureDirectoryExists(app_path('Martis/Tools'));

    $this->artisan('martis:tool', [
        'name' => 'Reports',
        '--component-key' => 'app:reports-page',
    ])->assertSuccessful();

    $contents = (string) file_get_contents($path);
    expect($contents)->toContain("withComponent('app:reports-page')");
})->afterEach(function () {
    (new Filesystem)->delete(app_path('Martis/Tools/Reports.php'));
});

it('martis:tool --menu-section embeds the section call in the Tool stub', function () {
    $path = app_path('Martis/Tools/Backups.php');
    (new Filesystem)->ensureDirectoryExists(app_path('Martis/Tools'));

    $this->artisan('martis:tool', [
        'name' => 'Backups',
        '--menu-section' => 'Operations',
    ])->assertSuccessful();

    $contents = (string) file_get_contents($path);
    expect($contents)->toContain("withMenuSection('Operations')");
})->afterEach(function () {
    (new Filesystem)->delete(app_path('Martis/Tools/Backups.php'));
});

it('martis:tool --with-component drops the TSX stub in the auto-discovery bucket', function () {
    // Use a unique class name not present in the testbench fixtures
    // (Imports/Backups/Reports/Quick/SystemHealth all ship pre-baked).
    $php = app_path('Martis/Tools/StubAutoDiscoveryTool.php');
    // v1.9 convention: filename is the bare class basename (no "Tool"
    // suffix), bucket lives under resources/js/martis-extensions/.
    $tsx = base_path('resources/js/martis-extensions/tools/StubAutoDiscoveryTool.tsx');
    $fs = new Filesystem;
    $fs->ensureDirectoryExists(app_path('Martis/Tools'));

    // Wipe both potential leftovers from a prior failing run before
    // running the artisan command. parent::handle() returns false
    // when the destination PHP class already exists, which would
    // cascade into "TSX never scaffolded" and confuse the assertion.
    if ($fs->exists($php)) {
        $fs->delete($php);
    }
    if ($fs->exists($tsx)) {
        $fs->delete($tsx);
    }

    $this->artisan('martis:tool', [
        'name' => 'StubAutoDiscoveryTool',
        '--with-component' => true,
    ])->assertSuccessful();

    clearstatcache();
    expect(file_exists($php))->toBeTrue();
    expect(file_exists($tsx))->toBeTrue();

    $contents = (string) file_get_contents($tsx);
    expect($contents)
        ->toContain('export default function StubAutoDiscoveryToolTool')
        // Auto-discovery uses `import.meta.glob` + filename → key
        // mapping; the stub no longer contains a manual register call.
        ->not->toContain('componentRegistry.register')
        ->not->toContain('@martis/admin')
        ->toContain('"tool:stub-auto-discovery-tool"');
})->afterEach(function () {
    $fs = new Filesystem;
    $fs->delete(app_path('Martis/Tools/StubAutoDiscoveryTool.php'));
    $fs->delete(base_path('resources/js/martis-extensions/tools/StubAutoDiscoveryTool.tsx'));
    $bucketDir = base_path('resources/js/martis-extensions/tools');
    if (is_dir($bucketDir) && count(scandir($bucketDir)) === 2) {
        rmdir($bucketDir);
    }
    $rootDir = base_path('resources/js/martis-extensions');
    if (is_dir($rootDir) && count(scandir($rootDir)) === 2) {
        rmdir($rootDir);
    }
});

it('martis:tool --with-component aborts on TSX collision when not interactive and --force is missing', function () {
    $tsx = base_path('resources/js/martis-extensions/tools/CollisionAbortTool.tsx');
    $fs = new Filesystem;

    $fs->ensureDirectoryExists(dirname($tsx));
    $fs->put($tsx, '// custom content I do not want overwritten');
    $fs->ensureDirectoryExists(app_path('Martis/Tools'));
    // Pre-wipe stale PHP from a prior run so parent::handle() does
    // not short-circuit before scaffoldReactComponent runs.
    if ($fs->exists(app_path('Martis/Tools/CollisionAbortTool.php'))) {
        $fs->delete(app_path('Martis/Tools/CollisionAbortTool.php'));
    }

    $this->artisan('martis:tool', [
        'name' => 'CollisionAbortTool',
        '--with-component' => true,
    ])->assertSuccessful(); // PHP class still scaffolds; TSX scaffold aborts with a non-fatal error.

    expect((string) $fs->get($tsx))->toBe('// custom content I do not want overwritten');
})->afterEach(function () {
    $fs = new Filesystem;
    $fs->delete(app_path('Martis/Tools/CollisionAbortTool.php'));
    $fs->delete(base_path('resources/js/martis-extensions/tools/CollisionAbortTool.tsx'));
    $bucketDir = base_path('resources/js/martis-extensions/tools');
    if (is_dir($bucketDir) && count(scandir($bucketDir)) === 2) {
        rmdir($bucketDir);
    }
    $rootDir = base_path('resources/js/martis-extensions');
    if (is_dir($rootDir) && count(scandir($rootDir)) === 2) {
        rmdir($rootDir);
    }
});

it('martis:tool --with-component --force overwrites an existing TSX', function () {
    $tsx = base_path('resources/js/martis-extensions/tools/CollisionForceTool.tsx');
    $fs = new Filesystem;

    $fs->ensureDirectoryExists(dirname($tsx));
    $fs->put($tsx, '// stale content that should be replaced');
    $fs->ensureDirectoryExists(app_path('Martis/Tools'));
    if ($fs->exists(app_path('Martis/Tools/CollisionForceTool.php'))) {
        $fs->delete(app_path('Martis/Tools/CollisionForceTool.php'));
    }

    $this->artisan('martis:tool', [
        'name' => 'CollisionForceTool',
        '--with-component' => true,
        '--force' => true,
    ])->assertSuccessful();

    $contents = (string) $fs->get($tsx);
    expect($contents)
        ->toContain('export default function CollisionForceToolTool')
        ->not->toContain('stale content');
})->afterEach(function () {
    $fs = new Filesystem;
    $fs->delete(app_path('Martis/Tools/CollisionForceTool.php'));
    $fs->delete(base_path('resources/js/martis-extensions/tools/CollisionForceTool.tsx'));
    $bucketDir = base_path('resources/js/martis-extensions/tools');
    if (is_dir($bucketDir) && count(scandir($bucketDir)) === 2) {
        rmdir($bucketDir);
    }
    $rootDir = base_path('resources/js/martis-extensions');
    if (is_dir($rootDir) && count(scandir($rootDir)) === 2) {
        rmdir($rootDir);
    }
});

it('ToolMakeCommand is registered in the service provider', function () {
    $commands = $this->app->make(Kernel::class)->all();
    expect($commands)->toHaveKey('martis:tool');
});
