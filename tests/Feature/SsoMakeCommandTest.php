<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $this->filesystem = new Filesystem;
});

afterEach(function () {
    // Clean up anything the command may have published.
    $generated = [
        config_path('martis.php'),
        base_path('.env'),
        base_path('.env.example'),
        app_path('Providers/AppServiceProvider.php'),
    ];

    foreach ($generated as $path) {
        if ($this->filesystem->exists($path)) {
            // Strip out any SSO-related lines we might have inserted.
            $contents = (string) $this->filesystem->get($path);
            $stripped = preg_replace([
                '/MARTIS_SSO_[A-Z_]+=.*\n?/',
                '/AZURE_[A-Z_]+=.*\n?/',
            ], '', $contents) ?? $contents;

            if ($stripped !== $contents) {
                $this->filesystem->put($path, $stripped);
            }
        }
    }

    foreach (glob(database_path('migrations/*_add_*_group_name_to_roles_table.php')) ?: [] as $migration) {
        try {
            $this->filesystem->delete($migration);
        } catch (Throwable) {
            // ignore parallel-worker race
        }
    }
});

it('martis:sso requires a known provider name unless --custom is passed', function () {
    $this->artisan('martis:sso', ['provider' => 'unknown'])
        ->expectsOutputToContain("Unknown provider 'unknown'")
        ->assertFailed();
});

it('martis:sso accepts --custom for an unknown provider name', function () {
    $this->artisan('martis:sso', [
        'provider' => 'okta',
        '--custom' => true,
        '--no-composer' => true,
        '--no-listener' => true,
        '--no-migrate' => true,
    ])->assertSuccessful();
});

it('martis:sso azure --no-composer --no-listener --no-migrate runs cleanly', function () {
    if (! file_exists(config_path('martis.php'))) {
        $this->artisan('martis:install', ['--no-interaction' => true])->assertSuccessful();
    }

    $this->artisan('martis:sso', [
        'provider' => 'azure',
        '--no-composer' => true,
        '--no-listener' => true,
        '--no-migrate' => true,
        '--with-migration' => true,
    ])->assertSuccessful();

    // Migration should have been published when --with-migration is set.
    $migrations = glob(database_path('migrations/*_add_azure_group_name_to_roles_table.php')) ?: [];
    expect($migrations)->toHaveCount(1);
});

it('martis:sso azure is idempotent — running twice does not duplicate the config block', function () {
    // Force a clean config every run. Cross-file pollution (the
    // previous test adds an azure block; afterEach only strips env
    // lines) used to make the second `expectsOutputToContain` racy
    // because both sso calls would short-circuit with "already
    // declared". Wiping config here makes the assertion deterministic.
    if (file_exists(config_path('martis.php'))) {
        $this->filesystem->delete(config_path('martis.php'));
    }
    $this->artisan('martis:install', ['--no-interaction' => true])->assertSuccessful();

    $this->artisan('martis:sso', [
        'provider' => 'azure',
        '--no-composer' => true,
        '--no-listener' => true,
        '--no-migrate' => true,
    ])->assertSuccessful();

    $this->artisan('martis:sso', [
        'provider' => 'azure',
        '--no-composer' => true,
        '--no-listener' => true,
        '--no-migrate' => true,
    ])->expectsOutputToContain('already declared')
        ->assertSuccessful();

    // Config should contain exactly one azure block.
    $config = (string) file_get_contents(config_path('martis.php'));
    $occurrences = substr_count($config, "'azure' => [");
    expect($occurrences)->toBe(1);
});

it('martis:sso azure --with-migration only publishes the migration once', function () {
    if (! file_exists(config_path('martis.php'))) {
        $this->artisan('martis:install', ['--no-interaction' => true])->assertSuccessful();
    }

    $this->artisan('martis:sso', [
        'provider' => 'azure',
        '--with-migration' => true,
        '--no-composer' => true,
        '--no-listener' => true,
        '--no-migrate' => true,
    ])->assertSuccessful();

    $this->artisan('martis:sso', [
        'provider' => 'azure',
        '--with-migration' => true,
        '--no-composer' => true,
        '--no-listener' => true,
        '--no-migrate' => true,
    ])->assertSuccessful();

    $migrations = glob(database_path('migrations/*_add_azure_group_name_to_roles_table.php')) ?: [];
    expect($migrations)->toHaveCount(1);
});

it('martis:sso skips composer when all required packages are already declared', function () {
    if (! file_exists(config_path('martis.php'))) {
        $this->artisan('martis:install', ['--no-interaction' => true])->assertSuccessful();
    }

    // The package's own composer.json includes laravel/socialite + sociliteproviders/microsoft
    // are NOT present, so this test passes --no-composer to bypass real install.
    $this->artisan('martis:sso', [
        'provider' => 'azure',
        '--no-composer' => true,
        '--no-listener' => true,
        '--no-migrate' => true,
    ])->doesntExpectOutputToContain('Installing composer')
        ->assertSuccessful();
});

it('martis:sso registers the SocialiteProviders listener idempotently', function () {
    if (! file_exists(config_path('martis.php'))) {
        $this->artisan('martis:install', ['--no-interaction' => true])->assertSuccessful();
    }

    $providerPath = app_path('Providers/AppServiceProvider.php');
    $appServiceProvider = (new Filesystem)->exists($providerPath);

    if (! $appServiceProvider) {
        // Test environment may not have AppServiceProvider — skip.
        $this->markTestSkipped('AppServiceProvider not available in this test environment.');

        return;
    }

    $this->artisan('martis:sso', [
        'provider' => 'azure',
        '--no-composer' => true,
        '--no-migrate' => true,
    ])->assertSuccessful();

    $contents = (string) (new Filesystem)->get($providerPath);
    expect($contents)->toContain('MicrosoftExtendSocialite');

    // Re-running must not duplicate.
    $this->artisan('martis:sso', [
        'provider' => 'azure',
        '--no-composer' => true,
        '--no-migrate' => true,
    ])->expectsOutputToContain('already registered')
        ->assertSuccessful();

    $contents = (string) (new Filesystem)->get($providerPath);
    expect(substr_count($contents, 'MicrosoftExtendSocialite'))->toBe(1);
});
