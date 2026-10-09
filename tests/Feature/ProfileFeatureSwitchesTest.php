<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Martis\Auth\TwoFactorPass;
use Martis\Profile\TwoFactorService;

/*
 * The profile feature switches hold on the server (v2.8.0). Up to v2.7.0
 * `profile.avatar.enabled` and `profile.two_factor.enabled` hid their part of
 * the page only: the routes stayed registered, the 2FA setup wrote a secret
 * and an enrolled account still met the challenge with 2FA off. Now a switch
 * that is off removes its routes, as Fortify's features do, and 2FA off
 * challenges nobody. (The e-mail switch is in ProfileEmailChangeTest.)
 */

const PFS_PASSWORD = 'secret-pass-123';

/** Register the package routes again, under the config of the test. */
function pfsReloadRoutes(): void
{
    /** @var Router $router */
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    require __DIR__.'/../../routes/martis.php';
}

/** The names of the registered routes that start with $prefix. */
function pfsRouteNames(string $prefix): array
{
    /** @var Router $router */
    $router = app('router');
    $router->getRoutes()->refreshNameLookups();

    return collect($router->getRoutes()->getRoutesByName())
        ->keys()
        ->filter(fn (string $name) => str_starts_with($name, $prefix))
        ->sort()
        ->values()
        ->all();
}

function pfsUser(bool $enrolled = false): User
{
    /** @var User $user */
    $user = User::forceCreate([
        'name' => 'Ada',
        'email' => 'ada@example.com',
        'password' => bcrypt(PFS_PASSWORD),
        'two_factor_secret' => $enrolled ? encrypt('JBSWY3DPEHPK3PXP') : null,
        'two_factor_confirmed_at' => $enrolled ? now() : null,
    ]);

    return $user;
}

beforeEach(function () {
    Schema::dropIfExists('users');
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->string('profile_picture')->nullable();
        $table->text('two_factor_secret')->nullable();
        $table->text('two_factor_recovery_codes')->nullable();
        $table->timestamp('two_factor_confirmed_at')->nullable();
        $table->timestamp('two_factor_last_used_at')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });

    config()->set('auth.providers.users.model', User::class);
    Storage::fake('public');
});

it('registers the avatar and 2FA routes while their switches are on', function () {
    pfsReloadRoutes();

    expect(pfsRouteNames('martis.api.profile.avatar'))->toBe([
        'martis.api.profile.avatar.remove',
        'martis.api.profile.avatar.upload',
    ])->and(pfsRouteNames('martis.api.profile.2fa'))->toBe([
        'martis.api.profile.2fa.confirm',
        'martis.api.profile.2fa.disable',
        'martis.api.profile.2fa.recovery-codes',
        'martis.api.profile.2fa.setup',
    ])->and(pfsRouteNames('martis.api.2fa.challenge'))->toBe(['martis.api.2fa.challenge']);
});

it('does not register the avatar routes while profile.avatar.enabled is off', function () {
    config()->set('martis.profile.avatar.enabled', false);
    pfsReloadRoutes();
    $user = pfsUser();

    expect(pfsRouteNames('martis.api.profile.avatar'))->toBe([]);

    $this->actingAs($user)
        ->postJson('/martis/api/profile/avatar', ['avatar' => UploadedFile::fake()->image('me.png', 64, 64)])
        ->assertNotFound();
    $this->deleteJson('/martis/api/profile/avatar')->assertNotFound();

    expect(Storage::disk('public')->allFiles())->toBe([])
        ->and($user->fresh()->profile_picture)->toBeNull();
});

it('does not register the 2FA routes while profile.two_factor.enabled is off', function () {
    config()->set('martis.profile.two_factor.enabled', false);
    pfsReloadRoutes();
    $user = pfsUser();

    expect(pfsRouteNames('martis.api.profile.2fa'))->toBe([])
        ->and(pfsRouteNames('martis.api.2fa.challenge'))->toBe([]);

    $this->actingAs($user);
    $this->postJson('/martis/api/profile/2fa/setup')->assertNotFound();
    $this->postJson('/martis/api/profile/2fa/confirm', ['code' => '123456'])->assertNotFound();
    $this->postJson('/martis/api/profile/2fa/recovery-codes', ['current_password' => PFS_PASSWORD])->assertNotFound();
    $this->deleteJson('/martis/api/profile/2fa', ['current_password' => PFS_PASSWORD])->assertNotFound();
    $this->postJson('/martis/api/2fa/challenge', ['code' => '123456'])->assertNotFound();

    expect($user->fresh()->two_factor_secret)->toBeNull();
});

it('challenges nobody while 2FA is off, an enrolled account included, and keeps its secret', function () {
    config()->set('martis.profile.two_factor.enabled', false);
    pfsReloadRoutes();
    $user = pfsUser(enrolled: true);
    $secret = $user->two_factor_secret;

    $this->postJson('/martis/api/auth/login', ['email' => 'ada@example.com', 'password' => PFS_PASSWORD])
        ->assertOk()
        ->assertJsonMissingPath('two_factor_required')
        ->assertJsonPath('email', 'ada@example.com');

    auth()->forgetGuards();
    $this->getJson('/martis/api/auth/user')->assertOk()->assertJsonMissingPath('two_factor_pending');
    $this->getJson('/martis/api/profile')->assertOk()->assertJsonPath('two_factor_enabled', false);

    expect(session(TwoFactorPass::SESSION_KEY))->toBeNull()
        ->and($user->fresh()->two_factor_secret)->toBe($secret)
        ->and($user->fresh()->two_factor_confirmed_at)->not->toBeNull()
        ->and(app(TwoFactorService::class)->isEnabled($user->fresh()))->toBeTrue()
        ->and(app(TwoFactorService::class)->isActive($user->fresh()))->toBeFalse();
});

it('still challenges an enrolled account while 2FA is on', function () {
    $user = pfsUser(enrolled: true);

    $this->postJson('/martis/api/auth/login', ['email' => 'ada@example.com', 'password' => PFS_PASSWORD])
        ->assertOk()
        ->assertJson(['two_factor_required' => true]);

    auth()->forgetGuards();
    $this->getJson('/martis/api/profile')->assertStatus(423);
    expect(app(TwoFactorService::class)->isActive($user))->toBeTrue();
});

it('keeps the password change and the name while every feature switch is off', function () {
    config()->set('martis.profile.avatar.enabled', false);
    config()->set('martis.profile.two_factor.enabled', false);
    config()->set('martis.profile.account.email_editable', false);
    pfsReloadRoutes();
    $user = pfsUser(enrolled: true);

    $this->actingAs($user);
    $this->patchJson('/martis/api/profile', ['name' => 'Ada Lovelace'])
        ->assertOk()
        ->assertJsonPath('name', 'Ada Lovelace')
        ->assertJsonPath('email', 'ada@example.com');
    $this->postJson('/martis/api/profile/password', [
        'current_password' => PFS_PASSWORD,
        'password' => 'Another-Secret-456',
        'password_confirmation' => 'Another-Secret-456',
    ])->assertOk();

    expect(password_verify('Another-Secret-456', $user->fresh()->password))->toBeTrue();
});
