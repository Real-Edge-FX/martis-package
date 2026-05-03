<?php

use Illuminate\Filesystem\Filesystem;
use Martis\Stubs\StubResolver;

// -----------------------------------------------------------------------------
// StubResolver — resolution between package default and user-published override
// -----------------------------------------------------------------------------

beforeEach(function () {
    $this->stubsDir = base_path('stubs/martis');
    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    if ($files->isDirectory($this->stubsDir)) {
        $files->deleteDirectory($this->stubsDir);
    }
});

afterEach(function () {
    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    if ($files->isDirectory($this->stubsDir)) {
        $files->deleteDirectory($this->stubsDir);
    }
});

it('falls back to the package stub when no user override exists', function () {
    $resolved = StubResolver::path('resource.stub');
    expect($resolved)->toBe(StubResolver::packagePath('resource.stub'));
    expect(file_exists($resolved))->toBeTrue();
});

it('returns the user override when stubs/martis/<name> exists', function () {
    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    $files->makeDirectory($this->stubsDir, 0755, true);
    $override = $this->stubsDir.'/resource.stub';
    $files->put($override, '/* override */');

    $resolved = StubResolver::path('resource.stub');

    expect($resolved)->toBe($override);
    expect($resolved)->not->toBe(StubResolver::packagePath('resource.stub'));
});

it('packagePath always returns the bundled stub regardless of override', function () {
    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    $files->makeDirectory($this->stubsDir, 0755, true);
    $files->put($this->stubsDir.'/resource.stub', '/* override */');

    $packagePath = StubResolver::packagePath('resource.stub');

    expect($packagePath)->toContain('martis-package/stubs/resource.stub');
    expect($packagePath)->not->toContain('stubs/martis');
});

it('packageDirectory points at the package stubs/ folder', function () {
    $dir = StubResolver::packageDirectory();
    expect(is_dir($dir))->toBeTrue();
    expect(file_exists($dir.'/resource.stub'))->toBeTrue();
});

it('install/sso/roles stubs route through StubResolver and respect user overrides', function () {
    // Each of these stubs was previously read directly from
    // `__DIR__.'/../../stubs/...'` (InstallCommand, SsoMakeCommand) or
    // `vendor/martis/martis/stubs/...` (RolesScaffoldCommand). They
    // were copied into `stubs/martis/` by `martis:stubs` but no command
    // ever read from there, so the override was inert. After v1.8.8
    // they all route through StubResolver — proving an override at
    // `stubs/martis/<name>` is the resolved path.
    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    $files->makeDirectory($this->stubsDir, 0755, true);

    $stubsToCheck = [
        'create_martis_action_events_table.php.stub',
        'create_user_preferences_table.php.stub',
        'create_martis_notifications_table.php.stub',
        'add_profile_picture_column.php.stub',
        'add_two_factor_columns.php.stub',
        'add_provider_group_column_to_roles_table.php.stub',
        'MartisServiceProvider.php.stub',
        'roles-policy.stub',
        'roles-seeder.stub',
        'roles-permission-resource.stub',
        'roles-role-resource.stub',
        'roles-user-resource.stub',
    ];

    foreach ($stubsToCheck as $stub) {
        $override = $this->stubsDir.'/'.$stub;
        $files->put($override, '/* override for '.$stub.' */');
        expect(StubResolver::path($stub))->toBe($override);
    }
});

it('overrides are scoped per-stub — an unrelated override does not affect resolution', function () {
    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    $files->makeDirectory($this->stubsDir, 0755, true);
    $files->put($this->stubsDir.'/lens.stub', '/* lens override only */');

    // Override exists for `lens.stub`, but `resource.stub` should still
    // resolve to the package default since no override was published for it.
    $resolved = StubResolver::path('resource.stub');

    expect($resolved)->toBe(StubResolver::packagePath('resource.stub'));
});
