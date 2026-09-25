<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Martis\Invitations\InvitationManager;
use Martis\Stubs\StubResolver;
use Martis\Tests\Fixtures\GuardUsers\Admin as FlowGuardAdmin;
use Martis\Tests\Fixtures\GuardUsers\SiteUser as FlowGuardSiteUser;

/*
 * The auth flows that create or find a user for the Martis guard use that
 * guard's provider. The registration pipeline (self-registration and the
 * invitation accept) created the account in the Martis guard's model but
 * checked the email's uniqueness in `users`; and the pipeline, the
 * invitation's anti-takeover check and the email verification link read
 * `config('martis.guard', 'web')`, which is null when MARTIS_GUARD is unset:
 * they fell back to the `users` provider even when the app's default guard,
 * the Martis guard then, signs in another model, and the invitation signed
 * the account created in `users` into that guard (the user with that id).
 */

beforeEach(function () {
    config()->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'flow_admins']);
    config()->set('auth.providers.flow_admins', ['driver' => 'eloquent', 'model' => FlowGuardAdmin::class]);
    config()->set('auth.providers.users.model', FlowGuardSiteUser::class);
    config()->set('martis.guard', 'admin');
    config()->set('martis.invitations.enabled', true);

    foreach (['invitations', 'martis_action_events', 'martis_test_guard_users_admins', 'users'] as $table) {
        Schema::dropIfExists($table);
    }
    foreach (['martis_test_guard_users_admins', 'users'] as $table) {
        Schema::create($table, function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }
    Schema::create('martis_action_events', function ($t) {
        $t->id();
        $t->uuid('batch_id');
        $t->unsignedBigInteger('user_id')->nullable();
        $t->string('name');
        $t->string('actionable_type')->nullable();
        $t->string('actionable_id')->nullable();
        $t->string('target_type')->nullable();
        $t->string('target_id')->nullable();
        $t->string('model_type')->nullable();
        $t->string('model_id')->nullable();
        $t->json('fields')->nullable();
        $t->string('status');
        $t->text('exception')->nullable();
        $t->json('original')->nullable();
        $t->json('changes')->nullable();
        $t->timestamps();
    });
});

afterEach(function () {
    foreach (['invitations', 'martis_action_events', 'martis_test_guard_users_admins', 'users'] as $table) {
        Schema::dropIfExists($table);
    }
});

function flowGuardInvitationsTable(): void
{
    (require StubResolver::path('create_invitations_table.php.stub'))->up();
}

/** Accept an invitation for this email; returns the response and the panel user the next request sees. */
function flowGuardAccept(string $email): array
{
    flowGuardInvitationsTable();
    $invitation = app(InvitationManager::class)->invite($email);

    $response = test()->postJson('/martis/api/invitations/accept', [
        'token' => $invitation->rawToken,
        'name' => 'Ann Invitee',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    auth()->forgetGuards();
    $user = json_decode((string) test()->getJson('/martis/api/auth/user')->getContent(), true);

    return [$response, $user];
}

it('accepts an invitation into the Martis guard users when a site account has that email', function () {
    FlowGuardSiteUser::create(['name' => 'Ann Site', 'email' => 'ann@example.com', 'password' => bcrypt('x')]);

    [$response, $user] = flowGuardAccept('ann@example.com');

    $response->assertOk()->assertJson(['ok' => true]);
    $admin = FlowGuardAdmin::query()->where('email', 'ann@example.com')->sole();
    expect($user['id'] ?? null)->toBe($admin->getKey())
        ->and($user['name'] ?? null)->toBe('Ann Invitee');
});

it('registers into the Martis guard users, checking the email among them', function () {
    config()->set('martis.auth.registration.enabled', true);
    FlowGuardAdmin::create(['name' => 'Taken', 'email' => 'taken@example.com', 'password' => bcrypt('x')]);
    FlowGuardSiteUser::create(['name' => 'Site', 'email' => 'site@example.com', 'password' => bcrypt('x')]);
    $payload = fn (string $email): array => ['name' => 'New', 'email' => $email, 'password' => 'password123', 'password_confirmation' => 'password123'];

    $this->postJson('/martis/api/auth/register', $payload('taken@example.com'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
    $this->postJson('/martis/api/auth/register', $payload('site@example.com'))
        ->assertCreated();

    expect(FlowGuardAdmin::query()->where('email', 'taken@example.com')->count())->toBe(1)
        ->and(FlowGuardAdmin::query()->where('email', 'site@example.com')->count())->toBe(1)
        ->and(FlowGuardSiteUser::query()->count())->toBe(1);
});

it('follows the app default guard when MARTIS_GUARD is unset', function () {
    config()->set('martis.guard', null);
    config()->set('auth.defaults.guard', 'admin');
    // A site account holds the id the new account would get in `users`.
    FlowGuardSiteUser::create(['name' => 'Site', 'email' => 'site@example.com', 'password' => bcrypt('x')]);
    FlowGuardAdmin::create(['name' => 'First', 'email' => 'first@example.com', 'password' => bcrypt('x')]);

    [$response, $user] = flowGuardAccept('ann@example.com');

    $response->assertOk();
    $admin = FlowGuardAdmin::query()->where('email', 'ann@example.com')->sole();
    expect($user['email'] ?? null)->toBe('ann@example.com')
        ->and($user['id'] ?? null)->toBe($admin->getKey())
        ->and(FlowGuardSiteUser::query()->where('email', 'ann@example.com')->exists())->toBeFalse();
});

it('refuses an invitation for a Martis guard user email neutrally when MARTIS_GUARD is unset', function () {
    config()->set('martis.guard', null);
    config()->set('auth.defaults.guard', 'admin');
    FlowGuardAdmin::create(['name' => 'Ann', 'email' => 'ann@example.com', 'password' => bcrypt('x')]);

    [$response] = flowGuardAccept('ann@example.com');

    // The anti-takeover answer, the same as for an unknown token: a
    // validation error on `email` would say the address has an account.
    $response->assertStatus(422)
        ->assertJsonValidationErrors('token')
        ->assertJsonMissingValidationErrors('email');
    expect(FlowGuardAdmin::query()->where('email', 'ann@example.com')->count())->toBe(1);
});

it('verifies the email of a Martis guard user when MARTIS_GUARD is unset', function () {
    config()->set('martis.guard', null);
    config()->set('auth.defaults.guard', 'admin');
    config()->set('martis.auth.email_verification.enabled', true);
    FlowGuardSiteUser::create(['name' => 'Site', 'email' => 'site@example.com', 'password' => bcrypt('x')]);
    $admin = FlowGuardAdmin::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('x')]);

    $url = URL::temporarySignedRoute('martis.email.verify', now()->addHour(), [
        'id' => $admin->getKey(),
        'hash' => sha1('admin@example.com'),
    ]);

    $this->get($url)->assertRedirect(route('martis.login', ['verified' => 1]));
    expect($admin->fresh()?->hasVerifiedEmail())->toBeTrue();
});
