<?php

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Martis\Contracts\SendsEmailVerification;
use Tests\Stubs\VerifiableUser;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

if (! class_exists('Tests\\Stubs\\VerifiableUser')) {
    eval('namespace Tests\\Stubs;
        class VerifiableUser extends \\Illuminate\\Foundation\\Auth\\User implements \\Illuminate\\Contracts\\Auth\\MustVerifyEmail {
            use \\Illuminate\\Notifications\\Notifiable;
            protected $table = "users";
            protected $guarded = [];
            protected $hidden = ["password"];
        }
    ');
}

function ensureVerifyTable(): void
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
    ensureVerifyTable();
    config([
        'auth.providers.users.model' => VerifiableUser::class,
    ]);
});

// ---------------------------------------------------------------------------
// Middleware behaviour
// ---------------------------------------------------------------------------

it('the verified middleware is a pass-through when email_verification.enabled=false', function () {
    config(['martis.auth.email_verification.enabled' => false]);

    $user = VerifiableUser::create([
        'name' => 'Unverified',
        'email' => 'unverified@example.com',
        'password' => Hash::make('secret'),
        'email_verified_at' => null,
    ]);

    // Calls a protected route that goes through the middleware group.
    $response = $this->actingAs($user)->get('/martis');

    // Without verification gating the user enters the dashboard normally.
    $response->assertStatus(200);
});

it('the verified middleware redirects unverified users when enabled', function () {
    config(['martis.auth.email_verification.enabled' => true]);

    $user = VerifiableUser::create([
        'name' => 'Unverified',
        'email' => 'unverified2@example.com',
        'password' => Hash::make('secret'),
        'email_verified_at' => null,
    ]);

    $response = $this->actingAs($user)->get('/martis');

    $response->assertRedirect('/martis/email/verify');
});

it('the verified middleware lets verified users through when enabled', function () {
    config(['martis.auth.email_verification.enabled' => true]);

    $user = VerifiableUser::create([
        'name' => 'Verified',
        'email' => 'verified@example.com',
        'password' => Hash::make('secret'),
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($user)->get('/martis');

    $response->assertStatus(200);
});

it('the verified middleware blocks unverified users even when their User model does NOT implement MustVerifyEmail (v1.8.9 regression)', function () {
    // The standard Laravel `App\Models\User` has the `email_verified_at`
    // column but is NOT typically declared as `implements MustVerifyEmail`.
    // Pre-1.8.9 the middleware silently passed those users through with
    // the flag on — letting unverified accounts into the panel.
    config(['martis.auth.email_verification.enabled' => true]);

    if (! class_exists('Tests\\Stubs\\BareUser')) {
        eval('namespace Tests\\Stubs;
            class BareUser extends \\Illuminate\\Foundation\\Auth\\User {
                use \\Illuminate\\Notifications\\Notifiable;
                protected $table = "users";
                protected $guarded = [];
                protected $hidden = ["password"];
            }
        ');
    }
    config(['auth.providers.users.model' => 'Tests\\Stubs\\BareUser']);

    /** @var User $user */
    $user = ('Tests\\Stubs\\BareUser')::create([
        'name' => 'Bare',
        'email' => 'bare-unverified@example.com',
        'password' => Hash::make('secret'),
        'email_verified_at' => null,
    ]);

    $response = $this->actingAs($user)->get('/martis');

    $response->assertRedirect('/martis/email/verify');
});

it('the verified middleware lets users without MustVerifyEmail through when their email_verified_at column is set', function () {
    config(['martis.auth.email_verification.enabled' => true]);

    if (! class_exists('Tests\\Stubs\\BareUser')) {
        eval('namespace Tests\\Stubs;
            class BareUser extends \\Illuminate\\Foundation\\Auth\\User {
                use \\Illuminate\\Notifications\\Notifiable;
                protected $table = "users";
                protected $guarded = [];
                protected $hidden = ["password"];
            }
        ');
    }
    config(['auth.providers.users.model' => 'Tests\\Stubs\\BareUser']);

    /** @var User $user */
    $user = ('Tests\\Stubs\\BareUser')::create([
        'name' => 'Bare',
        'email' => 'bare-verified@example.com',
        'password' => Hash::make('secret'),
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($user)->get('/martis');

    $response->assertStatus(200);
});

it('the verified middleware honours notice_url override', function () {
    config([
        'martis.auth.email_verification.enabled' => true,
        'martis.auth.email_verification.notice_url' => 'https://accounts.example.com/verify',
    ]);

    $user = VerifiableUser::create([
        'name' => 'Off platform',
        'email' => 'offp@example.com',
        'password' => Hash::make('secret'),
        'email_verified_at' => null,
    ]);

    $response = $this->actingAs($user)->get('/martis');

    $response->assertRedirect('https://accounts.example.com/verify');
});

// ---------------------------------------------------------------------------
// Notice + send endpoints
// ---------------------------------------------------------------------------

it('GET /email/verify renders the SPA when enabled and user is unverified', function () {
    config(['martis.auth.email_verification.enabled' => true]);

    $user = VerifiableUser::create([
        'name' => 'Notice',
        'email' => 'notice@example.com',
        'password' => Hash::make('secret'),
        'email_verified_at' => null,
    ]);

    $response = $this->actingAs($user)->get('/martis/email/verify');
    $response->assertStatus(200);
});

it('GET /email/verify 404s when feature is disabled', function () {
    config(['martis.auth.email_verification.enabled' => false]);

    $user = VerifiableUser::create([
        'name' => 'Disabled',
        'email' => 'disabled@example.com',
        'password' => Hash::make('secret'),
        'email_verified_at' => null,
    ]);

    $response = $this->actingAs($user)->get('/martis/email/verify');
    $response->assertStatus(404);
});

it('POST /api/auth/email/verification-notification dispatches the notification', function () {
    config(['martis.auth.email_verification.enabled' => true]);
    Notification::fake();

    $user = VerifiableUser::create([
        'name' => 'Resend',
        'email' => 'resend@example.com',
        'password' => Hash::make('secret'),
        'email_verified_at' => null,
    ]);

    $response = $this->actingAs($user)
        ->postJson('/martis/api/auth/email/verification-notification');

    $response->assertStatus(200)->assertJson(['ok' => true]);
    Notification::assertSentTo($user, VerifyEmail::class);
});

it('POST /api/auth/email/verification-notification 404s when feature is disabled', function () {
    config(['martis.auth.email_verification.enabled' => false]);

    $user = VerifiableUser::create([
        'name' => 'Off',
        'email' => 'off@example.com',
        'password' => Hash::make('secret'),
        'email_verified_at' => null,
    ]);

    $response = $this->actingAs($user)
        ->postJson('/martis/api/auth/email/verification-notification');

    $response->assertStatus(404);
});

// ---------------------------------------------------------------------------
// Custom SendsEmailVerification binding (Layer 3 override)
// ---------------------------------------------------------------------------

it('consumer can override the SendsEmailVerification contract', function () {
    config(['martis.auth.email_verification.enabled' => true]);

    $captured = [];
    app()->bind(SendsEmailVerification::class, function () use (&$captured) {
        return new class($captured) implements SendsEmailVerification
        {
            public function __construct(private array &$captured) {}

            public function send(Authenticatable $user): void
            {
                $this->captured[] = $user->email;
            }
        };
    });

    $user = VerifiableUser::create([
        'name' => 'Custom',
        'email' => 'custom@example.com',
        'password' => Hash::make('secret'),
        'email_verified_at' => null,
    ]);

    $this->actingAs($user)->postJson('/martis/api/auth/email/verification-notification')
        ->assertStatus(200);

    expect($captured)->toBe(['custom@example.com']);
});

// ---------------------------------------------------------------------------
// Misconfigured auth provider path
// ---------------------------------------------------------------------------

it('GET /email/verify/{id}/{hash} returns 500 without leaking a message when the auth model is not configured', function () {
    // Simulate a broken consumer config: the provider model key is missing.
    config([
        'martis.auth.email_verification.enabled' => true,
        'auth.providers.users.model' => null,
    ]);

    // Build a signed URL for a dummy id/hash — the abort(500) fires before
    // any user lookup so the values do not matter.
    $url = URL::signedRoute('martis.email.verify', [
        'id' => '1',
        'hash' => sha1('irrelevant@example.com'),
    ]);

    $response = $this->get($url);

    // The response must be 500 and must NOT include the internal error message
    // in the response body regardless of APP_DEBUG state.
    $response->assertStatus(500);
    expect((string) $response->getContent())->not->toContain('not configured');
});

// ---------------------------------------------------------------------------
// Login + bootstrap gates (v1.8.14)
// ---------------------------------------------------------------------------

it('POST /api/auth/login returns email_verification_required for an unverified user when the flag is on', function () {
    config(['martis.auth.email_verification.enabled' => true]);

    VerifiableUser::create([
        'name' => 'Pending',
        'email' => 'pending@example.com',
        'password' => Hash::make('secret-1234'),
        'email_verified_at' => null,
    ]);

    $response = $this->postJson('/martis/api/auth/login', [
        'email' => 'pending@example.com',
        'password' => 'secret-1234',
    ]);

    $response->assertStatus(200)
        ->assertJson(['email_verification_required' => true]);

    // Session is still authenticated so the resend endpoint works.
    expect(auth()->check())->toBeTrue();
});

it('POST /api/auth/login returns the user payload when verified', function () {
    config(['martis.auth.email_verification.enabled' => true]);

    VerifiableUser::create([
        'name' => 'Done',
        'email' => 'done@example.com',
        'password' => Hash::make('secret-1234'),
        'email_verified_at' => now(),
    ]);

    $this->postJson('/martis/api/auth/login', [
        'email' => 'done@example.com',
        'password' => 'secret-1234',
    ])
        ->assertStatus(200)
        ->assertJsonMissing(['email_verification_required' => true])
        ->assertJsonPath('email', 'done@example.com');
});

it('GET /api/auth/user returns email_verification_pending so the SPA can bootstrap on /email/verify', function () {
    config(['martis.auth.email_verification.enabled' => true]);

    $user = VerifiableUser::create([
        'name' => 'Pending',
        'email' => 'pending2@example.com',
        'password' => Hash::make('secret-1234'),
        'email_verified_at' => null,
    ]);

    $this->actingAs($user)->getJson('/martis/api/auth/user')
        ->assertStatus(200)
        ->assertJson(['email_verification_pending' => true]);
});

it('login + user gates stay off when email_verification.enabled is false', function () {
    config(['martis.auth.email_verification.enabled' => false]);

    $user = VerifiableUser::create([
        'name' => 'Free',
        'email' => 'free@example.com',
        'password' => Hash::make('secret-1234'),
        'email_verified_at' => null,
    ]);

    $this->postJson('/martis/api/auth/login', [
        'email' => 'free@example.com',
        'password' => 'secret-1234',
    ])
        ->assertStatus(200)
        ->assertJsonMissing(['email_verification_required' => true]);

    $this->actingAs($user)->getJson('/martis/api/auth/user')
        ->assertJsonMissing(['email_verification_pending' => true]);
});
