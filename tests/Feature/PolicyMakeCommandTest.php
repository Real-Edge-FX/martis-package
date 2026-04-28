<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Martis\Console\PolicyMakeCommand;

// -----------------------------------------------------------------------------
// martis:policy — generator (renamed from martis:make-policy in v1.1)
// -----------------------------------------------------------------------------

beforeEach(function () {
    $this->policyDir = base_path('App/Martis/Policies');
    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    if ($files->isDirectory($this->policyDir)) {
        $files->deleteDirectory($this->policyDir);
    }
});

afterEach(function () {
    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    if ($files->isDirectory($this->policyDir)) {
        $files->deleteDirectory($this->policyDir);
    }
});

it('martis:policy is registered with the canonical name', function () {
    $commands = $this->app->make(Kernel::class)->all();
    expect($commands)->toHaveKey('martis:policy');
    expect($commands['martis:policy'])->toBeInstanceOf(PolicyMakeCommand::class);
});

it('martis:make-policy is registered as a back-compat alias and runs the same generator', function () {
    // The historical name (v0.x) is kept as an alias on PolicyMakeCommand
    // so existing tooling does not break after the v1.1 rename.
    $this->artisan('martis:make-policy', ['name' => 'BackCompatPolicy'])->assertSuccessful();

    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    expect($files->exists($this->policyDir.'/BackCompatPolicy.php'))->toBeTrue();
});

it('martis:policy generates a policy file under the configured namespace', function () {
    $this->artisan('martis:policy', ['name' => 'TestPolicy'])->assertSuccessful();

    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    $generated = $this->policyDir.'/TestPolicy.php';

    expect($files->exists($generated))->toBeTrue();
    expect($files->get($generated))->toContain('class TestPolicy');
});
