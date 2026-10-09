<?php

declare(strict_types=1);

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Martis\Contracts\ProfileResourceContract;
use Martis\Profile\EmailChange;
use Martis\Profile\EmailChangeConfirmationNotification;
use Martis\Profile\EmailChangedNotification;
use Martis\Profile\EmailChangeRequestedNotification;
use Martis\Profile\ProfileResource;
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

/** Open the link as a guest in another browser and click its button: the POST that applies the change. */
function emailChangeFollow(string $url)
{
    auth()->forgetGuards();
    test()->flushSession();

    return test()->post($url);
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

    emailChangeFollow($url)->assertOk()->assertJson(['redirect' => '/martis/login?email_change=changed']);

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

    emailChangeFollow(emailChangeLink('new@example.com'))->assertOk()->assertJson(['redirect' => '/martis/login?email_change=changed']);

    Notification::assertSentTo($this->user->fresh(), VerifyEmail::class);
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
    emailChangeFollow(emailChangeLink('plain2@example.com'))->assertOk()->assertJson(['redirect' => '/martis/login?email_change=changed']);

    expect($plain::query()->find($user->getKey())->email)->toBe('plain2@example.com');
});

it('does not change anything when the mailed link is merely opened (scanner, preview, prefetch)', function () {
    emailChangePatch(['name' => 'Ada', 'email' => 'victim@corp.example', 'current_password' => 'Correct-Horse-1'])->assertOk();
    $url = emailChangeLink('victim@corp.example');
    Notification::fake();
    auth()->forgetGuards();
    $this->flushSession();

    // Several loads, as a gateway that prefetches the victim's mail would make.
    foreach (range(1, 3) as $_) {
        $this->get($url)
            ->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee('id="martis-root"', false);
    }

    expect($this->user->fresh()->email)->toBe('ada@example.com');
    Notification::assertNothingSent();
    $this->assertGuest(config('martis.guard'));
});

it('sends an opened link with a bad signature, or while the profile is off, to the login page as invalid', function () {
    emailChangePatch(['name' => 'Ada', 'email' => 'new@example.com', 'current_password' => 'Correct-Horse-1'])->assertOk();
    $url = emailChangeLink('new@example.com');

    $this->get(preg_replace('/&signature=[^&]*/', '', $url))->assertRedirect('/martis/login?email_change=invalid');

    config()->set('martis.profile.enabled', false);
    $this->get($url)->assertRedirect('/martis/login?email_change=invalid');
    expect($this->user->fresh()->email)->toBe('ada@example.com');
});

it('enforces the CSRF check on the confirmation POST and changes nothing without it', function () {
    emailChangePatch(['name' => 'Ada', 'email' => 'new@example.com', 'current_password' => 'Correct-Horse-1'])->assertOk();
    $url = emailChangeLink('new@example.com');
    auth()->forgetGuards();
    $this->flushSession();
    // The framework skips the CSRF check while the app runs as `testing`.
    $this->app['env'] = 'local';

    $this->post($url)->assertStatus(419);
    expect($this->user->fresh()->email)->toBe('ada@example.com');

    $this->withSession(['_token' => 'csrf-token'])->post($url, ['_token' => 'csrf-token'])
        ->assertOk()
        ->assertJson(['outcome' => 'changed']);
    expect($this->user->fresh()->email)->toBe('new@example.com');
});

it('sends a signed-in browser back to the profile page', function () {
    emailChangePatch(['name' => 'Ada', 'email' => 'new@example.com', 'current_password' => 'Correct-Horse-1'])->assertOk();
    $url = emailChangeLink('new@example.com');

    $this->actingAs($this->user)->post($url)->assertOk()->assertJson(['redirect' => '/martis/profile?email_change=changed']);
    expect($this->user->fresh()->email)->toBe('new@example.com');
});

it('works once: a link followed again, or issued before another change, is dead', function () {
    emailChangePatch(['name' => 'Ada', 'email' => 'first@example.com', 'current_password' => 'Correct-Horse-1'])->assertOk();
    $first = emailChangeLink('first@example.com');
    auth()->forgetGuards();
    Notification::fake();
    emailChangePatch(['name' => 'Ada', 'email' => 'second@example.com', 'current_password' => 'Correct-Horse-1'])->assertOk();
    $second = emailChangeLink('second@example.com');

    emailChangeFollow($first)->assertOk()->assertJson(['redirect' => '/martis/login?email_change=changed']);
    expect($this->user->fresh()->email)->toBe('first@example.com');

    // Followed again, and the one issued for the address that has since changed.
    emailChangeFollow($first)->assertOk()->assertJson(['redirect' => '/martis/login?email_change=invalid']);
    emailChangeFollow($second)->assertOk()->assertJson(['redirect' => '/martis/login?email_change=invalid']);

    expect($this->user->fresh()->email)->toBe('first@example.com');
});

it('asks again whether the address is free when the link is followed', function () {
    emailChangePatch(['name' => 'Ada', 'email' => 'new@example.com', 'current_password' => 'Correct-Horse-1'])->assertOk();
    $url = emailChangeLink('new@example.com');

    // Somebody else registers it in the meantime.
    EmailChangeUser::create(['name' => 'Taken', 'email' => 'new@example.com', 'password' => bcrypt('x')]);

    emailChangeFollow($url)->assertOk()->assertJson(['redirect' => '/martis/login?email_change=rejected']);

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
        emailChangeFollow($link)->assertOk()->assertJson(['redirect' => '/martis/login?email_change=invalid']);
    }

    $this->travel(61)->minutes();
    emailChangeFollow($url)->assertOk()->assertJson(['redirect' => '/martis/login?email_change=invalid']);

    expect($this->user->fresh()->email)->toBe('ada@example.com')
        ->and($other->fresh()->email)->toBe('other@example.com');
});

it('expires the link after martis.profile.email_change.ttl_minutes', function () {
    config()->set('martis.profile.email_change.ttl_minutes', 5);
    emailChangePatch(['name' => 'Ada', 'email' => 'new@example.com', 'current_password' => 'Correct-Horse-1'])->assertOk();
    $url = emailChangeLink('new@example.com');

    $this->travel(4)->minutes();
    emailChangeFollow($url)->assertOk()->assertJson(['redirect' => '/martis/login?email_change=changed']);
});

// `profile.account.email_editable` false holds on the server (v2.8.0): up to
// v2.7.0 it locked the field in the UI only, and a hand-made PATCH still
// mailed a confirmation link.
it('refuses another address while the e-mail is locked, and saves and mails nothing', function () {
    config()->set('martis.profile.account.email_editable', false);

    emailChangePatch(['name' => 'Ada Lovelace', 'email' => 'new@example.com', 'current_password' => 'Correct-Horse-1'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => 'Your email address cannot be changed here.']);

    expect($this->user->fresh()->name)->toBe('Ada')
        ->and($this->user->fresh()->email)->toBe('ada@example.com');
    Notification::assertNothingSent();
});

it('saves the name while the e-mail is locked, with the same address, another letter case or none', function (array $email) {
    config()->set('martis.profile.account.email_editable', false);

    emailChangePatch(['name' => 'Ada Lovelace'] + $email)
        ->assertOk()
        ->assertJsonPath('email', 'ada@example.com')
        ->assertJsonMissingPath('pending_email');

    expect($this->user->fresh()->name)->toBe('Ada Lovelace')
        ->and($this->user->fresh()->email)->toBe('ada@example.com');
    Notification::assertNothingSent();
})->with([
    'the same address' => [['email' => 'ada@example.com']],
    'another letter case' => [['email' => 'ADA@Example.com']],
    'no address' => [[]],
    'an empty address' => [['email' => '']],
]);

it('refuses a link mailed before the e-mail was locked', function () {
    emailChangePatch(['name' => 'Ada', 'email' => 'new@example.com', 'current_password' => 'Correct-Horse-1'])->assertOk();
    $url = emailChangeLink('new@example.com');
    config()->set('martis.profile.account.email_editable', false);

    $this->get($url)->assertRedirect('/martis/login?email_change=invalid');
    emailChangeFollow($url)->assertOk()->assertJson(['outcome' => 'invalid']);

    expect($this->user->fresh()->email)->toBe('ada@example.com');
});

it('refuses the link while the profile is disabled', function () {
    emailChangePatch(['name' => 'Ada', 'email' => 'new@example.com', 'current_password' => 'Correct-Horse-1'])->assertOk();
    $url = emailChangeLink('new@example.com');
    config()->set('martis.profile.enabled', false);

    emailChangeFollow($url)->assertOk()->assertJson(['redirect' => '/martis/login?email_change=invalid']);

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
    $resource = new class extends ProfileResource
    {
        public function updateRules(Authenticatable $user): array
        {
            return ['name' => ['required', 'string', 'max:255']];
        }

        public function applyUpdate(Authenticatable $user, array $data): void
        {
            $user->forceFill(['name' => $data['name']])->save();
        }
    };
    $this->app->instance(ProfileResourceContract::class, $resource);

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

// ── The limit on the mail it sends ──────────────────────────────────────────

it('limits the confirmation mails one user can ask for, and sends none past the limit', function () {
    config()->set('martis.profile.email_change.throttle_attempts', 3);

    foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $new) {
        emailChangePatch(['name' => 'Ada', 'email' => $new, 'current_password' => 'Correct-Horse-1'])->assertOk();
    }
    Notification::assertSentOnDemandTimes(EmailChangeConfirmationNotification::class, 3);

    // The 4th is refused with the throttle's 429 and Retry-After, before the name is saved or any mail goes.
    emailChangePatch(['name' => 'Ada Lovelace', 'email' => 'd@example.com', 'current_password' => 'Correct-Horse-1'])
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    Notification::assertSentOnDemandTimes(EmailChangeConfirmationNotification::class, 3);
    expect($this->user->fresh()->name)->toBe('Ada');

    // The nearest cases stay open: a save that changes no address, and the same user after the window.
    emailChangePatch(['name' => 'Ada Lovelace', 'email' => 'ada@example.com'])->assertOk();
    expect($this->user->fresh()->name)->toBe('Ada Lovelace');

    $this->travel(61)->minutes();
    emailChangePatch(['name' => 'Ada', 'email' => 'd@example.com', 'current_password' => 'Correct-Horse-1'])->assertOk();
});

it('limits the mails one address can be sent, whoever asks, and keeps another user free', function () {
    config()->set('martis.profile.email_change.throttle_attempts', 2);
    $other = EmailChangeUser::create(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => bcrypt('Correct-Horse-1'), 'email_verified_at' => now()]);

    // Two different users name the same victim address: its bucket is spent.
    emailChangePatch(['name' => 'Ada', 'email' => 'victim@example.com', 'current_password' => 'Correct-Horse-1'])->assertOk();
    $this->user = $other;
    emailChangePatch(['name' => 'Bob', 'email' => 'victim@example.com', 'current_password' => 'Correct-Horse-1'])->assertOk();

    $third = EmailChangeUser::create(['name' => 'Cy', 'email' => 'cy@example.com', 'password' => bcrypt('Correct-Horse-1'), 'email_verified_at' => now()]);
    $this->user = $third;
    emailChangePatch(['name' => 'Cy', 'email' => 'Victim@Example.com', 'current_password' => 'Correct-Horse-1'])->assertStatus(429);

    // ... while the same user asking for another address is not held by it.
    emailChangePatch(['name' => 'Cy', 'email' => 'cy2@example.com', 'current_password' => 'Correct-Horse-1'])->assertOk();
});

it('turns the email change limit off with zero attempts', function () {
    config()->set('martis.profile.email_change.throttle_attempts', 0);

    foreach (range(1, 8) as $i) {
        emailChangePatch(['name' => 'Ada', 'email' => "n{$i}@example.com", 'current_password' => 'Correct-Horse-1'])->assertOk();
    }
});
