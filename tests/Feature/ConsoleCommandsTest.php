<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

function cleanupMartisInstallArtifacts(): void
{
    $filesystem = new Filesystem;

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
            } catch (\Throwable) {
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

// ---------------------------------------------------------------------------
// martis:resource
// ---------------------------------------------------------------------------

it('martis:resource generates a Resource class file', function () {
    $path = app_path('Martis/Resources/PostResource.php');

    (new Filesystem)->ensureDirectoryExists(app_path('Martis/Resources'));

    $this->artisan('martis:resource', ['name' => 'Post'])->assertSuccessful();

    expect(file_exists($path))->toBeTrue();

    $contents = file_get_contents($path);
    expect($contents)
        ->toContain('class PostResource extends Resource')
        ->toContain('namespace App\\Martis\\Resources')
        ->toContain('return Post::class');
})->afterEach(function () {
    (new Filesystem)->delete(app_path('Martis/Resources/PostResource.php'));
});

it('martis:resource does not duplicate the Resource suffix', function () {
    $path = app_path('Martis/Resources/CommentResource.php');

    (new Filesystem)->ensureDirectoryExists(app_path('Martis/Resources'));

    $this->artisan('martis:resource', ['name' => 'CommentResource'])->assertSuccessful();

    expect(file_exists($path))->toBeTrue();
    expect(file_exists(app_path('Martis/Resources/CommentResourceResource.php')))->toBeFalse();
})->afterEach(function () {
    (new Filesystem)->delete(app_path('Martis/Resources/CommentResource.php'));
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
    (new Filesystem)->delete(resource_path('js/martis/fields/rating.tsx'));
});

it('martis:field generates a TSX component file', function () {
    $tsxPath = resource_path('js/martis/fields/rating.tsx');

    $this->artisan('martis:field', ['name' => 'Rating'])->assertSuccessful();

    expect(file_exists($tsxPath))->toBeTrue();

    $contents = file_get_contents($tsxPath);
    expect($contents)
        ->toContain('RatingFieldDisplay')
        ->toContain('RatingFieldInput');
})->afterEach(function () {
    (new Filesystem)->delete(app_path('Martis/Fields/RatingField.php'));
    (new Filesystem)->delete(resource_path('js/martis/fields/rating.tsx'));
});

it('martis:field does not duplicate Field suffix', function () {
    $phpPath = app_path('Martis/Fields/ColorField.php');

    $this->artisan('martis:field', ['name' => 'ColorField'])->assertSuccessful();

    expect(file_exists($phpPath))->toBeTrue();
    expect(file_exists(app_path('Martis/Fields/ColorFieldField.php')))->toBeFalse();
})->afterEach(function () {
    (new Filesystem)->delete(app_path('Martis/Fields/ColorField.php'));
    (new Filesystem)->delete(resource_path('js/martis/fields/color.tsx'));
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
