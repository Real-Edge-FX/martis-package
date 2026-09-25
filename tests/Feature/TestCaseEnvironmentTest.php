<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Env;
use Symfony\Component\Process\Exception\ProcessSignaledException;
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

    // A throwaway application based in the probe directory: the probe file
    // never goes near a skeleton the suite boots in, and a cached config in
    // the shared skeleton (bootstrap/cache/config.php makes the loader skip
    // the `.env`) cannot hide a regression.
    $container = Container::getInstance();
    $app = new Application($dir);

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

// Without a `.env` the drivers are the skeleton's config defaults, but a
// variable exported in the shell still reaches the config, so the suite pins
// the ones it assumes.
it('pins the drivers the suite assumes over the environment', function () {
    $app = new Container;
    $app['config'] = new Repository([
        'app' => ['debug' => true],
        'cache' => ['default' => 'redis'],
        'session' => ['driver' => 'file'],
        'queue' => ['default' => 'database'],
    ]);

    $this->defineEnvironment($app);

    expect($app['config']->get('app.debug'))->toBeFalse()
        ->and($app['config']->get('cache.default'))->toBe('array')
        ->and($app['config']->get('session.driver'))->toBe('array')
        ->and($app['config']->get('queue.default'))->toBe('sync');
});

// Under `pest --parallel` every worker wrote into that shared skeleton too,
// and read the files another worker was writing or deleting (published
// assets and config, generated app/Martis classes). CI runs the suite in one
// process, so this test drives the worker branch in subprocesses that hold
// a worker token: a worker killed before it could clean up, then a worker
// that starts a process of its own with the same environment.
it('gives a parallel worker a copy of the testbench skeleton of its own', function () {
    $script = tempnam(sys_get_temp_dir(), 'martis-skeleton-probe-');
    file_put_contents($script, <<<'PHP'
        <?php
        require $argv[1].'/vendor/autoload.php';

        $path = Martis\Tests\TestCase::applicationBasePath();
        $mode = $argv[2];

        if ($mode !== 'worker') {
            echo $path;

            if ($mode === 'killed') {
                // Ctrl+C or SIGKILL: the shutdown function never runs.
                posix_kill(getmypid(), 9);
            }

            exit(0);
        }

        $shared = Orchestra\Testbench\default_skeleton_path();
        $child = (string) shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.escapeshellarg($argv[1]).' child');

        echo json_encode([
            'path' => $path,
            'shared' => $shared,
            'default_skeleton' => Orchestra\Testbench\uses_default_skeleton($path),
            'config' => is_dir($path.'/config'),
            'vendor' => file_exists($path.'/vendor') || is_link($path.'/vendor'),
            'env' => file_exists($path.'/.env'),
            'memoised' => Martis\Tests\TestCase::applicationBasePath() === $path,
            'mtime_kept' => filemtime($path.'/config/app.php') === filemtime($shared.'/config/app.php'),
            'leftover' => is_dir($argv[3]),
            'child' => $child,
            'child_copy_left' => $child !== '' && is_dir($child),
            'copy_after_child' => is_dir($path),
        ]);
        PHP);

    $env = ['TEST_TOKEN' => '99'];

    try {
        $killed = new Process([PHP_BINARY, $script, dirname(__DIR__, 2), 'killed'], null, $env);

        try {
            $killed->run();
        } catch (ProcessSignaledException) {
            // The SIGKILL it sent itself.
        }

        $leftover = trim($killed->getOutput());

        expect($leftover)->not->toBe('')
            ->and(is_dir($leftover))->toBeTrue('a killed worker leaves its copy behind');

        $worker = new Process([PHP_BINARY, $script, dirname(__DIR__, 2), 'worker', $leftover], null, $env);
        $worker->run();

        expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());

        $probe = json_decode($worker->getOutput(), true);

        expect($probe['path'])->not->toBe($probe['shared'])
            // Still testbench's default skeleton, so the application boots the same way.
            ->and($probe['default_skeleton'])->toBeTrue()
            ->and($probe['config'])->toBeTrue()
            // Neither the vendor/ symlink `vendor/bin/testbench` creates nor a leftover `.env`.
            ->and($probe['vendor'])->toBeFalse()
            ->and($probe['env'])->toBeFalse()
            ->and($probe['memoised'])->toBeTrue()
            // Blade tells a stale compiled view by its time, so a copy keeps it.
            ->and($probe['mtime_kept'])->toBeTrue()
            // The killed worker's copy went when this one made its own.
            ->and($probe['leftover'])->toBeFalse()
            // A process started from the worker gets a copy of its own, removes
            // it when it exits, and leaves the worker's copy alone.
            ->and($probe['child'])->not->toBe('')->not->toBe($probe['path'])
            ->and($probe['child_copy_left'])->toBeFalse()
            ->and($probe['copy_after_child'])->toBeTrue()
            // Removed when the worker exits.
            ->and(is_dir($probe['path']))->toBeFalse();

        // A sequential run (no worker token) gets a copy too: nothing writes
        // the skeleton under vendor/.
        $sequential = new Process([PHP_BINARY, $script, dirname(__DIR__, 2), 'child'], null, ['TEST_TOKEN' => false]);
        $sequential->run();

        expect($sequential->isSuccessful())->toBeTrue($sequential->getErrorOutput())
            ->and(trim($sequential->getOutput()))->toEndWith('-seq')
            ->not->toBe($probe['shared']);
    } finally {
        @unlink($script);
    }
});

// After a Ctrl+C in a parallel run every new worker finds the same orphan
// copies on its first test. They used to delete them all at once, and the
// ones that lost the race threw from applicationBasePath() (a directory
// another worker had just removed), failing every test of that worker.
it('lets concurrent workers sweep the same orphan copies', function () {
    $script = tempnam(sys_get_temp_dir(), 'martis-skeleton-sweep-');
    file_put_contents($script, <<<'PHP'
        <?php
        require $argv[1].'/vendor/autoload.php';

        echo Martis\Tests\TestCase::applicationBasePath(), "\n";
        fflush(STDOUT);

        if ($argv[2] === 'held') {
            // Alive until the test SIGKILLs it, like a worker hit by Ctrl+C.
            sleep(60);
        }
        PHP);

    $run = fn (string $mode): Process => new Process(
        [PHP_BINARY, $script, dirname(__DIR__, 2), $mode],
        null,
        ['TEST_TOKEN' => '98'],
    );

    try {
        // Three workers alive at once (a live copy is never swept), then
        // killed before their shutdown functions run.
        $held = array_map(fn (): Process => $run('held'), range(1, 3));

        foreach ($held as $process) {
            $process->start();
        }

        $orphans = [];

        foreach ($held as $process) {
            $deadline = microtime(true) + 30;

            while (! str_contains($process->getOutput(), "\n") && $process->isRunning() && microtime(true) < $deadline) {
                usleep(20_000);
            }

            $orphans[] = trim($process->getOutput());
        }

        foreach ($held as $process) {
            $process->signal(9);
            $process->wait();
        }

        expect(array_filter($orphans, 'is_dir'))->toHaveCount(3);

        $workers = array_map(fn (): Process => $run('worker'), range(1, 4));

        foreach ($workers as $worker) {
            $worker->start();
        }

        foreach ($workers as $worker) {
            $worker->wait();

            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput())
                ->and(trim($worker->getOutput()))->not->toBe('');
        }

        expect(array_filter($orphans, 'is_dir'))->toBe([])
            // Nor a claimed orphan left half-deleted.
            ->and(glob(dirname($orphans[0]).'/.*.claimed-*') ?: [])->toBe([]);
    } finally {
        @unlink($script);
    }
});
