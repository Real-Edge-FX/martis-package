<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Martis\Auth\MagicLinkService;
use Martis\Auth\PasswordChangeRequirement;
use Martis\Auth\TwoFactorPass;
use Martis\Contracts\MustChangePassword;
use Martis\Events\PasswordChanged;
use Martis\Invitations\InvitationManager;
use Martis\MartisServiceProvider;
use Martis\Profile\TwoFactorService;
use Martis\Sso\SsoSession;
use Martis\Stubs\StubResolver;

// ===========================================================================
// The forced password change gate (v2.3.0, martis.auth.password_change). A
// user the app flags (the MustChangePassword contract, else a boolean column)
// is held after the 2FA challenge, email verification and the panel gate:
// 409 {"password_change_required": true} on JSON routes, a redirect to the
// change page otherwise, until they choose a new password. Neither Nova 5 nor
// Fortify has such a gate: it mirrors Martis's email verification gate.
// ===========================================================================

class PasswordChangeGateUser extends User
{
    protected $table = 'users';

    protected $guarded = [];

    protected $casts = ['must_change_password' => 'boolean', 'two_factor_confirmed_at' => 'datetime'];
}

class PasswordChangeContractUser extends User implements MustChangePassword
{
    protected $table = 'users';

    protected $guarded = [];

    public static bool $must = true;

    public static int $marked = 0;

    public function mustChangePassword(): bool
    {
        return self::$must;
    }

    public function markPasswordChanged(): void
    {
        self::$marked++;
        self::$must = false;
    }
}

function heldUser(array $attributes = []): PasswordChangeGateUser
{
    return PasswordChangeGateUser::query()->create([
        'name' => 'Held',
        'email' => 'held@example.com',
        'password' => Hash::make('Temporary-Pass-1'),
        'must_change_password' => true,
        ...$attributes,
    ]);
}

/** A signed-in session of $user that passed the 2FA challenge, plus $session. */
function asHeld(User $user, array $session = []): mixed
{
    return test()->actingAs($user)->withSession([TwoFactorPass::SESSION_KEY => (string) $user->getKey(), ...$session]);
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
    Schema::dropIfExists('password_reset_tokens');
    Schema::create('password_reset_tokens', function ($table) {
        $table->string('email')->primary();
        $table->string('token');
        $table->timestamp('created_at')->nullable();
    });

    Password::$defaultCallback = null;
    PasswordChangeContractUser::$must = true;
    PasswordChangeContractUser::$marked = 0;
    config([
        'auth.providers.users.model' => PasswordChangeGateUser::class,
        'martis.auth.password_change.enabled' => true,
    ]);
});

afterEach(fn () => Password::$defaultCallback = null);

$change = ['current_password' => 'Temporary-Pass-1', 'password' => 'Brand-New-Pass-1', 'password_confirmation' => 'Brand-New-Pass-1'];

it('changes nothing while the gate is disabled', function () {
    config(['martis.auth.password_change.enabled' => false]);
    $user = heldUser();

    asHeld($user)->getJson('/martis/api/tools')->assertOk();
    asHeld($user)->get('/martis')->assertOk();
    asHeld($user)->getJson('/martis/api/auth/user')->assertOk()->assertJsonMissing(['password_change_pending' => true]);
    asHeld($user)->get('/martis/password/change')->assertNotFound();
});

it('holds a flagged user on every protected JSON route', function () {
    asHeld(heldUser())->getJson('/martis/api/tools')
        ->assertStatus(409)
        ->assertExactJson(['password_change_required' => true, 'message' => 'Password change required.']);
});

it('redirects every page to the change page, and serves the page itself', function () {
    $user = heldUser();

    asHeld($user)->get('/martis')->assertRedirect('/martis/password/change');
    asHeld($user)->get('/martis/resources/users')->assertRedirect('/martis/password/change');
    asHeld($user)->get('/martis/password/change')->assertOk()->assertSee('id="martis-root"', false);
});

it('sends a held page request to the configured url', function () {
    config(['martis.auth.password_change.url' => '/account/password']);

    asHeld(heldUser())->get('/martis')->assertRedirect('/account/password');
});

it('keeps the session routes reachable and tells the SPA on bootstrap', function () {
    $user = heldUser();

    asHeld($user)->getJson('/martis/api/auth/user')->assertOk()->assertJson(['password_change_pending' => true]);
    asHeld($user)->postJson('/martis/api/auth/logout')->assertOk();
});

it('sends a flagged user from the login answer', function () {
    heldUser();

    $this->postJson('/martis/api/auth/login', ['email' => 'held@example.com', 'password' => 'Temporary-Pass-1'])
        ->assertOk()
        ->assertJson(['password_change_required' => true]);

    expect(auth()->check())->toBeTrue();
});

it('lets the 2FA challenge come first', function () {
    $user = heldUser(['two_factor_confirmed_at' => now()]);
    $pending = [];

    test()->actingAs($user)->withSession($pending)->postJson('/martis/api/auth/password/change', [])->assertStatus(423);
    test()->actingAs($user)->withSession($pending)->get('/martis/2fa/challenge')->assertOk();
});

it('adds the gate to the 2FA challenge answer', function () {
    $this->mock(TwoFactorService::class, fn ($mock) => $mock->shouldReceive('verifyForUser')->andReturn(true));
    $user = heldUser(['two_factor_confirmed_at' => now()]);

    test()->actingAs($user)
        ->postJson('/martis/api/2fa/challenge', ['code' => '123456'])
        ->assertOk()
        ->assertJson(['message' => 'Authenticated.', 'password_change_required' => true]);
});

it('refuses a wrong current password, a mismatch, the same password and a weak one', function () {
    Password::defaults(fn () => Password::min(12));
    $user = heldUser();
    $post = fn (array $payload) => asHeld($user)->postJson('/martis/api/auth/password/change', $payload);

    $post(['current_password' => 'Wrong-Pass-1', 'password' => 'Brand-New-Pass-1', 'password_confirmation' => 'Brand-New-Pass-1'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
    $post(['current_password' => 'Temporary-Pass-1', 'password' => 'Brand-New-Pass-1', 'password_confirmation' => 'Brand-New-Pass-2'])->assertUnprocessable()->assertJsonValidationErrors('password');
    $post(['current_password' => 'Temporary-Pass-1', 'password' => 'Temporary-Pass-1', 'password_confirmation' => 'Temporary-Pass-1'])->assertUnprocessable()->assertJsonValidationErrors('password');
    $post(['current_password' => 'Temporary-Pass-1', 'password' => 'Short-1', 'password_confirmation' => 'Short-1'])->assertUnprocessable()->assertJsonValidationErrors('password');

    expect($user->fresh()?->must_change_password)->toBeTrue();
});

it('changes the password, clears the flag, deletes the reset tokens and fires PasswordChanged', function () use ($change) {
    config(['martis.auth.passwordReset.enabled' => true]);
    Event::fake([PasswordChanged::class]);
    $user = heldUser();
    PasswordBroker::broker()->createToken($user);

    asHeld($user)->postJson('/martis/api/auth/password/change', $change)
        ->assertOk()
        ->assertJson(['message' => __('martis::auth.password_change_done')]);

    $fresh = $user->fresh();
    expect($fresh?->must_change_password)->toBeFalse()
        ->and(Hash::check('Brand-New-Pass-1', (string) $fresh?->password))->toBeTrue()
        ->and(DB::table('password_reset_tokens')->count())->toBe(0);
    Event::assertDispatched(PasswordChanged::class, fn (PasswordChanged $event): bool => $event->forced && $event->user->getAuthIdentifier() === $user->getKey());

    asHeld($user)->getJson('/martis/api/tools')->assertOk();
});

it('refuses the endpoint to a user the gate does not hold', function () use ($change) {
    asHeld(heldUser(['must_change_password' => false]))->postJson('/martis/api/auth/password/change', $change)->assertForbidden();
});

it('clears the flag when an unheld session changes the password from Profile, with PasswordChanged(forced: false)', function () use ($change) {
    Event::fake([PasswordChanged::class]);
    $user = heldUser();

    // An SSO session is never held, so Profile answers.
    asHeld($user, ['martis_sso_provider' => 'azure'])->postJson('/martis/api/profile/password', $change)->assertOk();

    expect($user->fresh()?->must_change_password)->toBeFalse();
    Event::assertDispatched(PasswordChanged::class, fn (PasswordChanged $event): bool => ! $event->forced);
});

it('clears the flag on a password reset by email', function () {
    config(['martis.auth.passwordReset.enabled' => true]);
    $user = heldUser();
    $token = PasswordBroker::broker()->createToken($user);

    $this->postJson('/martis/api/auth/password/reset', ['token' => $token, 'email' => 'held@example.com', 'password' => 'Brand-New-Pass-1', 'password_confirmation' => 'Brand-New-Pass-1'])
        ->assertOk();

    expect($user->fresh()?->must_change_password)->toBeFalse();
});

it('never holds an impersonation session', function () {
    config(['martis.impersonation.enabled' => true, 'martis.impersonation.session_key' => 'martis.impersonation']);
    $user = heldUser();

    asHeld($user)->getJson('/martis/api/tools')->assertStatus(409); // control: held
    $this->flushSession();
    asHeld($user, ['martis.impersonation' => ['operator_id' => 99]])->getJson('/martis/api/tools')->assertOk();
});

it('never holds an SSO session', function () {
    $user = heldUser();

    asHeld($user)->getJson('/martis/api/tools')->assertStatus(409); // control: held
    $this->flushSession();
    asHeld($user, ['martis_sso_provider' => 'azure'])->getJson('/martis/api/tools')->assertOk();
    asHeld($user, ['martis_sso_provider' => 'azure'])->getJson('/martis/api/auth/user')
        ->assertJsonMissing(['password_change_pending' => true]);
    $this->flushSession();
    asHeld($user)->getJson('/martis/api/auth/user')->assertJson(['password_change_pending' => true]);
});

it('tells the SPA a password change is required on the JSON login of LoginController, as AuthController does', function () {
    heldUser();

    $this->postJson('/martis/login', ['email' => 'held@example.com', 'password' => 'Temporary-Pass-1'])
        ->assertOk()
        ->assertJson(['password_change_required' => true])
        ->assertJsonMissing(['email' => 'held@example.com']);

    auth()->logout();
    $this->flushSession();
    PasswordChangeGateUser::query()->update(['must_change_password' => false]);
    $this->postJson('/martis/login', ['email' => 'held@example.com', 'password' => 'Temporary-Pass-1'])
        ->assertOk()
        ->assertJsonMissing(['password_change_required' => true])
        ->assertJson(['email' => 'held@example.com']);
});

it('forgets a stale SSO origin, session marker and cookie, on a password, magic-link or invitation sign-in', function () {
    heldUser(['must_change_password' => false]);
    $cookie = Crypt::encryptString('1|azure');
    // The test app keeps the cookie jar between requests, and Laravel never
    // empties its queue: flush it so each answer shows only its own cookies.
    $cleared = fn ($response): bool => collect($response->headers->getCookies())
        ->contains(fn ($c) => $c->getName() === SsoSession::COOKIE && $c->isCleared());

    $login = $this->withSession(['martis_sso_provider' => 'azure'])
        ->withCookie(SsoSession::COOKIE, $cookie)
        ->post('/martis/login', ['email' => 'held@example.com', 'password' => 'Temporary-Pass-1'])
        ->assertSessionMissing('martis_sso_provider');
    expect($cleared($login))->toBeTrue();

    auth()->logout();
    app('cookie')->flushQueuedCookies();
    config(['martis.auth.magic_link.enabled' => true]);
    $token = app(MagicLinkService::class)->issue('held@example.com');
    $magic = $this->withSession(['martis_sso_provider' => 'azure'])
        ->withCredentials()
        ->withCookie(SsoSession::COOKIE, $cookie)
        ->postJson('/martis/api/auth/magic-link/consume', ['email' => 'held@example.com', 'token' => $token])
        ->assertOk()
        ->assertSessionMissing('martis_sso_provider');
    expect($cleared($magic))->toBeTrue();

    auth()->logout();
    app('cookie')->flushQueuedCookies();
    config(['martis.invitations.enabled' => true]);
    Schema::dropIfExists('invitations');
    (require StubResolver::path('create_invitations_table.php.stub'))->up();
    $invitation = app(InvitationManager::class)->invite('invitee@example.com');
    $accept = $this->withSession(['martis_sso_provider' => 'azure'])
        ->withCredentials()
        ->withCookie(SsoSession::COOKIE, $cookie)
        ->postJson('/martis/api/invitations/accept', ['token' => $invitation->rawToken, 'name' => 'Ann', 'password' => 'Brand-New-Pass-1', 'password_confirmation' => 'Brand-New-Pass-1'])
        ->assertOk()
        ->assertSessionMissing('martis_sso_provider');
    expect($cleared($accept))->toBeTrue();
});

it('reads the MustChangePassword contract before the column', function () use ($change) {
    config(['auth.providers.users.model' => PasswordChangeContractUser::class]);
    $user = PasswordChangeContractUser::query()->create(['name' => 'Contract', 'email' => 'contract@example.com', 'password' => Hash::make('Temporary-Pass-1'), 'must_change_password' => false]);

    asHeld($user)->getJson('/martis/api/tools')->assertStatus(409);
    asHeld($user)->postJson('/martis/api/auth/password/change', $change)->assertOk();

    expect(PasswordChangeContractUser::$marked)->toBe(1);
    asHeld($user)->getJson('/martis/api/tools')->assertOk();
});

it('holds nobody when the users have neither the contract nor the column', function () {
    Schema::table('users', fn ($table) => $table->dropColumn('must_change_password'));
    $user = PasswordChangeGateUser::query()->create(['name' => 'Plain', 'email' => 'plain@example.com', 'password' => Hash::make('Temporary-Pass-1')]);

    expect(PasswordChangeRequirement::flagged($user))->toBeFalse();
    asHeld($user)->getJson('/martis/api/tools')->assertOk();
});

it('fails loudly on a column setting it cannot use', function () {
    $user = heldUser();
    config(['martis.auth.password_change.column' => '']);

    expect(fn () => PasswordChangeRequirement::flagged($user))
        ->toThrow(InvalidArgumentException::class, 'martis.auth.password_change.column');
});

it('publishes a migration that adds the configured column to the Martis guard users table', function () {
    Schema::table('users', fn ($table) => $table->dropColumn('must_change_password'));
    config(['martis.auth.password_change.column' => 'password_change_due']);
    $file = sys_get_temp_dir().'/'.uniqid('martis_password_change_', true).'.php';
    copy(StubResolver::packagePath('add_must_change_password_column.php.stub'), $file);
    $migration = require $file;
    unlink($file);

    $migration->up();
    $migration->up();
    expect(Schema::hasColumn('users', 'password_change_due'))->toBeTrue();

    $migration->down();
    expect(Schema::hasColumn('users', 'password_change_due'))->toBeFalse();
});

it('publishes the migration under its own tag', function () {
    $paths = ServiceProvider::pathsToPublish(MartisServiceProvider::class, 'martis-password-change-migration');

    expect($paths)->toHaveCount(1)
        ->and((string) array_key_first($paths))->toEndWith('stubs/add_must_change_password_column.php.stub')
        ->and((string) array_values($paths)[0])->toEndWith('_000006_add_must_change_password_column.php');
});
