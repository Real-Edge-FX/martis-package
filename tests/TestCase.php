<?php

namespace Martis\Tests;

use FilesystemIterator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Martis\MartisServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

    /**
     * Do not load the `.env` of the testbench skeleton the tests run in.
     *
     * The skeleton lives under vendor/ and keeps what every run leaves in it.
     * `vendor/bin/testbench` (spawned by McpServeCommandTransportTest)
     * copies the skeleton's `.env.example` to `.env` while it runs and
     * deletes the copy only when it exits cleanly, so a killed subprocess
     * leaves it behind. Loaded into the test application, its
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
     * worker copies the skeleton to a temporary directory, every application
     * of that worker boots there, and the copy is removed when the worker
     * exits. A sequential run, CI's included, keeps the shared skeleton.
     */
    public static function applicationBasePath()
    {
        $token = getenv('TEST_TOKEN');

        if (! is_string($token) || $token === '') {
            return parent::applicationBasePath();
        }

        return self::$workerSkeleton ??= self::copySkeletonForWorker(parent::applicationBasePath());
    }

    private static function copySkeletonForWorker(string $skeleton): string
    {
        $copy = sys_get_temp_dir().'/martis-testbench-'.substr(md5($skeleton), 0, 12).'/'
            .(getenv('UNIQUE_TEST_TOKEN') ?: getenv('TEST_TOKEN'));

        $filesystem = new Filesystem;
        $filesystem->deleteDirectory($copy);

        // `vendor` is the symlink `vendor/bin/testbench` creates, `.env` a
        // copy it can leave behind, and the log only grows run after run.
        self::copyDirectory($skeleton, $copy, ['vendor', '.env', 'storage/logs/laravel.log']);

        // deleteDirectory() removes a symlink without following it, so the
        // vendor/ a testbench subprocess links in here is left alone. The
        // parent goes once the last worker's copy is gone.
        register_shutdown_function(static function () use ($filesystem, $copy): void {
            $filesystem->deleteDirectory($copy);
            @rmdir(dirname($copy));
        });

        return (string) realpath($copy);
    }

    /**
     * Copy a directory tree, recreating symlinks instead of following them.
     *
     * @param  list<string>  $skip  Paths relative to $from.
     */
    private static function copyDirectory(string $from, string $to, array $skip, string $relative = ''): void
    {
        if (! is_dir($to)) {
            mkdir($to, 0777, true);
        }

        foreach (new FilesystemIterator($from, FilesystemIterator::SKIP_DOTS) as $item) {
            $path = ltrim($relative.'/'.$item->getFilename(), '/');
            $target = $to.'/'.$item->getFilename();

            if (in_array($path, $skip, true)) {
                continue;
            }

            if ($item->isLink()) {
                symlink((string) readlink($item->getPathname()), $target);
            } elseif ($item->isDir()) {
                self::copyDirectory($item->getPathname(), $target, $skip, $path);
            } else {
                copy($item->getPathname(), $target);
            }
        }
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
