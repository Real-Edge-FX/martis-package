<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Martis\Profile\EmailChange;
use Martis\Profile\EmailChangeConfirmationNotification;
use Martis\Profile\EmailChangedNotification;
use Martis\Profile\EmailChangeRequestedNotification;
use Martis\Sso\IdentityResolver;
use Martis\Sso\SsoIdentity;

/*
 * Changing the email address of the profile (v2.4.0, F017 + F020). The
 * address is the identity of the account, and an SSO provider that matches
 * by email adopts the local account that holds it, so the PATCH does not
 * write it: it asks the current password, mails a confirmation link to the
 * NEW address and a notice to the OLD one, and only the link switches it.
 */

class EmailChangeUser extends User implements MustVerifyEmail
{
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];

    protected $casts = ['email_verified_at' => 'datetime'];
}

beforeEach(function () {
    Schema::dropIfExists('users');
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->rememberToken();
        $table->timestamps();
    });

    config()->set('auth.providers.users.model', EmailChangeUser::class);
    config()->set('app.url', 'https://panel.example.com');
    Notification::fake();

    $this->user = EmailChangeUser::create([
        'name' => 'Ada',
        'email' => 'ada@example.com',
        'password' => bcrypt('Correct-Horse-1'),
        'email_verified_at' => now(),
    ]);
});

/** PATCH the profile of $this->user. */
function emailChangePatch(array $payload)
{
    return test()->actingAs(test()->user)
        ->withSession(['martis_two_factor_passed' => true])
        ->patchJson('/martis/api/profile', $payload);
}

/** The confirmation link a request mailed to $to. */
function emailChangeLink(string $to): string
{
    $url = null;
    Notification::assertSentOnDemand(
        EmailChangeConfirmationNotification::class,
        function (EmailChangeConfirmationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($to, &$url): bool {
            if (($notifiable->routes['mail'] ?? null) !== $to) {
                return false;
            }
            $url = $notification->url;

            return true;
        },
    );

    return (string) $url;
}

/** Follow a link as a guest in another browser. */
function emailChangeFollow(string $url)
{
    auth()->forgetGuards();
    test()->flushSession();

    return test()->get($url);
}

it('does not change the address without the current password', function () {
    emailChangePatch(['name' => 'Ada', 'email' => 'new@example.com'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('current_password');

    expect($this->user->fresh()->email)->toBe('ada@example.com');
    Notification::assertNothingSent();
});

it('does not change the address with a wrong current password', function () {
    emailChangePatch(['name' => 'Ada', 'email' => 'new@example.com', 'current_password' => 'not-it'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('current_password');

    expect($this->user->fresh()->email)->toBe('ada@example.com');
    Notification::assertNothingSent();
});

it('keeps the address, saves the name and answers with the pending address when the password is right', function () {
    emailChangePatch(['name' => 'Ada Lovelace', 'email' => 'new@example.com', 'current_password' => 'Correct-Horse-1'])
        ->assertOk()
        ->assertJsonPath('email', 'ada@example.com')
        ->assertJsonPath('name', 'Ada Lovelace')
        ->assertJsonPath('pending_email', 'new@example.com');

    $fresh = $this->user->fresh();
    expect($fresh->email)->toBe('ada@example.com')
        ->and($fresh->name)->toBe('Ada Lovelace')
        ->and($fresh->hasVerifiedEmail())->toBeTrue();
});

it('mails the confirmation link to the new address and a notice to the old one', function () {
    emailChangePatch(['name' => 'Ada', 'email' => 'new@example.com', 'current_password' => 'Correct-Horse-1'])->assertOk();

    $url = emailChangeLink('new@example.com');
    expect($url)->toStartWith('https://panel.example.com/martis/profile/email/confirm/'.$this->user->getKey().'?')
        ->toContain('signature=')->toContain('expires=');

    Notification::assertSentOnDemand(
        EmailChangeRequestedNotification::class,
        fn (EmailChangeRequestedNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => ($notifiable->routes['mail'] ?? null) === 'ada@example.com'
            && $notification->newEmail === 'new@example.com',
    );
    Notification::assertSentOnDemandTimes(EmailChangeConfirmationNotification::class, 1);
    Notification::assertSentOnDemandTimes(EmailChangedNotification::class, 0);
});

it('builds the confirmation link on APP_URL when the request carries a forged Host', function () {
    $this->actingAs($this->user)
        ->withSession(['martis_two_factor_passed' => true])
        ->patchJson('http://evil.test/martis/api/profile', ['name' => 'Ada', 'email' => 'new@example.com', 'current_password' => 'Correct-Horse-1'])
        ->assertOk();

    expect(emailChangeLink('new@example.com'))->toStartWith('https://panel.example.com/martis/profile/email/confirm/')
        ->not->toContain('evil.test');
});

it('switches the address when the link is followed, resets the verification and tells the old address', function () {
    emailChangePatch(['name' => 'Ada', 'email' => 'new@example.com', 'current_password' => 'Correct-Horse-1'])->assertOk();
    $url = emailChangeLink('new@example.com');

    emailChangeFollow($url)->assertRedirect('/martis/login?email_change=changed');

    $fresh = $this->user->fresh();
    expect($fresh->email)->toBe('new@example.com')
        ->and($fresh->email_verified_at)->toBeNull();
    Notification::assertSentOnDemand(
        EmailChangedNotification::class,
        fn (EmailChangedNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => ($notifiable->routes['mail'] ?? null) === 'ada@example.com'
            && $notification->oldEmail === 'ada@example.com'
            && $notification->newEmail === 'new@example.com',
    );
});

it('sends the verification link of the app to the new address when verification is on', function () {
    config()->set('martis.auth.email_verification.enabled', true);
    emailChangePatch(['name' => 'Ada', 'email' => 'new@example.com', 'current_password' => 'Correct-Horse-1'])->assertOk();

    emailChangeFollow(emailChangeLink('new@example.com'))->assertRedirect('/martis/login?email_change=changed');

    Notification::assertSentTo($this->user->fresh(), Illuminate\Auth\Notifications\VerifyEmail::class);
    expect($this->user->fresh()->email_verified_at)->toBeNull();
});

it('leaves the verification alone for a model with neither the contract nor Martis verification', function () {
    // A model without MustVerifyEmail, and Martis verification off: nothing to reset.
    Schema::dropIfExists('users');
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });
    $plain = new class extends User
    {
        protected $table = 'users';

        protected $guarded = [];
    };
    config()->set('auth.providers.users.model', $plain::class);
    $user = $plain::create(['name' => 'Plain', 'email' => 'plain@example.com', 'password' => bcrypt('Correct-Horse-1')]);

    $this->actingAs($user)->withSession(['martis_two_factor_passed' => true])
        ->patchJson('/martis/api/profile', ['name' => 'Plain', 'email' => 'plain2@example.com', 'current_password' => 'Correct-Horse-1'])
        ->assertOk();
    emailChangeFollow(emailChangeLink('plain2@example.com'))->assertRedirect('/martis/login?email_change=changed');

    expect($plain::query()->find($user->getKey())->email)->toBe('plain2@example.com');
});

it('sends a signed-in browser back to the profile page', function () {
    emailChangePatch(['name' => 'Ada', 'email' => 'new@example.com', 'current_password' => 'Correct-Horse-1'])->assertOk();
    $url = emailChangeLink('new@example.com');

    $this->actingAs($this->user)->get($url)->assertRedirect('/martis/profile?email_change=changed');
    expect($this->user->fresh()->email)->toBe('new@example.com');
});

it('works once: a link followed again, or issued before another change, is dead', function () {
    emailChangePatch(['name' => 'Ada', 'email' => 'first@example.com', 'current_password' => 'Correct-Horse-1'])->assertOk();
    $first = emailChangeLink('first@example.com');
    auth()->forgetGuards();
    Notification::fake();
    emailChangePatch(['name' => 'Ada', 'email' => 'second@example.com', 'current_password' => 'Correct-Horse-1'])->assertOk();
    $second = emailChangeLink('second@example.com');

    emailChangeFollow($first)->assertRedirect('/martis/login?email_change=changed');
    expect($this->user->fresh()->email)->toBe('first@example.com');

    // Followed again, and the one issued for the address that has since changed.
    emailChangeFollow($first)->assertRedirect('/martis/login?email_change=invalid');
    emailChangeFollow($second)->assertRedirect('/martis/login?email_change=invalid');

    expect($this->user->fresh()->email)->toBe('first@example.com');
});

it('asks again whether the address is free when the link is followed', function () {
    emailChangePatch(['name' => 'Ada', 'email' => 'new@example.com', 'current_password' => 'Correct-Horse-1'])->assertOk();
    $url = emailChangeLink('new@example.com');

    // Somebody else registers it in the meantime.
    EmailChangeUser::create(['name' => 'Taken', 'email' => 'new@example.com', 'password' => bcrypt('x')]);

    emailChangeFollow($url)->assertRedirect('/martis/login?email_change=rejected');

    expect($this->user->fresh()->email)->toBe('ada@example.com');
    Notification::assertSentOnDemandTimes(EmailChangedNotification::class, 0);
});

it('refuses a link whose address, user or signature was altered, or that expired', function () {
    emailChangePatch(['name' => 'Ada', 'email' => 'new@example.com', 'current_password' => 'Correct-Horse-1'])->assertOk();
    $url = emailChangeLink('new@example.com');
    $other = EmailChangeUser::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => bcrypt('x')]);

    $tampered = [
        'another address' => str_replace('to=new%40example.com', 'to=attacker%40example.com', $url),
        'another user' => str_replace('/confirm/'.$this->user->getKey().'?', '/confirm/'.$other->getKey().'?', $url),
        'no signature' => preg_replace('/&signature=[^&]*/', '', $url),
    ];

    foreach ($tampered as $label => $link) {
        emailChangeFollow($link)->assertRedirect('/martis/login?email_change=invalid');
    }

    $this->travel(61)->minutes();
    emailChangeFollow($url)->assertRedirect('/martis/login?email_change=invalid');

    expect($this->user->fresh()->email)->toBe('ada@example.com')
        ->and($other->fresh()->email)->toBe('other@example.com');
});

it('expires the link after martis.profile.email_change.ttl_minutes', function () {
    config()->set('martis.profile.email_change.ttl_minutes', 5);
    emailChangePatch(['name' => 'Ada', 'email' => 'new@example.com', 'current_password' => 'Correct-Horse-1'])->assertOk();
    $url = emailChangeLink('new@example.com');

    $this->travel(4)->minutes();
    emailChangeFollow($url)->assertRedirect('/martis/login?email_change=changed');
});

it('refuses the link while the profile is disabled', function () {
    emailChangePatch(['name' => 'Ada', 'email' => 'new@example.com', 'current_password' => 'Correct-Horse-1'])->assertOk();
    $url = emailChangeLink('new@example.com');
    config()->set('martis.profile.enabled', false);

    emailChangeFollow($url)->assertRedirect('/martis/login?email_change=invalid');

    expect($this->user->fresh()->email)->toBe('ada@example.com');
});

it('saves the name alone without the password and mails nobody', function () {
    emailChangePatch(['name' => 'Ada Lovelace', 'email' => 'ada@example.com'])
        ->assertOk()
        ->assertJsonMissingPath('pending_email');

    expect($this->user->fresh()->name)->toBe('Ada Lovelace');
    Notification::assertNothingSent();
});

it('takes the same address in another letter case for no change, and keeps it as stored', function () {
    emailChangePatch(['name' => 'Ada', 'email' => 'ADA@Example.com'])->assertOk()->assertJsonMissingPath('pending_email');

    expect($this->user->fresh()->email)->toBe('ada@example.com');
    Notification::assertNothingSent();
});

it('refuses an address another user holds, before any mail', function () {
    EmailChangeUser::create(['name' => 'Taken', 'email' => 'taken@example.com', 'password' => bcrypt('x')]);

    emailChangePatch(['name' => 'Ada', 'email' => 'taken@example.com', 'current_password' => 'Correct-Horse-1'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    Notification::assertNothingSent();
});

it('does not let a session without the password take the address of a colleague who never signed in through SSO (F017)', function () {
    config()->set('martis.auth.sso.providers.azure', ['identity_match_attribute' => 'email', 'auto_create_user' => true, 'sync_user_attributes' => ['name', 'email']]);

    // The attacker, a password user, aims at the colleague's address.
    emailChangePatch(['name' => 'Ada', 'email' => 'colleague@example.com'])->assertUnprocessable();
    emailChangePatch(['name' => 'Ada', 'email' => 'colleague@example.com', 'current_password' => 'Correct-Horse-1'])->assertOk();

    // The link went to the colleague's inbox, not the attacker's: the account stays on its address.
    expect($this->user->fresh()->email)->toBe('ada@example.com');

    // The colleague's first SSO sign-in finds nobody to adopt and gets an account of their own.
    $resolved = (new IdentityResolver)->resolve(
        new SsoIdentity(provider: 'azure', externalId: 'oid-colleague', email: 'colleague@example.com', name: 'Colleague'),
        'azure',
    );
    expect($resolved)->not->toBeNull()
        ->and($resolved->getKey())->not->toBe($this->user->getKey())
        ->and($this->user->fresh()->email)->toBe('ada@example.com');
});

it('rules out the email change when the resource has no email rule', function () {
    $resource = new class extends Martis\Profile\ProfileResource
    {
        public function updateRules(Illuminate\Contracts\Auth\Authenticatable $user): array
        {
            return ['name' => ['required', 'string', 'max:255']];
        }

        public function applyUpdate(Illuminate\Contracts\Auth\Authenticatable $user, array $data): void
        {
            $user->forceFill(['name' => $data['name']])->save();
        }
    };
    $this->app->instance(Martis\Contracts\ProfileResourceContract::class, $resource);

    emailChangePatch(['name' => 'Ada Lovelace', 'email' => 'new@example.com'])->assertOk();

    expect($this->user->fresh()->email)->toBe('ada@example.com')
        ->and($this->user->fresh()->name)->toBe('Ada Lovelace');
    Notification::assertNothingSent();
});

it('answers isChange() by the letters of the address, not their case', function () {
    $change = app(EmailChange::class);

    expect($change->isChange($this->user, 'ADA@example.com'))->toBeFalse()
        ->and($change->isChange($this->user, ' ada@example.com '))->toBeFalse()
        ->and($change->isChange($this->user, 'new@example.com'))->toBeTrue()
        ->and($change->isChange($this->user, ''))->toBeFalse()
        ->and($change->isChange($this->user, ['new@example.com']))->toBeFalse();
});
