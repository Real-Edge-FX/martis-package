<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Martis\Contracts\ProfileResourceContract;
use Martis\Profile\ProfileResource;

/**
 * `profile.resource` names the class behind the profile page, and the
 * avatar of `/api/auth/user` and of the login response comes from the same
 * class, so the Topbar shows what the profile page shows. A value that
 * names no profile resource throws instead of falling back to the default.
 */
class ProfileResourceConfigCustomResource extends ProfileResource
{
    public function toArray(Authenticatable $user): array
    {
        $data = parent::toArray($user);
        $data['avatar_url'] = 'https://cdn.example.test/configured.png';

        return $data;
    }
}

class ProfileResourceConfigBareResource implements ProfileResourceContract
{
    public function toArray(Authenticatable $user): array
    {
        return ['name' => 'Bare'];
    }

    public function updateRules(Authenticatable $user): array
    {
        return [];
    }

    public function applyUpdate(Authenticatable $user, array $data): void {}
}

function profileResourceConfigUser(): User
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

beforeEach(function () {
    config()->set('martis.profile.resource', null);
});

it('resolves the default profile resource when profile.resource is unset', function (mixed $unset) {
    config()->set('martis.profile.resource', $unset);

    expect(app(ProfileResourceContract::class))->toBeInstanceOf(ProfileResource::class);
})->with(['null' => [null], 'empty string' => ['']]);

it('serves the configured resource on the profile page and /api/auth/user', function () {
    config()->set('martis.profile.resource', ProfileResourceConfigCustomResource::class);
    $user = profileResourceConfigUser();
    $avatar = 'https://cdn.example.test/configured.png';

    $this->actingAs($user, config('martis.guard'))
        ->withSession(['martis_two_factor_passed' => true])
        ->getJson('/martis/api/profile')
        ->assertOk()
        ->assertJsonPath('avatar_url', $avatar);

    $this->actingAs($user, config('martis.guard'))
        ->withSession(['martis_two_factor_passed' => true])
        ->getJson('/martis/api/auth/user')
        ->assertOk()
        ->assertJsonPath('avatar_url', $avatar);
});

it('serves the configured resource on the login response', function () {
    config()->set('martis.profile.resource', ProfileResourceConfigCustomResource::class);
    profileResourceConfigUser();

    $this->postJson('/martis/api/auth/login', ['email' => 'ada@example.test', 'password' => 'secret-1234'])
        ->assertOk()
        ->assertJsonPath('email', 'ada@example.test')
        ->assertJsonPath('avatar_url', 'https://cdn.example.test/configured.png');
});

it('gives /api/auth/user a null avatar_url when the resource has none', function () {
    config()->set('martis.profile.resource', ProfileResourceConfigBareResource::class);
    $user = profileResourceConfigUser();

    $this->actingAs($user, config('martis.guard'))
        ->withSession(['martis_two_factor_passed' => true])
        ->getJson('/martis/api/auth/user')
        ->assertOk()
        ->assertJsonPath('avatar_url', null)
        ->assertJsonPath('email', 'ada@example.test');
});

it('throws, naming the key, when profile.resource names no profile resource', function (mixed $value, string $reason) {
    config()->set('martis.profile.resource', $value);

    expect(fn () => app(ProfileResourceContract::class))->toThrow(
        InvalidArgumentException::class,
        "The [martis.profile.resource] config value is not a profile resource: {$reason}.",
    );
})->with([
    'a missing class' => ['App\\Martis\\MissingProfileResource', 'no class is named "App\\Martis\\MissingProfileResource"'],
    'a class outside the contract' => [stdClass::class, 'stdClass does not implement '.ProfileResourceContract::class],
    'an array' => [[ProfileResource::class], 'got array'],
]);
