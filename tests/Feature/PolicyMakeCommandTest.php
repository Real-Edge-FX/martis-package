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

// -----------------------------------------------------------------------------
// The generated policy is deny-by-default (F116): a policy registered without
// an edit must fail closed, not hand every panel user full write access.
// -----------------------------------------------------------------------------

it('martis:policy generates a deny-by-default policy: every ability returns false', function () {
    // The generated file type-hints the app's User model; the testbench
    // skeleton has none, so alias the framework's.
    if (! class_exists(\App\Models\User::class)) {
        class_alias(\Illuminate\Foundation\Auth\User::class, 'App\Models\User');
    }

    $this->artisan('martis:policy', ['name' => 'DenyByDefaultProbePolicy'])->assertSuccessful();

    require_once $this->policyDir.'/DenyByDefaultProbePolicy.php';

    $policy = new \App\Martis\Policies\DenyByDefaultProbePolicy;
    $user = new \App\Models\User;
    $record = new class extends \Illuminate\Database\Eloquent\Model {};

    $abilities = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass($policy))->getMethods(ReflectionMethod::IS_PUBLIC),
    );
    sort($abilities);

    // Every resource and action ability Martis asks the policy about is
    // generated, so none falls back to a Martis default (viewAny and the
    // relationship abilities default to allowed, the rest to the update/delete
    // fallbacks) that the developer never saw.
    expect($abilities)->toBe([
        'create', 'delete', 'forceDelete', 'replicate', 'restore',
        'runAction', 'runDestructiveAction', 'update', 'view', 'viewAny',
    ]);

    foreach ($abilities as $ability) {
        $arguments = match ($ability) {
            'viewAny', 'create' => [$user],
            default => [$user, $record],
        };

        expect($policy->{$ability}(...$arguments))->toBeFalse("{$ability} must deny until the developer edits it");
    }
});

it('martis:policy leaves a TODO on every ability so the edit is not forgotten', function () {
    $this->artisan('martis:policy', ['name' => 'TodoProbePolicy'])->assertSuccessful();

    $source = (string) file_get_contents($this->policyDir.'/TodoProbePolicy.php');

    // One TODO per generated ability, and no ability that allows anything.
    expect(substr_count($source, '// TODO'))->toBeGreaterThanOrEqual(10)
        ->and($source)->not->toMatch('/function (?!add|attach|detach)\w+\([^)]*\)\s*:\s*bool\s*\{[^}]*return true;/s')
        ->and($source)->toContain('deny-by-default');
});
