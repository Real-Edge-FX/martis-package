<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Martis\Auth\AccountLoginThrottle;
use Martis\Auth\AuthConfigurationException;
use Martis\Auth\PasswordLogin;
use Martis\MartisServiceProvider;

// ===========================================================================
// The password sign-in switch (`martis.auth.password_login`, v2.21.0).
//
// Off, POST /login and POST /api/auth/login refuse every address with a 403
// before the controller reads a credential, break-glass addresses excepted,
// and the password reset flow is off with it.
// ===========================================================================

function passwordLoginUser(string $email = 'person@example.com'): User
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
    $user = User::forceCreate(['name' => 'Person', 'email' => $email, 'password' => Hash::make('secret-pass-123')]);

    return $user;
}

/** The two password sign-in routes: [label => [method, uri]]. */
dataset('password sign-in routes', [
    'POST /login' => ['post', '/martis/login'],
    'POST /api/auth/login' => ['postJson', '/martis/api/auth/login'],
]);

beforeEach(function () {
    Cache::flush();
    config()->set('auth.providers.users.model', User::class);
});

it('keeps password sign-in on by default', function (string $method, string $uri) {
    $user = passwordLoginUser();

    $response = $this->{$method}($uri, ['email' => 'person@example.com', 'password' => 'secret-pass-123']);

    expect($response->getStatusCode())->toBeIn([200, 302]);
    $this->assertAuthenticatedAs($user);

    expect(config('martis.auth.password_login.enabled'))->toBeTrue()
        ->and(config('martis.auth.password_login.break_glass_emails'))->toBe([]);
})->with('password sign-in routes');

it('refuses valid credentials with a 403 when password sign-in is off', function (string $method, string $uri) {
    passwordLoginUser();
    config()->set('martis.auth.password_login.enabled', false);

    $response = $this->{$method}($uri, ['email' => 'person@example.com', 'password' => 'secret-pass-123']);

    $response->assertForbidden();
    $this->assertGuest();
    expect($response->getContent())->toContain(__('martis::auth.password_login_disabled'));
})->with('password sign-in routes');

it('answers the same for a known address, an unknown one and an empty body', function () {
    passwordLoginUser();
    config()->set('martis.auth.password_login.enabled', false);

    $answers = collect([
        ['email' => 'person@example.com', 'password' => 'secret-pass-123'],
        ['email' => 'nobody@example.com', 'password' => 'whatever'],
        [],
    ])->map(fn (array $body) => $this->postJson('/martis/api/auth/login', $body))->each->assertForbidden();

    expect($answers->map->getContent()->unique())->toHaveCount(1);
});

it('refuses before the controller validates or reads a credential', function () {
    config()->set('martis.auth.password_login.enabled', false);

    // A malformed body would be a 422 from the controller's validation.
    $this->postJson('/martis/api/auth/login', ['email' => ['not', 'a', 'string']])->assertForbidden();
    $this->post('/martis/login', [])->assertForbidden();
});

it('lets a break-glass address through to the normal flow, case-insensitively and trimmed', function (string $method, string $uri) {
    $user = passwordLoginUser();
    config()->set('martis.auth.password_login.enabled', false);
    config()->set('martis.auth.password_login.break_glass_emails', ['  Person@Example.COM ', 'other@example.com']);

    $response = $this->{$method}($uri, ['email' => 'person@example.com', 'password' => 'secret-pass-123']);

    expect($response->getStatusCode())->toBeIn([200, 302]);
    $this->assertAuthenticatedAs($user);
})->with('password sign-in routes');

it('matches the break-glass list case-insensitively and trimmed', function () {
    config()->set('martis.auth.password_login.break_glass_emails', ['  Person@Example.COM ', '']);

    expect(PasswordLogin::isBreakGlass('person@example.com'))->toBeTrue()
        ->and(PasswordLogin::isBreakGlass(' PERSON@example.com'))->toBeTrue()
        ->and(PasswordLogin::isBreakGlass('other@example.com'))->toBeFalse()
        ->and(PasswordLogin::isBreakGlass(''))->toBeFalse()
        ->and(PasswordLogin::isBreakGlass(null))->toBeFalse()
        ->and(PasswordLogin::isBreakGlass(['person@example.com']))->toBeFalse();
});

// --- the break-glass list is not an oracle -----------------------------------

/** What a refusal shows a caller: status, content type and body. */
function refusalOf(TestResponse $response): array
{
    return [
        'status' => $response->getStatusCode(),
        'content-type' => $response->headers->get('Content-Type'),
        'body' => $response->getContent(),
    ];
}

it('answers a failed break-glass sign-in exactly as it answers an address that is not listed', function (string $method, string $uri) {
    passwordLoginUser();
    config()->set('martis.auth.password_login.enabled', false);
    config()->set('martis.auth.password_login.break_glass_emails', ['person@example.com']);

    $unlisted = refusalOf($this->{$method}($uri, ['email' => 'other@example.com', 'password' => 'secret-pass-123']));

    $failures = [
        'wrong password' => ['email' => 'person@example.com', 'password' => 'wrong-password'],
        'no password' => ['email' => 'person@example.com'],
        'a malformed body' => ['email' => ['person@example.com'], 'password' => 'x'],
    ];

    expect($unlisted['status'])->toBe(403);

    foreach ($failures as $label => $body) {
        expect(refusalOf($this->{$method}($uri, $body)))->toBe($unlisted, $label);
    }
})->with('password sign-in routes');

it('answers a listed address with no account like any other address', function () {
    passwordLoginUser();
    config()->set('martis.auth.password_login.enabled', false);
    config()->set('martis.auth.password_login.break_glass_emails', ['ghost@example.com']);

    $unlisted = refusalOf($this->postJson('/martis/api/auth/login', ['email' => 'other@example.com', 'password' => 'x']));

    expect(refusalOf($this->postJson('/martis/api/auth/login', ['email' => 'ghost@example.com', 'password' => 'x'])))->toBe($unlisted);
    $this->assertGuest();
});

it('leaves no validation errors or old input in the session of a refused form sign-in', function () {
    passwordLoginUser();
    config()->set('martis.auth.password_login.enabled', false);
    config()->set('martis.auth.password_login.break_glass_emails', ['person@example.com']);

    $this->post('/martis/login', ['email' => 'person@example.com', 'password' => 'wrong-password'])->assertForbidden();

    expect(session()->has('errors'))->toBeFalse()
        ->and(session()->has('_old_input'))->toBeFalse();
});

it('does not let the per-account throttle tell a break-glass address apart', function () {
    $user = passwordLoginUser();
    config()->set('martis.auth.password_login.enabled', false);
    config()->set('martis.auth.password_login.break_glass_emails', ['person@example.com']);
    config()->set('martis.throttle.login_email_attempts', 2);

    $unlisted = refusalOf($this->postJson('/martis/api/auth/login', ['email' => 'other@example.com', 'password' => 'x']));

    // Spend the account's bucket, which the controller answers with a 429.
    foreach (range(1, 2) as $ignored) {
        AccountLoginThrottle::hit(AccountLoginThrottle::PASSWORD, 'person@example.com', $user);
    }

    expect(refusalOf($this->postJson('/martis/api/auth/login', ['email' => 'person@example.com', 'password' => 'secret-pass-123'])))
        ->toBe($unlisted);
    $this->assertGuest();
});

it('keeps counting a failed break-glass sign-in against the account', function () {
    $user = passwordLoginUser();
    config()->set('martis.auth.password_login.enabled', false);
    config()->set('martis.auth.password_login.break_glass_emails', ['person@example.com']);

    $this->postJson('/martis/api/auth/login', ['email' => 'person@example.com', 'password' => 'wrong-password'])->assertForbidden();

    expect(AccountLoginThrottle::tooMany(AccountLoginThrottle::PASSWORD, 'person@example.com', $user))->toBeFalse();
    expect(RateLimiter::attempts(AccountLoginThrottle::key(AccountLoginThrottle::PASSWORD, 'person@example.com', $user)))->toBe(1);
});

it('lets the listed address sign in with the right password, and shows the next step after correct credentials', function () {
    $user = passwordLoginUser();
    config()->set('martis.auth.password_login.enabled', false);
    config()->set('martis.auth.password_login.break_glass_emails', ['person@example.com']);

    $this->postJson('/martis/api/auth/login', ['email' => 'person@example.com', 'password' => 'secret-pass-123'])
        ->assertOk()->assertJsonPath('email', 'person@example.com');
    $this->assertAuthenticatedAs($user);
});

it('shows the email verification step after correct break-glass credentials', function () {
    $user = passwordLoginUser();
    config()->set('martis.auth.email_verification.enabled', true);
    config()->set('martis.auth.password_login.enabled', false);
    config()->set('martis.auth.password_login.break_glass_emails', ['person@example.com']);

    $this->postJson('/martis/api/auth/login', ['email' => 'person@example.com', 'password' => 'secret-pass-123'])
        ->assertOk()->assertJsonPath('email_verification_required', true);
    $this->assertAuthenticatedAs($user);
});

it('looks the account up by the same normalised address the list is matched on', function () {
    $user = passwordLoginUser();
    config()->set('martis.auth.password_login.enabled', false);
    config()->set('martis.auth.password_login.break_glass_emails', ['  Person@Example.COM ']);

    // A case variant reaches the account of the listed (lower-cased) address,
    // on a database whose comparison is case-sensitive too.
    $this->postJson('/martis/api/auth/login', ['email' => 'PERSON@Example.com', 'password' => 'secret-pass-123'])->assertOk();
    $this->assertAuthenticatedAs($user);
});

it('does not normalise anything while password sign-in is on', function () {
    passwordLoginUser();
    config()->set('martis.auth.password_login.break_glass_emails', ['person@example.com']);

    // On: the controller answers as it always did (a wrong password is a 422).
    $this->postJson('/martis/api/auth/login', ['email' => 'person@example.com', 'password' => 'wrong-password'])->assertStatus(422);
    $this->postJson('/martis/api/auth/login', ['email' => 'PERSON@example.com', 'password' => 'secret-pass-123'])->assertStatus(422);
});

it('runs the throttles before the switch', function () {
    config()->set('martis.auth.password_login.enabled', false);
    config()->set('martis.throttle.login_attempts', 2);

    // The generic throttle is built from config when the routes load, so
    // spend the named per-email limiter, which reads its limit at request time.
    RateLimiter::for('martis-login', fn () => Limit::perMinute(1)->by('fixed'));

    $this->postJson('/martis/api/auth/login', ['email' => 'a@example.com'])->assertForbidden();
    $this->postJson('/martis/api/auth/login', ['email' => 'a@example.com'])->assertStatus(429);
});

it('turns the password reset endpoints and pages off with it, as when passwordReset is disabled', function () {
    config()->set('martis.auth.passwordReset.enabled', true);
    config()->set('martis.auth.password_login.enabled', false);

    $this->postJson('/martis/api/auth/password/email', ['email' => 'person@example.com'])->assertNotFound();
    $this->postJson('/martis/api/auth/password/reset', [
        'token' => 't', 'email' => 'person@example.com', 'password' => 'abcd1234', 'password_confirmation' => 'abcd1234',
    ])->assertNotFound();
    $this->get('/martis/forgot-password')->assertRedirect('/martis/login');
    expect($this->get('/martis/reset-password')->headers->get('Location'))->toStartWith('http://localhost/martis/login');

    expect(PasswordLogin::passwordResetEnabled())->toBeFalse();
});

it('keeps the password reset flow on while password sign-in is on', function () {
    config()->set('martis.auth.passwordReset.enabled', true);

    $this->get('/martis/forgot-password')->assertOk();
    expect(PasswordLogin::passwordResetEnabled())->toBeTrue();
});

it('publishes the switch and hides the reset link for the SPA, without the break-glass list', function () {
    config()->set('martis.auth.passwordReset.enabled', true);
    config()->set('martis.auth.password_login.enabled', false);
    config()->set('martis.auth.password_login.break_glass_emails', ['secret-breakglass@example.com']);

    $html = $this->get('/martis/login')->assertOk()->getContent();
    preg_match('/^\s*auth: (.*),$/m', $html, $match);
    $auth = json_decode($match[1], true);

    expect($auth['passwordLogin'])->toBe(['enabled' => false])
        ->and($auth['passwordReset']['enabled'])->toBeFalse()
        ->and($html)->not->toContain('secret-breakglass');
});

it('does not touch the other sign-in flows', function () {
    passwordLoginUser('existing@example.com');
    config()->set('martis.auth.password_login.enabled', false);
    config()->set('martis.auth.registration.enabled', true);
    config()->set('martis.auth.magic_link.enabled', true);

    $this->postJson('/martis/api/auth/register', [
        'name' => 'Jane', 'email' => 'jane@example.com', 'password' => 'abcd1234', 'password_confirmation' => 'abcd1234',
    ])->assertStatus(201);
    // Reaches its controller (a 503 here is the test app's missing mailer).
    expect($this->postJson('/martis/api/auth/magic-link/request', ['email' => 'jane@example.com'])->getStatusCode())->not->toBe(403);
});

// --- configuration errors name the key --------------------------------------

it('throws naming the key when the switch or the break-glass list is unusable', function () {
    config()->set('martis.auth.password_login.enabled', ['yes']);
    expect(fn () => PasswordLogin::enabled())->toThrow(AuthConfigurationException::class, 'martis.auth.password_login.enabled');

    config()->set('martis.auth.password_login.enabled', true);
    config()->set('martis.auth.password_login.break_glass_emails', 'a@example.com');
    expect(fn () => PasswordLogin::breakGlassEmails())->toThrow(AuthConfigurationException::class, 'martis.auth.password_login.break_glass_emails');

    config()->set('martis.auth.password_login.break_glass_emails', [['a@example.com']]);
    expect(fn () => PasswordLogin::breakGlassEmails())->toThrow(AuthConfigurationException::class, 'martis.auth.password_login.break_glass_emails');
});

it('reads the env var forms of the switch', function (mixed $value, bool $expected) {
    config()->set('martis.auth.password_login.enabled', $value);

    expect(PasswordLogin::enabled())->toBe($expected);
})->with([['false', false], ['0', false], ['true', true], [1, true], [false, false]]);

// --- the boot warning --------------------------------------------------------

/** Boot the provider again, as a request does, with the log watched. */
function bootMartisProvider(): void
{
    app()->getProvider(MartisServiceProvider::class)->boot();
}

it('logs one warning naming the config keys when password sign-in is off and nothing else lets people in', function () {
    config()->set('martis.auth.password_login.enabled', false);
    config()->set('martis.auth.sso.enabled', false);
    config()->set('martis.auth.magic_link.enabled', false);

    Log::shouldReceive('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'martis.auth.password_login.enabled')
        && str_contains($message, 'martis.auth.sso')
        && str_contains($message, 'martis.auth.magic_link.enabled'));

    PasswordLogin::warnWhenLockedOut();
    PasswordLogin::warnWhenLockedOut();
    PasswordLogin::warnWhenLockedOut();
});

it('warns from the boot of the provider, once across boots', function () {
    config()->set('martis.auth.password_login.enabled', false);

    Log::shouldReceive('warning')->once();

    bootMartisProvider();
    bootMartisProvider();
});

it('does not warn when password sign-in is on, or when SSO or magic link is a way in', function (array $config) {
    foreach ($config as $key => $value) {
        config()->set($key, $value);
    }

    Log::shouldReceive('warning')->never();

    PasswordLogin::warnWhenLockedOut();
})->with([
    'password on' => [['martis.auth.password_login.enabled' => true]],
    'enabled sso provider' => [[
        'martis.auth.password_login.enabled' => false,
        'martis.auth.sso.enabled' => true,
        'martis.auth.sso.providers' => ['azure' => ['enabled' => true]],
    ]],
    'magic link' => [['martis.auth.password_login.enabled' => false, 'martis.auth.magic_link.enabled' => true]],
]);

it('warns when SSO is on but no provider is enabled', function () {
    config()->set('martis.auth.password_login.enabled', false);
    config()->set('martis.auth.sso.enabled', true);
    config()->set('martis.auth.sso.providers', ['azure' => ['enabled' => false]]);

    Log::shouldReceive('warning')->once();

    PasswordLogin::warnWhenLockedOut();
});

it('never breaks the boot when the cache fails', function () {
    config()->set('martis.auth.password_login.enabled', false);
    Cache::shouldReceive('add')->andThrow(new RuntimeException('cache down'));
    Log::shouldReceive('warning')->never();

    PasswordLogin::warnWhenLockedOut();

    expect(true)->toBeTrue();
});
