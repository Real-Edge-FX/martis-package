<?php

namespace Martis\Tests;

use Martis\MartisServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [MartisServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // App key required for session/encryption in HTTP tests
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

        // Remove auth middleware for testing
        $app['config']->set('martis.middleware', ['web']);
    }
}
