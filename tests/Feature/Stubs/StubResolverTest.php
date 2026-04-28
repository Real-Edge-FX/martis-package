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
