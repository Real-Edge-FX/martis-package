<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Martis\Auth\MagicLinkService;
use Martis\Invitations\InvitationManager;
use Martis\Sso\SsoCache;
use Martis\Stubs\StubResolver;

// ===========================================================================
// An unusable SSO cache store must refuse a sign-in BEFORE anyone is
// authenticated (v2.22.0). Every sign-in path drops the SSO origin of the
// session afterwards (SsoSession::forget), which reads the SSO cache: when
// that throws after the guard logged the user in, the answer is a 500 and the
// browser is signed in anyway.
// ===========================================================================

beforeEach(function () {
    config(['auth.providers.users.model' => User::class]);

    Schema::dropIfExists('users');
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->rememberToken();
        $table->timestamps();
    });
    Schema::dropIfExists('password_reset_tokens');
    Schema::create('password_reset_tokens', function ($table) {
        $table->string('email')->primary();
        $table->string('token');
        $table->timestamp('created_at')->nullable();
    });
    Schema::dropIfExists('invitations');
    (require StubResolver::path('create_invitations_table.php.stub'))->up();
    Schema::dropIfExists('martis_action_events');
    Schema::create('martis_action_events', function ($t) {
        $t->id();
        $t->uuid('batch_id');
        $t->unsignedBigInteger('user_id')->nullable();
        $t->string('name');
        $t->string('actionable_type')->nullable();
        $t->string('actionable_id')->nullable();
        $t->string('target_type')->nullable();
        $t->string('target_id')->nullable();
        $t->string('model_type')->nullable();
        $t->string('model_id')->nullable();
        $t->json('fields')->nullable();
        $t->string('status');
        $t->text('exception')->nullable();
        $t->json('original')->nullable();
        $t->json('changes')->nullable();
        $t->timestamps();
    });

    User::forceCreate(['name' => 'Pat', 'email' => 'pat@example.com', 'password' => Hash::make('Temporary-Pass-1'), 'email_verified_at' => now()]);
});

afterEach(function () {
    Schema::dropIfExists('invitations');
    Schema::dropIfExists('martis_action_events');
    Schema::dropIfExists('password_reset_tokens');
});

function ssoBadStore(bool $ssoOn = true): void
{
    config()->set('martis.auth.sso.enabled', $ssoOn);
    config()->set('martis.auth.sso.cache_store', 'nope');
}

it('assertUsable() is a no-op with SSO off and throws naming the key with SSO on', function () {
    config()->set('martis.auth.sso.cache_store', 'nope');
    config()->set('martis.auth.sso.enabled', false);
    SsoCache::assertUsable();

    config()->set('martis.auth.sso.enabled', true);
    expect(fn () => SsoCache::assertUsable())->toThrow(InvalidArgumentException::class, 'martis.auth.sso.cache_store');
});

it('refuses a password sign-in on an unusable store before anyone is signed in', function (string $how) {
    ssoBadStore();
    $credentials = ['email' => 'pat@example.com', 'password' => 'Temporary-Pass-1'];

    expect(fn () => $how === 'spa'
        ? $this->withoutExceptionHandling()->postJson('/martis/api/auth/login', $credentials)
        : $this->withoutExceptionHandling()->postJson('/martis/login', $credentials))
        ->toThrow(InvalidArgumentException::class, 'martis.auth.sso.cache_store');

    expect(auth()->guard(config('martis.guard'))->check())->toBeFalse();
    auth()->forgetGuards();
    $this->getJson('/martis/api/auth/user')->assertOk();
    expect(json_decode((string) $this->getJson('/martis/api/auth/user')->getContent(), true))->toBeNull();
})->with(['the SPA endpoint' => ['spa'], 'the form endpoint' => ['form']]);

it('answers a password sign-in as before when the store is fine or SSO is off', function () {
    config()->set('martis.auth.sso.cache_store', 'nope');
    config()->set('martis.auth.sso.enabled', false);

    $this->postJson('/martis/api/auth/login', ['email' => 'pat@example.com', 'password' => 'Temporary-Pass-1'])->assertOk();
});

it('refuses a magic-link sign-in on an unusable store before the link is spent or anyone is signed in', function () {
    config()->set('martis.auth.magic_link.enabled', true);
    $token = app(MagicLinkService::class)->issue('pat@example.com');
    ssoBadStore();

    expect(fn () => $this->withoutExceptionHandling()->postJson('/martis/api/auth/magic-link/consume', ['email' => 'pat@example.com', 'token' => $token]))
        ->toThrow(InvalidArgumentException::class, 'martis.auth.sso.cache_store');
    expect(auth()->guard(config('martis.guard'))->check())->toBeFalse();

    // The link was not spent: once the store is fixed it works.
    config()->set('martis.auth.sso.enabled', false);
    $this->postJson('/martis/api/auth/magic-link/consume', ['email' => 'pat@example.com', 'token' => $token])->assertOk();
});

it('refuses an invitation that logs the invitee in on an unusable store, before the invitation is used', function () {
    config(['martis.invitations.enabled' => true, 'martis.invitations.login_after_accept' => true]);
    $invitation = app(InvitationManager::class)->invite('ann@example.com');
    ssoBadStore();
    $payload = [
        'token' => $invitation->rawToken,
        'name' => 'Ann Invitee',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ];

    expect(fn () => $this->withoutExceptionHandling()->postJson('/martis/api/invitations/accept', $payload))
        ->toThrow(InvalidArgumentException::class, 'martis.auth.sso.cache_store');
    expect(auth()->guard(config('martis.guard'))->check())->toBeFalse()
        ->and(User::query()->where('email', 'ann@example.com')->exists())->toBeFalse();

    config()->set('martis.auth.sso.enabled', false);
    $this->postJson('/martis/api/invitations/accept', $payload)->assertOk();
});
