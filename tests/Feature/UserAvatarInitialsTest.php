<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Martis\Contracts\ProfileResourceContract;
use Martis\Profile\ProfileResource;

/**
 * The Topbar and the profile page paint the user's avatar from the server:
 * `avatar_initials` and `avatar_palette` (a slot of the theme's
 * `--martis-avatar-N` tokens), computed by Martis\Support\Initials like the
 * Avatar and UiAvatar fields. "Ada Lovelace" is AL on slot 11, "Grace
 * Hopper" GH on slot 6 (InitialsTest pins both).
 */
class UserAvatarInitialsCustomResource extends ProfileResource
{
    public function toArray(Authenticatable $user): array
    {
        return ['avatar_initials' => 'XY', 'avatar_palette' => 3] + parent::toArray($user);
    }
}

class UserAvatarInitialsBareResource implements ProfileResourceContract
{
    public function toArray(Authenticatable $user): array
    {
        return ['name' => 'Bare'];
    }

    public function updateRules(Authenticatable $user): array
    {
        return ['name' => ['required', 'string']];
    }

    public function applyUpdate(Authenticatable $user, array $data): void
    {
        /** @var User $user */
        $user->forceFill(['name' => $data['name']])->save();
    }
}

function userAvatarInitialsUser(): User
{
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('profile_picture')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /** @var User $user */
    $user = User::forceCreate([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
        'password' => Hash::make('secret-1234'),
    ]);

    return $user;
}

function userAvatarInitialsActingAs(User $user): void
{
    test()->actingAs($user, config('martis.guard'))
        ->withSession(['martis_two_factor_passed' => true]);
}

beforeEach(function () {
    config()->set('martis.profile.resource', null);
});

it('sends the user\'s initials and palette slot on /api/auth/user and the profile', function () {
    userAvatarInitialsActingAs(userAvatarInitialsUser());

    $this->getJson('/martis/api/auth/user')
        ->assertOk()
        ->assertJsonPath('avatar_initials', 'AL')
        ->assertJsonPath('avatar_palette', 11);

    $this->getJson('/martis/api/profile')
        ->assertOk()
        ->assertJsonPath('avatar_initials', 'AL')
        ->assertJsonPath('avatar_palette', 11);
});

it('sends them on the login response', function () {
    userAvatarInitialsUser();

    $this->postJson('/martis/api/auth/login', ['email' => 'ada@example.test', 'password' => 'secret-1234'])
        ->assertOk()
        ->assertJsonPath('avatar_initials', 'AL')
        ->assertJsonPath('avatar_palette', 11);
});

it('sends the new name\'s initials and slot after an account update', function () {
    userAvatarInitialsActingAs(userAvatarInitialsUser());

    $this->patchJson('/martis/api/profile', ['name' => 'Grace Hopper', 'email' => 'ada@example.test'])
        ->assertOk()
        ->assertJsonPath('avatar_initials', 'GH')
        ->assertJsonPath('avatar_palette', 6);
});

it('keeps the initials and slot a custom resource gives', function () {
    config()->set('martis.profile.resource', UserAvatarInitialsCustomResource::class);
    userAvatarInitialsActingAs(userAvatarInitialsUser());

    $this->getJson('/martis/api/auth/user')
        ->assertOk()
        ->assertJsonPath('avatar_initials', 'XY')
        ->assertJsonPath('avatar_palette', 3);

    $this->getJson('/martis/api/profile')
        ->assertOk()
        ->assertJsonPath('avatar_initials', 'XY')
        ->assertJsonPath('avatar_palette', 3);
});

it('computes them for a resource that does not give them', function () {
    config()->set('martis.profile.resource', UserAvatarInitialsBareResource::class);
    userAvatarInitialsActingAs(userAvatarInitialsUser());

    $this->getJson('/martis/api/auth/user')
        ->assertOk()
        ->assertJsonPath('avatar_initials', 'AL')
        ->assertJsonPath('avatar_palette', 11);

    $this->getJson('/martis/api/profile')
        ->assertOk()
        ->assertJsonPath('name', 'Bare')
        ->assertJsonPath('avatar_initials', 'AL')
        ->assertJsonPath('avatar_palette', 11);

    $this->patchJson('/martis/api/profile', ['name' => 'Grace Hopper'])
        ->assertOk()
        ->assertJsonPath('avatar_initials', 'GH')
        ->assertJsonPath('avatar_palette', 6);
});
