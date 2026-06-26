<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;

/**
 * Data-driven coverage for the user-PK resolver inlined in each of the
 * three Martis migration stubs:
 *
 *   • stubs/create_user_preferences_table.php.stub
 *   • stubs/create_martis_action_events_table.php.stub
 *   • stubs/create_martis_notifications_table.php.stub
 *
 * Each stub introspects `auth.providers.{provider}.model`, looks for
 * the canonical `HasUuids` / `HasUlids` traits, and falls back to
 * `getKeyType()` + `getIncrementing()`. A config override
 * (`martis.user_id_column_type`) trumps the auto-detection.
 *
 * The tests below build a fake `users` table per shape (bigint / uuid
 * / ulid / generic string), pin the matching test user model on the
 * auth provider config, and run each published stub against SQLite.
 * Assertions check that the migration completed AND that the
 * `user_id` (or polymorphic `notifiable_*`) columns are typed the
 * way the host expects.
 */
class BigintTestUser extends Authenticatable {}

class UuidTestUser extends Authenticatable
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;
}

class UlidTestUser extends Authenticatable
{
    use HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;
}

class StringTestUser extends Authenticatable
{
    protected $keyType = 'string';

    public $incrementing = false;
}

beforeEach(function () {
    foreach (['martis_user_preferences', 'martis_action_events', 'notifications', 'users'] as $t) {
        Schema::dropIfExists($t);
    }
    config()->set('martis.user_id_column_type', null);
    config()->set('martis.guard', null);
});

function loadStub(string $basename): Migration
{
    $stub = (string) file_get_contents(__DIR__.'/../../stubs/'.$basename);
    $tmp = tempnam(sys_get_temp_dir(), 'martis_stub_').'.php';
    file_put_contents($tmp, $stub);
    /** @var Migration $migration */
    $migration = require $tmp;
    @unlink($tmp);

    return $migration;
}

function createUsersTable(string $shape): void
{
    Schema::create('users', function ($table) use ($shape) {
        match ($shape) {
            'uuid' => $table->uuid('id')->primary(),
            'ulid' => $table->ulid('id')->primary(),
            'string' => $table->string('id')->primary(),
            default => $table->id(),
        };
        $table->string('email')->unique();
    });
}

function columnInfo(string $table, string $column): array
{
    foreach (Schema::getColumns($table) as $c) {
        if (($c['name'] ?? null) === $column) {
            return $c;
        }
    }
    throw new RuntimeException("Column {$column} not found in {$table}");
}

function columnIsIntegerLike(array $column): bool
{
    $type = mb_strtolower((string) ($column['type_name'] ?? $column['type'] ?? ''));

    return str_contains($type, 'int');
}

// ---------------------------------------------------------------------------
// user_preferences
// ---------------------------------------------------------------------------

it('user_preferences writes a bigint user_id by default (bigint host user)', function () {
    createUsersTable('bigint');
    config()->set('auth.providers.users.model', BigintTestUser::class);

    loadStub('create_user_preferences_table.php.stub')->up();

    expect(Schema::hasTable('martis_user_preferences'))->toBeTrue();
    expect(columnIsIntegerLike(columnInfo('martis_user_preferences', 'user_id')))->toBeTrue();
});

it('user_preferences writes a uuid user_id when host user uses HasUuids', function () {
    createUsersTable('uuid');
    config()->set('auth.providers.users.model', UuidTestUser::class);

    loadStub('create_user_preferences_table.php.stub')->up();

    expect(Schema::hasTable('martis_user_preferences'))->toBeTrue();
    expect(columnIsIntegerLike(columnInfo('martis_user_preferences', 'user_id')))->toBeFalse();
});

it('user_preferences writes a ulid user_id when host user uses HasUlids', function () {
    createUsersTable('ulid');
    config()->set('auth.providers.users.model', UlidTestUser::class);

    loadStub('create_user_preferences_table.php.stub')->up();

    expect(Schema::hasTable('martis_user_preferences'))->toBeTrue();
    expect(columnIsIntegerLike(columnInfo('martis_user_preferences', 'user_id')))->toBeFalse();
});

it('user_preferences writes a string user_id when host user has keyType=string without trait', function () {
    createUsersTable('string');
    config()->set('auth.providers.users.model', StringTestUser::class);

    loadStub('create_user_preferences_table.php.stub')->up();

    expect(Schema::hasTable('martis_user_preferences'))->toBeTrue();
    expect(columnIsIntegerLike(columnInfo('martis_user_preferences', 'user_id')))->toBeFalse();
});

it('user_preferences honours the env override even when auto-detection would pick bigint', function () {
    createUsersTable('uuid');
    config()->set('auth.providers.users.model', BigintTestUser::class);
    config()->set('martis.user_id_column_type', 'uuid');

    loadStub('create_user_preferences_table.php.stub')->up();

    expect(columnIsIntegerLike(columnInfo('martis_user_preferences', 'user_id')))->toBeFalse();
});

it('user_preferences ignores an invalid env override and falls back to auto-detection', function () {
    createUsersTable('bigint');
    config()->set('auth.providers.users.model', BigintTestUser::class);
    config()->set('martis.user_id_column_type', 'something-bogus');

    loadStub('create_user_preferences_table.php.stub')->up();

    expect(columnIsIntegerLike(columnInfo('martis_user_preferences', 'user_id')))->toBeTrue();
});

// ---------------------------------------------------------------------------
// action_events
// ---------------------------------------------------------------------------

it('action_events writes a bigint nullable user_id by default', function () {
    createUsersTable('bigint');
    config()->set('auth.providers.users.model', BigintTestUser::class);

    loadStub('create_martis_action_events_table.php.stub')->up();

    expect(Schema::hasTable('martis_action_events'))->toBeTrue();
    expect(columnIsIntegerLike(columnInfo('martis_action_events', 'user_id')))->toBeTrue();
});

it('action_events writes a uuid nullable user_id for HasUuids host user', function () {
    createUsersTable('uuid');
    config()->set('auth.providers.users.model', UuidTestUser::class);

    loadStub('create_martis_action_events_table.php.stub')->up();

    expect(columnIsIntegerLike(columnInfo('martis_action_events', 'user_id')))->toBeFalse();
});

it('action_events writes a ulid nullable user_id for HasUlids host user', function () {
    createUsersTable('ulid');
    config()->set('auth.providers.users.model', UlidTestUser::class);

    loadStub('create_martis_action_events_table.php.stub')->up();

    expect(columnIsIntegerLike(columnInfo('martis_action_events', 'user_id')))->toBeFalse();
});

// ---------------------------------------------------------------------------
// notifications (polymorphic morphs)
// ---------------------------------------------------------------------------

it('notifications writes bigint notifiable_id by default', function () {
    createUsersTable('bigint');
    config()->set('auth.providers.users.model', BigintTestUser::class);

    loadStub('create_martis_notifications_table.php.stub')->up();

    expect(Schema::hasTable('notifications'))->toBeTrue();
    expect(columnIsIntegerLike(columnInfo('notifications', 'notifiable_id')))->toBeTrue();
});

it('notifications writes uuid notifiable_id for HasUuids host user', function () {
    createUsersTable('uuid');
    config()->set('auth.providers.users.model', UuidTestUser::class);

    loadStub('create_martis_notifications_table.php.stub')->up();

    expect(Schema::hasTable('notifications'))->toBeTrue();
    expect(columnIsIntegerLike(columnInfo('notifications', 'notifiable_id')))->toBeFalse();
});

it('notifications writes ulid notifiable_id for HasUlids host user', function () {
    createUsersTable('ulid');
    config()->set('auth.providers.users.model', UlidTestUser::class);

    loadStub('create_martis_notifications_table.php.stub')->up();

    expect(Schema::hasTable('notifications'))->toBeTrue();
    expect(columnIsIntegerLike(columnInfo('notifications', 'notifiable_id')))->toBeFalse();
});

it('reproduces the v1.12.1 bug: HasUuids host running the OLD bigint-only stub would fail', function () {
    // Sanity check — proves the test infrastructure differentiates the
    // two paths. We assert that the bigint-default branch (used by the
    // OLD stub) and the uuid branch (used by the new stub) produce
    // different column types when the host has a UUID user.
    createUsersTable('uuid');

    config()->set('auth.providers.users.model', BigintTestUser::class);
    loadStub('create_user_preferences_table.php.stub')->up();
    $bigintCol = columnInfo('martis_user_preferences', 'user_id');

    Schema::dropIfExists('martis_user_preferences');

    config()->set('auth.providers.users.model', UuidTestUser::class);
    loadStub('create_user_preferences_table.php.stub')->up();
    $uuidCol = columnInfo('martis_user_preferences', 'user_id');

    expect(columnIsIntegerLike($bigintCol))->toBeTrue();
    expect(columnIsIntegerLike($uuidCol))->toBeFalse();
});
