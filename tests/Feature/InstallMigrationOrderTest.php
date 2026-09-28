<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Martis\Tests\Support\SkeletonSnapshot;
use Martis\Tests\TestCase;

/*
 * Laravel runs pending migrations sorted by file name. martis:install
 * publishes its migrations within one second, so each needs its own
 * timestamp, in publish order, or `alter_…` runs before `create_…`.
 */

/**
 * The migrations martis:install publishes with --with-profile, --with-2fa
 * and --with-sessions, in the order it publishes them.
 *
 * @return list<string>
 */
function martisInstallMigrationOrder(): array
{
    return [
        'create_martis_action_events_table',
        'alter_martis_action_events_morph_ids_to_string',
        'fix_martis_action_events_morph_ids_string_v2',
        'create_martis_user_preferences_table',
        'drop_dashboards_layout_from_user_preferences_table',
        'create_notifications_table',
        'create_martis_cache_state_table',
        'add_martis_profile_picture_column_to_users_table',
        'add_martis_two_factor_columns_to_users_table',
        'create_sessions_table',
    ];
}

// martis:install publishes migrations, the host provider, translations and
// the extension scaffold into the skeleton: put them back as this file
// found them.
beforeAll(function () {
    $GLOBALS['__martis_install_order_skeleton'] = SkeletonSnapshot::take(TestCase::applicationBasePath(), [
        'database/migrations',
        'app/Providers/MartisServiceProvider.php',
        'bootstrap/providers.php',
        'lang',
        'config/martis.php',
        'vite.extensions.config.ts',
        'tsconfig.extensions.json',
        'resources/js/martis-extensions',
        'package.json',
    ]);
});

afterAll(function () {
    $GLOBALS['__martis_install_order_skeleton']->restore();
});

beforeEach(function () {
    foreach (martisInstallMigrationOrder() as $name) {
        foreach (glob(database_path("migrations/*_{$name}.php")) ?: [] as $file) {
            unlink($file);
        }
    }

    // Environment fixture, not part of the behavior under test: the
    // testbench skeleton (unlike a real Laravel app) ships no
    // create_users_table migration, and TestCase::migrateFreshUsing()
    // points migrate:fresh at an empty path so refreshing the database
    // never creates one either. add_martis_profile_picture_column_to_users_table
    // and add_martis_two_factor_columns_to_users_table run Schema::table()
    // against `users` without a hasTable() guard (a real install always has
    // the column's target table), so `migrate` inside martis:install throws
    // "no such table: users" unless the table already exists. Same fixture
    // ConsoleCommandsTest.php uses for its martis:user tests.
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }
});

afterEach(function () {
    $filesystem = new Filesystem;

    foreach ([public_path('vendor/martis'), app_path('Martis')] as $dir) {
        $filesystem->deleteDirectory($dir);
    }
});

it('names the migrations martis:install publishes in publish order', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-27 19:30:35'));

    $this->artisan('martis:install', [
        '--no-interaction' => true,
        '--with-profile' => true,
        '--with-2fa' => true,
        '--with-sessions' => true,
    ])->assertSuccessful();

    $published = [];

    foreach (martisInstallMigrationOrder() as $name) {
        $files = glob(database_path("migrations/*_{$name}.php")) ?: [];
        expect($files)->toHaveCount(1);
        $published[] = basename($files[0]);
    }

    $sorted = $published;
    sort($sorted, SORT_STRING);

    expect($sorted)->toBe($published);
});
