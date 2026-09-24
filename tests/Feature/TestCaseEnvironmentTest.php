<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Env;

// The test application runs in the testbench skeleton under vendor/, which
// every test process shares, and `vendor/bin/testbench` leaves a `.env`
// there when it is killed (see McpServeCommandTransportTest). Loaded into
// the test application, that file switched the cache store to `database`
// and the session driver to `cookie`: 1045 tests failed locally while CI,
// which installs a fresh vendor/ on every run, stayed green. CI never has
// such a file, so only this test can tell when the suite reads it again.
it('does not load the .env of the application it tests', function () {
    $key = 'MARTIS_TEST_ENV_FILE_PROBE';
    $dir = sys_get_temp_dir().'/martis-env-probe-'.bin2hex(random_bytes(6));
    mkdir($dir);
    file_put_contents($dir.'/.env', "{$key}=loaded\n");

    // A throwaway application, so the probe file never goes near the shared
    // skeleton that parallel workers read.
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
