<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Martis\Auth\Listeners\RecordRoleChange;
use Martis\Tests\Fixtures\GuardUsers\Admin;
use Martis\Tests\Fixtures\GuardUsers\SiteUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;

/*
 * revoke_sessions_on_demote deleted the sessions whose user_id equalled the
 * id of the model the Spatie event named, whatever that model was. Spatie
 * fires PermissionDetached with the ROLE as `model` when a permission is
 * revoked from a role, so the sweep signed out whichever user had the role's
 * id, and the users who held the role (and had just lost the permission)
 * kept their sessions. It now sweeps the sessions of the Martis guard's
 * users the event stands for: the user, or the users who hold the role.
 */

/** A role as Spatie's Role is for the sweep: a model with a users() relation. */
class DemoteTestRole extends Model
{
    protected $table = 'demote_test_roles';

    protected $guarded = [];

    public $timestamps = false;

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(SiteUser::class, 'demote_test_role_user', 'role_id', 'user_id');
    }
}

/** A role whose users are not the Martis guard's (another guard's model). */
class DemoteTestOtherGuardRole extends Model
{
    protected $table = 'demote_test_roles';

    protected $guarded = [];

    public $timestamps = false;

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(Admin::class, 'demote_test_role_user', 'role_id', 'user_id');
    }
}

/** Another class on the table of the Martis guard's users, as an app's Staff beside its User. */
class DemoteTestStaff extends SiteUser {}

/** A model that is neither a user nor a role. */
class DemoteTestTeam extends Model
{
    protected $table = 'demote_test_roles';

    protected $guarded = [];

    public $timestamps = false;
}

function demoteSession(string $id, int $userId): void
{
    DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => 'x', 'last_activity' => 100]);
}

function demoteRemainingSessions(): array
{
    return DB::table('sessions')->orderBy('id')->pluck('id')->all();
}

beforeEach(function () {
    config()->set('auth.providers.users.model', SiteUser::class);
    config()->set('martis.guard', null);
    config()->set('martis.authz.revoke_sessions_on_demote', true);
    config()->set('martis.audit.role_changes', false);
    config()->set('session.driver', 'database');
    config()->set('session.table', 'sessions');
    config()->set('auth.guards', ['web' => ['driver' => 'session', 'provider' => 'users']]);

    foreach (['users', 'sessions', 'demote_test_roles', 'demote_test_role_user'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->string('password');
        $table->rememberToken();
        $table->timestamps();
    });
    Schema::create('sessions', function ($table) {
        $table->string('id')->primary();
        $table->foreignId('user_id')->nullable()->index();
        $table->string('ip_address', 45)->nullable();
        $table->text('user_agent')->nullable();
        $table->longText('payload');
        $table->integer('last_activity')->index();
    });
    Schema::create('demote_test_roles', function ($table) {
        $table->id();
        $table->string('name');
    });
    Schema::create('demote_test_role_user', function ($table) {
        $table->unsignedBigInteger('role_id');
        $table->unsignedBigInteger('user_id');
    });

    foreach (['one', 'two', 'three', 'four', 'five', 'six', 'seven'] as $name) {
        SiteUser::create(['name' => $name, 'email' => $name.'@example.com', 'password' => 'x']);
    }
});

afterEach(function () {
    foreach (['users', 'sessions', 'demote_test_roles', 'demote_test_role_user'] as $table) {
        Schema::dropIfExists($table);
    }
});

it('signs out the users who hold the role when a permission is revoked from it, not the user with its id', function () {
    // Role 7: held by users 2 and 3. User 7 has nothing to do with it.
    foreach (range(1, 6) as $ignored) {
        DemoteTestRole::create(['name' => 'filler']);
    }
    $role = DemoteTestRole::create(['name' => 'editor']);
    expect($role->getKey())->toBe(7);
    DB::table('demote_test_role_user')->insert([['role_id' => 7, 'user_id' => 2], ['role_id' => 7, 'user_id' => 3]]);
    demoteSession('holder-2-laptop', 2);
    demoteSession('holder-2-phone', 2);
    demoteSession('holder-3', 3);
    demoteSession('bystander-7', 7);
    demoteSession('bystander-1', 1);

    app(RecordRoleChange::class)->handlePermissionDetached((object) ['model' => $role, 'permissionsOrIds' => [11]]);

    expect(demoteRemainingSessions())->toBe(['bystander-1', 'bystander-7']);
});

it('keeps the sweep of a user a role or a permission was detached from', function () {
    $user = SiteUser::find(4);
    demoteSession('demoted-a', 4);
    demoteSession('demoted-b', 4);
    demoteSession('bystander', 5);

    app(RecordRoleChange::class)->handleRoleDetached((object) ['model' => $user, 'rolesOrIds' => [1]]);

    expect(demoteRemainingSessions())->toBe(['bystander']);

    demoteSession('demoted-c', 4);
    app(RecordRoleChange::class)->handlePermissionDetached((object) ['model' => $user, 'permissionsOrIds' => [1]]);

    expect(demoteRemainingSessions())->toBe(['bystander']);
});

it('recognises a user of the Martis guard by its table, whatever the class', function () {
    config()->set('auth.providers.users.model', DemoteTestStaff::class);
    demoteSession('demoted', 4);
    demoteSession('bystander', 5);

    // The event names the site's SiteUser, the guard's model is Staff: one table, one person.
    app(RecordRoleChange::class)->handleRoleDetached((object) ['model' => SiteUser::find(4), 'rolesOrIds' => [1]]);

    expect(demoteRemainingSessions())->toBe(['bystander']);
});

it('keeps the current session of the operator, also when they hold the role they changed', function () {
    $role = DemoteTestRole::create(['name' => 'editor']);
    DB::table('demote_test_role_user')->insert([['role_id' => $role->id, 'user_id' => 2], ['role_id' => $role->id, 'user_id' => 3]]);
    app('session.store')->setId('opercurrentsessionaaaaaaaaaaaaaaaaaaaaaa');
    demoteSession('opercurrentsessionaaaaaaaaaaaaaaaaaaaaaa', 2);
    demoteSession('operator-other-device', 2);
    demoteSession('holder-3', 3);

    app(RecordRoleChange::class)->handlePermissionDetached((object) ['model' => $role, 'permissionsOrIds' => [11]]);

    expect(demoteRemainingSessions())->toBe(['opercurrentsessionaaaaaaaaaaaaaaaaaaaaaa']);
});

it('revokes nothing, with a warning, for a role whose users are not the Martis guard users', function () {
    Log::spy();
    $role = DemoteTestOtherGuardRole::create(['name' => 'editor']);
    DB::table('demote_test_role_user')->insert(['role_id' => $role->id, 'user_id' => 2]);
    demoteSession('holder-2', 2);

    app(RecordRoleChange::class)->handlePermissionDetached((object) ['model' => $role, 'permissionsOrIds' => [11]]);

    expect(demoteRemainingSessions())->toBe(['holder-2']);
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'revoke_sessions_on_demote skipped')
            && $context['model'] === DemoteTestOtherGuardRole::class)
        ->once();
});

it('revokes nothing, with a warning, for a model that is neither a user nor a role', function () {
    Log::spy();
    $team = DemoteTestTeam::create(['name' => 'team']);
    demoteSession('session-of-id-1', 1);

    app(RecordRoleChange::class)->handlePermissionDetached((object) ['model' => $team, 'permissionsOrIds' => [11]]);

    expect(demoteRemainingSessions())->toBe(['session-of-id-1']);
    Log::shouldHaveReceived('warning')->once();
});

it('revokes nothing for a role nobody holds', function () {
    $role = DemoteTestRole::create(['name' => 'empty']);
    demoteSession('session-of-id-1', 1);

    app(RecordRoleChange::class)->handlePermissionDetached((object) ['model' => $role, 'permissionsOrIds' => [11]]);

    expect(demoteRemainingSessions())->toBe(['session-of-id-1']);
});

it('does nothing while revoke_sessions_on_demote is off', function () {
    config()->set('martis.authz.revoke_sessions_on_demote', false);
    demoteSession('demoted', 4);

    app(RecordRoleChange::class)->handleRoleDetached((object) ['model' => SiteUser::find(4), 'rolesOrIds' => [1]]);

    expect(demoteRemainingSessions())->toBe(['demoted']);
});

// ── With Spatie's own models and events ─────────────────────────────────────

describe('with spatie/laravel-permission', function () {
    beforeEach(function () {
        if (! class_exists(Role::class)) {
            $this->markTestSkipped('Install spatie/laravel-permission to run the Spatie flow.');
        }

        $this->app->register(PermissionServiceProvider::class);
        config()->set('permission.events_enabled', true);
        config()->set('auth.providers.users.model', DemoteSpatieUser::class);
        (require __DIR__.'/../../vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub')->up();
    });

    it('signs out the holders of a role when a permission is revoked from it', function () {
        // Roles 1 and 2 first, so the role we change (2) has the id of user 2, who holds nothing.
        Role::create(['name' => 'filler', 'guard_name' => 'web']);
        $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
        $permission = Permission::create(['name' => 'edit posts', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        DemoteSpatieUser::find(1)->assignRole($role);
        expect($role->getKey())->toBe(2);

        demoteSession('holder-1', 1);
        demoteSession('bystander-2', 2);

        $role->revokePermissionTo($permission);

        expect(demoteRemainingSessions())->toBe(['bystander-2']);
    });

    it('signs out a user a role is removed from', function () {
        $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
        $user = DemoteSpatieUser::find(3);
        $user->assignRole($role);
        demoteSession('demoted-3', 3);
        demoteSession('bystander-1', 1);

        $user->removeRole($role);

        expect(demoteRemainingSessions())->toBe(['bystander-1']);
    });
});

class DemoteSpatieUser extends SiteUser
{
    use HasRoles;
}
