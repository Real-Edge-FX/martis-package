<?php

use Illuminate\Support\Facades\Schema;
use Martis\Stubs\StubResolver;

// ---------------------------------------------------------------------------
// The key-type-aware sessions migration stub (create_sessions_table.php.stub)
// ---------------------------------------------------------------------------

afterEach(function () {
    Schema::dropIfExists('sessions');
    Schema::dropIfExists('mtx_sessions');
    // Remove any sessions migration a driving install published.
    foreach ((array) glob(database_path('migrations/*_create_sessions_table.php')) as $f) {
        if (is_string($f)) {
            @unlink($f);
        }
    }
});

it('creates a bigint user_id when the host key type is bigint', function () {
    config(['martis.user_id_column_type' => 'bigint', 'session.table' => 'sessions']);
    Schema::dropIfExists('sessions');

    $migration = require StubResolver::path('create_sessions_table.php.stub');
    $migration->up();

    expect(Schema::hasTable('sessions'))->toBeTrue();
    expect(Schema::hasColumn('sessions', 'user_id'))->toBeTrue();
    // bigint/integer affinity — NOT a string column.
    expect(Schema::getColumnType('sessions', 'user_id'))->toContain('int');
});

it('creates a non-integer (uuid) user_id when the host key type is uuid', function () {
    config(['martis.user_id_column_type' => 'uuid', 'session.table' => 'sessions']);
    Schema::dropIfExists('sessions');

    $migration = require StubResolver::path('create_sessions_table.php.stub');
    $migration->up();

    // A UUID-keyed host must NOT get a bigint column (the Postgres failure the
    // report describes). The column is a string/char type instead.
    expect(Schema::getColumnType('sessions', 'user_id'))->not->toContain('int');
});

it('matches the Laravel session schema (id, user_id, ip_address, user_agent, payload, last_activity)', function () {
    config(['martis.user_id_column_type' => 'bigint', 'session.table' => 'sessions']);
    Schema::dropIfExists('sessions');

    $migration = require StubResolver::path('create_sessions_table.php.stub');
    $migration->up();

    foreach (['id', 'user_id', 'ip_address', 'user_agent', 'payload', 'last_activity'] as $col) {
        expect(Schema::hasColumn('sessions', $col))->toBeTrue();
    }
});

it('is idempotent — leaves a pre-existing sessions table untouched', function () {
    config(['session.table' => 'sessions']);
    Schema::dropIfExists('sessions');
    // A pre-existing table (e.g. the host already ran `php artisan session:table`)
    // with a DIFFERENT shape — the stub must not touch it.
    Schema::create('sessions', function ($t) {
        $t->string('id')->primary();
        $t->string('marker');
    });

    $migration = require StubResolver::path('create_sessions_table.php.stub');
    $migration->up();

    expect(Schema::hasColumn('sessions', 'marker'))->toBeTrue();
    expect(Schema::hasColumn('sessions', 'payload'))->toBeFalse();
});

it('honours a custom session.table name', function () {
    config(['martis.user_id_column_type' => 'bigint', 'session.table' => 'mtx_sessions']);
    Schema::dropIfExists('mtx_sessions');

    $migration = require StubResolver::path('create_sessions_table.php.stub');
    $migration->up();

    expect(Schema::hasTable('mtx_sessions'))->toBeTrue();
    expect(Schema::hasTable('sessions'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Install preflight + provisioning flag
// ---------------------------------------------------------------------------

it('warns at install time when the sessions section is active but the driver is not database', function () {
    config([
        'martis.profile.sections' => ['account', 'sessions'],
        'session.driver' => 'file',
    ]);

    $this->artisan('martis:install')
        ->expectsOutputToContain('SESSION_DRIVER=database')
        ->assertSuccessful();
})->group('sessions-install');

it('does not warn when the sessions section is absent', function () {
    config([
        'martis.profile.sections' => ['account', 'password'],
        'session.driver' => 'file',
    ]);

    $this->artisan('martis:install')
        ->doesntExpectOutputToContain('SESSION_DRIVER=database')
        ->assertSuccessful();
})->group('sessions-install');

it('publishes the sessions migration with --with-sessions', function () {
    config(['martis.profile.sections' => ['account', 'sessions']]);

    foreach ((array) glob(database_path('migrations/*_create_sessions_table.php')) as $f) {
        if (is_string($f)) {
            @unlink($f);
        }
    }

    $this->artisan('martis:install', ['--with-sessions' => true])->assertSuccessful();

    $published = (array) glob(database_path('migrations/*_create_sessions_table.php'));
    expect($published)->not->toBeEmpty();
})->group('sessions-install');

it('--with-sessions forces provisioning even when the section is removed', function () {
    // Explicit flag is absolute: it bypasses the section-membership gate.
    config(['martis.profile.sections' => ['account', 'password']]);

    foreach ((array) glob(database_path('migrations/*_create_sessions_table.php')) as $f) {
        if (is_string($f)) {
            @unlink($f);
        }
    }

    $this->artisan('martis:install', ['--with-sessions' => true])->assertSuccessful();

    expect((array) glob(database_path('migrations/*_create_sessions_table.php')))->not->toBeEmpty();
})->group('sessions-install');

it('--no-sessions skips provisioning even when the section is active', function () {
    config(['martis.profile.sections' => ['account', 'sessions']]);

    foreach ((array) glob(database_path('migrations/*_create_sessions_table.php')) as $f) {
        if (is_string($f)) {
            @unlink($f);
        }
    }

    $this->artisan('martis:install', ['--no-sessions' => true])->assertSuccessful();

    expect((array) glob(database_path('migrations/*_create_sessions_table.php')))->toBeEmpty();
})->group('sessions-install');
