<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeAuthUser(string $email = 'admin@example.com', string $password = 'secret123'): User
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

    /** @var User $user */
    $user = User::forceCreate([
        'name' => 'Test Admin',
        'email' => $email,
        'password' => Hash::make($password),
    ]);

    return $user;
}

// ---------------------------------------------------------------------------
// Testes originais
// ---------------------------------------------------------------------------

it('GET /martis/login returns 200', function () {
    $response = $this->get('/martis/login');
    $response->assertStatus(200);
});

it('unauthenticated GET /martis redirects to login', function () {
    $response = $this->get('/martis');
    $response->assertRedirect('/martis/login');
});

it('unauthenticated GET /martis/any-page redirects to login', function () {
    $response = $this->get('/martis/resources/users');
    $response->assertRedirect('/martis/login');
});

it('unauthenticated JSON request to /martis returns 401', function () {
    $response = $this->getJson('/martis');
    $response->assertStatus(401);
});

it('config is overridable by the app', function () {
    config(['martis.path' => 'admin']);

    expect(config('martis.path'))->toBe('admin');

    config(['martis.path' => 'martis']);
});

// ---------------------------------------------------------------------------
// Authentication tests
// ---------------------------------------------------------------------------

it('POST /martis/login with valid credentials authenticates user', function () {
    $user = makeAuthUser('login@example.com', 'mypassword');

    $response = $this->post('/martis/login', [
        'email' => 'login@example.com',
        'password' => 'mypassword',
    ]);

    $response->assertRedirect();
    $this->assertAuthenticatedAs($user);
});

it('POST /martis/login with invalid credentials returns error', function () {
    makeAuthUser('wrong@example.com', 'correctpassword');

    $response = $this->post('/martis/login', [
        'email' => 'wrong@example.com',
        'password' => 'wrongpassword',
    ]);

    $response->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('GET /martis/api/auth/user returns authenticated user as JSON', function () {
    $user = makeAuthUser('user@example.com', 'secret');

    $response = $this->actingAs($user)->getJson('/martis/api/auth/user');

    $response->assertStatus(200)
        ->assertJsonFragment(['id' => $user->id, 'email' => 'user@example.com']);
});

it('GET /martis/api/auth/user returns null when unauthenticated', function () {
    $response = $this->getJson('/martis/api/auth/user');

    $response->assertStatus(200)->assertContent('null');
});

it('POST /martis/api/auth/logout logs out the user', function () {
    $user = makeAuthUser('logout@example.com', 'secret');

    $this->actingAs($user)->postJson('/martis/api/auth/logout');

    $this->assertGuest();
});

it('authenticated user can access protected routes', function () {
    $user = makeAuthUser('access@example.com', 'secret');

    $response = $this->actingAs($user)->get('/martis');

    $response->assertStatus(200);
});
