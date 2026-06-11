<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Filesystem\Filesystem;
use Martis\Console\InstallCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * End-to-end test for the `updatePackageJsonDeps()` path: writes a
 * fake host `package.json`, points Laravel's base_path at a tmp dir,
 * runs the protected method via reflection, and asserts the on-disk
 * result. Covers the integration between the pure resolver and the
 * file write.
 */
beforeEach(function () {
    $this->base = sys_get_temp_dir().'/martis-install-pkgjson-'.uniqid();
    mkdir($this->base, 0755, true);
    $this->originalBase = base_path();
    app()->setBasePath($this->base);
});

afterEach(function () {
    if (isset($this->originalBase)) {
        app()->setBasePath($this->originalBase);
    }
    if (isset($this->base) && is_dir($this->base)) {
        rmtree($this->base);
    }
    putenv('MARTIS_PLUGIN_REACT_RANGE');
});

function runUpdatePackageJsonDeps(): InstallCommand
{
    $command = app(InstallCommand::class);
    $command->setLaravel(app());

    $output = new OutputStyle(
        new ArrayInput([]),
        new BufferedOutput,
    );
    $command->setOutput($output);

    // `$this->components` is initialised by Command::run(); we are
    // calling a protected helper in isolation, so bootstrap it the
    // same way the framework does.
    $componentsRef = new ReflectionProperty(Command::class, 'components');
    $componentsRef->setAccessible(true);
    $componentsRef->setValue($command, app()->make(
        Factory::class,
        ['output' => $output],
    ));

    $ref = new ReflectionMethod(InstallCommand::class, 'updatePackageJsonDeps');
    $ref->setAccessible(true);
    $ref->invoke($command, new Filesystem);

    return $command;
}

it('writes vite + plugin-react from the table when the host has vite ^7', function () {
    file_put_contents($this->base.'/package.json', json_encode([
        'devDependencies' => ['vite' => '^7'],
    ]));

    runUpdatePackageJsonDeps();

    $pkg = json_decode((string) file_get_contents($this->base.'/package.json'), true);
    expect($pkg['devDependencies']['vite'])->toBe('^7')
        ->and($pkg['devDependencies']['@vitejs/plugin-react'])->toBe('^5')
        ->and($pkg['dependencies']['react'])->toBe('^18 || ^19');
});

it('respects the env override on the actual write path', function () {
    putenv('MARTIS_PLUGIN_REACT_RANGE=^7');
    file_put_contents($this->base.'/package.json', json_encode([
        'devDependencies' => ['vite' => '^10'],
    ]));

    runUpdatePackageJsonDeps();

    $pkg = json_decode((string) file_get_contents($this->base.'/package.json'), true);
    expect($pkg['devDependencies']['vite'])->toBe('^10')
        ->and($pkg['devDependencies']['@vitejs/plugin-react'])->toBe('^7');
});

it('skips writing @vitejs/plugin-react when the host vite is beyond the compat table', function () {
    file_put_contents($this->base.'/package.json', json_encode([
        'devDependencies' => ['vite' => '^10'],
    ]));

    runUpdatePackageJsonDeps();

    $pkg = json_decode((string) file_get_contents($this->base.'/package.json'), true);
    expect($pkg['devDependencies']['vite'])->toBe('^10')
        ->and($pkg['devDependencies'])->not->toHaveKey('@vitejs/plugin-react');
});

it('never overwrites a host-set @vitejs/plugin-react constraint', function () {
    file_put_contents($this->base.'/package.json', json_encode([
        'devDependencies' => [
            'vite' => '^7',
            '@vitejs/plugin-react' => '^4.3.1',
        ],
    ]));

    runUpdatePackageJsonDeps();

    $pkg = json_decode((string) file_get_contents($this->base.'/package.json'), true);
    expect($pkg['devDependencies']['@vitejs/plugin-react'])->toBe('^4.3.1');
});

it('seeds vite + plugin-react when the host package.json has no devDependencies block', function () {
    file_put_contents($this->base.'/package.json', json_encode(['name' => 'acme/demo']));

    runUpdatePackageJsonDeps();

    $pkg = json_decode((string) file_get_contents($this->base.'/package.json'), true);
    expect($pkg['devDependencies']['vite'])->toBe('^7')
        ->and($pkg['devDependencies']['@vitejs/plugin-react'])->toBe('^5');
});
