<?php

declare(strict_types=1);

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

/*
 * The forgot-password and reset-password endpoints are public, so what they
 * say must not tell an anonymous caller which addresses have a panel account.
 * The forgot endpoint answered 422 "We can't find a user" for an unknown
 * address and 200 for a known one (or "throttled" for a known one asked twice
 * within the broker's window), although its docblock promised "neutral in
 * prod". The reset endpoint told an unknown address from a known one with a
 * wrong token. Outside app.debug they now answer alike.
 */

class NeutralResetUser extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];
}

beforeEach(function () {
    config()->set('auth.providers.users', ['driver' => 'eloquent', 'model' => NeutralResetUser::class]);
    config()->set('auth.passwords.users', ['provider' => 'users', 'table' => 'password_reset_tokens', 'expire' => 60, 'throttle' => 60]);
    config()->set('auth.defaults.passwords', 'users');
    config()->set('martis.guard', null);
    config()->set('martis.auth.passwordReset.enabled', true);
    config()->set('martis.auth.passwordReset.url', null);
    config()->set('martis.auth.passwordReset.broker', null);
    config()->set('app.debug', false);

    foreach (['users', 'password_reset_tokens'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->rememberToken();
        $table->timestamps();
    });
    Schema::create('password_reset_tokens', function ($table) {
        $table->string('email')->primary();
        $table->string('token');
        $table->timestamp('created_at')->nullable();
    });

    NeutralResetUser::create(['name' => 'Known', 'email' => 'known@example.com', 'password' => bcrypt('secret')]);
});

afterEach(function () {
    foreach (['users', 'password_reset_tokens'] as $table) {
        Schema::dropIfExists($table);
    }
});

function neutralForgot(string $email): TestResponse
{
    return test()->postJson('/martis/api/auth/password/email', ['email' => $email]);
}

function neutralReset(string $email, string $token = 'not-the-token'): TestResponse
{
    return test()->postJson('/martis/api/auth/password/reset', [
        'token' => $token,
        'email' => $email,
        'password' => 'A-New-Password-9',
        'password_confirmation' => 'A-New-Password-9',
    ]);
}

/** What a caller can see of a response: its status, its type and its bytes. */
function neutralShape(TestResponse $response): array
{
    return [$response->getStatusCode(), $response->headers->get('Content-Type'), $response->getContent()];
}

it('answers a known and an unknown address with the same response', function () {
    Notification::fake();

    $known = neutralForgot('known@example.com');
    $unknown = neutralForgot('nobody@example.com');

    $known->assertOk()->assertJson(['ok' => true]);
    expect(neutralShape($unknown))->toBe(neutralShape($known));
    Notification::assertSentToTimes(NeutralResetUser::first(), ResetPassword::class, 1);
    Notification::assertCount(1);
});

it('answers a known address asked twice within the broker window like an unknown one', function () {
    Notification::fake();

    $first = neutralForgot('known@example.com');
    $second = neutralForgot('known@example.com');
    $unknown = neutralForgot('nobody@example.com');

    $second->assertOk();
    expect(neutralShape($second))->toBe(neutralShape($first))
        ->and(neutralShape($unknown))->toBe(neutralShape($first));
    // The second one was throttled: only one link went out.
    Notification::assertCount(1);
});

it('keeps the neutral answer free of the broker detail for an unknown address', function () {
    Notification::fake();

    $body = neutralForgot('nobody@example.com')->assertOk()->getContent();

    expect($body)->not->toContain(__('passwords.user'))
        ->and($body)->not->toContain('errors');
    Notification::assertNothingSent();
});

it('says what happened only when app.debug is on', function () {
    Notification::fake();
    config()->set('app.debug', true);

    neutralForgot('nobody@example.com')
        ->assertUnprocessable()
        ->assertJson(['message' => __('passwords.user'), 'errors' => ['email' => [__('passwords.user')]]]);

    neutralForgot('known@example.com')->assertOk();
    neutralForgot('known@example.com')
        ->assertUnprocessable()
        ->assertJson(['message' => __('passwords.throttled')]);
});

it('still validates the request: a malformed address is a 422 for every caller', function () {
    neutralForgot('not-an-email')->assertUnprocessable()->assertJsonValidationErrors(['email']);
});

it('answers the reset of an unknown address like a known one with a wrong token', function () {
    $known = neutralReset('known@example.com');
    $unknown = neutralReset('nobody@example.com');

    $known->assertUnprocessable();
    expect(neutralShape($unknown))->toBe(neutralShape($known))
        ->and($known->json('message'))->toBe(__('passwords.token'));
});

it('tells an unknown address from a bad token on reset only when app.debug is on', function () {
    config()->set('app.debug', true);

    expect(neutralReset('nobody@example.com')->json('message'))->toBe(__('passwords.user'))
        ->and(neutralReset('known@example.com')->json('message'))->toBe(__('passwords.token'));
});

it('still resets the password of a known address with the right token', function () {
    $user = NeutralResetUser::where('email', 'known@example.com')->first();
    $token = app('auth.password')->broker('users')->createToken($user);

    neutralReset('known@example.com', $token)->assertOk()->assertJson(['ok' => true]);

    expect(password_verify('A-New-Password-9', $user->fresh()->password))->toBeTrue();
});
