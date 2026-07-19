<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Martis\Invitations\Invitation;
use Martis\Invitations\InvitationManager;
use Martis\Invitations\InvitationUrl;
use Martis\MartisServiceProvider;
use Martis\Stubs\StubResolver;

// -----------------------------------------------------------------------------
// Task 8 — InvitationController + public accept routes. Thin HTTP glue over
// InvitationManager (T1-T7): GET renders the SPA shell (identically for
// valid/unknown/expired/used tokens — no enumeration), POST accept creates
// the user via the manager and honours login_after_accept. Both 503 when
// `martis.invitations.enabled` is false. Accept is TOKEN-authorized: the
// `martis-invite` Gate (privileged "issue an invite" ability) must NOT be
// required here.
//
// Provisioning mirrors InvitationManagerTest.php / InvitationAuditTest.php:
// users + invitations (via the published migration stub) + martis_action_events.
// -----------------------------------------------------------------------------

beforeEach(function () {
    config(['auth.providers.users.model' => User::class]);

    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    Schema::dropIfExists('invitations');
    $migration = require StubResolver::path('create_invitations_table.php.stub');
    $migration->up();

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
});

afterEach(function () {
    Schema::dropIfExists('invitations');
    Schema::dropIfExists('martis_action_events');
    InvitationUrl::createUrlUsing(null); // reset static seam state between tests
});

function acceptPayload(string $rawToken, array $overrides = []): array
{
    return array_merge([
        'token' => $rawToken,
        'name' => 'Ann Invitee',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ], $overrides);
}

// -----------------------------------------------------------------------------
// Disabled — 503 for both endpoints, regardless of token validity
// -----------------------------------------------------------------------------

it('GET invitations/accept/{token} 503s when invitations are disabled', function () {
    config(['martis.invitations.enabled' => false]);

    $response = $this->get('/martis/invitations/accept/whatever-token');

    $response->assertStatus(503);
});

it('POST api/invitations/accept 503s when invitations are disabled', function () {
    config(['martis.invitations.enabled' => false]);

    $response = $this->postJson('/martis/api/invitations/accept', acceptPayload('whatever-token'));

    $response->assertStatus(503);
});

// -----------------------------------------------------------------------------
// GET show() — always 200 + SPA shell, identical for valid/unknown/expired
// -----------------------------------------------------------------------------

it('GET invitations/accept/{token} renders the SPA shell for a valid pending token', function () {
    config(['martis.invitations.enabled' => true]);

    $inv = app(InvitationManager::class)->invite('valid@ex.com');

    $response = $this->get('/martis/invitations/accept/'.$inv->rawToken);

    $response->assertStatus(200);
    $response->assertSee('id="martis-root"', false);
});

it('GET invitations/accept/{token} renders the SAME neutral 200 shell for an unknown token', function () {
    config(['martis.invitations.enabled' => true]);

    $valid = app(InvitationManager::class)->invite('valid2@ex.com');
    $validResponse = $this->get('/martis/invitations/accept/'.$valid->rawToken);

    $unknownResponse = $this->get('/martis/invitations/accept/not-a-real-token-at-all');

    // No enumeration: unknown token does not 404, and the response is
    // byte-for-byte the same shell as the valid-token response.
    $unknownResponse->assertStatus(200);
    expect($unknownResponse->status())->toBe($validResponse->status());
    expect($unknownResponse->getContent())->toBe($validResponse->getContent());
});

it('GET invitations/accept/{token} renders the SAME neutral 200 shell for an expired token', function () {
    config(['martis.invitations.enabled' => true]);

    $inv = app(InvitationManager::class)->invite('expired@ex.com');
    $inv->forceFill(['expires_at' => now()->subHour()])->save();

    $response = $this->get('/martis/invitations/accept/'.$inv->rawToken);

    $response->assertStatus(200);
    $response->assertSee('id="martis-root"', false);
});

// -----------------------------------------------------------------------------
// POST accept() — success paths
// -----------------------------------------------------------------------------

it('POST accept creates the user and logs in when login_after_accept is true', function () {
    config([
        'martis.invitations.enabled' => true,
        'martis.invitations.login_after_accept' => true,
    ]);

    $inv = app(InvitationManager::class)->invite('login-after@ex.com', 'editor');

    $response = $this->postJson('/martis/api/invitations/accept', acceptPayload($inv->rawToken));

    $response->assertStatus(200)->assertJson(['ok' => true]);
    expect(User::where('email', 'login-after@ex.com')->exists())->toBeTrue();
    $this->assertAuthenticated();

    $inv->refresh();
    expect($inv->status)->toBe(Invitation::STATUS_ACCEPTED);
});

it('POST accept creates the user but does NOT log in when login_after_accept is false', function () {
    config([
        'martis.invitations.enabled' => true,
        'martis.invitations.login_after_accept' => false,
    ]);

    $inv = app(InvitationManager::class)->invite('no-login-after@ex.com');

    $response = $this->postJson('/martis/api/invitations/accept', acceptPayload($inv->rawToken));

    $response->assertStatus(200)->assertJson(['ok' => true]);
    expect(User::where('email', 'no-login-after@ex.com')->exists())->toBeTrue();
    $this->assertGuest();
    expect($response->json('redirect'))->toContain('/martis/login');
});

it('honours redirect_after_accept when login_after_accept is true', function () {
    config([
        'martis.invitations.enabled' => true,
        'martis.invitations.login_after_accept' => true,
        'martis.invitations.redirect_after_accept' => '/martis/welcome',
    ]);

    $inv = app(InvitationManager::class)->invite('custom-redirect@ex.com');

    $response = $this->postJson('/martis/api/invitations/accept', acceptPayload($inv->rawToken));

    $response->assertStatus(200);
    expect($response->json('redirect'))->toBe('/martis/welcome');
});

// -----------------------------------------------------------------------------
// POST accept() — neutral failure paths (no enumeration)
// -----------------------------------------------------------------------------

it('POST accept is neutral for an unknown token', function () {
    config(['martis.invitations.enabled' => true]);

    $response = $this->postJson('/martis/api/invitations/accept', acceptPayload('not-a-real-token'));

    $response->assertStatus(422);
    expect($response->json('errors.token'))->not->toBeNull();
});

it('POST accept is neutral for an expired token, with the SAME shape as unknown', function () {
    config(['martis.invitations.enabled' => true]);

    $inv = app(InvitationManager::class)->invite('expired-accept@ex.com');
    $inv->forceFill(['expires_at' => now()->subHour()])->save();

    $expiredResponse = $this->postJson('/martis/api/invitations/accept', acceptPayload($inv->rawToken));
    $unknownResponse = $this->postJson('/martis/api/invitations/accept', acceptPayload('not-a-real-token-either'));

    $expiredResponse->assertStatus(422);
    expect($expiredResponse->json())->toBe($unknownResponse->json());
});

it('POST accept is neutral for an already-used token', function () {
    config(['martis.invitations.enabled' => true]);

    $inv = app(InvitationManager::class)->invite('used@ex.com');
    $this->postJson('/martis/api/invitations/accept', acceptPayload($inv->rawToken))->assertStatus(200);

    $second = $this->postJson('/martis/api/invitations/accept', acceptPayload($inv->rawToken, [
        'name' => 'Second Try',
    ]));

    $second->assertStatus(422);
    expect($second->json('errors.token'))->not->toBeNull();
    expect(User::where('name', 'Second Try')->exists())->toBeFalse();
});

// -----------------------------------------------------------------------------
// POST accept() — bad password: normal 422, invitation stays retryable
// -----------------------------------------------------------------------------

it('POST accept 422s with a password error for a bad password, and the invitation remains acceptable', function () {
    config(['martis.invitations.enabled' => true]);

    $inv = app(InvitationManager::class)->invite('retry@ex.com');

    $bad = $this->postJson('/martis/api/invitations/accept', acceptPayload($inv->rawToken, [
        'password' => 'short',
        'password_confirmation' => 'nope',
    ]));

    $bad->assertStatus(422);
    expect($bad->json('errors.password'))->not->toBeNull();

    $inv->refresh();
    expect($inv->status)->toBe(Invitation::STATUS_PENDING);

    // Retry with a valid password succeeds against the SAME token.
    $good = $this->postJson('/martis/api/invitations/accept', acceptPayload($inv->rawToken));
    $good->assertStatus(200)->assertJson(['ok' => true]);
    expect(User::where('email', 'retry@ex.com')->exists())->toBeTrue();
});

// -----------------------------------------------------------------------------
// POST accept() — no gate required (token-authorized, not gate-authorized)
// -----------------------------------------------------------------------------

it('POST accept does not require the martis-invite Gate', function () {
    config(['martis.invitations.enabled' => true]);

    // Explicitly deny the privileged "issue an invite" ability — accept()
    // must still succeed because it is authorized by the token, not a Gate.
    Gate::define('martis-invite', fn ($user = null) => false);

    $inv = app(InvitationManager::class)->invite('gate-free@ex.com');

    $response = $this->postJson('/martis/api/invitations/accept', acceptPayload($inv->rawToken));

    $response->assertStatus(200)->assertJson(['ok' => true]);
});

// -----------------------------------------------------------------------------
// Validation — only signup_fields + password are accepted/required
// -----------------------------------------------------------------------------

it('POST accept 422s when token is missing', function () {
    config(['martis.invitations.enabled' => true]);

    $response = $this->postJson('/martis/api/invitations/accept', [
        'name' => 'Ann',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertStatus(422);
    expect($response->json('errors.token'))->not->toBeNull();
});

// -----------------------------------------------------------------------------
// InvitationUrl — the accept-URL callback seam (Task 10 will consume it)
// -----------------------------------------------------------------------------

it('InvitationUrl defaults to the martis.invitations.accept route', function () {
    config(['martis.invitations.enabled' => true]);

    $inv = new Invitation;

    expect(InvitationUrl::url($inv, 'raw-token-123'))
        ->toBe(route('martis.invitations.accept', 'raw-token-123'));
});

it('InvitationUrl::createUrlUsing() overrides the default builder', function () {
    InvitationUrl::createUrlUsing(fn (Invitation $invitation, string $rawToken): string => 'https://custom.example.com/invite/'.$rawToken
    );

    $inv = new Invitation;

    expect(InvitationUrl::url($inv, 'raw-token-456'))->toBe('https://custom.example.com/invite/raw-token-456');
});

it('MartisServiceProvider seeds the default accept-URL callback when invitations are enabled and none is set', function () {
    config(['martis.invitations.enabled' => true]);
    InvitationUrl::createUrlUsing(null);

    $provider = new MartisServiceProvider(app());
    $method = new ReflectionMethod($provider, 'registerInvitationAcceptUrl');
    $method->setAccessible(true);
    $method->invoke($provider);

    expect(InvitationUrl::hasCustomCallback())->toBeTrue();

    $inv = new Invitation;
    expect(InvitationUrl::url($inv, 'seeded-token'))
        ->toBe(route('martis.invitations.accept', 'seeded-token'));
});

it('MartisServiceProvider does not clobber an already-registered accept-URL callback', function () {
    config(['martis.invitations.enabled' => true]);
    InvitationUrl::createUrlUsing(fn (Invitation $invitation, string $rawToken): string => 'https://consumer.example.com/'.$rawToken
    );

    $provider = new MartisServiceProvider(app());
    $method = new ReflectionMethod($provider, 'registerInvitationAcceptUrl');
    $method->setAccessible(true);
    $method->invoke($provider);

    $inv = new Invitation;
    expect(InvitationUrl::url($inv, 'consumer-token'))->toBe('https://consumer.example.com/consumer-token');
});

it('MartisServiceProvider does not seed the accept-URL callback when invitations are disabled', function () {
    config(['martis.invitations.enabled' => false]);
    InvitationUrl::createUrlUsing(null);

    $provider = new MartisServiceProvider(app());
    $method = new ReflectionMethod($provider, 'registerInvitationAcceptUrl');
    $method->setAccessible(true);
    $method->invoke($provider);

    expect(InvitationUrl::hasCustomCallback())->toBeFalse();
});
