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

it('GET /martis/reset-password renders SPA when reset is enabled, never cached and without a Referer', function () {
    config([
        'martis.auth.passwordReset.enabled' => true,
        'martis.auth.passwordReset.url' => null,
    ]);

    $response = $this->get('/martis/reset-password');

    // The token travels in the fragment, so this request never sees it.
    $response->assertStatus(200)->assertHeader('Referrer-Policy', 'no-referrer');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('GET /martis/reset-password/{token} sends a link emailed before v2.6.0 to the fragment form', function () {
    config([
        'martis.auth.passwordReset.enabled' => true,
        'martis.auth.passwordReset.url' => null,
    ]);

    $response = $this->get('/martis/reset-password/some-token-value?email=jane%40example.com');

    $response->assertRedirect('/martis/reset-password#token=some-token-value&email=jane%40example.com')
        ->assertHeader('Referrer-Policy', 'no-referrer');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    // The token is only after the `#`: the part a proxy logs has none.
    $location = parse_url($response->headers->get('Location'));
    expect($location['path'])->toBe('/martis/reset-password')
        ->and($location)->not->toHaveKey('query')
        ->and($location['fragment'])->toContain('some-token-value');
});

it('GET /martis/reset-password/{token} leaves the email out of the fragment when the link has none', function () {
    config([
        'martis.auth.passwordReset.enabled' => true,
        'martis.auth.passwordReset.url' => null,
    ]);

    $this->get('/martis/reset-password/some-token-value')
        ->assertRedirect('/martis/reset-password#token=some-token-value');
});

it('GET /martis/reset-password?token= sends a custom link builder that kept the query string to the fragment form', function () {
    config([
        'martis.auth.passwordReset.enabled' => true,
        'martis.auth.passwordReset.url' => null,
    ]);

    $this->get('/martis/reset-password?token=abc&email=jane%40example.com')
        ->assertRedirect('/martis/reset-password#token=abc&email=jane%40example.com');
});

it('GET /martis/reset-password/{token} keeps the disabled and off-platform redirects', function () {
    config(['martis.auth.passwordReset.enabled' => false]);
    $this->get('/martis/reset-password/some-token-value')->assertRedirect('/martis/login#');

    config([
        'martis.auth.passwordReset.enabled' => true,
        'martis.auth.passwordReset.url' => 'https://reset.example.com',
    ]);
    $this->get('/martis/reset-password/some-token-value')->assertRedirect('https://reset.example.com#');
});

it('redirects away from the reset page without the fragment, so the token does not follow', function (string $path) {
    // Disabled.
    config(['martis.auth.passwordReset.enabled' => false]);
    $response = $this->get($path);
    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toEndWith('/martis/login#')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer');

    // Off-platform page: the configured URL, exactly, and an empty fragment.
    config([
        'martis.auth.passwordReset.enabled' => true,
        'martis.auth.passwordReset.url' => 'https://reset.example.com/start',
    ]);
    expect($this->get($path)->headers->get('Location'))->toBe('https://reset.example.com/start#');

    // Signed in.
    config(['martis.auth.passwordReset.url' => null]);
    $this->actingAs(User::forceCreate(['name' => 'Jane', 'email' => 'jane@example.com', 'password' => bcrypt('x')]));
    $location = $this->get($path)->headers->get('Location');
    expect($location)->toEndWith('#')
        ->and(parse_url($location, PHP_URL_PATH))->toBe('/martis');
})->with([
    'page' => ['/martis/reset-password'],
    'legacy link' => ['/martis/reset-password/some-token-value?email=jane%40example.com'],
]);

it('GET /martis/reset-password?<token> sends the bare-key URL of route(name, $token) to the fragment form', function () {
    config([
        'martis.auth.passwordReset.enabled' => true,
        'martis.auth.passwordReset.url' => null,
    ]);
    $token = str_repeat('aB3_-', 13); // 65 chars

    $url = route('martis.password.reset', $token);
    expect(parse_url($url, PHP_URL_QUERY))->toBe($token);
    $this->get($url)->assertRedirect('/martis/reset-password#token='.$token);

    $url = route('martis.password.reset', [$token, 'email' => 'a@b.c']);
    // Laravel puts the bare key after the named parameters.
    expect(parse_url($url, PHP_URL_QUERY))->toBe('email=a%40b.c&'.$token);
    $this->get($url)->assertRedirect('/martis/reset-password#token='.$token.'&email=a%40b.c');
});

it('GET /martis/reset-password renders the shell for a query string that carries no token', function (string $query) {
    config([
        'martis.auth.passwordReset.enabled' => true,
        'martis.auth.passwordReset.url' => null,
    ]);

    $this->get('/martis/reset-password'.$query)->assertStatus(200);
})->with(['empty token' => ['?token='], 'flag' => ['?lang'], 'utm' => ['?utm_source=x'], 'short bare key' => ['?abc123']]);

it('carries a reset token and an email with special characters through the legacy redirect unchanged', function () {
    config([
        'martis.auth.passwordReset.enabled' => true,
        'martis.auth.passwordReset.url' => null,
    ]);
    $token = 'a+b=c%d~e_f-g';
    $email = 'jane+tag@example.com';

    $response = $this->get('/martis/reset-password/'.rawurlencode($token).'?'.http_build_query(['email' => $email]));
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_FRAGMENT), $parsed);

    expect($parsed)->toBe(['token' => $token, 'email' => $email]);
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
