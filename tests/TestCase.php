<?php

namespace Martis\Tests;

use FilesystemIterator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Martis\MartisServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use RuntimeException;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

    /**
     * Do not load the `.env` of the testbench skeleton the tests run in.
     *
     * The skeleton lives under vendor/ and keeps what every run leaves in it.
     * `vendor/bin/testbench` (spawned by McpServeCommandTransportTest) puts
     * a `.env` there while it runs (a copy of the package root's `.env`,
     * `.env.example` or `.env.dist` when there is one, otherwise of the
     * skeleton's `.env.example`) and deletes it only when it exits cleanly,
     * so a killed subprocess leaves it behind. Loaded into the test application, its
     * `CACHE_STORE=database` and `SESSION_DRIVER=cookie` break every test
     * that touches the cache or the session (the test database has no
     * `cache` table, see migrateFreshUsing()). CI installs a fresh vendor/
     * on every run and never has the file, so the tests run on the
     * skeleton's config defaults there; ignoring the file does the same
     * locally.
     *
     * @var bool
     */
    protected $loadEnvironmentVariables = false;

    /**
     * The copy of the testbench skeleton this parallel worker runs in.
     */
    private static ?string $workerSkeleton = null;

    /**
     * Give each parallel worker a copy of the testbench skeleton of its own.
     *
     * The suite writes into the skeleton: martis:install publishes assets,
     * config and migrations, the generators write app/Martis classes, and
     * `vendor/bin/testbench` copies a `.env`. Under `pest --parallel` every
     * worker shared it, and one worker read the files another was writing or
     * deleting. Paratest sets TEST_TOKEN in each worker: the first test of a
     * worker copies the skeleton to a temporary directory named after its
     * process, and every application of that worker boots there. The copy
     * goes when the process exits; one whose process could not clean up
     * (Ctrl+C, SIGKILL) goes when the next copy is made. A sequential run,
     * CI's included, keeps the shared skeleton.
     */
    public static function applicationBasePath()
    {
        $token = getenv('TEST_TOKEN');

        if (! is_string($token) || $token === '') {
            return parent::applicationBasePath();
        }

        return self::$workerSkeleton ??= self::copySkeletonForWorker(parent::applicationBasePath(), $token);
    }

    private static function copySkeletonForWorker(string $skeleton, string $token): string
    {
        $root = sys_get_temp_dir().'/martis-testbench-'.substr(md5($skeleton), 0, 12);
        $copy = $root.'/'.getmypid().'-'.$token;
        $filesystem = new Filesystem;

        // A copy whose process is gone was left by a worker killed before its
        // shutdown function ran; one named after this process can only be a
        // leftover of an earlier process with the same pid. A live copy, a
        // sibling worker's or that of the process this one was started from,
        // is left alone.
        foreach (glob($root.'/*-*', GLOB_ONLYDIR) ?: [] as $sibling) {
            $pid = (int) strtok(basename($sibling), '-');

            if ($pid > 0 && ($pid === getmypid() || ! self::processIsRunning($pid))) {
                $filesystem->deleteDirectory($sibling);
            }
        }

        // `vendor` is the symlink `vendor/bin/testbench` creates, `.env` a
        // copy it can leave behind, and the log only grows run after run.
        self::copyDirectory($skeleton, $copy, ['vendor', '.env', 'storage/logs/laravel.log']);

        // deleteDirectory() removes a symlink without following it, so the
        // vendor/ a testbench subprocess links in here is left alone. Only the
        // process that made the copy removes it (a forked child inherits this
        // function), and the parent goes once the last copy is gone.
        $owner = getmypid();

        register_shutdown_function(static function () use ($filesystem, $copy, $owner): void {
            if (getmypid() === $owner) {
                $filesystem->deleteDirectory($copy);
                @rmdir(dirname($copy));
            }
        });

        $path = realpath($copy);

        if ($path === false) {
            throw new RuntimeException("Cannot resolve the testbench skeleton copy {$copy}.");
        }

        return $path;
    }

    /**
     * Whether a process exists (EPERM: it does, under another user). Without
     * the posix extension every process counts as running, so no copy is
     * ever taken for a leftover.
     */
    private static function processIsRunning(int $pid): bool
    {
        if (! function_exists('posix_kill')) {
            return true;
        }

        return posix_kill($pid, 0) || posix_get_last_error() === 1;
    }

    /**
     * Copy a directory tree, recreating symlinks instead of following them
     * and keeping each file's modification time: Blade compares a compiled
     * view's time with its source's, so a copy stamped "now" would pass a
     * stale compiled view off as current.
     *
     * @param  list<string>  $skip  Paths relative to $from.
     */
    private static function copyDirectory(string $from, string $to, array $skip, string $relative = ''): void
    {
        if (! is_dir($to) && ! @mkdir($to, 0777, true) && ! is_dir($to)) {
            throw self::copyFailure("create {$to}");
        }

        foreach (new FilesystemIterator($from, FilesystemIterator::SKIP_DOTS) as $item) {
            $path = ltrim($relative.'/'.$item->getFilename(), '/');
            $source = $item->getPathname();
            $target = $to.'/'.$item->getFilename();

            if (in_array($path, $skip, true)) {
                continue;
            }

            if ($item->isLink()) {
                $link = @readlink($source);

                if ($link === false || ! @symlink($link, $target)) {
                    throw self::copyFailure("link {$target} like {$source}");
                }
            } elseif ($item->isDir()) {
                self::copyDirectory($source, $target, $skip, $path);
            } elseif (! @copy($source, $target) || ! @touch($target, $item->getMTime())) {
                throw self::copyFailure("copy {$source} to {$target}");
            }
        }
    }

    private static function copyFailure(string $action): RuntimeException
    {
        return new RuntimeException("Cannot {$action} for the testbench skeleton copy: "
            .(error_get_last()['message'] ?? 'unknown error'));
    }

    protected function getPackageProviders($app): array
    {
        return [MartisServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('martis.middleware', ['web']);

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // The drivers the suite assumes. Without a `.env` they are the
        // skeleton's config defaults, but a variable exported in the shell
        // (APP_DEBUG, CACHE_STORE, SESSION_DRIVER, QUEUE_CONNECTION) still
        // reaches the config.
        $app['config']->set('app.debug', false);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('queue.default', 'sync');
    }

    /**
     * Point RefreshDatabase's `migrate:fresh` at an empty folder, so it
     * never scans the skeleton's `database/migrations/`. `martis:install`
     * publishes Martis migrations into that folder, and they stay there for
     * the tests that follow (and, in the shared skeleton, for later runs):
     * `migrate:fresh` ran them and crashed with "no such table users".
     *
     * Each test therefore starts from an empty in-memory database: the
     * `migrations` table plus the `martis_cache_state` table that
     * afterRefreshingDatabase() adds. A test creates the tables it needs.
     * Loading Laravel's migrations before the refresh does not provide
     * them: `migrate:fresh` drops every table first.
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--drop-views' => false,
            '--drop-types' => false,
            '--seed' => false,
            '--path' => __DIR__.'/migrations-empty',
            '--realpath' => true,
        ];
    }

    /**
     * Add the table every test's database starts with.
     */
    protected function afterRefreshingDatabase(): void
    {
        // v1.8.8 — operational metadata for the cache subsystem. Every
        // suite that exercises MartisCache writes to this table, so it
        // is part of the standard fixture.
        if (! Schema::hasTable('martis_cache_state')) {
            Schema::create('martis_cache_state', function (Blueprint $table) {
                $table->string('type')->primary();
                $table->unsignedInteger('version')->default(1);
                $table->timestamp('cleared_at')->nullable();
                $table->boolean('override')->nullable();
                $table->timestamps();
            });
        }
    }
}
