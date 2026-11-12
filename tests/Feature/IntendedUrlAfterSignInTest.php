<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;

// ===========================================================================
// The intended URL after a JSON sign-in (v2.21.0).
//
// The SPA signs in with a JSON request and used to open the dashboard whatever
// Laravel's `url.intended` held. The response that completes a sign-in now
// carries it, pulled from the session once, only when it is a same-origin URL
// under the Martis base path. A 2FA challenge, email verification or forced
// password change in between keeps it in the session for the step that
// completes the sign-in.
// ===========================================================================

const IU_PASSWORD = 'secret-pass-123';
const IU_ISSUE = '/martis/sso/issue/partner?state=abc';

class IntendedUrlUser extends User
{
    protected $table = 'users';

    protected $guarded = [];

    protected $casts = ['must_change_password' => 'boolean', 'two_factor_confirmed_at' => 'datetime'];
}

function iuUser(array $attributes = []): IntendedUrlUser
{
    return IntendedUrlUser::query()->create([
        'name' => 'Person',
        'email' => 'person@example.com',
        'password' => Hash::make(IU_PASSWORD),
        'email_verified_at' => now(),
        ...$attributes,
    ]);
}

/** The attributes of an account with 2FA on, whose recovery code is `recovery-code-one`. */
function iuTwoFactor(): array
{
    return [
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => encrypt(json_encode([bcrypt('recovery-code-one')])),
    ];
}

function iuSignIn(string $intended): TestResponse
{
    return test()->withSession(['url.intended' => $intended])
        ->postJson('/martis/api/auth/login', ['email' => 'person@example.com', 'password' => IU_PASSWORD]);
}

beforeEach(function () {
    Schema::dropIfExists('users');
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->boolean('must_change_password')->default(false);
        $table->text('two_factor_secret')->nullable();
        $table->text('two_factor_recovery_codes')->nullable();
        $table->timestamp('two_factor_confirmed_at')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });

    cache()->flush();
    config()->set('auth.providers.users.model', IntendedUrlUser::class);
});

it('returns the intended URL with the sign-in, once', function () {
    $user = iuUser();

    iuSignIn('http://localhost'.IU_ISSUE)
        ->assertOk()
        ->assertJsonPath('email', 'person@example.com')
        ->assertJsonPath('intended_url', IU_ISSUE);

    $this->assertAuthenticatedAs($user);
    expect(session()->has('url.intended'))->toBeFalse();
});

it('returns nothing when no page was asked for before the sign-in', function () {
    iuUser();

    $this->postJson('/martis/api/auth/login', ['email' => 'person@example.com', 'password' => IU_PASSWORD])
        ->assertOk()
        ->assertJsonMissingPath('intended_url');
});

it('returns the path and query of a same-origin absolute URL, without its fragment', function () {
    iuUser();

    iuSignIn('http://localhost/martis/resources/users?page=2#row')->assertOk()
        ->assertJsonPath('intended_url', '/martis/resources/users?page=2');
});

it('drops, and forgets, a URL that is not a same-origin path under the base path', function (string $intended) {
    iuUser();

    iuSignIn($intended)->assertOk()->assertJsonMissingPath('intended_url');

    expect(session()->has('url.intended'))->toBeFalse();
})->with([
    'absolute foreign' => ['https://evil.example/martis/x'],
    'foreign host, same path' => ['http://evil.example/martis/sso/issue/a'],
    'protocol-relative' => ['//evil.example/martis/x'],
    'userinfo trick' => ['http://localhost@evil.example/martis/x'],
    'other port' => ['http://localhost:8080/martis/x'],
    'other scheme' => ['https://localhost/martis/x'],
    'javascript' => ['javascript:alert(1)'],
    'data' => ['data:text/html,x'],
    'outside the base path' => ['/other/page'],
    'the site root' => ['/'],
    'the base path alone' => ['/martis'],
    'a sibling with the same prefix' => ['/martis-other/x'],
    'dot segments out of the base' => ['/martis/../admin'],
    'encoded dot segments' => ['/martis/%2e%2e/admin'],
    'encoded slash' => ['/martis/a%2f..%2fb'],
    'backslash' => ['/martis/\\evil.example'],
    'encoded backslash' => ['/martis/%5Cevil.example'],
    'control character' => ["/martis/x\r\nSet-Cookie: a=b"],
    'empty' => [''],
]);

it('forgets a value that is not a string', function () {
    iuUser();

    test()->withSession(['url.intended' => ['/martis/x']])
        ->postJson('/martis/api/auth/login', ['email' => 'person@example.com', 'password' => IU_PASSWORD])
        ->assertOk()->assertJsonMissingPath('intended_url');

    expect(session()->has('url.intended'))->toBeFalse();
});

it('keeps the intended URL through the 2FA challenge and returns it with the challenge that completes the sign-in', function () {
    iuUser(iuTwoFactor());

    iuSignIn(IU_ISSUE)
        ->assertOk()
        ->assertJsonPath('two_factor_required', true)
        ->assertJsonMissingPath('intended_url');
    expect(session('url.intended'))->toBe(IU_ISSUE);

    $this->postJson('/martis/api/2fa/challenge', ['code' => 'recovery-code-one', 'use_recovery_code' => true])
        ->assertOk()
        ->assertJsonPath('intended_url', IU_ISSUE);
    expect(session()->has('url.intended'))->toBeFalse();
});

it('does not hand a foreign URL out with the 2FA challenge either', function () {
    iuUser(iuTwoFactor());

    iuSignIn('https://evil.example/martis/x')->assertJsonPath('two_factor_required', true);

    $this->postJson('/martis/api/2fa/challenge', ['code' => 'recovery-code-one', 'use_recovery_code' => true])
        ->assertOk()->assertJsonMissingPath('intended_url');
    expect(session()->has('url.intended'))->toBeFalse();
});

it('keeps the intended URL through the email verification step', function () {
    config()->set('martis.auth.email_verification.enabled', true);
    $user = iuUser(['email_verified_at' => null]);

    iuSignIn(IU_ISSUE)
        ->assertOk()
        ->assertJsonPath('email_verification_required', true)
        ->assertJsonMissingPath('intended_url');
    expect(session('url.intended'))->toBe(IU_ISSUE);

    $link = URL::signedRoute('martis.email.verify', ['id' => $user->getKey(), 'hash' => sha1('person@example.com')]);

    // The person opens the emailed link in the browser they signed in with.
    $this->actingAs($user)->get($link)->assertRedirect(IU_ISSUE);
    expect(session()->has('url.intended'))->toBeFalse()
        ->and($user->fresh()->email_verified_at)->not->toBeNull();
});

it('sends a verified person to the dashboard when the remembered URL is foreign', function () {
    config()->set('martis.auth.email_verification.enabled', true);
    $user = iuUser(['email_verified_at' => null]);
    $link = URL::signedRoute('martis.email.verify', ['id' => $user->getKey(), 'hash' => sha1('person@example.com')]);

    $this->withSession(['url.intended' => 'https://evil.example/martis/x'])->actingAs($user)->get($link)
        ->assertRedirect(route('martis.index'));
});

it('keeps the intended URL through the forced password change and returns it with the change', function () {
    config()->set('martis.auth.password_change.enabled', true);
    $user = iuUser(['must_change_password' => true]);

    iuSignIn(IU_ISSUE)
        ->assertOk()
        ->assertJsonPath('password_change_required', true)
        ->assertJsonMissingPath('intended_url');
    expect(session('url.intended'))->toBe(IU_ISSUE);

    $this->actingAs($user)->postJson('/martis/api/auth/password/change', [
        'current_password' => IU_PASSWORD,
        'password' => 'Brand-New-Pass-1',
        'password_confirmation' => 'Brand-New-Pass-1',
    ])->assertOk()->assertJsonPath('intended_url', IU_ISSUE);

    expect(session()->has('url.intended'))->toBeFalse();
});

it('keeps the intended URL when the 2FA challenge hands over to a forced password change', function () {
    config()->set('martis.auth.password_change.enabled', true);
    iuUser(iuTwoFactor() + ['must_change_password' => true]);

    iuSignIn(IU_ISSUE)->assertJsonPath('two_factor_required', true);

    $this->postJson('/martis/api/2fa/challenge', ['code' => 'recovery-code-one', 'use_recovery_code' => true])
        ->assertOk()
        ->assertJsonPath('password_change_required', true)
        ->assertJsonMissingPath('intended_url');
    expect(session('url.intended'))->toBe(IU_ISSUE);
});

it('keeps the intended URL when the 2FA challenge hands over to the email verification', function () {
    config()->set('martis.auth.email_verification.enabled', true);
    iuUser(iuTwoFactor() + ['email_verified_at' => null]);

    iuSignIn(IU_ISSUE)->assertJsonPath('two_factor_required', true);

    $this->postJson('/martis/api/2fa/challenge', ['code' => 'recovery-code-one', 'use_recovery_code' => true])
        ->assertOk()->assertJsonMissingPath('intended_url');
    expect(session('url.intended'))->toBe(IU_ISSUE);
});

it('does not change the form sign-in, which redirects to the intended URL itself', function () {
    iuUser();

    $this->withSession(['url.intended' => 'http://localhost'.IU_ISSUE])
        ->post('/martis/login', ['email' => 'person@example.com', 'password' => IU_PASSWORD])
        ->assertRedirect('http://localhost'.IU_ISSUE);
});
