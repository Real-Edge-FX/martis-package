<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password;
use Martis\Auth\PasswordPolicy;
use Martis\Contracts\RegistersUsers;
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

/*
 * Password::default() replaces anything Password::defaults() gives that is
 * not an Illuminate\Contracts\Validation\Rule with Password::min(8): an
 * array of rules, or a ValidationRule (what `php artisan make:rule` makes).
 * The app's own rule would then be enforced nowhere, silently. Martis
 * refuses it, naming Password::defaults() and what it gave.
 */
it('refuses a Password::defaults() it cannot use instead of falling back to min(8)', function (Closure $defaults, string $given) {
    Password::defaults($defaults);

    expect(fn () => PasswordPolicy::rule())->toThrow(InvalidArgumentException::class, 'Password::defaults() gave '.$given)
        ->and(fn () => PasswordPolicy::requirements())->toThrow(InvalidArgumentException::class, 'Password::defaults()');
})->with([
    'an array of rules' => [fn () => ['required', 'min:12'], 'array'],
    'a ValidationRule' => [fn () => new class implements ValidationRule
    {
        public function validate(string $attribute, mixed $value, Closure $fail): void
        {
            if (strlen((string) $value) < 12) {
                $fail('too short');
            }
        }
    }, 'Illuminate\\Contracts\\Validation\\ValidationRule@anonymous'],
    'a string' => [fn () => 'min:12', 'string'],
]);

it('reads a Password::defaults() that gives null as unset, as Laravel does (control)', function () {
    Password::defaults(fn () => null);

    expect(PasswordPolicy::rule())->toBeInstanceOf(Password::class)
        ->and(PasswordPolicy::requirements())->toBe(['minLength' => 8]);
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

/*
 * The invitation accept and the RegistersUsers it hands the signup to both
 * validated the password with the policy, so with uncompromised() every
 * accept asked Have I Been Pwned twice. The default registrar validates it;
 * the accept checks it itself only for a registrar of the app's own, which
 * may not (the guarantee the accept always gave).
 */
it('checks the policy of an invitation accept once, so uncompromised() asks Have I Been Pwned once', function () {
    config(['martis.invitations.enabled' => true]);
    Schema::dropIfExists('invitations');
    (require StubResolver::path('create_invitations_table.php.stub'))->up();
    Password::defaults(fn () => Password::min(8)->uncompromised());
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    $invitation = app(InvitationManager::class)->invite('invitee@example.com');

    $this->postJson('/martis/api/invitations/accept', ['token' => $invitation->rawToken, 'name' => 'Ann Invitee', 'password' => 'Brand-New-Pass-1', 'password_confirmation' => 'Brand-New-Pass-1'])
        ->assertOk();

    Http::assertSentCount(1);
});

it('still checks the policy of an invitation accept when the app registrar does not (control)', function () {
    config(['martis.invitations.enabled' => true]);
    Schema::dropIfExists('invitations');
    (require StubResolver::path('create_invitations_table.php.stub'))->up();
    Password::defaults(fn () => Password::min(12));
    app()->bind(RegistersUsers::class, fn () => new class implements RegistersUsers
    {
        public function register(Request $request): Authenticatable
        {
            return PasswordPolicyTestUser::query()->create([
                'name' => (string) $request->input('name'),
                'email' => (string) $request->input('email'),
                'password' => Hash::make((string) $request->input('password')),
            ]);
        }
    });
    $invitation = app(InvitationManager::class)->invite('invitee@example.com');
    $post = fn (string $password) => $this->postJson('/martis/api/invitations/accept', ['token' => $invitation->rawToken, 'name' => 'Ann Invitee', 'password' => $password, 'password_confirmation' => $password]);

    $post('Short-Pass1')->assertUnprocessable()->assertJsonValidationErrors('password');
    $post('Long-Enough-Pass-1')->assertOk();
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
