<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Martis\Auth\GuardCatalog;
use Martis\Auth\Listeners\RecordRoleChange;
use Martis\Tests\Fixtures\GuardUsers\Admin;
use Martis\Tests\Fixtures\GuardUsers\SiteUser;

/*
 * Laravel's database session handler writes `sessions.user_id` with the id
 * of the request's guard and no table: a panel request writes the Martis
 * guard's user, a site request the site guard's. With a MARTIS_GUARD whose
 * model has its own table, the same id is an admin in one row and a site
 * user in another, so the profile's browser sessions listed (IP, device)
 * and revoked another person's sessions, and revoke_sessions_on_demote
 * signed out the site user who shares the demoted admin's id. Both now
 * refuse to act on an id that can name two people; one table is unchanged.
 */

/** A second model on the site's `users` table, as an app's Staff extends User. */
class SessionGuardStaff extends SiteUser {}

const SESSION_GUARD_ADMIN_ROW = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const SESSION_GUARD_SITE_ROW = 'ssssssssssssssssssssssssssssssssssssssss';

beforeEach(function () {
    config()->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'guard_users_admins']);
    config()->set('auth.providers.guard_users_admins', ['driver' => 'eloquent', 'model' => Admin::class]);
    config()->set('auth.providers.users.model', SiteUser::class);
    config()->set('martis.guard', 'admin');
    config()->set('martis.audit.role_changes', false);
    config()->set('session.driver', 'database');
    config()->set('session.table', 'sessions');

    foreach (['martis_test_guard_users_admins', 'users', 'sessions'] as $table) {
        Schema::dropIfExists($table);
    }
    foreach (['martis_test_guard_users_admins', 'users'] as $table) {
        Schema::create($table, function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }
    Schema::create('sessions', function ($table) {
        $table->string('id')->primary();
        $table->foreignId('user_id')->nullable()->index();
        $table->string('ip_address', 45)->nullable();
        $table->text('user_agent')->nullable();
        $table->longText('payload');
        $table->integer('last_activity')->index();
    });

    // The admin and the site user share id 1: two people, one id.
    $this->admin = Admin::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('secret')]);
    $this->site = SiteUser::create(['name' => 'Site', 'email' => 'site@example.com', 'password' => bcrypt('secret')]);
    expect($this->admin->getKey())->toBe($this->site->getKey());

    DB::table('sessions')->insert([
        ['id' => SESSION_GUARD_ADMIN_ROW, 'user_id' => 1, 'ip_address' => '10.0.0.1', 'user_agent' => 'admin-laptop', 'payload' => 'x', 'last_activity' => 200],
        ['id' => SESSION_GUARD_SITE_ROW, 'user_id' => 1, 'ip_address' => '10.0.0.9', 'user_agent' => 'site-phone', 'payload' => 'y', 'last_activity' => 100],
    ]);
});

afterEach(function () {
    foreach (['martis_test_guard_users_admins', 'users', 'sessions'] as $table) {
        Schema::dropIfExists($table);
    }
});

function sessionGuardAs(Admin|SiteUser $user, string $guard): array
{
    return [auth()->guard($guard)->getName() => $user->getKey()];
}

it('counts the tables whose ids the session rows can hold', function () {
    expect(GuardCatalog::sessionUserTables())->toBe(['sqlite|martis_test_guard_users_admins', 'sqlite|users'])
        ->and(GuardCatalog::sessionUserIdsAreAmbiguous())->toBeTrue();

    // A Martis guard on the site's table, through another provider and class.
    config()->set('auth.providers.staff', ['driver' => 'eloquent', 'model' => SessionGuardStaff::class]);
    config()->set('auth.guards.admin.provider', 'staff');
    expect(GuardCatalog::sessionUserTables())->toBe(['sqlite|users'])
        ->and(GuardCatalog::sessionUserIdsAreAmbiguous())->toBeFalse();

    // A `database` provider names its table; an unknown driver counts apart.
    config()->set('auth.providers.staff', ['driver' => 'database', 'table' => 'users']);
    expect(GuardCatalog::sessionUserIdsAreAmbiguous())->toBeFalse();
    config()->set('auth.providers.staff', ['driver' => 'ldap']);
    expect(GuardCatalog::sessionUserTables())->toBe(['provider:staff', 'sqlite|users']);

    // The default install: one session guard, one table.
    config()->set('martis.guard', null);
    config()->set('auth.guards', ['web' => ['driver' => 'session', 'provider' => 'users']]);
    expect(GuardCatalog::sessionUserTables())->toBe(['sqlite|users']);
});

it('reports browser sessions as unsupported, with the reason, when their ids span two tables', function () {
    $this->withSession(sessionGuardAs($this->admin, 'admin'))
        ->getJson('/martis/api/profile/sessions')
        ->assertOk()
        ->assertJsonPath('supported', false)
        ->assertJsonPath('sessions', [])
        ->assertJsonPath('reason', __('martis::profile.sessions_unsupported_guards'))
        ->assertDontSee('10.0.0.9')
        ->assertDontSee('site-phone');
});

it('revokes no session by an id that can name another person', function () {
    $session = sessionGuardAs($this->admin, 'admin');

    $this->withSession($session)->deleteJson('/martis/api/profile/sessions/others')->assertNoContent();
    $this->withSession($session)->deleteJson('/martis/api/profile/sessions/'.SESSION_GUARD_SITE_ROW)->assertNoContent();

    expect(DB::table('sessions')->whereIn('id', [SESSION_GUARD_ADMIN_ROW, SESSION_GUARD_SITE_ROW])->count())->toBe(2);
});

it('lists and revokes the sessions when the guards share one table', function () {
    config()->set('auth.providers.staff', ['driver' => 'eloquent', 'model' => SessionGuardStaff::class]);
    config()->set('auth.guards.admin.provider', 'staff');
    $session = sessionGuardAs($this->site, 'admin');

    $this->withSession($session)
        ->getJson('/martis/api/profile/sessions')
        ->assertOk()
        ->assertJsonPath('supported', true)
        ->assertJsonMissingPath('reason')
        ->assertJsonPath('sessions.0.id', SESSION_GUARD_ADMIN_ROW)
        ->assertJsonPath('sessions.1.id', SESSION_GUARD_SITE_ROW);

    $this->withSession($session)
        ->deleteJson('/martis/api/profile/sessions/'.SESSION_GUARD_SITE_ROW)
        ->assertOk()
        ->assertJsonPath('revoked', 1);
});

it('skips the demotion sweep, with a warning, when the session ids span two tables', function () {
    config()->set('martis.authz.revoke_sessions_on_demote', true);
    Log::spy();

    app(RecordRoleChange::class)->handleRoleDetached((object) ['model' => $this->admin, 'rolesOrIds' => [7]]);

    expect(DB::table('sessions')->count())->toBe(2);
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'revoke_sessions_on_demote skipped')
            && $context['model'] === Admin::class && $context['id'] === 1)
        ->once();
});

it('sweeps the demoted user sessions with one table', function () {
    config()->set('martis.authz.revoke_sessions_on_demote', true);
    config()->set('martis.guard', null);
    config()->set('auth.guards', ['web' => ['driver' => 'session', 'provider' => 'users']]);
    DB::table('sessions')->insert(['id' => 'oooooooooooooooooooooooooooooooooooooooo', 'user_id' => 2, 'payload' => 'z', 'last_activity' => 50]);

    app(RecordRoleChange::class)->handlePermissionDetached((object) ['model' => $this->site, 'permissionsOrIds' => [7]]);

    expect(DB::table('sessions')->pluck('id')->all())->toBe(['oooooooooooooooooooooooooooooooooooooooo']);
});
