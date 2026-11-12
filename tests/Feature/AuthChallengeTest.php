<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Martis\Auth\AuthConfigurationException;
use Martis\Auth\ChallengeConfig;
use Martis\Contracts\ChallengeVerifier;

// ===========================================================================
// The challenge (captcha) hook of the public auth forms (v2.21.0).
//
// `martis.challenge:<surface>` runs before the controller of every listed
// surface: the verifier's `false`, or any throwable, refuses with a 422 whose
// `errors.challenge_token` carries the message. Fail closed.
// ===========================================================================

/** Accepts the token `valid-token` and nothing else. */
class AcceptingChallengeVerifier implements ChallengeVerifier
{
    public static int $calls = 0;

    public function verify(Request $request): bool
    {
        self::$calls++;

        return $request->input('challenge_token') === 'valid-token';
    }
}

/** The vendor is down. */
class ThrowingChallengeVerifier implements ChallengeVerifier
{
    public function verify(Request $request): bool
    {
        throw new RuntimeException('captcha vendor timed out');
    }
}

/** Not a ChallengeVerifier. */
class NotAChallengeVerifier
{
    public function verify(Request $request): bool
    {
        return true;
    }
}

/**
 * Every challenged POST route: [surface, uri]. With its flow switched off,
 * each controller answers 404 or 503 (never 422) once it is reached, so a
 * 422 carrying `errors.challenge_token` proves the controller did not run.
 */
dataset('challenged routes', [
    'login (API)' => ['login', '/martis/api/auth/login'],
    'forgot' => ['forgot', '/martis/api/auth/password/email'],
    'reset' => ['reset', '/martis/api/auth/password/reset'],
    'magic_link' => ['magic_link', '/martis/api/auth/magic-link/request'],
    'register' => ['register', '/martis/api/auth/register'],
    'invitation' => ['invitation', '/martis/api/invitations/accept'],
]);

function enableChallenge(string $verifier = AcceptingChallengeVerifier::class, array $appliesTo = ['login', 'forgot', 'reset', 'magic_link', 'register', 'invitation']): void
{
    config()->set('martis.auth.challenge', [
        'enabled' => true,
        'verifier' => $verifier,
        'site_key' => 'site-key',
        'applies_to' => $appliesTo,
    ]);
}

function challengeUser(): User
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
    $user = User::forceCreate(['name' => 'Person', 'email' => 'person@example.com', 'password' => Hash::make('secret-pass-123')]);

    return $user;
}

/** Switch on every flow a challenged route guards, so its controller is reachable. */
function enableEveryFlow(): void
{
    config()->set('martis.auth.registration.enabled', true);
    config()->set('martis.auth.passwordReset.enabled', true);
    config()->set('martis.auth.magic_link.enabled', true);
    config()->set('martis.invitations.enabled', true);
}

beforeEach(function () {
    Cache::flush();
    AcceptingChallengeVerifier::$calls = 0;
    config()->set('auth.providers.users.model', User::class);
});

it('is off by default and leaves every route alone', function () {
    expect(config('martis.auth.challenge'))->toBe([
        'enabled' => false,
        'verifier' => null,
        'site_key' => null,
        'applies_to' => ['login', 'forgot', 'magic_link', 'register'],
    ]);

    $this->postJson('/martis/api/auth/password/email', ['email' => 'a@example.com'])->assertNotFound();
});

it('refuses a POST without a token with a 422 on challenge_token, before the controller', function (string $surface, string $uri) {
    enableChallenge();
    enableEveryFlow();

    $response = $this->postJson($uri, ['email' => 'person@example.com', 'password' => 'secret-pass-123']);

    $response->assertStatus(422)->assertJsonValidationErrors(['challenge_token']);
    expect($response->json('errors.challenge_token.0'))->toBe(__('martis::auth.challenge_failed'));
})->with('challenged routes');

it('refuses a wrong token the same way', function (string $surface, string $uri) {
    enableChallenge();
    enableEveryFlow();

    $this->postJson($uri, ['challenge_token' => 'forged'])->assertStatus(422)->assertJsonValidationErrors(['challenge_token']);
})->with('challenged routes');

it('lets a valid token through to the controller', function (string $surface, string $uri) {
    enableChallenge();
    enableEveryFlow();

    $response = $this->postJson($uri, ['challenge_token' => 'valid-token']);

    // The controller answers (its own validation, no body sent): the
    // challenge is passed.
    expect($response->getStatusCode())->toBeIn([404, 503, 422]);
    expect($response->json('errors.challenge_token'))->toBeNull();
    expect(AcceptingChallengeVerifier::$calls)->toBe(1);
})->with('challenged routes');

/** The surfaces whose own flow can be switched off: [surface, uri, the switch's config key]. */
dataset('flow-gated routes', [
    'forgot' => ['forgot', '/martis/api/auth/password/email', 'martis.auth.passwordReset.enabled'],
    'reset' => ['reset', '/martis/api/auth/password/reset', 'martis.auth.passwordReset.enabled'],
    'magic_link' => ['magic_link', '/martis/api/auth/magic-link/request', 'martis.auth.magic_link.enabled'],
    'register' => ['register', '/martis/api/auth/register', 'martis.auth.registration.enabled'],
    'invitation' => ['invitation', '/martis/api/invitations/accept', 'martis.invitations.enabled'],
]);

it('does not call the verifier for a surface whose own flow is switched off', function (string $surface, string $uri, string $switch) {
    enableChallenge();
    config()->set($switch, false);

    $response = $this->postJson($uri, ['email' => 'a@example.com']);

    // The flow's usual disabled answer, never the challenge's 422.
    expect($response->getStatusCode())->toBeIn([404, 503]);
    expect($response->json('errors.challenge_token'))->toBeNull();
    expect(AcceptingChallengeVerifier::$calls)->toBe(0);
})->with('flow-gated routes');

it('calls the verifier again once the flow is switched on', function (string $surface, string $uri, string $switch) {
    enableChallenge();
    enableEveryFlow();

    $this->postJson($uri, ['email' => 'a@example.com'])->assertStatus(422)->assertJsonValidationErrors(['challenge_token']);
    expect(AcceptingChallengeVerifier::$calls)->toBe(1);
})->with('flow-gated routes');

it('treats an empty value of the challenge switch as unset', function () {
    challengeUser();
    config()->set('martis.auth.challenge', ['enabled' => '', 'verifier' => null, 'site_key' => null, 'applies_to' => ['login']]);

    expect(ChallengeConfig::enabled())->toBeFalse();
    $this->postJson('/martis/api/auth/login', ['email' => 'a@example.com', 'password' => 'x'])->assertStatus(422)
        ->assertJsonMissingValidationErrors(['challenge_token']);
});

it('refuses valid credentials without a token on both login routes and signs nobody in', function () {
    $user = challengeUser();
    enableChallenge();
    $credentials = ['email' => 'person@example.com', 'password' => 'secret-pass-123'];

    $this->postJson('/martis/api/auth/login', $credentials)->assertStatus(422)->assertJsonValidationErrors(['challenge_token']);
    $this->assertGuest();

    // The form route flashes the error back, as it does for any invalid field.
    $this->post('/martis/login', $credentials)->assertSessionHasErrors(['challenge_token']);
    $this->assertGuest();

    $this->postJson('/martis/api/auth/login', $credentials + ['challenge_token' => 'valid-token'])->assertOk();
    $this->assertAuthenticatedAs($user);
});

it('signs in on the form route with a valid token', function () {
    $user = challengeUser();
    enableChallenge();

    $this->post('/martis/login', ['email' => 'person@example.com', 'password' => 'secret-pass-123', 'challenge_token' => 'valid-token'])
        ->assertRedirect();
    $this->assertAuthenticatedAs($user);
});

it('fails closed when the verifier throws, and reports the throwable', function (string $surface, string $uri) {
    Exceptions::fake();
    enableChallenge(ThrowingChallengeVerifier::class);
    enableEveryFlow();

    $this->postJson($uri, ['challenge_token' => 'valid-token'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['challenge_token']);

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'captcha vendor timed out');
})->with('challenged routes');

it('leaves the surfaces outside applies_to alone', function () {
    enableChallenge(AcceptingChallengeVerifier::class, ['login']);

    // forgot is not listed: the controller answers (404, reset is off).
    $this->postJson('/martis/api/auth/password/email', ['email' => 'a@example.com'])->assertNotFound();
    expect(AcceptingChallengeVerifier::$calls)->toBe(0);

    $this->postJson('/martis/api/auth/login', ['email' => 'a@example.com', 'password' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['challenge_token']);
});

it('does nothing while disabled, whatever the other keys hold', function () {
    config()->set('martis.auth.challenge', ['enabled' => false, 'verifier' => 'No\\Such\\Class', 'site_key' => null, 'applies_to' => ['nope']]);

    $this->postJson('/martis/api/auth/password/email', ['email' => 'a@example.com'])->assertNotFound();
});

it('does not guard the GET pages or the sign-out', function () {
    enableChallenge();

    $this->get('/martis/login')->assertOk();
    $this->get('/martis/forgot-password')->assertRedirect();
});

it('runs after the throttles and the password sign-in switch', function () {
    challengeUser();
    enableChallenge();
    config()->set('martis.auth.password_login.enabled', false);

    // Switch first: 403 even though the token is missing.
    $this->postJson('/martis/api/auth/login', ['email' => 'person@example.com', 'password' => 'x'])->assertForbidden();
    expect(AcceptingChallengeVerifier::$calls)->toBe(0);

    // Throttle first: 429 before the switch and the verifier.
    RateLimiter::for('martis-login', fn () => Limit::perMinute(1)->by('fixed'));
    $this->postJson('/martis/api/auth/login', ['email' => 'person@example.com'])->assertForbidden();
    $this->postJson('/martis/api/auth/login', ['email' => 'person@example.com'])->assertStatus(429);
    expect(AcceptingChallengeVerifier::$calls)->toBe(0);
});

it('still challenges a break-glass sign-in', function () {
    challengeUser();
    enableChallenge();
    config()->set('martis.auth.password_login.enabled', false);
    config()->set('martis.auth.password_login.break_glass_emails', ['person@example.com']);
    $credentials = ['email' => 'person@example.com', 'password' => 'secret-pass-123'];

    // Still challenged, but the refusal is the 403 every address gets while
    // password sign-in is off: the break-glass list is not an oracle.
    $this->postJson('/martis/api/auth/login', $credentials)->assertForbidden()->assertJsonMissingValidationErrors(['challenge_token']);
    $this->assertGuest();
    $this->postJson('/martis/api/auth/login', $credentials + ['challenge_token' => 'valid-token'])->assertOk();
});

// --- misconfiguration throws, naming the key ---------------------------------

it('throws naming martis.auth.challenge.verifier when it is missing, unknown or the wrong type', function (mixed $verifier, string $problem) {
    $this->withoutExceptionHandling();
    enableChallenge();
    config()->set('martis.auth.challenge.verifier', $verifier);

    expect(fn () => $this->postJson('/martis/api/auth/password/email', ['email' => 'a@example.com']))
        ->toThrow(AuthConfigurationException::class, 'martis.auth.challenge.verifier');
})->with([
    'null' => [null, 'missing'],
    'empty' => ['', 'missing'],
    'unknown class' => ['App\\Nope\\Missing', 'unknown'],
    'wrong type' => [NotAChallengeVerifier::class, 'wrong type'],
    'not a string' => [['x'], 'array'],
]);

it('throws naming martis.auth.challenge.applies_to for an unknown surface, even on a listed one', function () {
    $this->withoutExceptionHandling();
    enableChallenge(AcceptingChallengeVerifier::class, ['login', 'captcha']);

    expect(fn () => $this->postJson('/martis/api/auth/login', ['email' => 'a@example.com']))
        ->toThrow(AuthConfigurationException::class, 'martis.auth.challenge.applies_to');
});

it('throws naming martis.auth.challenge.applies_to when it is not an array', function () {
    enableChallenge();
    config()->set('martis.auth.challenge.applies_to', 'login');

    expect(fn () => ChallengeConfig::covers('login'))->toThrow(AuthConfigurationException::class, 'martis.auth.challenge.applies_to');
});

it('throws naming the key when enabled is not a boolean', function () {
    config()->set('martis.auth.challenge.enabled', ['yes']);

    expect(fn () => ChallengeConfig::enabled())->toThrow(AuthConfigurationException::class, 'martis.auth.challenge.enabled');
});

it('refuses an unknown surface in the middleware definition itself', function () {
    enableChallenge();

    expect(fn () => ChallengeConfig::covers('typo'))->toThrow(InvalidArgumentException::class, 'typo');
});

it('keeps the login page rendering when applies_to is unusable, publishing no surface', function () {
    config()->set('martis.auth.challenge', ['enabled' => true, 'verifier' => null, 'site_key' => 'k', 'applies_to' => ['nope']]);

    $this->get('/martis/login')->assertOk();
    expect(ChallengeConfig::published())->toBe(['enabled' => true, 'siteKey' => 'k', 'appliesTo' => []]);
});
