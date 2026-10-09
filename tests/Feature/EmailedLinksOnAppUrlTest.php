<?php

declare(strict_types=1);

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Martis\Auth\MagicLinkNotification;
use Martis\Invitations\Invitation;
use Martis\Invitations\InvitationUrl;
use Martis\MartisServiceProvider;
use Martis\Support\CanonicalUrl;

/*
 * Every URL Martis emails (sign-in link, password reset, invitation, email
 * verification) is built on APP_URL, never on the request: a request with a
 * forged Host header must not mail the victim a live token that points at
 * the attacker's domain.
 */

class EmailedLinksUser extends User implements MustVerifyEmail
{
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];
}

beforeEach(function () {
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamp('email_verified_at')->nullable();
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

    config()->set('app.url', 'https://panel.example.com');
    config()->set('auth.providers.users.model', EmailedLinksUser::class);
    $this->user = EmailedLinksUser::forceCreate(['name' => 'Maria', 'email' => 'maria@example.com', 'password' => bcrypt('x')]);
});

afterEach(function () {
    Schema::dropIfExists('password_reset_tokens');
    ResetPassword::createUrlUsing(null);
    VerifyEmail::createUrlUsing(null);
    InvitationUrl::createUrlUsing(null);
});

/**
 * The fragment of an emailed URL as parameters, after checking that the
 * token is nowhere else: the path and the query string are what proxies and
 * web servers log, the fragment never leaves the browser.
 *
 * @return array<string, string>
 */
function fragmentParameters(string $url, string $token): array
{
    $parts = parse_url($url);

    expect($parts['path'] ?? '')->not->toContain($token)
        ->and($parts['query'] ?? '')->not->toContain($token)
        ->and($parts['fragment'] ?? '')->toContain($token);

    parse_str($parts['fragment'], $parameters);

    return $parameters;
}

/** Run a protected provider method, as the boot of an app with that feature on would. */
function bootProviderMethod(string $method): void
{
    $provider = new MartisServiceProvider(app());
    (new ReflectionMethod($provider, $method))->invoke($provider);
}

it('mails the sign-in link on APP_URL when the request carries a forged Host', function () {
    Notification::fake();
    config()->set('martis.auth.magic_link.enabled', true);

    $this->postJson('http://evil.test/martis/api/auth/magic-link/request', ['email' => 'maria@example.com'])->assertOk();

    Notification::assertSentTo($this->user, MagicLinkNotification::class, function (MagicLinkNotification $notification): bool {
        return str_starts_with($notification->url, 'https://panel.example.com/martis/magic-link/confirm#email=maria%40example.com&token=')
            && ! str_contains($notification->url, 'evil.test');
    });
});

it('mails the sign-in link on APP_URL when a proxy forwards a forged X-Forwarded-Host', function () {
    Notification::fake();
    config()->set('martis.auth.magic_link.enabled', true);
    $proxies = Request::getTrustedProxies();
    $headers = Request::getTrustedHeaderSet();
    Request::setTrustedProxies(['*'], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO);

    try {
        $this->withHeaders(['X-Forwarded-Host' => 'evil.test', 'X-Forwarded-Proto' => 'http'])
            ->postJson('/martis/api/auth/magic-link/request', ['email' => 'maria@example.com'])
            ->assertOk();
    } finally {
        Request::setTrustedProxies($proxies, $headers);
    }

    Notification::assertSentTo($this->user, MagicLinkNotification::class, fn (MagicLinkNotification $notification): bool => str_starts_with($notification->url, 'https://panel.example.com/martis/magic-link/confirm#email=maria%40example.com&token='));
});

it('mails the password reset link on APP_URL when the request carries a forged Host', function () {
    Notification::fake();
    config()->set('martis.auth.passwordReset.enabled', true);
    ResetPassword::createUrlUsing(null);
    bootProviderMethod('registerPasswordResetUrl');

    $this->postJson('http://evil.test/martis/api/auth/password/email', ['email' => 'maria@example.com'])->assertOk();

    Notification::assertSentTo($this->user, ResetPassword::class, function (ResetPassword $notification): bool {
        $url = $notification->toMail($this->user)->actionUrl;

        return str_starts_with($url, 'https://panel.example.com/martis/reset-password#token='.$notification->token.'&email=maria%40example.com')
            && ! str_contains($url, 'evil.test');
    });
});

it('builds the invitation accept URL on APP_URL, from the default and from the provider seam', function () {
    config()->set('martis.invitations.enabled', true);
    app('url')->setRequest(Request::create('http://evil.test/martis/api/resources/invitations/actions'));

    expect(InvitationUrl::url(new Invitation, 'raw-token'))->toBe('https://panel.example.com/martis/invitations/accept#token=raw-token');

    InvitationUrl::createUrlUsing(null);
    bootProviderMethod('registerInvitationAcceptUrl');

    expect(InvitationUrl::url(new Invitation, 'raw-token'))->toBe('https://panel.example.com/martis/invitations/accept#token=raw-token');
});

it('keeps the one-time token of every emailed link out of its path and query string', function () {
    Notification::fake();
    config()->set('martis.auth.magic_link.enabled', true);
    config()->set('martis.auth.passwordReset.enabled', true);
    config()->set('martis.invitations.enabled', true);
    ResetPassword::createUrlUsing(null);
    bootProviderMethod('registerPasswordResetUrl');

    $this->postJson('/martis/api/auth/magic-link/request', ['email' => 'maria@example.com'])->assertOk();
    $this->postJson('/martis/api/auth/password/email', ['email' => 'maria@example.com'])->assertOk();

    $sent = [];
    Notification::assertSentTo($this->user, MagicLinkNotification::class, function (MagicLinkNotification $notification) use (&$sent): bool {
        $sent['magic'] = $notification->url;

        return true;
    });
    Notification::assertSentTo($this->user, ResetPassword::class, function (ResetPassword $notification) use (&$sent): bool {
        $sent['reset'] = [$notification->toMail($this->user)->actionUrl, $notification->token];

        return true;
    });

    parse_str((string) parse_url($sent['magic'], PHP_URL_FRAGMENT), $magicLink);
    $magicToken = $magicLink['token'];
    expect(fragmentParameters($sent['magic'], $magicToken))->toMatchArray(['email' => 'maria@example.com', 'token' => $magicToken]);

    [$resetUrl, $resetToken] = $sent['reset'];
    expect(fragmentParameters($resetUrl, $resetToken))->toMatchArray(['email' => 'maria@example.com', 'token' => $resetToken]);

    $invitationUrl = InvitationUrl::url(new Invitation, 'raw-invite-token');
    expect(fragmentParameters($invitationUrl, 'raw-invite-token'))->toBe(['token' => 'raw-invite-token'])
        ->and(parse_url($invitationUrl, PHP_URL_HOST))->toBe('panel.example.com');
});

it('resets the password with the token parsed from the fragment of the emailed URL', function () {
    Notification::fake();
    config()->set('martis.auth.passwordReset.enabled', true);
    config()->set('martis.auth.passwordReset.url', null);
    ResetPassword::createUrlUsing(null);
    bootProviderMethod('registerPasswordResetUrl');

    $this->postJson('/martis/api/auth/password/email', ['email' => 'maria@example.com'])->assertOk();

    $url = null;
    Notification::assertSentTo($this->user, ResetPassword::class, function (ResetPassword $notification) use (&$url): bool {
        $url = $notification->toMail($this->user)->actionUrl;

        return true;
    });

    // What the page does: read the fragment, POST it (the server never saw it).
    parse_str((string) parse_url($url, PHP_URL_FRAGMENT), $link);

    $this->postJson('/martis/api/auth/password/reset', [
        'token' => $link['token'],
        'email' => $link['email'],
        'password' => 'A-New-Password-9',
        'password_confirmation' => 'A-New-Password-9',
    ])->assertOk();

    expect(Hash::check('A-New-Password-9', $this->user->fresh()->password))->toBeTrue();
});

it('builds the email verification URL on APP_URL, and the signature still holds on that host', function () {
    config()->set('martis.auth.email_verification.enabled', true);
    VerifyEmail::createUrlUsing(null);
    bootProviderMethod('registerEmailVerificationUrl');
    app('url')->setRequest(Request::create('http://evil.test/martis/api/auth/register'));

    $url = (new VerifyEmail)->toMail($this->user)->actionUrl;

    expect($url)->toStartWith('https://panel.example.com/martis/email/verify/'.$this->user->getKey().'/');

    // The link is for the host APP_URL names: followed there, it verifies.
    $this->get($url)->assertRedirect();
    expect($this->user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('puts the URL generator back as it found it', function () {
    app('url')->forceRootUrl('https://consumer-forced.example.com');
    app('url')->forceScheme('https');

    $emailed = CanonicalUrl::route('martis.login');

    expect($emailed)->toBe('https://panel.example.com/martis/login')
        ->and(route('martis.login'))->toBe('https://consumer-forced.example.com/martis/login');

    app('url')->forceRootUrl(null);
    app('url')->forceScheme(null);
    app('url')->setRequest(Request::create('http://localhost/'));

    CanonicalUrl::route('martis.login');

    expect(route('martis.login'))->toBe('http://localhost/martis/login');
});

it('keeps the sub-directory of APP_URL and pins its scheme', function () {
    config()->set('app.url', 'http://panel.example.com/sub/');
    app('url')->setRequest(Request::create('https://evil.test/'));

    expect(CanonicalUrl::route('martis.login'))->toBe('http://panel.example.com/sub/martis/login');
});

it('refuses to build a link while APP_URL is not an absolute http(s) URL', function (mixed $appUrl) {
    config()->set('app.url', $appUrl);

    expect(fn () => CanonicalUrl::route('martis.login'))->toThrow(RuntimeException::class, 'APP_URL is not an absolute http(s) URL');
})->with([
    'empty' => [''],
    'null' => [null],
    'no scheme' => ['panel.example.com'],
    'other scheme' => ['ftp://panel.example.com'],
]);
