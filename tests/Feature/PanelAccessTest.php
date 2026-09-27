<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Martis\Auth\PanelAccess;

/*
 * `viewMartis` decides who may open the panel at all (v2.1.0). It is
 * optional: an app that does not define it lets every signed-in user in,
 * as before. Defined, it guards the shell, the protected API and the Tool
 * routes that run the Martis API stack (ToolRoutes::middleware), in every
 * environment.
 */

class PanelAccessTestUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

/** A policy of the user model: it must not decide the panel gate. */
class PanelAccessTestUserPolicy
{
    public function viewMartis(PanelAccessTestUser $user): bool
    {
        return true;
    }
}

beforeEach(function () {
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }

    $this->admin = PanelAccessTestUser::create(['name' => 'Admin', 'email' => 'admin@panel.test', 'password' => 'unused']);
    $this->member = PanelAccessTestUser::create(['name' => 'Member', 'email' => 'member@panel.test', 'password' => 'unused']);
});

function allowOnlyPanelAdmins(): void
{
    Gate::define(PanelAccess::GATE, fn (PanelAccessTestUser $user) => $user->email === 'admin@panel.test');
}

it('lets every signed-in user in while the app defines no viewMartis gate', function () {
    $this->actingAs($this->member)->get('/martis')->assertOk();
    $this->actingAs($this->member)->getJson('/martis/api/command-palette')->assertOk();
});

it('lets a user the gate allows in', function () {
    allowOnlyPanelAdmins();

    $this->actingAs($this->admin)->get('/martis')->assertOk();
    $this->actingAs($this->admin)->getJson('/martis/api/command-palette')->assertOk();
});

it('answers 403 with a message to an API request of a user the gate refuses', function () {
    allowOnlyPanelAdmins();

    $this->actingAs($this->member)->getJson('/martis/api/command-palette')
        ->assertForbidden()
        ->assertJsonPath('message', __('martis::messages.panel_forbidden'));
});

it('answers 403 with the no-access shell to a page request of a user the gate refuses', function () {
    allowOnlyPanelAdmins();

    $this->actingAs($this->member)->get('/martis/resources/users')
        ->assertForbidden()
        ->assertSee('panelForbidden: true', false);
});

it('marks the shell of an allowed user as not forbidden', function () {
    $this->actingAs($this->member)->get('/martis')->assertSee('panelForbidden: false', false);
});

it('lets a refused user sign out', function () {
    allowOnlyPanelAdmins();

    $this->actingAs($this->member)->postJson('/martis/api/auth/logout')->assertSuccessful();

    $this->assertGuest();
});

it('applies Gate::before callbacks', function () {
    allowOnlyPanelAdmins();
    Gate::before(fn (PanelAccessTestUser $user, string $ability) => $ability === PanelAccess::GATE && $user->email === 'member@panel.test' ? true : null);

    $this->actingAs($this->member)->getJson('/martis/api/command-palette')->assertOk();
});

it('is not decided by a policy of the user model', function () {
    Gate::policy(PanelAccessTestUser::class, PanelAccessTestUserPolicy::class);
    allowOnlyPanelAdmins();

    $this->actingAs($this->member)->getJson('/martis/api/command-palette')->assertForbidden();
});

it('guards the shell and the API, and leaves the sign-in routes alone', function () {
    Route::getRoutes()->refreshNameLookups();
    $middleware = function (string $name): array {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull("route {$name} is missing");

        return $route->gatherMiddleware();
    };

    expect($middleware('martis.spa'))->toContain('martis.authorize')
        ->and($middleware('martis.api.command-palette'))->toContain('martis.authorize');

    foreach (['martis.login', 'martis.logout', 'martis.api.auth.logout', 'martis.api.2fa.challenge', 'martis.email.verify.notice', 'martis.api.translations.show', 'martis.api.auth.user'] as $name) {
        expect($middleware($name))->not->toContain('martis.authorize');
    }
});

it('answers allows() for a guest only while the gate is undefined', function () {
    expect(PanelAccess::allows(null))->toBeTrue();

    allowOnlyPanelAdmins();

    expect(PanelAccess::allows(null))->toBeFalse()
        ->and(PanelAccess::allows($this->member))->toBeFalse()
        ->and(PanelAccess::allows($this->admin))->toBeTrue();
});

it('tells the SPA whether the signed-in user may open the panel', function () {
    $this->actingAs($this->member)->getJson('/martis/api/auth/user')->assertOk()->assertJsonPath('panel_access', true);

    allowOnlyPanelAdmins();

    $this->actingAs($this->member)->getJson('/martis/api/auth/user')->assertJsonPath('panel_access', false);
    $this->actingAs($this->admin)->getJson('/martis/api/auth/user')->assertJsonPath('panel_access', true);
});

it('tells the SPA on sign-in whether the user may open the panel', function () {
    config()->set('auth.providers.users.model', PanelAccessTestUser::class);
    $this->member->forceFill(['password' => Hash::make('member-secret')])->save();
    allowOnlyPanelAdmins();

    $this->postJson('/martis/api/auth/login', ['email' => 'member@panel.test', 'password' => 'member-secret'])
        ->assertOk()
        ->assertJsonPath('panel_access', false);
});
