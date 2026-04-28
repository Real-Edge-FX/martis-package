<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Martis\Console\StubsCommand;
use Martis\Stubs\StubResolver;

// -----------------------------------------------------------------------------
// martis:stubs — publish generator stubs into stubs/martis/ for customisation
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

it('martis:stubs is registered in the service provider', function () {
    $commands = $this->app->make(Kernel::class)->all();
    expect($commands)->toHaveKey('martis:stubs');
    expect($commands['martis:stubs'])->toBeInstanceOf(StubsCommand::class);
});

it('martis:stubs publishes every package stub into stubs/martis on a fresh project', function () {
    expect(is_dir($this->stubsDir))->toBeFalse();

    $this->artisan('martis:stubs')->assertSuccessful();

    expect(is_dir($this->stubsDir))->toBeTrue();

    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    $packageStubs = collect($files->files(StubResolver::packageDirectory()))
        ->map(fn ($f) => $f->getFilename())
        ->all();
    $publishedStubs = collect($files->files($this->stubsDir))
        ->map(fn ($f) => $f->getFilename())
        ->all();

    sort($packageStubs);
    sort($publishedStubs);

    expect($publishedStubs)->toEqual($packageStubs);
});

it('martis:stubs is idempotent — re-running skips existing files without --force', function () {
    $this->artisan('martis:stubs')->assertSuccessful();

    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    $target = $this->stubsDir.'/resource.stub';
    $files->put($target, '/* user override */');

    $this->artisan('martis:stubs')->assertSuccessful();

    expect($files->get($target))->toBe('/* user override */');
});

it('martis:stubs --force overwrites existing user overrides', function () {
    $this->artisan('martis:stubs')->assertSuccessful();

    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    $target = $this->stubsDir.'/resource.stub';
    $files->put($target, '/* user override */');

    $this->artisan('martis:stubs', ['--force' => true])->assertSuccessful();

    expect($files->get($target))->not->toBe('/* user override */');
    expect($files->get($target))->toContain('class {{ class }}');
});
