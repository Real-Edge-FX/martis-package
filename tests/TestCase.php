<?php

namespace Martis\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Martis\MartisServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

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
     * never scans the skeleton's `database/migrations/`. Every test
     * process shares that folder and `martis:install` publishes Martis
     * migrations into it: in parallel mode, `migrate:fresh` in one worker
     * ran the migrations another worker had just published and crashed
     * with "no such table users".
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
