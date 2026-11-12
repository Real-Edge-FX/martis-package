<?php

declare(strict_types=1);

use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Martis\Auth\TwoFactorPass;
use Martis\Events\SsoIssueDenied;
use Martis\Events\SsoTokenIssued;
use Martis\Sso\SignedToken\PasetoV4Public;
use Martis\Sso\SignedToken\TokenIssuer;
use Psr\Log\LoggerInterface;

require_once __DIR__.'/../Support/signed-token.php';

class StIssuerUser extends User
{
    protected $table = 'users';

    protected $guarded = [];

    protected $casts = ['two_factor_confirmed_at' => 'datetime'];
}

/** A user model with Spatie's role API. */
class StIssuerRoleUser extends StIssuerUser
{
    /** @return Collection<int, string> */
    public function getRoleNames(): Collection
    {
        return collect(['editor', 'admin']);
    }
}

/** A user model that asks for email verification. */
class StIssuerVerifyingUser extends StIssuerUser implements MustVerifyEmail
{
    use MustVerifyEmailTrait;
}

class StIssuerClaims
{
    public static array $result = ['email' => 'mapped@remote.test', 'name' => 'Mapped', 'roles' => ['PMI ADMIN']];

    public static ?string $seenAudience = null;

    public function __invoke(Authenticatable $user, string $audience): array
    {
        self::$seenAudience = $audience;

        return self::$result;
    }
}

beforeEach(function () {
    Schema::dropIfExists('users');
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->text('two_factor_secret')->nullable();
        $table->text('two_factor_recovery_codes')->nullable();
        $table->timestamp('two_factor_confirmed_at')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });
    config(['auth.providers.users.model' => StIssuerUser::class]);

    $this->keys = stKeys();
    config(['martis.auth.sso.issuer' => [
        'enabled' => true,
        'issuer' => 'https://a.example',
        'private_key' => $this->keys['secret_b64'],
        'ttl_seconds' => 60,
        'audiences' => [
            'panel-b' => ['callback_url' => 'https://b.example/martis/sso/panel_a/callback'],
            'other' => ['callback_url' => 'https://c.example/cb?tenant=7'],
        ],
    ]]);

    StIssuerClaims::$result = ['email' => 'mapped@remote.test', 'name' => 'Mapped', 'roles' => ['PMI ADMIN']];
    StIssuerClaims::$seenAudience = null;

    $this->user = StIssuerUser::query()->create([
        'name' => 'Ada Admin',
        'email' => 'ada@a.example',
        'password' => Hash::make('secret-pass-1'),
        'email_verified_at' => now(),
    ]);
    $this->state = str_repeat('s', 43);
});

/** A signed-in browser session of the user that passed the 2FA challenge. */
function stSignedIn(?User $user = null): mixed
{
    $user ??= test()->user;

    return test()->actingAs($user)->withSession([TwoFactorPass::SESSION_KEY => (string) $user->getKey()]);
}

function stIssueUrl(string $audience = 'panel-b', ?string $state = 'default'): string
{
    $state = $state === 'default' ? test()->state : $state;

    return route('martis.sso.issue', ['audience' => $audience]).($state === null ? '' : '?state='.$state);
}

/** The claims of the token in a callback redirect, verified against the issuer's public key. */
function stClaimsOf(string $location, string $publicKey): array
{
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    $parsed = PasetoV4Public::parse($query['token']);
    expect(PasetoV4Public::verify($parsed, $publicKey))->toBeTrue();

    return json_decode($parsed->message, true, 8, JSON_THROW_ON_ERROR);
}

// -----------------------------------------------------------------------------
// Who may ask
// -----------------------------------------------------------------------------

it('sends an anonymous visitor to the login page and remembers where they were going', function () {
    $this->get(stIssueUrl())
        ->assertRedirect(route('martis.login'))
        ->assertSessionHas('url.intended', stIssueUrl());
});

it('sends a user who has not passed the 2FA challenge to the challenge, and signs no token', function () {
    $user = StIssuerUser::query()->create([
        'name' => 'Two Factor', 'email' => '2fa@a.example', 'password' => Hash::make('x'),
        'email_verified_at' => now(), 'two_factor_confirmed_at' => now(),
    ]);

    $response = $this->actingAs($user)->get(stIssueUrl());

    $response->assertRedirect('/martis/2fa/challenge');
    expect((string) $response->headers->get('Location'))->not->toContain('token=');
});

it('signs no token for a user the viewMartis gate refuses', function () {
    Gate::define('viewMartis', fn () => false);

    $response = stSignedIn()->get(stIssueUrl());

    $response->assertForbidden();
    expect((string) $response->headers->get('Location'))->not->toContain('token=');
});

it('signs no token for an unverified user when email verification is required', function () {
    config(['martis.auth.email_verification.enabled' => true]);
    $this->user->forceFill(['email_verified_at' => null])->save();

    $response = stSignedIn()->get(stIssueUrl());

    expect((string) $response->headers->get('Location'))->not->toContain('token=');
    expect($response->status())->not->toBe(200);
});

it('answers 404 when the issuer is disabled or the audience is not configured', function () {
    stSignedIn()->get(stIssueUrl('nobody'))->assertNotFound();

    config(['martis.auth.sso.issuer.enabled' => false]);
    stSignedIn()->get(stIssueUrl())->assertNotFound();
});

it('answers 404 for every audience while the issuer block is absent', function () {
    config(['martis.auth.sso.issuer' => null]);

    stSignedIn()->get(stIssueUrl())->assertNotFound();
});

// -----------------------------------------------------------------------------
// The state parameter
// -----------------------------------------------------------------------------

it('requires a state of 16 to 128 URL-safe characters', function (?string $state) {
    $response = stSignedIn()->get(stIssueUrl('panel-b', $state));

    $response->assertStatus(422);
    expect((string) $response->headers->get('Location'))->not->toContain('token=');
})->with([
    'missing' => [null],
    'empty' => [''],
    'too short' => [str_repeat('a', 15)],
    'too long' => [str_repeat('a', 129)],
    'with a space' => [str_repeat('a', 10).'%20'.str_repeat('a', 10)],
    'with a plus' => [str_repeat('a', 10).'%2B'.str_repeat('a', 10)],
    'with a dot' => [str_repeat('a', 10).'.'.str_repeat('a', 10)],
    'with a newline' => [str_repeat('a', 10).'%0A'.str_repeat('a', 10)],
]);

it('refuses a state sent as an array', function () {
    stSignedIn()->get(route('martis.sso.issue', ['audience' => 'panel-b']).'?state[]='.str_repeat('a', 20))->assertStatus(422);
});

it('accepts the shortest and the longest state', function (string $state) {
    stSignedIn()->get(stIssueUrl('panel-b', $state))->assertRedirect();
})->with([str_repeat('a', 16), str_repeat('A_-9', 32)]);

// -----------------------------------------------------------------------------
// Where the token goes, and what it looks like
// -----------------------------------------------------------------------------

it('redirects to the callback_url of the audience with the token and the state, and signs the claims', function () {
    $response = stSignedIn()->get(stIssueUrl());

    $response->assertStatus(302);
    $location = (string) $response->headers->get('Location');
    expect($location)->toStartWith('https://b.example/martis/sso/panel_a/callback?');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    expect(array_keys($query))->toBe(['token', 'state'])
        ->and($query['state'])->toBe($this->state);

    $claims = stClaimsOf($location, $this->keys['public']);
    expect($claims['iss'])->toBe('https://a.example')
        ->and($claims['aud'])->toBe('panel-b')
        ->and($claims['sub'])->toBe((string) $this->user->getKey())
        ->and($claims['email'])->toBe('ada@a.example')
        ->and($claims['name'])->toBe('Ada Admin')
        ->and($claims['roles'])->toBe([])
        ->and($claims['state'])->toBe($this->state)
        ->and($claims['jti'])->toMatch('/^[0-9a-f]{32,}$/')
        ->and($claims)->not->toHaveKey('env')
        ->and($claims)->not->toHaveKey('password');

    $iat = DateTimeImmutable::createFromFormat(DATE_ATOM, $claims['iat']);
    $exp = DateTimeImmutable::createFromFormat(DATE_ATOM, $claims['exp']);
    expect($exp->getTimestamp() - $iat->getTimestamp())->toBe(60)
        ->and(abs($iat->getTimestamp() - time()))->toBeLessThan(5);
});

it('appends to a callback_url that already has a query string', function () {
    $location = (string) stSignedIn()->get(stIssueUrl('other'))->headers->get('Location');

    expect($location)->toStartWith('https://c.example/cb?tenant=7&token=');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    expect($query['tenant'])->toBe('7')->and($query['state'])->toBe($this->state);
});

it('never redirects to a URL that comes from the request', function () {
    $evil = 'https://evil.example/steal';
    $query = http_build_query([
        'state' => $this->state, 'callback_url' => $evil, 'redirect' => $evil, 'redirect_uri' => $evil,
        'return_to' => $evil, 'next' => $evil, 'url' => $evil, 'audience' => $evil,
    ]);

    $response = stSignedIn()
        ->withHeaders(['Host' => 'evil.example', 'X-Forwarded-Host' => 'evil.example', 'Referer' => $evil, 'Origin' => $evil])
        ->get(route('martis.sso.issue', ['audience' => 'panel-b']).'?'.$query);

    $location = (string) $response->headers->get('Location');
    expect($location)->toStartWith('https://b.example/martis/sso/panel_a/callback?token=')
        ->and($location)->not->toContain('evil.example');
});

it('sets Cache-Control no-store and Referrer-Policy no-referrer on the redirect', function () {
    $response = stSignedIn()->get(stIssueUrl());

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer');
});

it('signs a different token every time (random jti)', function () {
    $a = stClaimsOf((string) stSignedIn()->get(stIssueUrl())->headers->get('Location'), $this->keys['public']);
    $b = stClaimsOf((string) stSignedIn()->get(stIssueUrl())->headers->get('Location'), $this->keys['public']);

    expect($a['jti'])->not->toBe($b['jti']);
});

it('puts the kid of the signing key in the footer', function () {
    $location = (string) stSignedIn()->get(stIssueUrl())->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    $footer = json_decode(PasetoV4Public::parse($query['token'])->footer, true, 4, JSON_THROW_ON_ERROR);

    expect($footer)->toBe(['kid' => substr(hash('sha256', $this->keys['public']), 0, 16)]);
});

// -----------------------------------------------------------------------------
// Fetch Metadata
// -----------------------------------------------------------------------------

it('refuses a request the browser says is not a top-level navigation', function (array $headers) {
    $response = stSignedIn()->withHeaders($headers)->get(stIssueUrl());

    $response->assertForbidden();
    expect((string) $response->headers->get('Location'))->not->toContain('token=');
})->with([
    'XHR / fetch' => [['Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Dest' => 'empty']],
    'iframe' => [['Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'iframe']],
    'embed' => [['Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'embed']],
    'image' => [['Sec-Fetch-Mode' => 'no-cors', 'Sec-Fetch-Dest' => 'image']],
    'only the mode, wrong' => [['Sec-Fetch-Mode' => 'cors']],
    'only the destination, wrong' => [['Sec-Fetch-Dest' => 'script']],
    'wrong mode, right destination' => [['Sec-Fetch-Mode' => 'same-origin', 'Sec-Fetch-Dest' => 'document']],
]);

it('serves a top-level navigation, with or without Fetch Metadata', function (array $headers) {
    stSignedIn()->withHeaders($headers)->get(stIssueUrl())->assertStatus(302);
})->with([
    'no metadata (old browser, curl)' => [[]],
    'navigate and document' => [['Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document', 'Sec-Fetch-Site' => 'cross-site']],
    'only navigate' => [['Sec-Fetch-Mode' => 'navigate']],
]);

// -----------------------------------------------------------------------------
// TokenIssuer
// -----------------------------------------------------------------------------

it('uses the role names of a model that has them (Spatie) and the id as a string sub', function () {
    $user = StIssuerRoleUser::query()->find($this->user->getKey());

    $token = TokenIssuer::fromConfig()->issue($user, 'panel-b', $this->state);
    $claims = json_decode(PasetoV4Public::parse($token)->message, true, 8, JSON_THROW_ON_ERROR);

    expect($claims['roles'])->toBe(['editor', 'admin'])
        ->and($claims['sub'])->toBeString();
});

it('lets a claims class add and override email, name and roles, and sees the audience', function () {
    config(['martis.auth.sso.issuer.claims' => StIssuerClaims::class]);

    $token = TokenIssuer::fromConfig()->issue($this->user, 'panel-b', $this->state);
    $claims = json_decode(PasetoV4Public::parse($token)->message, true, 8, JSON_THROW_ON_ERROR);

    expect($claims['email'])->toBe('mapped@remote.test')
        ->and($claims['name'])->toBe('Mapped')
        ->and($claims['roles'])->toBe(['PMI ADMIN'])
        ->and(StIssuerClaims::$seenAudience)->toBe('panel-b');
});

it('lets a claims class add claims of its own', function () {
    config(['martis.auth.sso.issuer.claims' => StIssuerClaims::class]);
    StIssuerClaims::$result = ['department' => 'ops'];

    $token = TokenIssuer::fromConfig()->issue($this->user, 'panel-b', $this->state);
    $claims = json_decode(PasetoV4Public::parse($token)->message, true, 8, JSON_THROW_ON_ERROR);

    expect($claims['department'])->toBe('ops')->and($claims['email'])->toBe('ada@a.example');
});

it('refuses a claims class that overrides a reserved claim, naming the key', function (string $reserved) {
    config(['martis.auth.sso.issuer.claims' => StIssuerClaims::class]);
    StIssuerClaims::$result = [$reserved => 'forged'];

    expect(fn () => TokenIssuer::fromConfig()->issue($this->user, 'panel-b', $this->state))
        ->toThrow(InvalidArgumentException::class, 'martis.auth.sso.issuer.claims');
})->with(['iss', 'aud', 'sub', 'iat', 'exp', 'jti', 'state', 'email_verified']);

it('refuses a claims class that answers with the wrong types, naming the key', function (array $result) {
    config(['martis.auth.sso.issuer.claims' => StIssuerClaims::class]);
    StIssuerClaims::$result = $result;

    expect(fn () => TokenIssuer::fromConfig()->issue($this->user, 'panel-b', $this->state))
        ->toThrow(InvalidArgumentException::class, 'martis.auth.sso.issuer.claims');
})->with([
    'roles as a string' => [['roles' => 'admin']],
    'roles with a number' => [['roles' => ['admin', 1]]],
    'email as a number' => [['email' => 12]],
    'name as an array' => [['name' => ['x']]],
]);

it('refuses to sign for a user with no email, since the verifier requires one', function () {
    $user = new StIssuerUser(['name' => 'No Email']);
    $user->setAttribute('id', 9);

    expect(fn () => TokenIssuer::fromConfig()->issue($user, 'panel-b', $this->state))
        ->toThrow(RuntimeException::class, 'email');
});

it('adds the environment claim when the issuer is locked to one', function () {
    config(['martis.auth.sso.issuer.environment' => 'staging']);

    $token = TokenIssuer::fromConfig()->issue($this->user, 'panel-b', $this->state);
    $claims = json_decode(PasetoV4Public::parse($token)->message, true, 8, JSON_THROW_ON_ERROR);
    expect($claims['env'])->toBe('staging');
});

it('writes the roles under role_claim', function () {
    config(['martis.auth.sso.issuer.role_claim' => 'groups']);

    $token = TokenIssuer::fromConfig()->issue($this->user, 'panel-b', $this->state);
    $claims = json_decode(PasetoV4Public::parse($token)->message, true, 8, JSON_THROW_ON_ERROR);

    expect($claims['groups'])->toBe([])->and($claims)->not->toHaveKey('roles');
});

it('honours ttl_seconds', function () {
    config(['martis.auth.sso.issuer.ttl_seconds' => 120]);

    $token = TokenIssuer::fromConfig()->issue($this->user, 'panel-b', $this->state);
    $claims = json_decode(PasetoV4Public::parse($token)->message, true, 8, JSON_THROW_ON_ERROR);

    expect(DateTimeImmutable::createFromFormat(DATE_ATOM, $claims['exp'])->getTimestamp()
        - DateTimeImmutable::createFromFormat(DATE_ATOM, $claims['iat'])->getTimestamp())->toBe(120);
});

it('refuses an audience that is not configured and a state of the wrong shape', function () {
    expect(fn () => TokenIssuer::fromConfig()->issue($this->user, 'nobody', $this->state))
        ->toThrow(InvalidArgumentException::class, 'martis.auth.sso.issuer.audiences');
    expect(fn () => TokenIssuer::fromConfig()->issue($this->user, 'panel-b', 'short'))
        ->toThrow(InvalidArgumentException::class, 'state');
});

it('throws, naming the config key, for an issuer block that is set but unusable', function (array $override, string $key) {
    config(['martis.auth.sso.issuer' => array_merge(config('martis.auth.sso.issuer'), $override)]);

    expect(fn () => TokenIssuer::fromConfig()->issue($this->user, 'panel-b', $this->state))
        ->toThrow(InvalidArgumentException::class, $key);
})->with([
    'no issuer' => [['issuer' => ''], 'martis.auth.sso.issuer.issuer'],
    'no private key' => [['private_key' => null], 'martis.auth.sso.issuer.private_key'],
    'private key that is not base64' => [['private_key' => '***'], 'martis.auth.sso.issuer.private_key'],
    'private key of the wrong length' => [['private_key' => 'c2hvcnQ='], 'martis.auth.sso.issuer.private_key'],
    'ttl zero' => [['ttl_seconds' => 0], 'martis.auth.sso.issuer.ttl_seconds'],
    'ttl beyond ten minutes' => [['ttl_seconds' => 601], 'martis.auth.sso.issuer.ttl_seconds'],
    'ttl not a number' => [['ttl_seconds' => 'long'], 'martis.auth.sso.issuer.ttl_seconds'],
    'empty environment' => [['environment' => ''], 'martis.auth.sso.issuer.environment'],
    'claims that is not callable' => [['claims' => 'App\\Nope'], 'martis.auth.sso.issuer.claims'],
    'audiences not an array' => [['audiences' => 'panel-b'], 'martis.auth.sso.issuer.audiences'],
    'audience without a callback_url' => [['audiences' => ['panel-b' => []]], 'martis.auth.sso.issuer.audiences.panel-b.callback_url'],
    'relative callback_url' => [['audiences' => ['panel-b' => ['callback_url' => '/cb']]], 'martis.auth.sso.issuer.audiences.panel-b.callback_url'],
    'http callback_url on another loopback address' => [['audiences' => ['panel-b' => ['callback_url' => 'http://127.0.0.2/cb']]], 'martis.auth.sso.issuer.audiences.panel-b.callback_url'],
    'http callback_url on a real host' => [['audiences' => ['panel-b' => ['callback_url' => 'http://b.example/cb']]], 'martis.auth.sso.issuer.audiences.panel-b.callback_url'],
    'callback_url with credentials' => [['audiences' => ['panel-b' => ['callback_url' => 'https://user:pw@b.example/cb']]], 'martis.auth.sso.issuer.audiences.panel-b.callback_url'],
    'callback_url with a fragment' => [['audiences' => ['panel-b' => ['callback_url' => 'https://b.example/cb#x']]], 'martis.auth.sso.issuer.audiences.panel-b.callback_url'],
    'callback_url with a backslash' => [['audiences' => ['panel-b' => ['callback_url' => 'https://b.example\\@evil.example/cb']]], 'martis.auth.sso.issuer.audiences.panel-b.callback_url'],
    'callback_url with a space' => [['audiences' => ['panel-b' => ['callback_url' => 'https://b.example/c b']]], 'martis.auth.sso.issuer.audiences.panel-b.callback_url'],
    'audience named callback (shadows the callback route)' => [['audiences' => ['callback' => ['callback_url' => 'https://b.example/cb']]], 'martis.auth.sso.issuer.audiences'],
    'audience name with a slash' => [['audiences' => ['a/b' => ['callback_url' => 'https://b.example/cb']]], 'martis.auth.sso.issuer.audiences'],
]);

it('rejects a private key whose public half was swapped', function () {
    $a = stKeys();
    $b = stKeys();
    // libsodium secret key = seed (32) then public key (32): pair a's seed with b's public key.
    $franken = substr($a['secret'], 0, 32).$b['public'];
    config(['martis.auth.sso.issuer.private_key' => base64_encode($franken)]);

    expect(fn () => TokenIssuer::fromConfig()->issue($this->user, 'panel-b', $this->state))
        ->toThrow(InvalidArgumentException::class, 'martis.auth.sso.issuer.private_key');
});

it('allows http for localhost, .localhost, .test, 127.0.0.1 and [::1] callback hosts', function (string $url) {
    config(['martis.auth.sso.issuer.audiences' => ['panel-b' => ['callback_url' => $url]]]);

    $location = (string) stSignedIn()->get(stIssueUrl())->headers->get('Location');

    expect($location)->toStartWith($url);
})->with([
    'http://localhost:8001/martis/sso/panel_a/callback',
    'http://b.localhost/cb',
    'http://panel-b.test/cb',
    'http://127.0.0.1:8198/martis/sso/panel_a/callback',
    'http://[::1]:8198/martis/sso/panel_a/callback',
]);

it('says what to do when the private key is missing', function () {
    config(['martis.auth.sso.issuer.private_key' => '']);

    expect(fn () => TokenIssuer::fromConfig()->issue($this->user, 'panel-b', $this->state))
        ->toThrow(InvalidArgumentException::class, 'martis:sso:keys');
});

it('writes one audit line per token and no token or key material in it', function () {
    $logged = new ArrayObject;
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('info')->andReturnUsing(function (string $message, array $context = []) use ($logged) {
        $logged[] = ['message' => $message, 'context' => $context];
    });
    Log::shouldReceive('channel')->andReturn($logger);

    $location = (string) stSignedIn()->get(stIssueUrl())->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($logged)->toHaveCount(1);
    $context = json_encode($logged[0]['context'], JSON_THROW_ON_ERROR);
    expect($context)->toContain('panel-b')
        ->and($context)->not->toContain($query['token'])
        ->and($context)->not->toContain($this->keys['secret_b64'])
        ->and($context)->not->toContain(bin2hex($this->keys['secret']));
});

// -----------------------------------------------------------------------------
// Who may obtain a token for an audience (audiences.<name>.ability)
// -----------------------------------------------------------------------------

it('issues for any panel user while the audience sets no ability', function () {
    Gate::define('sign-in-to-b', fn () => false);

    stSignedIn()->get(stIssueUrl())->assertStatus(302);
});

it('issues when the ability of the audience allows the user', function () {
    config(['martis.auth.sso.issuer.audiences.panel-b.ability' => 'sign-in-to-b']);
    Gate::define('sign-in-to-b', fn (Authenticatable $user) => $user->getAuthIdentifier() === $this->user->getKey());

    $response = stSignedIn()->get(stIssueUrl());

    $response->assertStatus(302);
    expect((string) $response->headers->get('Location'))->toContain('token=');
});

it('answers 403 with a translated message, issues nothing and redirects nowhere when the ability denies', function () {
    config(['martis.auth.sso.issuer.audiences.panel-b.ability' => 'sign-in-to-b']);
    Gate::define('sign-in-to-b', fn () => false);
    $logged = new ArrayObject;
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('info')->andReturnUsing(function (string $message) use ($logged) {
        $logged[] = $message;
    });
    $logger->shouldReceive('warning')->once();
    Log::shouldReceive('channel')->andReturn($logger);

    $response = stSignedIn()->get(stIssueUrl());

    $response->assertForbidden();
    expect($response->headers->has('Location'))->toBeFalse()
        ->and($response->getContent())->toContain(__('martis::messages.sso_issue_forbidden'))
        ->and($response->getContent())->not->toContain('token=')
        ->and($logged)->toHaveCount(0);
    expect(__('martis::messages.sso_issue_forbidden'))->not->toBe('martis::messages.sso_issue_forbidden');
});

it('applies the ability of the requested audience only', function () {
    config(['martis.auth.sso.issuer.audiences.panel-b.ability' => 'sign-in-to-b']);
    Gate::define('sign-in-to-b', fn () => false);

    stSignedIn()->get(stIssueUrl('panel-b'))->assertForbidden();
    stSignedIn()->get(stIssueUrl('other'))->assertStatus(302);
});

it('checks the ability through the gate of the signed-in user (a Spatie-style permission works)', function () {
    config(['martis.auth.sso.issuer.audiences.panel-b.ability' => 'sso.panel-b']);
    Gate::before(fn ($user, $ability) => $ability === 'sso.panel-b' ? true : null);

    stSignedIn()->get(stIssueUrl())->assertStatus(302);
});

it('throws, naming the key, for an ability that is set but not a non-empty string', function (mixed $ability) {
    config(['martis.auth.sso.issuer.audiences.panel-b.ability' => $ability]);

    expect(fn () => TokenIssuer::fromConfig())
        ->toThrow(InvalidArgumentException::class, 'martis.auth.sso.issuer.audiences.panel-b.ability');
    stSignedIn()->get(stIssueUrl())->assertStatus(500);
})->with(['empty' => [''], 'blank' => ['  '], 'number' => [5], 'array' => [['a']], 'true' => [true]]);

// -----------------------------------------------------------------------------
// While an impersonation is active (review H3)
// -----------------------------------------------------------------------------

/** The session of an operator impersonating $target: the guard holds the target. */
function stImpersonating(User $operator, User $target): mixed
{
    config(['martis.impersonation.enabled' => true]);

    return test()->actingAs($target)->withSession([
        'martis.impersonation' => ['original' => $operator->getKey(), 'target' => $target->getKey(), 'started_at' => now()->toIso8601String(), 'two_factor_passed' => true],
        TwoFactorPass::SESSION_KEY => (string) $target->getKey(),
    ]);
}

it('answers 403 with a translated message and issues nothing while an impersonation is active', function () {
    $bob = StIssuerUser::query()->create(['name' => 'Bob', 'email' => 'bob@a.example', 'password' => Hash::make('x'), 'email_verified_at' => now()]);
    $logged = new ArrayObject;
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('info')->andReturnUsing(function (string $message) use ($logged) {
        $logged[] = $message;
    });
    $logger->shouldReceive('warning')->once();
    Log::shouldReceive('channel')->andReturn($logger);

    $response = stImpersonating($this->user, $bob)->get(stIssueUrl());

    $response->assertForbidden();
    expect($response->headers->has('Location'))->toBeFalse()
        ->and($response->getContent())->toContain(__('martis::messages.sso_issue_impersonating'))
        ->and($response->getContent())->not->toContain('token=')
        ->and($logged)->toHaveCount(0);
    expect(__('martis::messages.sso_issue_impersonating'))->not->toBe('martis::messages.sso_issue_impersonating');
});

it('issues again once the impersonation has ended', function () {
    $bob = StIssuerUser::query()->create(['name' => 'Bob', 'email' => 'bob@a.example', 'password' => Hash::make('x'), 'email_verified_at' => now()]);
    stImpersonating($this->user, $bob)->get(stIssueUrl())->assertForbidden();

    $response = stSignedIn($this->user)->withSession(['martis.impersonation' => null])->get(stIssueUrl());

    $response->assertStatus(302);
    $claims = stClaimsOf((string) $response->headers->get('Location'), $this->keys['public']);
    expect($claims['sub'])->toBe((string) $this->user->getKey());
});

it('keeps the 403 for an impersonation under a custom session key', function () {
    $bob = StIssuerUser::query()->create(['name' => 'Bob', 'email' => 'bob@a.example', 'password' => Hash::make('x'), 'email_verified_at' => now()]);
    config(['martis.impersonation.enabled' => true, 'martis.impersonation.session_key' => 'custom.impersonation']);

    test()->actingAs($bob)->withSession([
        'custom.impersonation' => ['original' => $this->user->getKey(), 'target' => $bob->getKey(), 'started_at' => now()->toIso8601String(), 'two_factor_passed' => true],
        TwoFactorPass::SESSION_KEY => (string) $bob->getKey(),
    ])->get(stIssueUrl())->assertForbidden();
});

// -----------------------------------------------------------------------------
// email_verified (review M3)
// -----------------------------------------------------------------------------

it('signs email_verified true for a user model that does not ask for verification', function () {
    $response = stSignedIn()->get(stIssueUrl());

    expect(stClaimsOf((string) $response->headers->get('Location'), $this->keys['public']))->toHaveKey('email_verified', true);
});

it('signs the verification state of a user model that asks for it', function (bool $verified) {
    config(['auth.providers.users.model' => StIssuerVerifyingUser::class]);
    $user = StIssuerVerifyingUser::query()->findOrFail($this->user->getKey());
    $user->forceFill(['email_verified_at' => $verified ? now() : null])->save();

    $token = TokenIssuer::fromConfig()->issue($user, 'panel-b', $this->state);
    $claims = json_decode(PasetoV4Public::parse($token)->message, true, 8, JSON_THROW_ON_ERROR);

    expect($claims['email_verified'])->toBe($verified);
})->with([true, false]);

it('does not let a claims class set email_verified', function () {
    config(['martis.auth.sso.issuer.claims' => StIssuerClaims::class]);
    StIssuerClaims::$result = ['email_verified' => true];

    expect(fn () => TokenIssuer::fromConfig()->issue($this->user, 'panel-b', $this->state))
        ->toThrow(InvalidArgumentException::class, 'email_verified');
});

// -----------------------------------------------------------------------------
// The environment claim is the issuer's alone (review D4)
// -----------------------------------------------------------------------------

it('reserves the environment claim even when the issuer sets no environment', function () {
    config(['martis.auth.sso.issuer.claims' => StIssuerClaims::class, 'martis.auth.sso.issuer.environment' => null]);
    StIssuerClaims::$result = ['env' => 'production'];

    expect(fn () => TokenIssuer::fromConfig()->issue($this->user, 'panel-b', $this->state))
        ->toThrow(InvalidArgumentException::class, 'martis.auth.sso.issuer.claims');
});

it('throws, naming the key and saying it was removed, for an environment_claim (the claim is always env)', function (?string $environment) {
    config(['martis.auth.sso.issuer.environment' => $environment, 'martis.auth.sso.issuer.environment_claim' => 'deployment']);

    expect(fn () => TokenIssuer::fromConfig())
        ->toThrow(InvalidArgumentException::class, 'martis.auth.sso.issuer.environment_claim')
        ->and(fn () => TokenIssuer::fromConfig())
        ->toThrow(InvalidArgumentException::class, 'removed in v2.22.0');
})->with(['no environment' => [null], 'an environment' => ['staging']]);

it('accepts an unset environment_claim, with or without an environment', function () {
    config(['martis.auth.sso.issuer.environment' => 'staging', 'martis.auth.sso.issuer.environment_claim' => null]);
    expect(TokenIssuer::fromConfig())->toBeInstanceOf(TokenIssuer::class);

    config(['martis.auth.sso.issuer.environment' => null]);
    expect(TokenIssuer::fromConfig())->toBeInstanceOf(TokenIssuer::class);
});

// -----------------------------------------------------------------------------
// Audit events and the denial log (v2.22.0)
// -----------------------------------------------------------------------------

/**
 * Capture the lines of the default channel's logger: [level, message, context].
 */
function stCaptureLog(): ArrayObject
{
    $lines = new ArrayObject;
    $logger = Mockery::mock(LoggerInterface::class);
    foreach (['info', 'warning'] as $level) {
        $logger->shouldReceive($level)->andReturnUsing(function (string $message, array $context = []) use ($lines, $level) {
            $lines[] = ['level' => $level, 'message' => $message, 'context' => $context];
        });
    }
    Log::shouldReceive('channel')->andReturn($logger);

    return $lines;
}

it('dispatches SsoTokenIssued with the user, the audience and the request id of the log line', function () {
    Event::fake([SsoTokenIssued::class, SsoIssueDenied::class]);
    $lines = stCaptureLog();

    $response = stSignedIn()->withHeaders(['X-Request-Id' => 'req-123'])->get(stIssueUrl());
    $response->assertStatus(302);

    Event::assertDispatched(SsoTokenIssued::class, fn (SsoTokenIssued $e) => $e->user->is($this->user)
        && $e->audience === 'panel-b'
        && $e->requestId === 'req-123');
    Event::assertNotDispatched(SsoIssueDenied::class);
    expect($lines)->toHaveCount(1)
        ->and($lines[0]['level'])->toBe('info')
        ->and($lines[0]['message'])->toBe('SSO token issued')
        ->and($lines[0]['context']['request_id'])->toBe('req-123');
});

it('generates a request id when the request has none, the same one for the event and the line', function () {
    Event::fake([SsoTokenIssued::class]);
    $lines = stCaptureLog();

    stSignedIn()->get(stIssueUrl())->assertStatus(302);

    Event::assertDispatched(SsoTokenIssued::class, fn (SsoTokenIssued $e) => $e->requestId === $lines[0]['context']['request_id']
        && preg_match('/\A[0-9a-f-]{36}\z/', $e->requestId) === 1);
});

it('cuts a long X-Request-Id to 128 characters in the event and the line', function () {
    Event::fake([SsoTokenIssued::class]);
    $lines = stCaptureLog();

    stSignedIn()->withHeaders(['X-Request-Id' => str_repeat('r', 300)])->get(stIssueUrl())->assertStatus(302);

    Event::assertDispatched(SsoTokenIssued::class, fn (SsoTokenIssued $e) => strlen($e->requestId) === 128);
    expect(strlen($lines[0]['context']['request_id']))->toBe(128);
});

it('dispatches SsoIssueDenied and logs a warning for each reason, with the answer unchanged', function (
    Closure $arrange,
    int $status,
    string $reason,
) {
    Event::fake([SsoTokenIssued::class, SsoIssueDenied::class]);
    $lines = stCaptureLog();

    $response = $arrange($this);
    expect($response->status())->toBe($status);

    Event::assertNotDispatched(SsoTokenIssued::class);
    Event::assertDispatchedTimes(SsoIssueDenied::class, 1);
    Event::assertDispatched(SsoIssueDenied::class, function (SsoIssueDenied $e) use ($reason, $lines) {
        return $e->reason === $reason
            && $e->audience === ($reason === SsoIssueDenied::UNKNOWN_AUDIENCE ? 'nobody' : 'panel-b')
            && ($reason === SsoIssueDenied::IMPERSONATING || $e->user?->is($this->user) === true)
            && $e->requestId === 'req-deny'
            && $lines[0]['context']['request_id'] === $e->requestId;
    });

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['level'])->toBe('warning')
        ->and($lines[0]['message'])->toBe('SSO token issue denied')
        ->and($lines[0]['context']['reason'])->toBe($reason)
        ->and($lines[0]['context']['user_id'])->not->toBeNull();
    // Nothing of a token or of the caller's state goes into the log line.
    expect(json_encode($lines[0]['context']))->not->toContain($this->state)->not->toContain('token');
})->with([
    'unknown_audience' => [
        fn ($t) => stSignedIn()->withHeaders(['X-Request-Id' => 'req-deny'])->get(stIssueUrl('nobody')),
        404,
        SsoIssueDenied::UNKNOWN_AUDIENCE,
    ],
    'not_navigation' => [
        fn ($t) => stSignedIn()->withHeaders(['X-Request-Id' => 'req-deny', 'Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Dest' => 'empty'])->get(stIssueUrl()),
        403,
        SsoIssueDenied::NOT_NAVIGATION,
    ],
    'invalid_state' => [
        fn ($t) => stSignedIn()->withHeaders(['X-Request-Id' => 'req-deny'])->get(stIssueUrl('panel-b', 'short')),
        422,
        SsoIssueDenied::INVALID_STATE,
    ],
    'forbidden' => [
        function ($t) {
            config(['martis.auth.sso.issuer.audiences.panel-b.ability' => 'sign-in-to-b']);
            Gate::define('sign-in-to-b', fn () => false);

            return stSignedIn()->withHeaders(['X-Request-Id' => 'req-deny'])->get(stIssueUrl());
        },
        403,
        SsoIssueDenied::FORBIDDEN,
    ],
    'impersonating' => [
        function ($t) {
            $bob = StIssuerUser::query()->create(['name' => 'Bob', 'email' => 'bob@a.example', 'password' => Hash::make('x'), 'email_verified_at' => now()]);

            return stImpersonating($t->user, $bob)->withHeaders(['X-Request-Id' => 'req-deny'])->get(stIssueUrl());
        },
        403,
        SsoIssueDenied::IMPERSONATING,
    ],
]);

it('names the impersonated user, the one the guard holds, in the denial of an impersonation', function () {
    $bob = StIssuerUser::query()->create(['name' => 'Bob', 'email' => 'bob@a.example', 'password' => Hash::make('x'), 'email_verified_at' => now()]);
    Event::fake([SsoIssueDenied::class]);
    stCaptureLog();

    stImpersonating($this->user, $bob)->get(stIssueUrl())->assertForbidden();

    Event::assertDispatched(SsoIssueDenied::class, fn (SsoIssueDenied $e) => $e->reason === 'impersonating' && $e->user?->is($bob) === true);
});

it('stays silent for a disabled issuer: a 404, no event and no log line', function () {
    config(['martis.auth.sso.issuer.enabled' => false]);
    Event::fake([SsoTokenIssued::class, SsoIssueDenied::class]);
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldNotReceive('info');
    $logger->shouldNotReceive('warning');
    Log::shouldReceive('channel')->andReturn($logger);

    stSignedIn()->get(stIssueUrl('nobody'))->assertNotFound();
    stSignedIn()->get(stIssueUrl())->assertNotFound();

    Event::assertNotDispatched(SsoIssueDenied::class);
    Event::assertNotDispatched(SsoTokenIssued::class);
});

it('does not dispatch for an audience name the route constraint already rejects', function () {
    Event::fake([SsoIssueDenied::class]);

    // The SPA fallback answers it, never the issuer controller.
    $response = stSignedIn()->get('/martis/sso/issue/'.str_repeat('a', 65).'?state='.$this->state);
    expect((string) $response->headers->get('Location'))->not->toContain('token=');

    Event::assertNotDispatched(SsoIssueDenied::class);
});

it('cuts a hostile X-Request-Id to printable ASCII and 128 bytes in the event and the line', function () {
    Event::fake([SsoTokenIssued::class]);
    $lines = stCaptureLog();

    stSignedIn()->withHeaders(['X-Request-Id' => "r\x1b[31m\xff".str_repeat('q', 300)])->get(stIssueUrl())->assertStatus(302);

    Event::assertDispatched(SsoTokenIssued::class, fn (SsoTokenIssued $e) => preg_match('/\A[\x20-\x7E]{1,128}\z/', $e->requestId) === 1 && str_starts_with($e->requestId, 'r?[31m?qqq'));
    expect($lines[0]['context']['request_id'])->toBe(Event::dispatched(SsoTokenIssued::class)->first()[0]->requestId);
});

it('dispatches SsoIssueDenied with cannot_sign, logs the denial and keeps the failure, when signing fails', function (Closure $arrange, string $exception) {
    $arrange($this);
    Event::fake([SsoTokenIssued::class, SsoIssueDenied::class]);
    $lines = stCaptureLog();

    expect(fn () => stSignedIn()->withoutExceptionHandling()->withHeaders(['X-Request-Id' => 'req-sign'])->get(stIssueUrl()))
        ->toThrow($exception);

    Event::assertNotDispatched(SsoTokenIssued::class);
    Event::assertDispatchedTimes(SsoIssueDenied::class, 1);
    Event::assertDispatched(SsoIssueDenied::class, fn (SsoIssueDenied $e) => $e->reason === 'cannot_sign'
        && $e->audience === 'panel-b' && $e->user?->is($this->user) === true && $e->requestId === 'req-sign');
    expect($lines)->toHaveCount(1)
        ->and($lines[0]['level'])->toBe('warning')
        ->and($lines[0]['context']['reason'])->toBe('cannot_sign')
        ->and($lines[0]['context']['request_id'])->toBe('req-sign')
        ->and(json_encode($lines[0]['context']))->not->toContain($this->state);
})->with([
    'a user with no email' => [
        function ($t) {
            config(['martis.auth.sso.issuer.claims' => StIssuerClaims::class]);
            StIssuerClaims::$result = ['name' => 'No email', 'email' => ''];
        },
        RuntimeException::class,
    ],
    'an issuer block that is unusable' => [
        fn ($t) => config(['martis.auth.sso.issuer.ttl_seconds' => 0]),
        InvalidArgumentException::class,
    ],
]);

it('answers 500 to a failing signature, as before', function () {
    config(['martis.auth.sso.issuer.claims' => StIssuerClaims::class]);
    StIssuerClaims::$result = ['name' => 'No email', 'email' => ''];
    Event::fake([SsoIssueDenied::class]);

    stSignedIn()->get(stIssueUrl())->assertStatus(500);
});

it('puts neither the token nor the state into the issued event or the log line', function () {
    Event::fake([SsoTokenIssued::class]);
    $lines = stCaptureLog();

    $location = (string) stSignedIn()->get(stIssueUrl())->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    $dump = json_encode([$lines->getArrayCopy()], JSON_THROW_ON_ERROR);
    expect($dump)->not->toContain($query['token'])->not->toContain($this->state);
    Event::assertDispatched(SsoTokenIssued::class, function (SsoTokenIssued $e) use ($query) {
        $dump = json_encode(['audience' => $e->audience, 'request_id' => $e->requestId]);

        return ! str_contains($dump, $query['token']) && ! str_contains($dump, $this->state);
    });
});
