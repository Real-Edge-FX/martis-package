<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Martis\Auth\MagicLinkNotification;
use Martis\Auth\MagicLinkService;

/*
 * The magic link looked the email up in the `users` provider and signed the
 * user it found into the Martis guard. With a MARTIS_GUARD whose provider
 * has its own model, the session then held a site user's id under the
 * Martis guard, and the next request loaded the admin with that id: the
 * link of a site account opened the panel as another person. The lookup,
 * the auto-registration and the notification now use the Martis guard's
 * provider.
 */

class MagicGuardAdmin extends Authenticatable
{
    protected $table = 'martis_test_magic_admins';

    protected $guarded = [];
}

class MagicGuardSiteUser extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];
}

beforeEach(function () {
    config()->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'magic_admins']);
    config()->set('auth.providers.magic_admins', ['driver' => 'eloquent', 'model' => MagicGuardAdmin::class]);
    config()->set('auth.providers.users.model', MagicGuardSiteUser::class);
    config()->set('martis.guard', 'admin');
    config()->set('martis.auth.magic_link.enabled', true);
    config()->set('martis.auth.magic_link.auto_register', false);

    foreach (['martis_test_magic_admins', 'users'] as $table) {
        Schema::dropIfExists($table);
        Schema::create($table, function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }
    Schema::dropIfExists('password_reset_tokens');
    Schema::create('password_reset_tokens', function ($table) {
        $table->string('email')->primary();
        $table->string('token');
        $table->timestamp('created_at')->nullable();
    });

    // The same id in both tables, different people.
    $this->site = MagicGuardSiteUser::create(['name' => 'Site', 'email' => 'site@example.com', 'password' => bcrypt('x')]);
    $this->admin = MagicGuardAdmin::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('x')]);
    expect($this->site->getKey())->toBe($this->admin->getKey());
});

afterEach(function () {
    foreach (['martis_test_magic_admins', 'users', 'password_reset_tokens'] as $table) {
        Schema::dropIfExists($table);
    }
});

/** Consume a fresh link for this email, then ask a new request who is signed in to the panel. */
function magicGuardConsume(string $email): array
{
    $token = app(MagicLinkService::class)->issue($email);
    $consume = test()->get('/martis/api/auth/magic-link/consume?email='.urlencode($email).'&token='.$token);

    // The next request resolves the Martis guard's user from the session
    // (the endpoint answers a raw `null` to a guest).
    auth()->forgetGuards();
    $user = json_decode((string) test()->getJson('/martis/api/auth/user')->assertOk()->getContent(), true);

    return [$consume, $user];
}

it('does not sign anyone into the panel with the link of a site user email', function () {
    [$consume, $user] = magicGuardConsume('site@example.com');

    $consume->assertRedirect('/martis/login?magic_link=expired');
    expect(session()->has(auth()->guard('admin')->getName()))->toBeFalse()
        ->and($user)->toBeNull();
});

it('signs the admin in with the link of the admin email', function () {
    [$consume, $user] = magicGuardConsume('admin@example.com');

    $consume->assertRedirect('/martis');
    expect($user['email'] ?? null)->toBe('admin@example.com');
});

it('mails the link only for an email of the Martis guard, to a model without Notifiable too', function () {
    Notification::fake();

    $this->postJson('/martis/api/auth/magic-link/request', ['email' => 'site@example.com'])
        ->assertOk()
        ->assertJson(['ok' => true]);
    Notification::assertNothingSent();

    // MagicGuardAdmin does not use Notifiable: the mail goes to the address.
    $this->postJson('/martis/api/auth/magic-link/request', ['email' => 'admin@example.com'])
        ->assertOk()
        ->assertJson(['ok' => true]);
    Notification::assertSentOnDemand(
        MagicLinkNotification::class,
        fn (MagicLinkNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes === ['mail' => 'admin@example.com'],
    );
    Notification::assertNotSentTo($this->site, MagicLinkNotification::class);
});

it('registers the new account among the Martis guard users', function () {
    config()->set('martis.auth.magic_link.auto_register', true);

    [$consume, $user] = magicGuardConsume('new@example.com');

    $consume->assertRedirect('/martis');
    expect(MagicGuardAdmin::query()->where('email', 'new@example.com')->exists())->toBeTrue()
        ->and(MagicGuardSiteUser::query()->where('email', 'new@example.com')->exists())->toBeFalse()
        ->and($user['email'] ?? null)->toBe('new@example.com');
});
