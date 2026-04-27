<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Schema;
use Martis\Sso\IdentityResolver;
use Martis\Sso\SsoIdentity;
use Martis\Tests\TestCase;

uses(TestCase::class);

class IdentityResolverTestUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

beforeEach(function () {
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('azure_external_id')->nullable();
            $table->timestamps();
        });
    } elseif (! Schema::hasColumn('users', 'azure_external_id')) {
        Schema::table('users', fn ($t) => $t->string('azure_external_id')->nullable());
    }

    config()->set('auth.providers.users.model', IdentityResolverTestUser::class);

    config()->set('martis.auth.sso.providers.azure', [
        'auto_create_user' => true,
        'identity_match_attribute' => 'email',
        'sync_user_attributes' => ['name', 'email'],
    ]);

    IdentityResolver::forgetResolver();
});

afterEach(function () {
    IdentityResolverTestUser::query()->delete();
    IdentityResolver::forgetResolver();
});

function makeIdentity(string $email = 'user@example.com', string $name = 'Test User'): SsoIdentity
{
    return new SsoIdentity(
        provider: 'azure',
        externalId: 'azure-'.md5($email),
        email: $email,
        name: $name,
    );
}

it('finds an existing user by email', function () {
    IdentityResolverTestUser::query()->create([
        'name' => 'Existing',
        'email' => 'existing@example.com',
        'password' => bcrypt('x'),
    ]);

    $resolver = new IdentityResolver;
    $user = $resolver->resolve(makeIdentity('existing@example.com', 'Sso Name'), 'azure');

    expect($user)->not->toBeNull();
    expect($user->email)->toBe('existing@example.com');
    // Name was synced from the SSO identity.
    expect($user->name)->toBe('Sso Name');
});

it('creates a new user when none exists and auto_create_user is true', function () {
    expect(IdentityResolverTestUser::query()->where('email', 'new@example.com')->exists())->toBeFalse();

    $resolver = new IdentityResolver;
    $user = $resolver->resolve(makeIdentity('new@example.com', 'New Person'), 'azure');

    expect($user)->not->toBeNull();
    expect($user->name)->toBe('New Person');
    expect(IdentityResolverTestUser::query()->where('email', 'new@example.com')->exists())->toBeTrue();
});

it('returns null when user is missing and auto_create_user is false', function () {
    config()->set('martis.auth.sso.providers.azure.auto_create_user', false);

    $resolver = new IdentityResolver;
    $user = $resolver->resolve(makeIdentity('ghost@example.com'), 'azure');

    expect($user)->toBeNull();
});

it('matches by external_id when identity_match_attribute is set', function () {
    config()->set('martis.auth.sso.providers.azure.identity_match_attribute', 'external_id');
    config()->set('martis.auth.sso.providers.azure.identity_external_id_column', 'azure_external_id');

    IdentityResolverTestUser::query()->create([
        'name' => 'External Match',
        'email' => 'old-email@example.com',
        'password' => bcrypt('x'),
        'azure_external_id' => 'azure-fixed-id',
    ]);

    $identity = new SsoIdentity(
        provider: 'azure',
        externalId: 'azure-fixed-id',
        email: 'updated-email@example.com',
        name: 'External Match',
    );

    $resolver = new IdentityResolver;
    $user = $resolver->resolve($identity, 'azure');

    expect($user)->not->toBeNull();
    // Email got updated from the SSO identity (sync_user_attributes).
    expect($user->email)->toBe('updated-email@example.com');
});

it('global resolver override beats every config-driven path', function () {
    IdentityResolver::resolveUsing(function (SsoIdentity $id, string $provider): ?User {
        $user = IdentityResolverTestUser::query()->updateOrCreate(
            ['email' => 'fixed@example.com'],
            ['name' => 'Hooked', 'password' => bcrypt('x')],
        );

        return $user;
    });

    $resolver = new IdentityResolver;
    $user = $resolver->resolve(makeIdentity('whatever@example.com'), 'azure');

    expect($user->email)->toBe('fixed@example.com');
    expect($user->name)->toBe('Hooked');
});

it('does not touch attributes that are not in sync_user_attributes', function () {
    config()->set('martis.auth.sso.providers.azure.sync_user_attributes', []);

    IdentityResolverTestUser::query()->create([
        'name' => 'Original Name',
        'email' => 'sync@example.com',
        'password' => bcrypt('x'),
    ]);

    $resolver = new IdentityResolver;
    $user = $resolver->resolve(makeIdentity('sync@example.com', 'Updated Name'), 'azure');

    // sync_user_attributes is empty — name is NOT updated.
    expect($user->name)->toBe('Original Name');
});
