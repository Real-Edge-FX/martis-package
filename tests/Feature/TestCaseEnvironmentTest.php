<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Env;
use Symfony\Component\Process\Process;

// The test application runs in the testbench skeleton under vendor/, which
// keeps what every run leaves in it, and `vendor/bin/testbench` leaves a
// `.env` there when it is killed (see McpServeCommandTransportTest). Loaded into
// the test application, that file switched the cache store to `database`
// and the session driver to `cookie`: 1045 tests failed locally while CI,
// which installs a fresh vendor/ on every run, stayed green. CI never has
// such a file, so only this test can tell when the suite reads it again.
it('does not load the .env of the application it tests', function () {
    $key = 'MARTIS_TEST_ENV_FILE_PROBE';
    $dir = sys_get_temp_dir().'/martis-env-probe-'.bin2hex(random_bytes(6));
    mkdir($dir);
    file_put_contents($dir.'/.env', "{$key}=loaded\n");

    // A throwaway application, so the probe file never goes near a skeleton
    // the suite boots in.
    $container = Container::getInstance();
    $app = new Application(base_path());
    $app->useEnvironmentPath($dir);

    try {
        // The step of TestCase::createApplication() that reads the `.env`.
        $this->resolveApplicationEnvironmentVariables($app);

        expect(Env::get($key))->toBeNull();
    } finally {
        Container::setInstance($container);
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
        rmtree($dir);
    }
});

// Under `pest --parallel` every worker wrote into that shared skeleton too,
// and read the files another worker was writing or deleting (published
// assets and config, generated app/Martis classes). CI runs the suite in one
// process, so this test drives the worker branch in a subprocess that holds
// a worker token of its own.
it('gives a parallel worker a copy of the testbench skeleton of its own', function () {
    $script = tempnam(sys_get_temp_dir(), 'martis-skeleton-probe-');
    file_put_contents($script, <<<'PHP'
        <?php
        require $argv[1].'/vendor/autoload.php';

        $path = Martis\Tests\TestCase::applicationBasePath();

        echo json_encode([
            'path' => $path,
            'shared' => Orchestra\Testbench\default_skeleton_path(),
            'default_skeleton' => Orchestra\Testbench\uses_default_skeleton($path),
            'config' => is_dir($path.'/config'),
            'vendor' => file_exists($path.'/vendor') || is_link($path.'/vendor'),
            'env' => file_exists($path.'/.env'),
            'memoised' => Martis\Tests\TestCase::applicationBasePath() === $path,
        ]);
        PHP);

    try {
        $process = new Process([PHP_BINARY, $script, dirname(__DIR__, 2)], null, [
            'TEST_TOKEN' => '99',
            'UNIQUE_TEST_TOKEN' => '99_'.bin2hex(random_bytes(6)),
        ]);
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        $probe = json_decode($process->getOutput(), true);

        expect($probe['path'])->not->toBe($probe['shared'])
            // Still testbench's default skeleton, so the application boots the same way.
            ->and($probe['default_skeleton'])->toBeTrue()
            ->and($probe['config'])->toBeTrue()
            // Neither the vendor/ symlink `vendor/bin/testbench` creates nor a leftover `.env`.
            ->and($probe['vendor'])->toBeFalse()
            ->and($probe['env'])->toBeFalse()
            ->and($probe['memoised'])->toBeTrue()
            // Removed when the worker exits.
            ->and(is_dir($probe['path']))->toBeFalse();
    } finally {
        @unlink($script);
    }
});
