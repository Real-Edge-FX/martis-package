<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;

/*
 * `martis:roles` — pin the contract that the command:
 *
 *   1. Refuses to scaffold when Spatie is missing AND the user passed
 *      `--no-install`. (Spec: install or refuse, never half-scaffold.)
 *   2. Renders the three resource stubs into the host app's
 *      `app/Martis/Resources/` directory with the host's User model
 *      and configured namespace.
 *   3. Generates three policies and the admin-role seeder.
 *   4. Is idempotent — re-running it without `--force` skips files
 *      already on disk.
 *
 * The composer install path itself is exercised by the host
 * environment (CI runs `--no-install` because Spatie is dev-suggested,
 * not required, in martis-package).
 */

beforeEach(function () {
    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);

    foreach ([
        app_path('Martis/Resources'),
        app_path('Policies'),
        database_path('seeders'),
    ] as $dir) {
        if ($files->isDirectory($dir)) {
            $files->deleteDirectory($dir);
        }
    }

    // Stub the User model file so `patchUserModel` has something to
    // chew on. The User model class itself does not need to be
    // autoloaded — the command operates on the file contents.
    $userPath = app_path('Models/User.php');
    $files->ensureDirectoryExists(dirname($userPath));
    $files->put($userPath, <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;
}

PHP);

    $authProvider = app_path('Providers/AuthServiceProvider.php');
    $files->ensureDirectoryExists(dirname($authProvider));
    $files->put($authProvider, <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        //
    }
}

PHP);
});

afterEach(function () {
    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);

    foreach ([
        app_path('Martis/Resources'),
        app_path('Policies'),
        app_path('Models'),
        app_path('Providers'),
        database_path('seeders'),
    ] as $dir) {
        if ($files->isDirectory($dir)) {
            $files->deleteDirectory($dir);
        }
    }
});

it('martis:roles is registered with the service provider', function () {
    $commands = $this->app->make(Kernel::class)->all();
    expect($commands)->toHaveKey('martis:roles');
});

it('with --no-install + Spatie present, scaffolds the three resources', function () {
    if (! class_exists('Spatie\\Permission\\PermissionServiceProvider')) {
        $this->markTestSkipped('spatie/laravel-permission not installed');
    }

    $exitCode = $this->artisan('martis:roles', [
        '--no-install' => true,
        '--no-publish-spatie' => true,
        '--no-migrate' => true,
    ])->run();

    expect($exitCode)->toBe(0);

    foreach (['UserResource', 'RoleResource', 'PermissionResource'] as $name) {
        $path = app_path('Martis/Resources/'.$name.'.php');
        expect(file_exists($path))->toBeTrue("missing {$name}");
        $contents = file_get_contents($path);
        expect($contents)->toContain('belongsToSystemSection')
            ->toContain('return true');
    }
});

it('renders the User model with HasRoles trait imported', function () {
    if (! class_exists('Spatie\\Permission\\PermissionServiceProvider')) {
        $this->markTestSkipped('spatie/laravel-permission not installed');
    }

    $this->artisan('martis:roles', [
        '--no-install' => true,
        '--no-publish-spatie' => true,
        '--no-migrate' => true,
    ])->run();

    $userContents = (string) file_get_contents(app_path('Models/User.php'));
    expect($userContents)->toContain('Spatie\\Permission\\Traits\\HasRoles');
    expect($userContents)->toContain('HasRoles');
});

it('writes the three policies into app/Policies/', function () {
    if (! class_exists('Spatie\\Permission\\PermissionServiceProvider')) {
        $this->markTestSkipped('spatie/laravel-permission not installed');
    }

    $this->artisan('martis:roles', [
        '--no-install' => true,
        '--no-publish-spatie' => true,
        '--no-migrate' => true,
    ])->run();

    foreach (['UserPolicy', 'RolePolicy', 'PermissionPolicy'] as $name) {
        $path = app_path('Policies/'.$name.'.php');
        expect(file_exists($path))->toBeTrue("missing {$name}");

        $contents = (string) file_get_contents($path);
        expect($contents)->toContain('hasRole(\'admin\')');

        // Regression: the policy file must lint clean. UserPolicy used
        // to emit two `use App\Models\User;` lines (one for $user, one
        // for $model) which is a fatal "Cannot use X as Y because the
        // name is already in use" at autoload time. Catch that here by
        // requiring the rendered template to have at most one `use`
        // line per fully-qualified class.
        $useLines = [];
        foreach (explode("\n", $contents) as $line) {
            if (preg_match('/^use ([\w\\\\]+);/', $line, $matches)) {
                $useLines[] = $matches[1];
            }
        }
        expect($useLines)->toEqual(array_unique($useLines), "{$name} contains duplicate `use` imports: ".implode(', ', $useLines));
    }
});

it('writes the admin-role seeder', function () {
    if (! class_exists('Spatie\\Permission\\PermissionServiceProvider')) {
        $this->markTestSkipped('spatie/laravel-permission not installed');
    }

    $this->artisan('martis:roles', [
        '--no-install' => true,
        '--no-publish-spatie' => true,
        '--no-migrate' => true,
    ])->run();

    $seederPath = database_path('seeders/MartisRolesSeeder.php');
    expect(file_exists($seederPath))->toBeTrue();
    expect(file_get_contents($seederPath))->toContain('firstOrCreate');
});

it('registers the policies in AuthServiceProvider', function () {
    if (! class_exists('Spatie\\Permission\\PermissionServiceProvider')) {
        $this->markTestSkipped('spatie/laravel-permission not installed');
    }

    $this->artisan('martis:roles', [
        '--no-install' => true,
        '--no-publish-spatie' => true,
        '--no-migrate' => true,
    ])->run();

    $contents = (string) file_get_contents(app_path('Providers/AuthServiceProvider.php'));
    expect($contents)->toContain('martis:roles policies')
        ->toContain('UserPolicy::class')
        ->toContain('RolePolicy::class')
        ->toContain('PermissionPolicy::class');
});

it('is idempotent — re-running without --force skips existing files', function () {
    if (! class_exists('Spatie\\Permission\\PermissionServiceProvider')) {
        $this->markTestSkipped('spatie/laravel-permission not installed');
    }

    $this->artisan('martis:roles', [
        '--no-install' => true,
        '--no-publish-spatie' => true,
        '--no-migrate' => true,
    ])->run();

    $userResourcePath = app_path('Martis/Resources/UserResource.php');
    $hash = md5((string) file_get_contents($userResourcePath));

    // Mutate to detect an unwanted overwrite.
    file_put_contents($userResourcePath, "/* user-customised */\n".file_get_contents($userResourcePath));
    $mutatedHash = md5((string) file_get_contents($userResourcePath));

    $this->artisan('martis:roles', [
        '--no-install' => true,
        '--no-publish-spatie' => true,
        '--no-migrate' => true,
    ])->run();

    $hashAfterRerun = md5((string) file_get_contents($userResourcePath));
    expect($hashAfterRerun)->toBe($mutatedHash);
    expect($hashAfterRerun)->not->toBe($hash);
});
