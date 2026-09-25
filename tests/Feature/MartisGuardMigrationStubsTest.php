<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Martis\Auth\GuardCatalog;
use Martis\Models\UserPreference;

/*
 * The Martis tables that hold a user the panel signs in reference the table
 * of the Martis guard's model. The stubs constrained `user_id` (preferences)
 * and `invited_by` / `accepted_user_id` (invitations) to `users`, and the
 * two-factor and avatar migrations altered `users`: with a MARTIS_GUARD
 * whose model has its own table, saving an admin's preferences failed on the
 * foreign key (or tied the row to the site user with the same id, whose
 * deletion then cascaded to the admin's preferences), and the admin's
 * two-factor setup had no columns to write.
 */

class StubGuardAdmin extends Authenticatable
{
    protected $table = 'martis_test_stub_admins';

    protected $guarded = [];
}

class StubGuardUuidAdmin extends Authenticatable
{
    use HasUuids;

    protected $table = 'martis_test_stub_uuid_admins';

    protected $keyType = 'string';

    public $incrementing = false;
}

class StubGuardCodeAdmin extends Authenticatable
{
    protected $table = 'martis_test_stub_code_admins';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;
}

const STUB_GUARD_TABLES = [
    'invitations',
    'martis_user_preferences',
    'martis_test_stub_admins',
    'martis_test_stub_uuid_admins',
    'martis_test_stub_code_admins',
    'users',
];

beforeEach(function () {
    Schema::disableForeignKeyConstraints();
    foreach (STUB_GUARD_TABLES as $table) {
        Schema::dropIfExists($table);
    }

    config()->set('martis.user_id_column_type', null);
    config()->set('martis.guard', null);
    config()->set('auth.providers.stub_admins', ['driver' => 'eloquent', 'model' => StubGuardAdmin::class]);
    config()->set('auth.providers.stub_uuid_admins', ['driver' => 'eloquent', 'model' => StubGuardUuidAdmin::class]);
    config()->set('auth.providers.stub_code_admins', ['driver' => 'eloquent', 'model' => StubGuardCodeAdmin::class]);

    foreach (['users' => 'id', 'martis_test_stub_admins' => 'id', 'martis_test_stub_uuid_admins' => 'uuid', 'martis_test_stub_code_admins' => 'code'] as $table => $key) {
        Schema::create($table, function ($table) use ($key) {
            match ($key) {
                'uuid' => $table->uuid('id')->primary(),
                'code' => $table->string('code')->primary(),
                default => $table->id(),
            };
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }
});

afterEach(function () {
    Schema::disableForeignKeyConstraints();
    foreach (STUB_GUARD_TABLES as $table) {
        Schema::dropIfExists($table);
    }
});

function stubGuardMigration(string $basename): Migration
{
    $tmp = (string) tempnam(sys_get_temp_dir(), 'martis_guard_stub_');
    file_put_contents($tmp, (string) file_get_contents(__DIR__.'/../../stubs/'.$basename));

    try {
        /** @var Migration $migration */
        $migration = require $tmp;
    } finally {
        @unlink($tmp);
    }

    return $migration;
}

function stubGuardUse(string $provider): void
{
    config()->set('auth.guards.admin', ['driver' => 'session', 'provider' => $provider]);
    config()->set('martis.guard', 'admin');
}

/** @return array<string, array{table: string, columns: list<string>, on_delete: string}> */
function stubGuardForeignKeys(string $table): array
{
    $keys = [];
    foreach (Schema::getForeignKeys($table) as $key) {
        $keys[$key['columns'][0]] = [
            'table' => $key['foreign_table'],
            'columns' => $key['foreign_columns'],
            'on_delete' => strtolower((string) $key['on_delete']),
        ];
    }
    ksort($keys);

    return $keys;
}

/**
 * Switch the default connection to a fresh in-memory SQLite database that
 * enforces foreign keys. The test's own database runs inside the
 * RefreshDatabase transaction, where `PRAGMA foreign_keys = ON` is a no-op.
 */
function stubGuardForeignKeyDatabase(): void
{
    config()->set('database.connections.martis_fk', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::setDefaultConnection('martis_fk');

    foreach (['users', 'martis_test_stub_admins'] as $table) {
        Schema::create($table, function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }
}

it('references users from the preferences and the invitations on a default install', function () {
    expect(GuardCatalog::martisUserModel())->toBe(config('auth.providers.users.model'));

    stubGuardMigration('create_user_preferences_table.php.stub')->up();
    stubGuardMigration('create_invitations_table.php.stub')->up();

    expect(stubGuardForeignKeys('martis_user_preferences'))->toBe([
        'user_id' => ['table' => 'users', 'columns' => ['id'], 'on_delete' => 'cascade'],
    ])->and(stubGuardForeignKeys('invitations'))->toBe([
        'accepted_user_id' => ['table' => 'users', 'columns' => ['id'], 'on_delete' => 'set null'],
        'invited_by' => ['table' => 'users', 'columns' => ['id'], 'on_delete' => 'set null'],
    ]);
});

it('references the Martis guard model table from the preferences and the invitations', function () {
    stubGuardUse('stub_admins');

    stubGuardMigration('create_user_preferences_table.php.stub')->up();
    stubGuardMigration('create_invitations_table.php.stub')->up();

    expect(stubGuardForeignKeys('martis_user_preferences'))->toBe([
        'user_id' => ['table' => 'martis_test_stub_admins', 'columns' => ['id'], 'on_delete' => 'cascade'],
    ])->and(stubGuardForeignKeys('invitations'))->toBe([
        'accepted_user_id' => ['table' => 'martis_test_stub_admins', 'columns' => ['id'], 'on_delete' => 'set null'],
        'invited_by' => ['table' => 'martis_test_stub_admins', 'columns' => ['id'], 'on_delete' => 'set null'],
    ]);
});

it('shapes the column and the reference on the Martis guard model key', function (string $provider, string $table, string $key, bool $integer) {
    stubGuardUse($provider);
    // The site's users keep a bigint key: the shape follows the Martis model.
    expect(config('auth.providers.users.model'))->not->toBe(config("auth.providers.{$provider}.model"));

    stubGuardMigration('create_user_preferences_table.php.stub')->up();
    stubGuardMigration('create_invitations_table.php.stub')->up();

    $type = static function (string $on, string $column): string {
        foreach (Schema::getColumns($on) as $info) {
            if ($info['name'] === $column) {
                return strtolower((string) ($info['type_name'] ?? $info['type']));
            }
        }

        return '';
    };

    expect(str_contains($type('martis_user_preferences', 'user_id'), 'int'))->toBe($integer)
        ->and(str_contains($type('invitations', 'invited_by'), 'int'))->toBe($integer)
        ->and(stubGuardForeignKeys('martis_user_preferences')['user_id']['table'])->toBe($table)
        ->and(stubGuardForeignKeys('martis_user_preferences')['user_id']['columns'])->toBe([$key])
        ->and(stubGuardForeignKeys('invitations')['invited_by']['columns'])->toBe([$key]);
})->with([
    'bigint' => ['stub_admins', 'martis_test_stub_admins', 'id', true],
    'uuid' => ['stub_uuid_admins', 'martis_test_stub_uuid_admins', 'id', false],
    'string key named code' => ['stub_code_admins', 'martis_test_stub_code_admins', 'code', false],
]);

it('adds the two-factor and avatar columns to the Martis guard model table', function () {
    stubGuardUse('stub_admins');

    stubGuardMigration('add_two_factor_columns.php.stub')->up();
    stubGuardMigration('add_profile_picture_column.php.stub')->up();

    foreach (['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_used_at', 'profile_picture'] as $column) {
        expect(Schema::hasColumn('martis_test_stub_admins', $column))->toBeTrue("{$column} on the admins table")
            ->and(Schema::hasColumn('users', $column))->toBeFalse("{$column} not on users");
    }

    stubGuardMigration('add_two_factor_columns.php.stub')->down();
    stubGuardMigration('add_profile_picture_column.php.stub')->down();

    expect(Schema::hasColumn('martis_test_stub_admins', 'two_factor_secret'))->toBeFalse()
        ->and(Schema::hasColumn('martis_test_stub_admins', 'profile_picture'))->toBeFalse();
});

it('adds the two-factor and avatar columns to users on a default install', function () {
    stubGuardMigration('add_two_factor_columns.php.stub')->up();
    stubGuardMigration('add_profile_picture_column.php.stub')->up();

    expect(Schema::hasColumn('users', 'two_factor_secret'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'profile_picture'))->toBeTrue()
        ->and(Schema::hasColumn('martis_test_stub_admins', 'two_factor_secret'))->toBeFalse();
});

it('saves an admin preferences through the panel with the foreign key enforced', function () {
    stubGuardUse('stub_admins');
    stubGuardForeignKeyDatabase();
    stubGuardMigration('create_user_preferences_table.php.stub')->up();

    // Admin 1 shares its id with a site user; admin 2 has no users row.
    $site = DB::table('users')->insertGetId(['name' => 'site', 'email' => 'site@example.com']);
    $shared = StubGuardAdmin::create(['name' => 'shared', 'email' => 'shared@example.com']);
    $own = StubGuardAdmin::create(['name' => 'own', 'email' => 'own@example.com']);
    expect($shared->getKey())->toBe($site);

    $guardKey = auth()->guard('admin')->getName();
    foreach ([$shared, $own] as $admin) {
        $this->withSession([$guardKey => $admin->getKey()])
            ->putJson('/martis/api/preferences', ['theme' => 'light'])
            ->assertOk();

        $this->flushSession();
        auth()->forgetGuards();
        config()->set('auth.defaults.guard', 'web');
    }

    expect(UserPreference::query()->orderBy('user_id')->pluck('user_id')->all())->toBe([$shared->getKey(), $own->getKey()]);

    // The site user's deletion leaves the admin's preferences alone.
    DB::table('users')->where('id', $site)->delete();
    expect(UserPreference::query()->where('user_id', $shared->getKey())->exists())->toBeTrue();

    // The admin's own deletion takes them.
    $shared->delete();
    expect(UserPreference::query()->where('user_id', $shared->getKey())->exists())->toBeFalse();

    DB::setDefaultConnection('sqlite');
    DB::purge('martis_fk');
});

/*
 * `sessions` and `notifications` are shared with the site: every session
 * guard writes `sessions.user_id`, and the site's notifications land in
 * `notifications`. v2.0.0 shaped their user columns on the Martis guard's
 * model alone, so beside an `admins` guard keyed by UUID the site's bigint
 * ids no longer fit (PostgreSQL and MySQL reject them on every site request
 * that writes the session), or the other way round.
 */

/** The shape a shared-table stub resolves, read from its private resolver. */
function stubGuardSharedShape(string $basename): string
{
    $migration = stubGuardMigration($basename);

    return (fn (): string => $this->resolveUserKey())->call($migration);
}

it('shapes the shared tables on the users of every guard that writes them', function (?string $provider, string $shape) {
    if ($provider !== null) {
        stubGuardUse($provider);
    }

    expect(stubGuardSharedShape('create_sessions_table.php.stub'))->toBe($shape)
        ->and(stubGuardSharedShape('create_martis_notifications_table.php.stub'))->toBe($shape);
})->with([
    'default guard only' => [null, 'bigint'],
    'bigint admins beside bigint users' => ['stub_admins', 'bigint'],
    'uuid admins beside bigint users' => ['stub_uuid_admins', 'string'],
    'string-keyed admins beside bigint users' => ['stub_code_admins', 'string'],
]);

it('counts a session guard the panel does not use', function () {
    config()->set('auth.guards.customer', ['driver' => 'session', 'provider' => 'stub_uuid_admins']);
    config()->set('auth.guards.api_token', ['driver' => 'token', 'provider' => 'stub_code_admins']);

    expect(stubGuardSharedShape('create_sessions_table.php.stub'))->toBe('string');

    // A guard of another driver writes no session.
    config()->set('auth.guards.customer', ['driver' => 'token', 'provider' => 'stub_uuid_admins']);
    expect(stubGuardSharedShape('create_sessions_table.php.stub'))->toBe('bigint');
});

it('keeps the uuid shape when every guard signs in uuid-keyed users', function () {
    config()->set('auth.providers.users.model', StubGuardUuidAdmin::class);
    stubGuardUse('stub_uuid_admins');

    expect(stubGuardSharedShape('create_sessions_table.php.stub'))->toBe('uuid')
        ->and(stubGuardSharedShape('create_martis_notifications_table.php.stub'))->toBe('uuid');
});

it('lets MARTIS_USER_ID_COLUMN_TYPE override the shared shape', function () {
    stubGuardUse('stub_uuid_admins');
    config()->set('martis.user_id_column_type', 'uuid');

    expect(stubGuardSharedShape('create_sessions_table.php.stub'))->toBe('uuid');
});

it('stores the ids of both guards in the shared tables when their keys differ', function () {
    stubGuardUse('stub_uuid_admins');
    Schema::dropIfExists('notifications');
    config()->set('session.table', 'martis_test_stub_sessions');
    Schema::dropIfExists('martis_test_stub_sessions');

    try {
        stubGuardMigration('create_sessions_table.php.stub')->up();
        stubGuardMigration('create_martis_notifications_table.php.stub')->up();

        $uuid = (string) Str::uuid();
        foreach (['site' => 5, 'admin' => $uuid] as $id => $userId) {
            DB::table('martis_test_stub_sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => '', 'last_activity' => 0]);
        }
        foreach ([[StubGuardAdmin::class, 5], [StubGuardUuidAdmin::class, $uuid]] as [$type, $userId]) {
            DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'test', 'notifiable_type' => $type, 'notifiable_id' => $userId, 'data' => '{}']);
        }

        $type = static function (string $on, string $column): string {
            foreach (Schema::getColumns($on) as $info) {
                if ($info['name'] === $column) {
                    return strtolower((string) ($info['type_name'] ?? $info['type']));
                }
            }

            return '';
        };

        expect($type('martis_test_stub_sessions', 'user_id'))->not->toContain('int')
            ->and($type('notifications', 'notifiable_id'))->not->toContain('int')
            ->and(DB::table('notifications')->where('notifiable_type', StubGuardUuidAdmin::class)->value('notifiable_id'))->toBe($uuid)
            ->and(Schema::hasIndex('notifications', ['notifiable_type', 'notifiable_id']))->toBeTrue();
    } finally {
        Schema::dropIfExists('martis_test_stub_sessions');
        Schema::dropIfExists('notifications');
    }
});
