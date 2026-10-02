<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Schema;
use Martis\Sso\IdentityResolver;
use Martis\Sso\SsoAdoptionRefusedException;
use Martis\Sso\SsoIdentity;
use Martis\Tests\TestCase;

uses(TestCase::class);

class IdentityAdoptionUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

function adoptionIdentity(string $email): SsoIdentity
{
    return new SsoIdentity(provider: 'azure', externalId: 'azure-'.md5($email), email: $email, name: 'Idp Name');
}

function adoptionUser(string $email, bool $verified): IdentityAdoptionUser
{
    return IdentityAdoptionUser::query()->create([
        'name' => 'Local',
        'email' => $email,
        'password' => bcrypt('attacker-password'),
        'email_verified_at' => $verified ? now() : null,
    ]);
}

beforeEach(function () {
    Schema::dropIfExists('users');
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->string('azure_external_id')->nullable();
        $table->timestamps();
    });

    config()->set('auth.providers.users.model', IdentityAdoptionUser::class);
    config()->set('martis.auth.sso.providers.azure', [
        'auto_create_user' => true,
        'identity_match_attribute' => 'email',
        'sync_user_attributes' => ['name', 'email'],
    ]);
    IdentityResolver::forgetResolver();
});

afterEach(fn () => IdentityResolver::forgetResolver());

it('refuses to adopt an unverified local row while self-registration is open (pre-hijack)', function () {
    config()->set('martis.auth.registration.enabled', true);
    $attacker = adoptionUser('victim@corp.example', verified: false);

    expect(fn () => (new IdentityResolver)->resolve(adoptionIdentity('victim@corp.example'), 'azure'))
        ->toThrow(SsoAdoptionRefusedException::class);

    // Nothing was synced onto the attacker's row and no second row was made.
    expect($attacker->refresh()->name)->toBe('Local');
    expect(IdentityAdoptionUser::query()->count())->toBe(1);
});

it('refuses the unverified row even when auto_create_user is off', function () {
    config()->set('martis.auth.registration.enabled', true);
    config()->set('martis.auth.sso.providers.azure.auto_create_user', false);
    adoptionUser('victim@corp.example', verified: false);

    expect(fn () => (new IdentityResolver)->resolve(adoptionIdentity('victim@corp.example'), 'azure'))
        ->toThrow(SsoAdoptionRefusedException::class);
});

it('adopts a local row whose email is verified, registration open', function () {
    config()->set('martis.auth.registration.enabled', true);
    $row = adoptionUser('real@corp.example', verified: true);

    $user = (new IdentityResolver)->resolve(adoptionIdentity('real@corp.example'), 'azure');

    expect($user?->getKey())->toBe($row->getKey());
});

it('adopts an unverified row when self-registration is off (admin-provisioned rows)', function () {
    config()->set('martis.auth.registration.enabled', false);
    $row = adoptionUser('staff@corp.example', verified: false);

    $user = (new IdentityResolver)->resolve(adoptionIdentity('staff@corp.example'), 'azure');

    expect($user?->getKey())->toBe($row->getKey());
});

it('still creates the user when no local row holds the address, registration open', function () {
    config()->set('martis.auth.registration.enabled', true);

    $user = (new IdentityResolver)->resolve(adoptionIdentity('new@corp.example'), 'azure');

    expect($user?->email)->toBe('new@corp.example');
});

it('leaves the external_id strategy alone: an unverified row linked by id is found', function () {
    config()->set('martis.auth.registration.enabled', true);
    config()->set('martis.auth.sso.providers.azure.identity_match_attribute', 'external_id');
    config()->set('martis.auth.sso.providers.azure.identity_external_id_column', 'azure_external_id');
    $row = adoptionUser('linked@corp.example', verified: false);
    $row->forceFill(['azure_external_id' => adoptionIdentity('linked@corp.example')->externalId])->save();

    $user = (new IdentityResolver)->resolve(adoptionIdentity('linked@corp.example'), 'azure');

    expect($user?->getKey())->toBe($row->getKey());
});

it('does not adopt by email under the external_id strategy for an unlinked row', function () {
    config()->set('martis.auth.registration.enabled', true);
    config()->set('martis.auth.sso.providers.azure.identity_match_attribute', 'external_id');
    config()->set('martis.auth.sso.providers.azure.identity_external_id_column', 'azure_external_id');
    adoptionUser('victim@corp.example', verified: false);
    config()->set('martis.auth.sso.providers.azure.auto_create_user', false);

    expect((new IdentityResolver)->resolve(adoptionIdentity('victim@corp.example'), 'azure'))->toBeNull();
});

it('refuses to create beside an unlinked row holding the address (external_id, auto_create on)', function () {
    config()->set('martis.auth.sso.providers.azure.identity_match_attribute', 'external_id');
    config()->set('martis.auth.sso.providers.azure.identity_external_id_column', 'azure_external_id');
    adoptionUser('victim@corp.example', verified: false);

    expect(fn () => (new IdentityResolver)->resolve(adoptionIdentity('victim@corp.example'), 'azure'))
        ->toThrow(SsoAdoptionRefusedException::class);
    expect(IdentityAdoptionUser::query()->count())->toBe(1);
});

function recreateUsersWithoutVerificationColumn(): void
{
    Schema::dropIfExists('users');
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->string('azure_external_id')->nullable();
        $table->timestamps();
    });
}

it('refuses to adopt a row of a model with no email_verified_at column while self-registration is open', function () {
    config()->set('martis.auth.registration.enabled', true);
    recreateUsersWithoutVerificationColumn();
    $attacker = IdentityAdoptionUser::query()->create([
        'name' => 'Local',
        'email' => 'victim@corp.example',
        'password' => bcrypt('attacker-password'),
    ]);
    $passwordBefore = $attacker->password;

    expect(fn () => (new IdentityResolver)->resolve(adoptionIdentity('victim@corp.example'), 'azure'))
        ->toThrow(SsoAdoptionRefusedException::class);

    // No row was adopted or synced, none was created beside it, and the
    // attacker's password is untouched.
    $attacker->refresh();
    expect($attacker->name)->toBe('Local');
    expect($attacker->password)->toBe($passwordBefore);
    expect(IdentityAdoptionUser::query()->count())->toBe(1);
});

it('refuses the column-less row even when auto_create_user is off', function () {
    config()->set('martis.auth.registration.enabled', true);
    config()->set('martis.auth.sso.providers.azure.auto_create_user', false);
    recreateUsersWithoutVerificationColumn();
    IdentityAdoptionUser::query()->create(['name' => 'L', 'email' => 'victim@corp.example', 'password' => 'x']);

    expect(fn () => (new IdentityResolver)->resolve(adoptionIdentity('victim@corp.example'), 'azure'))
        ->toThrow(SsoAdoptionRefusedException::class);
});

it('adopts a row of a model with no email_verified_at column when self-registration is off', function () {
    config()->set('martis.auth.registration.enabled', false);
    recreateUsersWithoutVerificationColumn();
    $row = IdentityAdoptionUser::query()->create(['name' => 'L', 'email' => 'old@corp.example', 'password' => 'x']);

    $user = (new IdentityResolver)->resolve(adoptionIdentity('old@corp.example'), 'azure');

    expect($user?->getKey())->toBe($row->getKey());
});

it('leaves the external_id strategy alone on a model with no email_verified_at column', function () {
    config()->set('martis.auth.registration.enabled', true);
    config()->set('martis.auth.sso.providers.azure.identity_match_attribute', 'external_id');
    config()->set('martis.auth.sso.providers.azure.identity_external_id_column', 'azure_external_id');
    recreateUsersWithoutVerificationColumn();
    $row = IdentityAdoptionUser::query()->create([
        'name' => 'L',
        'email' => 'linked@corp.example',
        'password' => 'x',
        'azure_external_id' => adoptionIdentity('linked@corp.example')->externalId,
    ]);

    $user = (new IdentityResolver)->resolve(adoptionIdentity('linked@corp.example'), 'azure');

    expect($user?->getKey())->toBe($row->getKey());
});
