<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Martis\Sso\Providers\AzureProvider;
use Martis\Sso\Providers\SignedTokenProvider;
use Martis\Sso\SignedToken\PasetoV4Public;
use Martis\Sso\SignedToken\Reason;
use Martis\Sso\SsoException;
use Martis\Sso\SsoIdentity;
use Martis\Sso\SsoManager;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Support/signed-token.php';

beforeEach(function () {
    $this->keys = stKeys();
    stConsumer([$this->keys['public_b64']]);
    $this->app->make(SsoManager::class)->flushHooksForTesting();
    Cache::flush();
});

/** The provider of the block stConsumer() configured. */
function stProvider(string $name = 'panel_a'): SignedTokenProvider
{
    $provider = app(SsoManager::class)->driver($name);
    expect($provider)->toBeInstanceOf(SignedTokenProvider::class);

    return $provider;
}

/** The reason an attempt is refused with. */
function stReason(SignedTokenProvider $provider, $session, string $token, ?string $state): string
{
    try {
        $provider->resolveIdentity(stCallback($session, $token, $state));
    } catch (SsoException $e) {
        return $e->reason;
    }

    return 'accepted';
}

// -----------------------------------------------------------------------------
// Driver resolution (SsoManager)
// -----------------------------------------------------------------------------

it('resolves a provider block by its driver and gives the instance its configured name', function () {
    stConsumer([$this->keys['public_b64']], 'panel_a');
    stConsumer([$this->keys['public_b64']], 'second_panel');

    $manager = app(SsoManager::class);

    expect($manager->driver('panel_a'))->toBeInstanceOf(SignedTokenProvider::class)
        ->and($manager->driver('panel_a')->name())->toBe('panel_a')
        ->and($manager->driver('second_panel')->name())->toBe('second_panel')
        ->and($manager->enabledProviders())->toContain('panel_a', 'second_panel');
});

it('keeps extend() registrations ahead of the driver of a block', function () {
    $manager = app(SsoManager::class);
    $manager->extend('panel_a', AzureProvider::class);

    expect($manager->driver('panel_a'))->toBeInstanceOf(AzureProvider::class);
});

it('does not resolve a disabled block, an unknown driver or another name of the azure driver', function () {
    $manager = app(SsoManager::class);

    config()->set('martis.auth.sso.providers.panel_a.enabled', false);
    expect($manager->driver('panel_a'))->toBeNull();

    config()->set('martis.auth.sso.providers.panel_a.enabled', true);
    config()->set('martis.auth.sso.providers.panel_a.driver', 'nonsense');
    expect($manager->driver('panel_a'))->toBeNull();

    // The Azure provider reads providers.azure.*, so another name would run on the wrong block.
    config()->set('martis.auth.sso.providers.panel_a.driver', 'azure');
    expect($manager->driver('panel_a'))->toBeNull();
    expect($manager->enabledProviders())->not->toContain('panel_a');
});

// -----------------------------------------------------------------------------
// redirect()
// -----------------------------------------------------------------------------

it('redirects to authorize_url with the audience and a fresh state, and nothing else', function () {
    $flow = stBegin(stProvider());

    parse_str((string) parse_url($flow['location'], PHP_URL_QUERY), $query);
    expect($flow['location'])->toStartWith('https://a.example/martis/sso/issue/panel-b?')
        ->and(array_keys($query))->toBe(['audience', 'state'])
        ->and($query['audience'])->toBe('https://b.example')
        ->and($query['state'])->toMatch('/^[A-Za-z0-9_-]{43,128}$/');

    $again = stBegin(stProvider());
    expect($again['state'])->not->toBe($flow['state']);
});

it('keeps the query string the authorize_url already has', function () {
    stConsumer([$this->keys['public_b64']], 'panel_a', ['authorize_url' => 'https://a.example/issue?tenant=7']);

    $flow = stBegin(stProvider());
    parse_str((string) parse_url($flow['location'], PHP_URL_QUERY), $query);

    expect($query)->toHaveKeys(['tenant', 'audience', 'state'])->and($query['tenant'])->toBe('7');
});

it('keeps the state of one provider apart from another', function () {
    stConsumer([$this->keys['public_b64']], 'second_panel');
    $session = stSession();

    $first = stBegin(stProvider('panel_a'), $session);
    $second = stBegin(stProvider('second_panel'), $session);

    expect($first['state'])->not->toBe($second['state']);

    $token = stSign(stClaims($first['state']), $this->keys['secret']);
    expect(stReason(stProvider('panel_a'), $session, $token, $first['state']))->toBe('accepted');
    // The second provider's state is untouched by the first provider's sign-in.
    $token = stSign(stClaims($second['state']), $this->keys['secret']);
    expect(stReason(stProvider('second_panel'), $session, $token, $second['state']))->toBe('accepted');
});

// -----------------------------------------------------------------------------
// resolveIdentity(): the happy path
// -----------------------------------------------------------------------------

it('turns a good token into an identity: sub, email, name and the roles claim', function () {
    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state'], ['roles' => ['PMI ADMIN', 'PMI EDITOR']]), $this->keys['secret']);

    $identity = stProvider()->resolveIdentity(stCallback($flow['session'], $token, $flow['state']));

    expect($identity)->toBeInstanceOf(SsoIdentity::class)
        ->and($identity->provider)->toBe('panel_a')
        ->and($identity->externalId)->toBe('42')
        ->and($identity->email)->toBe('person@example.com')
        ->and($identity->name)->toBe('Some Person')
        ->and($identity->externalRoles)->toBe(['PMI ADMIN', 'PMI EDITOR'])
        ->and($identity->accessToken)->toBeNull()
        ->and(json_encode($identity->raw))->not->toContain($token);
});

it('reads the roles from the configured role_claim and has no roles when the claim is absent', function () {
    stConsumer([$this->keys['public_b64']], 'panel_a', ['role_claim' => 'groups']);

    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state'], ['roles' => null, 'groups' => ['G1']]), $this->keys['secret']);
    expect(stProvider()->resolveIdentity(stCallback($flow['session'], $token, $flow['state']))->externalRoles)->toBe(['G1']);

    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state'], ['roles' => ['ignored'], 'groups' => null]), $this->keys['secret']);
    expect(stProvider()->resolveIdentity(stCallback($flow['session'], $token, $flow['state']))->externalRoles)->toBe([]);
});

// -----------------------------------------------------------------------------
// One reason per refusal
// -----------------------------------------------------------------------------

it('refuses a token that is not well formed with the malformed reason', function (string $token) {
    $flow = stBegin(stProvider());

    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe(Reason::MALFORMED);
})->with([
    'garbage' => ['not-a-token'],
    'a v3 token' => ['v3.public.AAAA'],
    'empty' => [''],
]);

it('refuses a callback without a token or with a token that is not a string', function () {
    $flow = stBegin(stProvider());
    $provider = stProvider();

    $none = Request::create('/cb', 'GET', ['state' => $flow['state']]);
    $none->setLaravelSession($flow['session']);
    $array = Request::create('/cb', 'GET', ['state' => $flow['state'], 'token' => ['a' => 'b']]);
    $array->setLaravelSession($flow['session']);

    foreach ([$none, $array] as $request) {
        expect(fn () => $provider->resolveIdentity($request))
            ->toThrow(fn (SsoException $e) => expect($e->reason)->toBe(Reason::MALFORMED));
    }
});

it('refuses a payload that is not a JSON object even when it is signed', function (string $payload) {
    $flow = stBegin(stProvider());
    $token = PasetoV4Public::sign($payload, $this->keys['secret']);

    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe(Reason::MALFORMED);
})->with([
    'a list' => ['[1,2,3]'],
    'a string' => ['"text"'],
    'a number' => ['7'],
    'not json' => ['not json at all'],
    'empty' => [''],
]);

it('refuses a tampered token and one signed by another key with the bad_signature reason', function () {
    $flow = stBegin(stProvider());
    $good = stSign(stClaims($flow['state']), $this->keys['secret']);

    $other = stKeys();
    $foreign = stSign(stClaims($flow['state']), $other['secret']);
    expect(stReason(stProvider(), $flow['session'], $foreign, $flow['state']))->toBe(Reason::BAD_SIGNATURE);

    // Flip one bit of the payload.
    $flow = stBegin(stProvider());
    $good = stSign(stClaims($flow['state']), $this->keys['secret']);
    [$body, $footer] = explode('.', substr($good, strlen('v4.public.')), 2);
    $raw = sodium_base642bin($body, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    $raw[5] = $raw[5] ^ "\x01";
    $tampered = 'v4.public.'.sodium_bin2base64($raw, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING).'.'.$footer;
    expect(stReason(stProvider(), $flow['session'], $tampered, $flow['state']))->toBe(Reason::BAD_SIGNATURE);
});

it('refuses a token that names another issuer or audience', function (string $claim, string $reason) {
    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state'], [$claim => 'https://elsewhere.example']), $this->keys['secret']);

    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe($reason);
})->with([
    'issuer' => ['iss', Reason::ISSUER],
    'audience' => ['aud', Reason::AUDIENCE],
]);

it('refuses an expired token beyond the clock skew and accepts one inside it', function () {
    $now = time();

    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state'], ['iat' => stTime($now - 50), 'exp' => stTime($now - 31)]), $this->keys['secret']);
    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe(Reason::EXPIRED);

    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state'], ['iat' => stTime($now - 50), 'exp' => stTime($now - 10)]), $this->keys['secret']);
    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe('accepted');
});

it('refuses a token issued in the future beyond the clock skew and accepts one inside it', function () {
    $now = time();

    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state'], ['iat' => stTime($now + 120), 'exp' => stTime($now + 180)]), $this->keys['secret']);
    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe(Reason::NOT_YET_VALID);

    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state'], ['iat' => stTime($now + 10), 'exp' => stTime($now + 70)]), $this->keys['secret']);
    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe('accepted');
});

it('refuses a token older than max_age_seconds even when its exp is far away', function () {
    $now = time();

    $flow = stBegin(stProvider());
    // 100 s old: max_age 60 + skew 30 = 90.
    $token = stSign(stClaims($flow['state'], ['iat' => stTime($now - 100), 'exp' => stTime($now + 3600)]), $this->keys['secret']);
    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe(Reason::EXPIRED);

    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state'], ['iat' => stTime($now - 80), 'exp' => stTime($now + 3600)]), $this->keys['secret']);
    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe('accepted');
});

it('honours max_age_seconds and clock_skew_seconds from the block', function () {
    stConsumer([$this->keys['public_b64']], 'panel_a', ['max_age_seconds' => 10, 'clock_skew_seconds' => 0]);
    $now = time();

    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state'], ['iat' => stTime($now - 15), 'exp' => stTime($now + 60)]), $this->keys['secret']);
    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe(Reason::EXPIRED);
});

it('refuses a staging token on a production verifier and a token without the environment claim', function () {
    stConsumer([$this->keys['public_b64']], 'panel_a', ['environment' => 'production']);

    $flow = stBegin(stProvider());
    $staging = stSign(stClaims($flow['state'], ['env' => 'staging']), $this->keys['secret']);
    expect(stReason(stProvider(), $flow['session'], $staging, $flow['state']))->toBe(Reason::ENVIRONMENT);

    $flow = stBegin(stProvider());
    $none = stSign(stClaims($flow['state']), $this->keys['secret']);
    expect(stReason(stProvider(), $flow['session'], $none, $flow['state']))->toBe(Reason::ENVIRONMENT);

    $flow = stBegin(stProvider());
    $production = stSign(stClaims($flow['state'], ['env' => 'production']), $this->keys['secret']);
    expect(stReason(stProvider(), $flow['session'], $production, $flow['state']))->toBe('accepted');
});

it('reads the environment from environment_claim, and does not lock when no environment is configured', function () {
    stConsumer([$this->keys['public_b64']], 'panel_a', ['environment' => 'production', 'environment_claim' => 'deployment']);

    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state'], ['deployment' => 'production']), $this->keys['secret']);
    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe('accepted');

    stConsumer([$this->keys['public_b64']]);
    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state'], ['env' => 'staging']), $this->keys['secret']);
    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe('accepted');
});

it('refuses a state that does not match the callback query, or the claim, or the session', function () {
    // Query parameter differs.
    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state']), $this->keys['secret']);
    expect(stReason(stProvider(), $flow['session'], $token, str_repeat('x', 43)))->toBe(Reason::STATE);

    // No query parameter at all.
    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state']), $this->keys['secret']);
    expect(stReason(stProvider(), $flow['session'], $token, null))->toBe(Reason::STATE);

    // The claim differs (a token issued for another browser session).
    $flow = stBegin(stProvider());
    $token = stSign(stClaims(str_repeat('y', 43)), $this->keys['secret']);
    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe(Reason::STATE);

    // The browser session never started the flow.
    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state']), $this->keys['secret']);
    $stranger = stSession();
    expect(stReason(stProvider(), $stranger, $token, $flow['state']))->toBe(Reason::STATE);
});

it('pulls the state from the session once', function () {
    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state']), $this->keys['secret']);

    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe('accepted');
    expect($flow['session']->has('martis_sso_state.panel_a'))->toBeFalse();
    // The same token and state again: no state left in the session.
    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe(Reason::STATE);
});

it('refuses a replayed jti', function () {
    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state']), $this->keys['secret']);

    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe('accepted');

    // The browser session starts a second flow with the same state, and the
    // same token comes back: only the one-time jti stops it.
    $flow['session']->put('martis_sso_state.panel_a', $flow['state']);
    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe(Reason::REPLAYED);
});

it('scopes the one-time jti to the provider', function () {
    stConsumer([$this->keys['public_b64']], 'second_panel');
    $session = stSession();
    $first = stBegin(stProvider('panel_a'), $session);

    $claims = stClaims($first['state']);
    $token = stSign($claims, $this->keys['secret']);
    expect(stReason(stProvider('panel_a'), $session, $token, $first['state']))->toBe('accepted');

    // Same jti on another provider's flow is that provider's own business.
    $second = stBegin(stProvider('second_panel'), $session);
    $token = stSign(stClaims($second['state'], ['jti' => $claims['jti']]), $this->keys['secret']);
    expect(stReason(stProvider('second_panel'), $session, $token, $second['state']))->toBe('accepted');
});

it('does not burn the jti of a token the verifier rejected', function () {
    $secret = $this->keys['secret'];

    $flow = stBegin(stProvider());
    $claims = stClaims($flow['state'], ['env' => 'staging']);
    $token = stSign($claims, $secret);

    stConsumer([$this->keys['public_b64']], 'panel_a', ['environment' => 'production']);
    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe(Reason::ENVIRONMENT);

    // Same token, verifier no longer locked: the jti was never recorded.
    stConsumer([$this->keys['public_b64']]);
    $flow['session']->put('martis_sso_state.panel_a', $flow['state']);
    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe('accepted');
});

it('does not burn the jti of a token refused for its state', function () {
    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state']), $this->keys['secret']);

    expect(stReason(stProvider(), $flow['session'], $token, str_repeat('x', 43)))->toBe(Reason::STATE);

    // The person starts over with the same state and the same token: the jti was never recorded.
    $flow['session']->put('martis_sso_state.panel_a', $flow['state']);
    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe('accepted');
});

it('checks the signature before any claim', function () {
    $flow = stBegin(stProvider());
    $now = time();
    $forged = stSign(
        stClaims($flow['state'], ['iss' => 'https://evil.example', 'aud' => 'x', 'exp' => stTime($now - 9999), 'iat' => stTime($now + 9999)]),
        stKeys()['secret'],
    );

    expect(stReason(stProvider(), $flow['session'], $forged, $flow['state']))->toBe(Reason::BAD_SIGNATURE);
});

it('refuses when the one-time store fails (fail closed) with its own reason', function () {
    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state']), $this->keys['secret']);

    Cache::shouldReceive('add')->once()->andThrow(new RuntimeException('redis is down'));

    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe(Reason::REPLAY_STORE);
});

it('refuses when the one-time store answers that it did not add the key', function () {
    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state']), $this->keys['secret']);

    Cache::shouldReceive('add')->once()->andReturn(false);

    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe(Reason::REPLAYED);
});

it('asks the cache to add the jti for the time the token can still be used', function () {
    $flow = stBegin(stProvider());
    $now = time();
    $token = stSign(stClaims($flow['state'], ['iat' => stTime($now), 'exp' => stTime($now + 60)]), $this->keys['secret']);

    Cache::shouldReceive('add')->once()->withArgs(function (string $key, mixed $value, mixed $ttl): bool {
        // min(exp, iat + max_age) + skew = 60 + 30 seconds, give or take the clock.
        return str_starts_with($key, 'martis_sso_jti:panel_a:')
            && $value === true
            && is_int($ttl) && $ttl >= 88 && $ttl <= 91;
    })->andReturn(true);

    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe('accepted');
});

it('refuses a token that lacks a required claim with the missing_claim reason', function (string $claim) {
    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state'], [$claim => null]), $this->keys['secret']);

    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe(Reason::MISSING_CLAIM);
})->with(['iss', 'aud', 'sub', 'iat', 'exp', 'jti', 'state', 'email', 'email_verified']);

it('counts an empty required claim as missing', function (string $claim) {
    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state'], [$claim => '']), $this->keys['secret']);

    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe(Reason::MISSING_CLAIM);
})->with(['iss', 'aud', 'sub', 'jti', 'email']);

it('refuses a claim of the wrong type or format as malformed', function (string $claim, mixed $value) {
    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state'], [$claim => $value]), $this->keys['secret']);

    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe(Reason::MALFORMED);
})->with([
    'sub as a number' => ['sub', 42],
    'sub as an array' => ['sub', ['42']],
    'iat as a number' => ['iat', 1700000000],
    'iat that is not a date' => ['iat', 'yesterday'],
    'exp with no zone' => ['exp', '2030-01-01T00:00:00'],
    'exp that is not a real day' => ['exp', '2030-02-31T00:00:00+00:00'],
    'roles as a string' => ['roles', 'admin'],
    'roles with a number' => ['roles', ['admin', 3]],
    'roles as an object' => ['roles', ['a' => 'admin']],
    'name as a number' => ['name', 5],
    'jti that is not a string' => ['jti', 12345],
]);

it('accepts a token without a name', function () {
    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state'], ['name' => null]), $this->keys['secret']);

    $identity = stProvider()->resolveIdentity(stCallback($flow['session'], $token, $flow['state']));

    expect($identity->name)->toBeNull();
});

// -----------------------------------------------------------------------------
// Key rotation
// -----------------------------------------------------------------------------

it('accepts a token signed by either of two configured keys and refuses a third', function () {
    $old = $this->keys;
    $new = stKeys();
    $third = stKeys();
    stConsumer([$old['public_b64'], $new['public_b64']]);

    foreach ([$old, $new] as $keys) {
        $flow = stBegin(stProvider());
        $token = stSign(stClaims($flow['state']), $keys['secret']);
        expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe('accepted');
    }

    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state']), $third['secret']);
    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe(Reason::BAD_SIGNATURE);
});

it('tries every configured key for a token whose footer carries no kid', function () {
    $old = $this->keys;
    $new = stKeys();
    stConsumer([$old['public_b64'], $new['public_b64']]);

    foreach ([$old, $new] as $keys) {
        $flow = stBegin(stProvider());
        $token = stSign(stClaims($flow['state']), $keys['secret'], '{"note":"no kid"}');
        expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe('accepted');
    }

    // And a token with no footer at all.
    $flow = stBegin(stProvider());
    $token = PasetoV4Public::sign(json_encode(stClaims($flow['state']), JSON_THROW_ON_ERROR), $new['secret']);
    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe('accepted');
});

it('refuses a kid that matches no configured key, even with a valid signature of a key it was not told about', function () {
    $other = stKeys();
    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state']), $other['secret']);

    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe(Reason::BAD_SIGNATURE);
});

it('refuses a footer that is not a JSON object as malformed', function () {
    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state']), $this->keys['secret'], 'not json');

    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe(Reason::MALFORMED);
});

// -----------------------------------------------------------------------------
// Config that is set but unusable throws and names the key
// -----------------------------------------------------------------------------

it('throws, naming the config key, for a block that is set but unusable', function (array $override, string $key) {
    stConsumer([$this->keys['public_b64']], 'panel_a', $override);
    $session = stSession();

    // redirect() needs the url and the audience; resolveIdentity() builds the whole block first.
    $attempt = function () use ($session) {
        $provider = stProvider();
        stBegin($provider, $session);
        $provider->resolveIdentity(stCallback($session, 'v4.public.x', 'x'));
    };

    expect($attempt)->toThrow(InvalidArgumentException::class, $key);
})->with([
    'no authorize_url' => [['authorize_url' => null], 'martis.auth.sso.providers.panel_a.authorize_url'],
    'relative authorize_url' => [['authorize_url' => '/issue'], 'martis.auth.sso.providers.panel_a.authorize_url'],
    'http authorize_url on a real host' => [['authorize_url' => 'http://a.example/issue'], 'martis.auth.sso.providers.panel_a.authorize_url'],
    'no public_keys' => [['public_keys' => null], 'martis.auth.sso.providers.panel_a.public_keys'],
    'empty public_keys' => [['public_keys' => []], 'martis.auth.sso.providers.panel_a.public_keys'],
    'public_keys as a string' => [['public_keys' => 'abc'], 'martis.auth.sso.providers.panel_a.public_keys'],
    'public key that is not base64' => [['public_keys' => ['!!!']], 'martis.auth.sso.providers.panel_a.public_keys'],
    'public key of the wrong length' => [['public_keys' => ['c2hvcnQ=']], 'martis.auth.sso.providers.panel_a.public_keys'],
    'no issuer' => [['issuer' => null], 'martis.auth.sso.providers.panel_a.issuer'],
    'no audience' => [['audience' => ''], 'martis.auth.sso.providers.panel_a.audience'],
    'max_age_seconds zero' => [['max_age_seconds' => 0], 'martis.auth.sso.providers.panel_a.max_age_seconds'],
    'max_age_seconds not a number' => [['max_age_seconds' => 'soon'], 'martis.auth.sso.providers.panel_a.max_age_seconds'],
    'negative clock_skew_seconds' => [['clock_skew_seconds' => -1], 'martis.auth.sso.providers.panel_a.clock_skew_seconds'],
    'empty role_claim' => [['role_claim' => ''], 'martis.auth.sso.providers.panel_a.role_claim'],
    'empty environment' => [['environment' => ''], 'martis.auth.sso.providers.panel_a.environment'],
    'empty environment_claim' => [['environment_claim' => ''], 'martis.auth.sso.providers.panel_a.environment_claim'],
]);

it('accepts the numeric strings an env() value gives for the numbers', function () {
    stConsumer([$this->keys['public_b64']], 'panel_a', ['max_age_seconds' => '60', 'clock_skew_seconds' => '30']);

    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state']), $this->keys['secret']);

    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe('accepted');
});

it('says what to do when ext-sodium is missing', function () {
    $script = __DIR__.'/../Support/without-sodium.php';
    $process = new Process([PHP_BINARY, '-n', $script]);
    $process->run();
    $out = trim($process->getOutput());

    if ($out === 'SODIUM_LOADED') {
        $this->markTestSkipped('This PHP has sodium built in, so it cannot be started without it.');
    }

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($out)->toContain('ext-sodium')->toContain('docs/sso.md');
});

// -----------------------------------------------------------------------------
// email_verified (review M3)
// -----------------------------------------------------------------------------

it('refuses an email_verified that is not a boolean as malformed', function (mixed $value) {
    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state'], ['email_verified' => $value]), $this->keys['secret']);

    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe(Reason::MALFORMED);
})->with(['a string' => ['true'], 'a number' => [1], 'an array' => [[true]], 'empty string' => ['']]);

it('carries the verification state of the issuer on the identity', function (bool $verified) {
    $provider = stProvider();
    $flow = stBegin($provider);
    $token = stSign(stClaims($flow['state'], ['email_verified' => $verified]), $this->keys['secret']);

    $identity = $provider->resolveIdentity(stCallback($flow['session'], $token, $flow['state']));

    expect($identity->emailVerified)->toBe($verified);
})->with([true, false]);
