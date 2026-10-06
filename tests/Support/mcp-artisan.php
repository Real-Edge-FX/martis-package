<?php

declare(strict_types=1);

/*
 * Runs one Artisan command against a Testbench application the way a
 * host app's `php artisan` does: through the framework console kernel,
 * without Testbench's Commander, which installs its own SIGTERM/SIGINT
 * handlers. StdioTransportTest spawns `mcp:start martis-docs` through
 * this file so that signal handling matches a real application.
 *
 * Usage: php tests/Support/mcp-artisan.php <command> [args...]
 */

use Illuminate\Contracts\Console\Kernel;
use Martis\MartisServiceProvider;
use Orchestra\Testbench\Foundation\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

require __DIR__.'/../../vendor/autoload.php';

$app = Application::create(options: [
    'load_environment_variables' => false,
    'extra' => ['providers' => [MartisServiceProvider::class], 'dont-discover' => ['*']],
]);

$kernel = $app->make(Kernel::class);
$status = $kernel->handle($input = new ArgvInput, new ConsoleOutput);
$kernel->terminate($input, $status);

exit($status);
