<?php

declare(strict_types=1);

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password;
use Martis\Auth\PasswordPolicy;
use Martis\Invitations\InvitationManager;
use Martis\Stubs\StubResolver;

// ===========================================================================
// One password policy (v2.3.0): every Martis surface that sets a password
// validates with the app's Password::defaults(), as Nova 5 and Fortify do
// (Password::default()), and the SPA draws its checklist from the same rule.
// ===========================================================================

class PasswordPolicyTestUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

function passwordPolicyUser(string $email, string $password = 'Current-Pass-1'): PasswordPolicyTestUser
{
    return PasswordPolicyTestUser::query()->create([
        'name' => 'Ada',
        'email' => $email,
        'password' => Hash::make($password),
    ]);
}

function passwordPolicyArgon(): void
{
    config(['hashing.driver' => 'argon2id', 'hashing.argon' => ['memory' => 1024, 'threads' => 1, 'time' => 1, 'verify' => true]]);
    app('hash')->forgetDrivers();
}

beforeEach(function () {
    Password::$defaultCallback = null;

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

    config(['auth.providers.users.model' => PasswordPolicyTestUser::class]);
});

afterEach(function () {
    Password::$defaultCallback = null;
});

it('is the app Password::defaults(), else Password::min(8)', function () {
    expect(PasswordPolicy::rule())->toBeInstanceOf(Password::class)
        ->and(PasswordPolicy::requirements())->toBe(['minLength' => 8]);

    Password::defaults(fn () => Password::min(12)->max(64)->mixedCase()->letters()->numbers()->symbols()->uncompromised());

    expect(PasswordPolicy::requirements())->toBe([
        'minLength' => 12,
        'maxLength' => 64,
        'uppercase' => true,
        'lowercase' => true,
        'letters' => true,
        'number' => true,
        'symbol' => true,
        'uncompromised' => true,
    ]);
});

it('leaves custom rules to the server, and has no requirements for a rule it cannot read', function () {
    Password::defaults(fn () => Password::min(10)->rules(['not_in:password1234']));
    expect(PasswordPolicy::requirements())->toBe(['minLength' => 10]);

    $custom = new class implements Rule
    {
        public function passes($attribute, $value): bool
        {
            return true;
        }

        public function message(): string
        {
            return 'custom';
        }
    };
    Password::defaults(fn () => $custom);

    expect(PasswordPolicy::rule())->toBe($custom)
        ->and(PasswordPolicy::requirements())->toBeNull();
});

it('applies the policy to the Profile password change', function () {
    Password::defaults(fn () => Password::min(12)->mixedCase()->numbers());
    $user = passwordPolicyUser('profile@example.com');
    $post = fn (string $password) => $this->actingAs($user)->withSession(['martis_two_factor_passed' => true])
        ->postJson('/martis/api/profile/password', ['current_password' => 'Current-Pass-1', 'password' => $password, 'password_confirmation' => $password]);

    $post('Abcdefghij1')->assertUnprocessable()->assertJsonValidationErrors('password');
    $post('Abcdefghijk1')->assertOk();

    expect(Hash::check('Abcdefghijk1', (string) $user->fresh()?->password))->toBeTrue();
});

it('no longer asks Profile for mixed case and numbers the app policy does not require', function () {
    $user = passwordPolicyUser('plain@example.com');

    $this->actingAs($user)->withSession(['martis_two_factor_passed' => true])
        ->postJson('/martis/api/profile/password', ['current_password' => 'Current-Pass-1', 'password' => 'abcdefgh', 'password_confirmation' => 'abcdefgh'])
        ->assertOk();
});

it('applies the policy to registration', function () {
    config(['martis.auth.registration.enabled' => true]);
    Password::defaults(fn () => Password::min(12)->mixedCase()->numbers());
    $post = fn (string $email, string $password) => $this->postJson('/martis/api/auth/register', ['name' => 'Ann', 'email' => $email, 'password' => $password, 'password_confirmation' => $password]);

    $post('short@example.com', 'Abcdefghij1')->assertUnprocessable()->assertJsonValidationErrors('password');
    $post('long@example.com', 'Abcdefghijk1')->assertCreated();
});

it('applies the policy to the password reset', function () {
    config(['martis.auth.passwordReset.enabled' => true]);
    Schema::create('password_reset_tokens', function ($table) {
        $table->string('email')->primary();
        $table->string('token');
        $table->timestamp('created_at')->nullable();
    });
    $user = passwordPolicyUser('reset@example.com');
    Password::defaults(fn () => Password::min(12)->mixedCase()->numbers());
    $token = PasswordBroker::broker()->createToken($user);
    $post = fn (string $password) => $this->postJson('/martis/api/auth/password/reset', ['token' => $token, 'email' => 'reset@example.com', 'password' => $password, 'password_confirmation' => $password]);

    $post('Abcdefghij1')->assertUnprocessable()->assertJsonValidationErrors('password');
    $post('Abcdefghijk1')->assertOk();
});

it('applies the policy to the invitation accept', function () {
    config(['martis.invitations.enabled' => true]);
    Schema::dropIfExists('invitations');
    (require StubResolver::path('create_invitations_table.php.stub'))->up();
    Password::defaults(fn () => Password::min(12)->mixedCase()->numbers());
    $invitation = app(InvitationManager::class)->invite('invitee@example.com');
    $post = fn (string $password) => $this->postJson('/martis/api/invitations/accept', ['token' => $invitation->rawToken, 'name' => 'Ann Invitee', 'password' => $password, 'password_confirmation' => $password]);

    $post('Abcdefghij1')->assertUnprocessable()->assertJsonValidationErrors('password');
    $post('Abcdefghijk1')->assertOk();
});

it('hashes a Profile password with the app hasher, so an Argon app still signs in', function () {
    if (! defined('PASSWORD_ARGON2ID')) {
        $this->markTestSkipped('PHP is built without Argon2id.');
    }
    passwordPolicyArgon();
    $user = passwordPolicyUser('argon@example.com', 'Old-Password-1');

    $this->actingAs($user)->withSession(['martis_two_factor_passed' => true])
        ->postJson('/martis/api/profile/password', ['current_password' => 'Old-Password-1', 'password' => 'New-Password-2', 'password_confirmation' => 'New-Password-2'])
        ->assertOk();

    expect(password_get_info((string) $user->fresh()?->password)['algoName'])->toBe('argon2id');

    auth()->logout();
    $this->postJson('/martis/api/auth/login', ['email' => 'argon@example.com', 'password' => 'New-Password-2'])
        ->assertOk()
        ->assertJsonPath('email', 'argon@example.com');
});

it('gives the SPA the requirements of the policy on every page, guest pages included', function () {
    Password::defaults(fn () => Password::min(12)->mixedCase());

    $this->get('/martis/login')
        ->assertOk()
        ->assertSee('"passwordRequirements":{"minLength":12,"uppercase":true,"lowercase":true}', false);
});
