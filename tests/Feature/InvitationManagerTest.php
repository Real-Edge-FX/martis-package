<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Schema;
use Martis\Invitations\InvalidInvitationException;
use Martis\Invitations\Invitation;
use Martis\Invitations\InvitationManager;
use Martis\Stubs\StubResolver;

// -----------------------------------------------------------------------------
// InvitationManager token core (Task 4) + accept() (Task 5). invite() +
// findByRawToken() ship the token core; accept() adds atomic single-use
// claiming, the anti-takeover guard, and the createUser() -> RegistersUsers
// seam. This test provisions the `invitations` table via the published
// migration stub (like InvitationModelTest.php) and a real `users` table +
// user model binding (like AuthSurfacesTest.php) so createUser() has a model
// to persist through the shared RegistersUsers pipeline.
// -----------------------------------------------------------------------------

/**
 * Test-only User model that records role assignments. The default test User
 * has no assignRole(), so the role branch of accept() is skipped there; this
 * stand-in lets that branch actually run and be asserted. NOT a Spatie
 * dependency — just a minimal recorder.
 */
class RecordingRoleUser extends User
{
    protected $table = 'users';

    /** @var list<string> */
    public array $assignedRoles = [];

    public function assignRole(string $role): void
    {
        $this->assignedRoles[] = $role;
    }
}

beforeEach(function () {
    config(['auth.providers.users.model' => User::class]);

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

    Schema::dropIfExists('invitations');

    $migration = require StubResolver::path('create_invitations_table.php.stub');
    $migration->up();
});

afterEach(function () {
    Schema::dropIfExists('invitations');
});

it('invite() stores only the hashed token and exposes the raw once', function () {
    $inv = app(InvitationManager::class)->invite('new@ex.com', 'editor', ['k' => 'v']);
    expect($inv->status)->toBe(Invitation::STATUS_PENDING);
    expect($inv->role)->toBe('editor');
    expect(strlen($inv->rawToken))->toBeGreaterThan(32);            // raw returned in-memory
    expect($inv->token)->toBe(hash('sha256', $inv->rawToken));      // only the hash persisted
    expect(Invitation::where('token', $inv->rawToken)->exists())->toBeFalse(); // raw never in DB
    expect($inv->expires_at)->not->toBeNull();
});

it('findByRawToken() resolves by hash and returns null for an unknown token', function () {
    $mgr = app(InvitationManager::class);
    $inv = $mgr->invite('new2@ex.com');
    expect($mgr->findByRawToken($inv->rawToken)?->id)->toBe($inv->id);
    expect($mgr->findByRawToken('not-a-real-token'))->toBeNull();
});

// -----------------------------------------------------------------------------
// accept() — atomic single-use, guards, createUser() seam (Task 5).
// -----------------------------------------------------------------------------

it('accept() creates the user, assigns the role, marks accepted, verifies email', function () {
    config(['martis.auth.registration' => ['default_role' => null]]);
    $mgr = app(InvitationManager::class);
    $inv = $mgr->invite('accept@ex.com', 'editor');
    $user = $mgr->accept($inv->rawToken, ['name' => 'Ana', 'password' => 'password123', 'password_confirmation' => 'password123']);
    $inv->refresh();
    expect($user->email)->toBe('accept@ex.com');
    expect($inv->status)->toBe(Invitation::STATUS_ACCEPTED);
    expect($inv->accepted_user_id)->toBe($user->getKey());
    expect($inv->accepted_at)->not->toBeNull();
    expect($user->email_verified_at)->not->toBeNull();  // proved control via the emailed token
});

it('accept() is single-use: a second accept of the same token is rejected', function () {
    $mgr = app(InvitationManager::class);
    $inv = $mgr->invite('once@ex.com');
    $mgr->accept($inv->rawToken, ['name' => 'A', 'password' => 'password123', 'password_confirmation' => 'password123']);
    $mgr->accept($inv->rawToken, ['name' => 'B', 'password' => 'password123', 'password_confirmation' => 'password123']);
})->throws(InvalidInvitationException::class);

it('accept() rejects an expired invitation', function () {
    $mgr = app(InvitationManager::class);
    $inv = $mgr->invite('exp@ex.com');
    $inv->forceFill(['expires_at' => now()->subHour()])->save();
    $mgr->accept($inv->rawToken, ['name' => 'A', 'password' => 'password123', 'password_confirmation' => 'password123']);
})->throws(InvalidInvitationException::class);

it('accept() refuses to take over an already-registered email', function () {
    // seed an existing user with the invited email first (use the test user helper)
    $mgr = app(InvitationManager::class);
    $inv = $mgr->invite('dupe@ex.com');
    User::forceCreate(['name' => 'X', 'email' => 'dupe@ex.com', 'password' => bcrypt('x')]);
    $mgr->accept($inv->rawToken, ['name' => 'A', 'password' => 'password123', 'password_confirmation' => 'password123']);
})->throws(InvalidInvitationException::class);

it('accept() rejects an unknown token', function () {
    $mgr = app(InvitationManager::class);
    $mgr->accept('not-a-real-token', ['name' => 'A', 'password' => 'password123', 'password_confirmation' => 'password123']);
})->throws(InvalidInvitationException::class);

it('accept() leaves the invitation claimable after an anti-takeover rejection', function () {
    // The email-exists guard rolls the atomic claim back so the row does not
    // read as accepted-without-user. Remove the blocker and the same token
    // must still accept — proving the rollback restored pending state.
    $mgr = app(InvitationManager::class);
    $inv = $mgr->invite('rollback@ex.com');
    $blocker = User::forceCreate(['name' => 'X', 'email' => 'rollback@ex.com', 'password' => bcrypt('x')]);

    try {
        $mgr->accept($inv->rawToken, ['name' => 'A', 'password' => 'password123', 'password_confirmation' => 'password123']);
    } catch (InvalidInvitationException) {
        // expected
    }

    $inv->refresh();
    expect($inv->status)->toBe(Invitation::STATUS_PENDING);
    expect($inv->accepted_at)->toBeNull();

    $blocker->delete();
    $user = $mgr->accept($inv->rawToken, ['name' => 'A', 'password' => 'password123', 'password_confirmation' => 'password123']);
    expect($user->email)->toBe('rollback@ex.com');
});

it('accept() cannot be tricked into injecting email, role, or metadata via $signup', function () {
    // The invitation dictates email + role; $signup is whitelisted to name/password.
    // Attacker-supplied email/role/is_admin must be ignored entirely.
    config(['martis.auth.registration' => ['default_role' => null]]);
    $mgr = app(InvitationManager::class);
    $inv = $mgr->invite('legit@ex.com', 'editor');

    $user = $mgr->accept($inv->rawToken, [
        'name' => 'Ann',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'email' => 'attacker@evil.com',   // injection attempt — must be ignored
        'role' => 'admin',                // injection attempt — must be ignored
        'is_admin' => true,               // injection attempt — must be ignored
    ]);

    expect($user->email)->toBe('legit@ex.com');                             // invitation email is authoritative
    expect($user->is_admin)->toBeNull();                                    // injected attribute never reached the model
    expect(User::where('email', 'attacker@evil.com')->exists())->toBeFalse(); // no user for the injected email

    $inv->refresh();
    expect($inv->role)->toBe('editor');                                     // role stays what the invitation dictated
});

it('accept() assigns the invitation role through the user model assignRole()', function () {
    // Bind a User subclass that actually defines assignRole() so the role
    // branch runs and is genuinely asserted (the base test User skips it).
    config(['auth.providers.users.model' => RecordingRoleUser::class]);
    config(['martis.auth.registration' => ['default_role' => null]]); // isolate: only the invitation role

    $mgr = app(InvitationManager::class);
    $inv = $mgr->invite('role@ex.com', 'editor');

    $user = $mgr->accept($inv->rawToken, ['name' => 'Ann', 'password' => 'password123', 'password_confirmation' => 'password123']);

    expect($user)->toBeInstanceOf(RecordingRoleUser::class);
    expect($user->assignedRoles)->toBe(['editor']);
});

// -----------------------------------------------------------------------------
// resend() (throttled) + revoke() (Task 6). resend() re-issues a fresh raw
// token/hash on a pending invitation and throttles repeat calls via
// updated_at; revoke() flips a pending invitation to revoked. The
// InvitationRevoked event dispatch is commented out pending Task 7.
// -----------------------------------------------------------------------------

it('resend() re-issues a fresh token and throttles', function () {
    $mgr = app(InvitationManager::class);
    $inv = $mgr->invite('re@ex.com');
    $old = $inv->token;
    $inv->forceFill(['updated_at' => now()->subMinutes(5)])->save();
    $mgr->resend($inv->refresh());
    expect($inv->refresh()->token)->not->toBe($old);
    $mgr->resend($inv->refresh()); // immediately again -> throttled
})->throws(InvalidInvitationException::class);

it('revoke() marks the invitation revoked', function () {
    $mgr = app(InvitationManager::class);
    $inv = $mgr->invite('rev@ex.com');
    $mgr->revoke($inv);
    expect($inv->refresh()->status)->toBe(Invitation::STATUS_REVOKED);
});
