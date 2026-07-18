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

it('POST /martis/login with keep_signed_in issues the remember-me token', function () {
    $user = makeAuthUser('remember@example.com', 'mypassword');
    expect($user->remember_token)->toBeNull();

    $response = $this->post('/martis/login', [
        'email' => 'remember@example.com',
        'password' => 'mypassword',
        'keep_signed_in' => true,
    ]);

    $response->assertRedirect();
    $this->assertAuthenticatedAs($user);
    // attempt($creds, true) persists a remember_token and queues the
    // long-lived remember cookie — the signal that the toggle was honoured.
    expect($user->fresh()->remember_token)->not->toBeNull();
});

it('POST /martis/login without keep_signed_in does not issue a remember-me token', function () {
    $user = makeAuthUser('noremember@example.com', 'mypassword');

    $this->post('/martis/login', [
        'email' => 'noremember@example.com',
        'password' => 'mypassword',
    ])->assertRedirect();

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->remember_token)->toBeNull();
});

// The SPA only ever posts to /api/auth/login (AuthController::login). It must
// honour "Keep me signed in" exactly like the non-SPA /login path above, or the
// default-checked toggle is a silent no-op and users are logged out after
// SESSION_LIFETIME despite asking to stay signed in.
it('POST /martis/api/auth/login with keep_signed_in issues the remember-me token AND cookie', function () {
    $user = makeAuthUser('api-remember@example.com', 'mypassword');
    expect($user->remember_token)->toBeNull();

    $response = $this->postJson('/martis/api/auth/login', [
        'email' => 'api-remember@example.com',
        'password' => 'mypassword',
        'keep_signed_in' => true,
    ]);

    $response->assertStatus(200);
    $this->assertAuthenticatedAs($user);
    // attempt($creds, true) persists a remember_token AND queues the
    // long-lived remember cookie (remember_web_<hash>). Assert BOTH: the
    // persisted token is the DB side, the cookie is what actually keeps the
    // browser signed in past config('session.lifetime') — the real symptom.
    expect($user->fresh()->remember_token)->not->toBeNull();
    $recaller = auth()->guard(config('martis.guard'))->getRecallerName();
    $response->assertCookie($recaller);
});

it('POST /martis/api/auth/login without keep_signed_in issues no remember-me token or cookie', function () {
    $user = makeAuthUser('api-noremember@example.com', 'mypassword');

    $response = $this->postJson('/martis/api/auth/login', [
        'email' => 'api-noremember@example.com',
        'password' => 'mypassword',
    ]);
    $response->assertStatus(200);

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->remember_token)->toBeNull();
    $response->assertCookieMissing(auth()->guard(config('martis.guard'))->getRecallerName());
});

it('POST /martis/api/auth/login with keep_signed_in=false issues no remember-me token or cookie', function () {
    // The SPA sends the toggle state explicitly (AuthContext posts
    // keep_signed_in: <state>), so an unchecked toggle arrives as `false`,
    // not an omitted field — assert that realistic path leaves no remember.
    $user = makeAuthUser('api-falseremember@example.com', 'mypassword');

    $response = $this->postJson('/martis/api/auth/login', [
        'email' => 'api-falseremember@example.com',
        'password' => 'mypassword',
        'keep_signed_in' => false,
    ]);
    $response->assertStatus(200);

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->remember_token)->toBeNull();
    $response->assertCookieMissing(auth()->guard(config('martis.guard'))->getRecallerName());
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
