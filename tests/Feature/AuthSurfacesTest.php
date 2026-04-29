<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Contracts\RegistersUsers;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function ensureUsersTable(): void
{
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
}

beforeEach(function () {
    ensureUsersTable();
    config(['auth.providers.users.model' => User::class]);
});

// ---------------------------------------------------------------------------
// Registration — page surface
// ---------------------------------------------------------------------------

it('GET /martis/register renders the SPA shell when registration is enabled and url is empty', function () {
    config([
        'martis.auth.registration.enabled' => true,
        'martis.auth.registration.url' => null,
    ]);

    $response = $this->get('/martis/register');
    $response->assertStatus(200);
});

it('GET /martis/register redirects to /martis/login when registration is disabled', function () {
    config(['martis.auth.registration.enabled' => false]);

    $response = $this->get('/martis/register');
    $response->assertRedirect('/martis/login');
});

it('GET /martis/register redirects off-platform when registration.url is set', function () {
    config([
        'martis.auth.registration.enabled' => true,
        'martis.auth.registration.url' => 'https://signup.example.com',
    ]);

    $response = $this->get('/martis/register');
    $response->assertRedirect('https://signup.example.com');
});

// ---------------------------------------------------------------------------
// Registration — API endpoint
// ---------------------------------------------------------------------------

it('POST /martis/api/auth/register creates a user when registration is enabled', function () {
    config(['martis.auth.registration.enabled' => true]);

    $response = $this->postJson('/martis/api/auth/register', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'abcd1234',
        'password_confirmation' => 'abcd1234',
    ]);

    $response->assertStatus(201)->assertJson(['ok' => true]);
    expect(User::where('email', 'jane@example.com')->exists())->toBeTrue();
});

it('POST /martis/api/auth/register 404s when registration is disabled', function () {
    config(['martis.auth.registration.enabled' => false]);

    $response = $this->postJson('/martis/api/auth/register', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'abcd1234',
        'password_confirmation' => 'abcd1234',
    ]);

    $response->assertStatus(404);
});

it('POST /martis/api/auth/register 422s on validation failure', function () {
    config(['martis.auth.registration.enabled' => true]);

    $response = $this->postJson('/martis/api/auth/register', [
        'name' => '',
        'email' => 'not-an-email',
        'password' => 'short',
        'password_confirmation' => 'mismatch',
    ]);

    $response->assertStatus(422);
    expect($response->json('errors'))->toHaveKeys(['name', 'email', 'password']);
});

// ---------------------------------------------------------------------------
// Password reset — page surface
// ---------------------------------------------------------------------------

it('GET /martis/forgot-password renders SPA when reset is enabled and url is empty', function () {
    config([
        'martis.auth.passwordReset.enabled' => true,
        'martis.auth.passwordReset.url' => null,
    ]);

    $response = $this->get('/martis/forgot-password');
    $response->assertStatus(200);
});

it('GET /martis/forgot-password redirects to /login when reset is disabled', function () {
    config(['martis.auth.passwordReset.enabled' => false]);

    $response = $this->get('/martis/forgot-password');
    $response->assertRedirect('/martis/login');
});

it('GET /martis/reset-password/{token} renders SPA when reset is enabled', function () {
    config([
        'martis.auth.passwordReset.enabled' => true,
        'martis.auth.passwordReset.url' => null,
    ]);

    $response = $this->get('/martis/reset-password/some-token-value');
    $response->assertStatus(200);
});

// ---------------------------------------------------------------------------
// Password reset — API endpoints
// ---------------------------------------------------------------------------

it('POST /martis/api/auth/password/email 404s when reset is disabled', function () {
    config(['martis.auth.passwordReset.enabled' => false]);

    $response = $this->postJson('/martis/api/auth/password/email', [
        'email' => 'jane@example.com',
    ]);

    $response->assertStatus(404);
});

it('POST /martis/api/auth/password/reset 404s when reset is disabled', function () {
    config(['martis.auth.passwordReset.enabled' => false]);

    $response = $this->postJson('/martis/api/auth/password/reset', [
        'token' => 'x',
        'email' => 'jane@example.com',
        'password' => 'abcd1234',
        'password_confirmation' => 'abcd1234',
    ]);

    $response->assertStatus(404);
});

// ---------------------------------------------------------------------------
// Override hook — consumers can rebind RegistersUsers
// ---------------------------------------------------------------------------

it('consumer can override the RegistersUsers binding', function () {
    config(['martis.auth.registration.enabled' => true]);

    app()->bind(RegistersUsers::class, function () {
        return new class implements RegistersUsers
        {
            public function register(Request $request): Authenticatable
            {
                $u = new User;
                $u->id = 99999;
                $u->name = 'overridden';
                $u->email = 'overridden@example.com';
                $u->password = 'x';

                return $u;
            }
        };
    });

    $response = $this->postJson('/martis/api/auth/register', [
        'name' => 'ignored',
        'email' => 'ignored@example.com',
        'password' => 'ignored1234',
        'password_confirmation' => 'ignored1234',
    ]);

    $response->assertStatus(201);
    // No user created in DB because the override didn't persist.
    expect(User::where('email', 'ignored@example.com')->exists())->toBeFalse();
});
