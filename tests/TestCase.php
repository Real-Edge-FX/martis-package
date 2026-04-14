<?php

namespace Martis\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
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
        $this->cleanupPublishedMartisMigrations();
        parent::setUp();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        $this->cleanupPublishedMartisMigrations();
        parent::tearDown();
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

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
    }

    /**
     * Add profile-related columns to the users table after all migrations run.
     *
     * This allows profile feature tests to work without publishing migrations.
     */
    protected function afterRefreshingDatabase(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'profile_picture')) {
                $table->string('profile_picture')->nullable();
            }
            if (! Schema::hasColumn('users', 'two_factor_secret')) {
                $table->text('two_factor_secret')->nullable();
            }
            if (! Schema::hasColumn('users', 'two_factor_recovery_codes')) {
                $table->text('two_factor_recovery_codes')->nullable();
            }
            if (! Schema::hasColumn('users', 'two_factor_confirmed_at')) {
                $table->timestamp('two_factor_confirmed_at')->nullable();
            }
        });
    }

    protected function cleanupPublishedMartisMigrations(): void
    {
        $filesystem = new Filesystem;
        $migrationPath = is_object($this->app) && method_exists($this->app, 'databasePath')
            ? $this->app->databasePath('migrations')
            : __DIR__.'/../vendor/orchestra/testbench-core/laravel/database/migrations';

        collect(glob($migrationPath.'/*_create_action_events_table.php') ?: [])->each(
            fn (string $path) => $filesystem->delete($path)
        );

        collect(glob($migrationPath.'/*_add_martis_profile_columns.php') ?: [])->each(
            fn (string $path) => $filesystem->delete($path)
        );
    }
}
