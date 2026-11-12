<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Schema;
use Martis\Sso\IdentityResolver;
use Martis\Sso\SsoAdoptionRefusedException;
use Martis\Sso\SsoException;
use Martis\Sso\SsoIdentity;
use Martis\Tests\TestCase;

uses(TestCase::class);

class ExternalIdUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

function extIdentity(string $externalId = 'idp-1', ?string $email = 'person@corp.example', ?string $name = 'Idp Name'): SsoIdentity
{
    return new SsoIdentity(provider: 'azure', externalId: $externalId, email: $email, name: $name);
}

function extRow(array $attributes = []): ExternalIdUser
{
    return ExternalIdUser::query()->create($attributes + [
        'name' => 'Local',
        'email' => 'person@corp.example',
        'email_verified_at' => now(),
        'password' => bcrypt('x'),
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
        $table->string('idp_subject')->nullable();
        $table->timestamps();
    });

    config()->set('auth.providers.users.model', ExternalIdUser::class);
    config()->set('martis.auth.registration.enabled', false);
    config()->set('martis.auth.sso.providers.azure', [
        'auto_create_user' => true,
        'identity_match_attribute' => 'external_id',
        'identity_external_id_column' => 'idp_subject',
        'sync_user_attributes' => ['name', 'email'],
    ]);
    IdentityResolver::forgetResolver();
});

afterEach(fn () => IdentityResolver::forgetResolver());

it('create() stores the IdP id in the configured column', function () {
    $user = (new IdentityResolver)->resolve(extIdentity('idp-77'), 'azure');

    expect($user->getAttribute('idp_subject'))->toBe('idp-77')
        ->and(ExternalIdUser::query()->where('idp_subject', 'idp-77')->count())->toBe(1);
});

it('create() stores the id in {provider}_external_id when no column is configured', function () {
    config()->set('martis.auth.sso.providers.azure.identity_external_id_column', null);

    $user = (new IdentityResolver)->resolve(extIdentity('idp-78'), 'azure');

    expect($user->getAttribute('azure_external_id'))->toBe('idp-78');
});

it('signs the same person in again by id: one row, no refusal (the second sign-in used to throw)', function () {
    $resolver = new IdentityResolver;

    $first = $resolver->resolve(extIdentity('idp-1'), 'azure');
    $second = $resolver->resolve(extIdentity('idp-1'), 'azure');

    expect($second->getKey())->toBe($first->getKey())
        ->and(ExternalIdUser::query()->count())->toBe(1);
});

it('follows an email that changed at the IdP when email is in sync_user_attributes: same row', function () {
    $row = extRow(['idp_subject' => 'idp-1']);

    $user = (new IdentityResolver)->resolve(extIdentity('idp-1', 'renamed@corp.example'), 'azure');

    expect($user->getKey())->toBe($row->getKey())
        ->and($row->refresh()->email)->toBe('renamed@corp.example')
        ->and(ExternalIdUser::query()->count())->toBe(1);
});

it('leaves the email of an id-matched row alone when email is not in sync_user_attributes', function () {
    config()->set('martis.auth.sso.providers.azure.sync_user_attributes', ['name']);
    $row = extRow(['idp_subject' => 'idp-1']);

    (new IdentityResolver)->resolve(extIdentity('idp-1', 'renamed@corp.example'), 'azure');

    expect($row->refresh()->email)->toBe('person@corp.example');
});

it('refuses a row that holds the IdP address when link_by_email is off (default)', function () {
    $row = extRow();

    expect(fn () => (new IdentityResolver)->resolve(extIdentity('idp-1'), 'azure'))
        ->toThrow(SsoAdoptionRefusedException::class);

    expect($row->refresh()->getAttribute('idp_subject'))->toBeNull()
        ->and(ExternalIdUser::query()->count())->toBe(1);
});

it('returns null for an unknown id with auto_create_user off and no row holding the address', function () {
    config()->set('martis.auth.sso.providers.azure.auto_create_user', false);

    expect((new IdentityResolver)->resolve(extIdentity('idp-1'), 'azure'))->toBeNull();
});

it('keeps returning null with link_by_email off, auto_create_user off and a row holding the address', function () {
    config()->set('martis.auth.sso.providers.azure.auto_create_user', false);
    $row = extRow();

    expect((new IdentityResolver)->resolve(extIdentity('idp-1'), 'azure'))->toBeNull()
        ->and($row->refresh()->getAttribute('idp_subject'))->toBeNull();
});

it('link_by_email adopts the row holding the address and writes the id into its empty column', function () {
    config()->set('martis.auth.sso.providers.azure.link_by_email', true);
    $row = extRow();

    $user = (new IdentityResolver)->resolve(extIdentity('idp-1'), 'azure');

    expect($user->getKey())->toBe($row->getKey())
        ->and($row->refresh()->getAttribute('idp_subject'))->toBe('idp-1')
        ->and(ExternalIdUser::query()->count())->toBe(1);

    // And from then on the id is what matches.
    $again = (new IdentityResolver)->resolve(extIdentity('idp-1', 'other@corp.example'), 'azure');
    expect($again->getKey())->toBe($row->getKey());
});

it('link_by_email links even when auto_create_user is off: linking is not creating', function () {
    config()->set('martis.auth.sso.providers.azure.link_by_email', true);
    config()->set('martis.auth.sso.providers.azure.auto_create_user', false);
    $row = extRow();

    $user = (new IdentityResolver)->resolve(extIdentity('idp-1'), 'azure');

    expect($user?->getKey())->toBe($row->getKey())
        ->and($row->refresh()->getAttribute('idp_subject'))->toBe('idp-1');
});

it('link_by_email never overwrites a different id: refuses with reason external_id_mismatch', function () {
    config()->set('martis.auth.sso.providers.azure.link_by_email', true);
    $row = extRow(['idp_subject' => 'someone-elses-id']);

    try {
        (new IdentityResolver)->resolve(extIdentity('idp-1'), 'azure');
        $this->fail('expected an SsoException');
    } catch (SsoException $e) {
        expect($e->reason)->toBe(SsoException::REASON_EXTERNAL_ID_MISMATCH)
            ->and($e->messageKey)->toBe('martis::messages.sso_account_unverified');
    }

    expect($row->refresh()->getAttribute('idp_subject'))->toBe('someone-elses-id');
});

it('link_by_email still refuses an unverified row while self-registration is open', function () {
    config()->set('martis.auth.sso.providers.azure.link_by_email', true);
    config()->set('martis.auth.registration.enabled', true);
    $row = extRow(['email_verified_at' => null]);

    expect(fn () => (new IdentityResolver)->resolve(extIdentity('idp-1'), 'azure'))
        ->toThrow(SsoAdoptionRefusedException::class);

    expect($row->refresh()->getAttribute('idp_subject'))->toBeNull();
});

it('link_by_email links a verified row while self-registration is open', function () {
    config()->set('martis.auth.sso.providers.azure.link_by_email', true);
    config()->set('martis.auth.registration.enabled', true);
    $row = extRow();

    expect((new IdentityResolver)->resolve(extIdentity('idp-1'), 'azure')?->getKey())->toBe($row->getKey());
});

it('refuses an empty id with reason missing_external_id and never matches a row with an empty column', function () {
    $orphan = extRow(['idp_subject' => null, 'email' => 'orphan@corp.example']);

    foreach (['', '   '] as $blank) {
        try {
            (new IdentityResolver)->resolve(extIdentity($blank, 'orphan@corp.example'), 'azure');
            $this->fail('expected an SsoException');
        } catch (SsoException $e) {
            expect($e->reason)->toBe(SsoException::REASON_MISSING_EXTERNAL_ID);
        }
    }

    expect(ExternalIdUser::query()->count())->toBe(1)
        ->and($orphan->refresh()->getAttribute('idp_subject'))->toBeNull();
});

it('refuses an empty id even with auto_create_user off and link_by_email on', function () {
    config()->set('martis.auth.sso.providers.azure.auto_create_user', false);
    config()->set('martis.auth.sso.providers.azure.link_by_email', true);

    expect(fn () => (new IdentityResolver)->resolve(extIdentity(''), 'azure'))
        ->toThrow(SsoException::class, 'external id');
});

it('cannot even build an identity with a null id', function () {
    expect(fn () => new SsoIdentity(provider: 'azure', externalId: null, email: 'a@b', name: 'A'))
        ->toThrow(TypeError::class);
});

it('does not require an id on the email strategy', function () {
    config()->set('martis.auth.sso.providers.azure.identity_match_attribute', 'email');
    $row = extRow();

    expect((new IdentityResolver)->resolve(extIdentity('', 'person@corp.example'), 'azure')?->getKey())->toBe($row->getKey());
});

it('sync_user_attributes external_id fills an empty column on the email strategy', function () {
    config()->set('martis.auth.sso.providers.azure.identity_match_attribute', 'email');
    config()->set('martis.auth.sso.providers.azure.sync_user_attributes', ['name', 'email', 'external_id']);
    $row = extRow();

    (new IdentityResolver)->resolve(extIdentity('idp-1'), 'azure');

    expect($row->refresh()->getAttribute('idp_subject'))->toBe('idp-1');
});

it('sync_user_attributes external_id stores the id of a user the email strategy creates', function () {
    config()->set('martis.auth.sso.providers.azure.identity_match_attribute', 'email');
    config()->set('martis.auth.sso.providers.azure.sync_user_attributes', ['external_id']);

    $user = (new IdentityResolver)->resolve(extIdentity('idp-9', 'brand-new@corp.example'), 'azure');

    expect($user->getAttribute('idp_subject'))->toBe('idp-9');
});

it('sync_user_attributes external_id never overwrites a different non-empty id', function () {
    config()->set('martis.auth.sso.providers.azure.identity_match_attribute', 'email');
    config()->set('martis.auth.sso.providers.azure.sync_user_attributes', ['name', 'external_id']);
    $row = extRow(['idp_subject' => 'someone-elses-id']);

    try {
        (new IdentityResolver)->resolve(extIdentity('idp-1'), 'azure');
        $this->fail('expected an SsoException');
    } catch (SsoException $e) {
        expect($e->reason)->toBe(SsoException::REASON_EXTERNAL_ID_MISMATCH);
    }

    expect($row->refresh()->getAttribute('idp_subject'))->toBe('someone-elses-id')
        ->and($row->name)->toBe('Local');
});

it('sync_user_attributes external_id is a no-op when the column already holds the same id', function () {
    config()->set('martis.auth.sso.providers.azure.identity_match_attribute', 'email');
    config()->set('martis.auth.sso.providers.azure.sync_user_attributes', ['external_id']);
    $row = extRow(['idp_subject' => 'idp-1']);

    expect((new IdentityResolver)->resolve(extIdentity('idp-1'), 'azure')?->getKey())->toBe($row->getKey());
});

it('sync_user_attributes without external_id leaves the column empty on the email strategy', function () {
    config()->set('martis.auth.sso.providers.azure.identity_match_attribute', 'email');
    $row = extRow();

    (new IdentityResolver)->resolve(extIdentity('idp-1'), 'azure');

    expect($row->refresh()->getAttribute('idp_subject'))->toBeNull();
});

it('throws naming the key for an identity_match_attribute it does not know', function () {
    config()->set('martis.auth.sso.providers.azure.identity_match_attribute', 'username');

    expect(fn () => (new IdentityResolver)->resolve(extIdentity(), 'azure'))
        ->toThrow(InvalidArgumentException::class, 'martis.auth.sso.providers.azure.identity_match_attribute');
});

it('throws naming the key for an identity_external_id_column that is not a non-empty string', function () {
    config()->set('martis.auth.sso.providers.azure.identity_external_id_column', ['idp_subject']);

    expect(fn () => (new IdentityResolver)->resolve(extIdentity(), 'azure'))
        ->toThrow(InvalidArgumentException::class, 'martis.auth.sso.providers.azure.identity_external_id_column');
});

// ---------------------------------------------------------------------------
// An identity whose email the provider says is unverified (review M3)
// ---------------------------------------------------------------------------

function extUnverifiedIdentity(string $externalId = 'idp-1'): SsoIdentity
{
    return new SsoIdentity(provider: 'azure', externalId: $externalId, email: 'person@corp.example', name: 'Idp Name', emailVerified: false);
}

it('refuses to adopt a row by an email the provider calls unverified, with the email_unverified reason and the generic text', function (string $strategy) {
    config()->set('martis.auth.sso.providers.azure.identity_match_attribute', $strategy);
    config()->set('martis.auth.sso.providers.azure.link_by_email', true);
    $row = extRow();

    try {
        (new IdentityResolver)->resolve(extUnverifiedIdentity(), 'azure');
        $this->fail('the row was adopted');
    } catch (SsoException $e) {
        expect($e->reason)->toBe(SsoException::REASON_EMAIL_UNVERIFIED)
            ->and($e->messageKey)->toBeNull();
    }

    expect($row->fresh()->idp_subject)->toBeNull();
})->with(['email', 'external_id']);

it('adopts a row by email when the provider says verified or says nothing', function (?bool $verified) {
    config()->set('martis.auth.sso.providers.azure.identity_match_attribute', 'email');
    $row = extRow();

    $user = (new IdentityResolver)->resolve(new SsoIdentity(provider: 'azure', externalId: 'idp-1', email: 'person@corp.example', name: 'N', emailVerified: $verified), 'azure');

    expect($user->getKey())->toBe($row->getKey());
})->with([true, null]);

it('still creates a user and matches by external id when the email is unverified', function () {
    $user = (new IdentityResolver)->resolve(new SsoIdentity(provider: 'azure', externalId: 'idp-9', email: 'new@corp.example', name: 'New', emailVerified: false), 'azure');
    expect($user->getAttribute('idp_subject'))->toBe('idp-9');

    $again = (new IdentityResolver)->resolve(new SsoIdentity(provider: 'azure', externalId: 'idp-9', email: 'new@corp.example', name: 'New', emailVerified: false), 'azure');
    expect($again->getKey())->toBe($user->getKey());
});
