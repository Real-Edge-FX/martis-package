<?php

use Illuminate\Support\Facades\Schema;
use Martis\Stubs\StubResolver;

// -----------------------------------------------------------------------------
// The key-type-aware invitations migration stub
// (create_invitations_table.php.stub). Portable schema stub only — no
// model or behaviour ships yet (see Task 1: config block + martis-invite
// gate, both default-off).
// -----------------------------------------------------------------------------

beforeEach(function () {
    Schema::dropIfExists('invitations');

    // The `invited_by` / `accepted_user_id` FK columns need a `users`
    // table to reference. RefreshDatabase's in-memory-SQLite fixture
    // does not reliably surface `defineDatabaseMigrations()`'s `users`
    // table by the time a test body runs (its in-memory connection is
    // swapped for a separately cached one first) — every Feature test
    // that needs `users` provisions it locally the same way (see
    // AvatarServiceTest::avatarTestUser()).
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }
});

afterEach(function () {
    Schema::dropIfExists('invitations');
});

it('creates a portable invitations table with the expected columns', function () {
    $migration = require StubResolver::path('create_invitations_table.php.stub');
    $migration->up();

    expect(Schema::hasTable('invitations'))->toBeTrue();
    foreach (['id', 'email', 'token', 'status', 'role', 'invited_by', 'accepted_user_id', 'expires_at', 'accepted_at', 'metadata', 'created_at', 'updated_at'] as $col) {
        expect(Schema::hasColumn('invitations', $col))->toBeTrue();
    }
});

it('is idempotent — running it twice does not error', function () {
    $migration = require StubResolver::path('create_invitations_table.php.stub');
    $migration->up();
    $migration->up();

    expect(Schema::hasTable('invitations'))->toBeTrue();
});

it('marks email as a non-unique index and token as unique', function () {
    $migration = require StubResolver::path('create_invitations_table.php.stub');
    $migration->up();

    $indexes = collect(Schema::getIndexes('invitations'));

    $tokenIndex = $indexes->first(fn ($i) => in_array('token', $i['columns'], true));
    expect($tokenIndex)->not->toBeNull();
    expect($tokenIndex['unique'])->toBeTrue();

    $emailIndex = $indexes->first(fn ($i) => $i['columns'] === ['email']);
    expect($emailIndex)->not->toBeNull();
    expect($emailIndex['unique'])->toBeFalse();
});

it('creates a bigint invited_by/accepted_user_id when the host key type is bigint', function () {
    config(['martis.user_id_column_type' => 'bigint']);

    $migration = require StubResolver::path('create_invitations_table.php.stub');
    $migration->up();

    foreach (['invited_by', 'accepted_user_id'] as $column) {
        expect(Schema::getColumnType('invitations', $column))->toContain('int');
    }
});

it('creates a non-integer (uuid) invited_by/accepted_user_id when the host key type is uuid', function () {
    config(['martis.user_id_column_type' => 'uuid']);
    Schema::dropIfExists('users');
    Schema::create('users', function ($table) {
        $table->uuid('id')->primary();
        $table->string('email')->unique();
    });

    $migration = require StubResolver::path('create_invitations_table.php.stub');
    $migration->up();

    foreach (['invited_by', 'accepted_user_id'] as $column) {
        expect(Schema::getColumnType('invitations', $column))->not->toContain('int');
    }
});
