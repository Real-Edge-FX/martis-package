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

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
    }

    /**
     * Override RefreshDatabase's `migrate:fresh` so it does NOT scan the
     * shared testbench `database/migrations/` folder. In parallel mode,
     * `martis:install` from another worker leaves published Martis
     * migrations in that folder; if `migrate:fresh` runs them before
     * the testbench's bundled `users` migration (loaded via
     * `loadLaravelMigrations`), they crash with "no such table users".
     *
     * This override switches the migrator to a directory that does
     * not exist, leaving testbench's bundled migrations as the sole
     * source. Each test run is therefore isolated from artefacts of
     * any other parallel worker.
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

}
