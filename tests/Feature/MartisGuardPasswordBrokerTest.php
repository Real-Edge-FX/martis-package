<?php

declare(strict_types=1);

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Martis\Auth\GuardCatalog;
use Martis\Auth\PasswordBrokerConfigurationException;

/*
 * Password reset runs on the password broker of the Martis guard's users.
 * v2.0.0 used the broker MARTIS_AUTH_PASSWORD_BROKER named, `users` by
 * default, whatever the guard: with an `admins` guard the forgot-password
 * form looked the email up among the site's users and reset that account.
 * Unset, the broker is now picked by its provider; set to a broker of
 * another provider, it throws naming the key.
 */

class BrokerGuardAdmin extends Authenticatable
{
    use Notifiable;

    protected $table = 'martis_test_broker_admins';

    protected $guarded = [];
}

class BrokerGuardSiteUser extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];
}

beforeEach(function () {
    config()->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'broker_admins']);
    config()->set('auth.providers.broker_admins', ['driver' => 'eloquent', 'model' => BrokerGuardAdmin::class]);
    config()->set('auth.providers.users', ['driver' => 'eloquent', 'model' => BrokerGuardSiteUser::class]);
    config()->set('auth.defaults.passwords', 'users');
    config()->set('auth.passwords', [
        'users' => ['provider' => 'users', 'table' => 'password_reset_tokens', 'expire' => 60, 'throttle' => 0],
        'admins' => ['provider' => 'broker_admins', 'table' => 'password_reset_tokens', 'expire' => 60, 'throttle' => 0],
    ]);
    config()->set('martis.auth.passwordReset.enabled', true);
    config()->set('martis.auth.passwordReset.url', null);
    config()->set('martis.auth.passwordReset.broker', null);

    foreach (['martis_test_broker_admins', 'users', 'password_reset_tokens'] as $table) {
        Schema::dropIfExists($table);
    }
    foreach (['martis_test_broker_admins', 'users'] as $table) {
        Schema::create($table, function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }
    Schema::create('password_reset_tokens', function ($table) {
        $table->string('email')->primary();
        $table->string('token');
        $table->timestamp('created_at')->nullable();
    });
});

afterEach(function () {
    foreach (['martis_test_broker_admins', 'users', 'password_reset_tokens'] as $table) {
        Schema::dropIfExists($table);
    }
});

it('uses the default broker when it reads the users of the default guard', function () {
    config()->set('martis.guard', null);

    expect(GuardCatalog::martisPasswordBroker())->toBe('users');
});

it('picks the broker of the Martis guard provider when none is set', function () {
    config()->set('martis.guard', 'admin');

    expect(GuardCatalog::martisPasswordBroker())->toBe('admins');
});

it('accepts a broker whose provider signs in users of the same table', function () {
    config()->set('martis.guard', 'admin');
    config()->set('auth.providers.admins_alias', ['driver' => 'eloquent', 'model' => BrokerGuardAdmin::class]);
    config()->set('auth.passwords', ['aliased' => ['provider' => 'admins_alias', 'table' => 'password_reset_tokens']]);

    expect(GuardCatalog::martisPasswordBroker())->toBe('aliased');

    config()->set('martis.auth.passwordReset.broker', 'aliased');
    expect(GuardCatalog::martisPasswordBroker())->toBe('aliased');
});

it('keeps a set broker that reads the Martis guard users', function () {
    config()->set('martis.guard', 'admin');
    config()->set('martis.auth.passwordReset.broker', 'admins');

    expect(GuardCatalog::martisPasswordBroker())->toBe('admins');
});

it('refuses a set broker of another provider, naming the key', function () {
    config()->set('martis.guard', 'admin');
    config()->set('martis.auth.passwordReset.broker', 'users');

    expect(fn () => GuardCatalog::martisPasswordBroker())->toThrow(
        PasswordBrokerConfigurationException::class,
        'The [martis.auth.passwordReset.broker] config value (MARTIS_AUTH_PASSWORD_BROKER) names the password broker [users], whose provider [users] is not the provider [broker_admins] of the Martis guard [admin]',
    );
});

it('refuses a set broker that config/auth.php does not define', function (mixed $value, string $shown) {
    config()->set('martis.auth.passwordReset.broker', $value);

    expect(fn () => GuardCatalog::martisPasswordBroker())->toThrow(
        PasswordBrokerConfigurationException::class,
        "The [martis.auth.passwordReset.broker] config value (MARTIS_AUTH_PASSWORD_BROKER) must name a password broker of config/auth.php (passwords), got {$shown}.",
    );
})->with([
    'unknown name' => ['staff', '[staff]'],
    'not a string' => [['users'], 'array'],
]);

it('refuses when no broker reads the Martis guard users', function () {
    config()->set('martis.guard', 'admin');
    config()->set('auth.passwords', ['users' => ['provider' => 'users', 'table' => 'password_reset_tokens']]);

    expect(fn () => GuardCatalog::martisPasswordBroker())->toThrow(
        PasswordBrokerConfigurationException::class,
        'No password broker of config/auth.php (passwords) reads the users of the Martis guard [admin] (provider [broker_admins])',
    );
});

it('mails the reset link to the admin, not to the site user with the same email', function () {
    config()->set('martis.guard', 'admin');
    Notification::fake();

    $site = BrokerGuardSiteUser::create(['name' => 'site', 'email' => 'same@example.com', 'password' => bcrypt('secret')]);
    $admin = BrokerGuardAdmin::create(['name' => 'admin', 'email' => 'same@example.com', 'password' => bcrypt('secret')]);

    $this->postJson('/martis/api/auth/password/email', ['email' => 'same@example.com'])->assertOk();

    Notification::assertSentTo($admin, ResetPassword::class);
    Notification::assertNotSentTo($site, ResetPassword::class);
});

it('fails loudly on a broker of another provider instead of answering as an unavailable mailer', function () {
    config()->set('martis.guard', 'admin');
    config()->set('martis.auth.passwordReset.broker', 'users');
    Notification::fake();

    BrokerGuardSiteUser::create(['name' => 'site', 'email' => 'same@example.com', 'password' => bcrypt('secret')]);

    $this->withoutExceptionHandling();

    expect(fn () => $this->postJson('/martis/api/auth/password/email', ['email' => 'same@example.com']))
        ->toThrow(PasswordBrokerConfigurationException::class, 'martis.auth.passwordReset.broker');

    Notification::assertNothingSent();
});

it('resets the admin password through the admin broker', function () {
    config()->set('martis.guard', 'admin');

    $site = BrokerGuardSiteUser::create(['name' => 'site', 'email' => 'same@example.com', 'password' => bcrypt('site-secret')]);
    $admin = BrokerGuardAdmin::create(['name' => 'admin', 'email' => 'same@example.com', 'password' => bcrypt('admin-secret')]);
    $token = app('auth.password')->broker('admins')->createToken($admin);

    $this->postJson('/martis/api/auth/password/reset', [
        'token' => $token,
        'email' => 'same@example.com',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertOk();

    expect(password_verify('new-password-123', (string) $admin->fresh()?->password))->toBeTrue()
        ->and(password_verify('site-secret', (string) $site->fresh()?->password))->toBeTrue();
});
