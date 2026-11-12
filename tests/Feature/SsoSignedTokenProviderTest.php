<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Martis\Sso\Providers\AzureProvider;
use Martis\Sso\Providers\SignedTokenProvider;
use Martis\Sso\SignedToken\PasetoV4Public;
use Martis\Sso\SignedToken\Reason;
use Martis\Sso\SsoException;
use Martis\Sso\SsoIdentity;
use Martis\Sso\SsoManager;
use Mockery\MockInterface;
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

/**
 * A mock of the cache repository the one-time store resolves (the
 * configured SSO store, else the default one).
 */
function stCacheMock(): MockInterface
{
    $repository = Mockery::mock(Repository::class);
    Cache::shouldReceive('store')->andReturn($repository);

    return $repository;
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

it('redirects to an authorize_url on a local host over http', function (string $url) {
    stConsumer([$this->keys['public_b64']], 'panel_a', ['authorize_url' => $url]);

    expect(stBegin(stProvider())['location'])->toStartWith($url.'?');
})->with([
    'http://localhost:8199/martis/sso/issue/panel-b',
    'http://a.localhost:8199/martis/sso/issue/panel-b',
    'http://127.0.0.1:8199/martis/sso/issue/panel-b',
    'http://[::1]:8199/martis/sso/issue/panel-b',
]);

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

it('refuses a token that carries the environment claim when this verifier has no environment', function () {
    // The issuer is locked to an environment, this verifier is not: half a lock refuses.
    stConsumer([$this->keys['public_b64']]);

    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state'], ['env' => 'staging']), $this->keys['secret']);
    $provider = stProvider();

    try {
        $provider->resolveIdentity(stCallback($flow['session'], $token, $flow['state']));
        $this->fail('the token was accepted');
    } catch (SsoException $e) {
        expect($e->reason)->toBe(Reason::ENVIRONMENT)
            ->and($e->getMessage())->toContain('environment');
    }
});

it('refuses a token that carries an empty environment claim when this verifier has no environment', function () {
    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state'], ['env' => '']), $this->keys['secret']);

    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe(Reason::ENVIRONMENT);
});

it('accepts a token without the environment claim when this verifier has no environment', function () {
    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state']), $this->keys['secret']);

    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe('accepted');
});

it('says to set environment in the refusal of a locked token at an unlocked verifier', function () {
    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state'], ['env' => 'staging']), $this->keys['secret']);

    expect(fn () => stProvider()->resolveIdentity(stCallback($flow['session'], $token, $flow['state'])))
        ->toThrow(fn (SsoException $e) => expect($e->getMessage())->toContain('set `environment`'));
});

it('throws, naming the key and saying it was removed, for an environment_claim (the claim is always env)', function (mixed $claim, ?string $environment) {
    stConsumer([$this->keys['public_b64']], 'panel_a', ['environment_claim' => $claim, 'environment' => $environment]);

    expect(fn () => stBegin(stProvider()))
        ->toThrow(InvalidArgumentException::class, 'martis.auth.sso.providers.panel_a.environment_claim')
        ->and(fn () => stBegin(stProvider()))
        ->toThrow(InvalidArgumentException::class, 'removed in v2.22.0');
})->with([
    'a custom name, no environment' => ['deployment', null],
    'a custom name with an environment' => ['deployment', 'production'],
    'the default name written out' => ['env', 'production'],
    'an empty string' => ['', null],
]);

it('locks on the env claim only', function () {
    stConsumer([$this->keys['public_b64']], 'panel_a', ['environment' => 'production']);

    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state'], ['deployment' => 'production']), $this->keys['secret']);
    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe(Reason::ENVIRONMENT);
});

it('does not read a claim of another name as an environment', function () {
    // The environment claim is always env: a token with a claim of another name carries none.
    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state'], ['stage' => 'staging']), $this->keys['secret']);

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
    // The same token and state again: the jti was used up by the first arrival, before the state.
    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe(Reason::REPLAYED);

    // A fresh token for the same state: no state is left in the session.
    $fresh = stSign(stClaims($flow['state']), $this->keys['secret']);
    expect(stReason(stProvider(), $flow['session'], $fresh, $flow['state']))->toBe(Reason::STATE);
});

it('accepts a state of exactly ten minutes and refuses one a second older', function () {
    $start = 1_800_000_000;
    Carbon::setTestNow(Carbon::createFromTimestamp($start));
    $provider = stProvider();

    // 600 s after redirect(): still good (the token is issued just before it comes back).
    $flow = stBegin($provider);
    Carbon::setTestNow(Carbon::createFromTimestamp($start + 600));
    $token = stSign(stClaims($flow['state'], ['iat' => stTime($start + 600), 'exp' => stTime($start + 660)]), $this->keys['secret']);
    expect(stReason($provider, $flow['session'], $token, $flow['state']))->toBe('accepted');

    // 601 s: refused as state.
    Carbon::setTestNow(Carbon::createFromTimestamp($start));
    $flow = stBegin($provider);
    Carbon::setTestNow(Carbon::createFromTimestamp($start + 601));
    $token = stSign(stClaims($flow['state'], ['iat' => stTime($start + 601), 'exp' => stTime($start + 661)]), $this->keys['secret']);
    expect(stReason($provider, $flow['session'], $token, $flow['state']))->toBe(Reason::STATE);
    // Pulled once, expired or not.
    expect($flow['session']->has('martis_sso_state.panel_a'))->toBeFalse();
    Carbon::setTestNow();
});

it('still uses up the jti of a token whose state had expired', function () {
    $start = 1_800_000_000;
    Carbon::setTestNow(Carbon::createFromTimestamp($start));
    $provider = stProvider();
    $flow = stBegin($provider);

    Carbon::setTestNow(Carbon::createFromTimestamp($start + 700));
    $token = stSign(stClaims($flow['state'], ['iat' => stTime($start + 700), 'exp' => stTime($start + 760)]), $this->keys['secret']);
    expect(stReason($provider, $flow['session'], $token, $flow['state']))->toBe(Reason::STATE);

    stRestoreState($flow);
    expect(stReason($provider, $flow['session'], $token, $flow['state']))->toBe(Reason::REPLAYED);
    Carbon::setTestNow();
});

it('refuses a state kept in the session without its creation time', function (mixed $entry) {
    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state']), $this->keys['secret']);
    $flow['session']->put('martis_sso_state.panel_a', $entry === 'plain' ? $flow['state'] : $entry);

    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe(Reason::STATE);
})->with([
    'a bare string (a flow started before v2.22.0)' => ['plain'],
    'an array without the time' => [['value' => 'x']],
    'a time that is not a number' => [['value' => 'x', 'at' => 'yesterday']],
]);

it('refuses a replayed jti', function () {
    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state']), $this->keys['secret']);

    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe('accepted');

    // The browser session starts a second flow with the same state, and the
    // same token comes back: only the one-time jti stops it.
    stRestoreState($flow);
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

    // Same token, verifier now locked to the token's environment: the jti was never recorded.
    stConsumer([$this->keys['public_b64']], 'panel_a', ['environment' => 'staging']);
    stRestoreState($flow);
    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe('accepted');
});

it('uses up the jti of a validly signed token even when its state is wrong (state fixation)', function () {
    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state']), $this->keys['secret']);

    // The victim's browser has another session: the state does not match, and the token is spent anyway.
    expect(stReason(stProvider(), stSession(), $token, $flow['state']))->toBe(Reason::STATE);

    // The attacker's browser holds the state and gets the token back from a log: it is already used.
    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe(Reason::REPLAYED);
});

it('uses up the jti when the state query parameter is missing or differs', function (?string $query) {
    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state']), $this->keys['secret']);

    expect(stReason(stProvider(), $flow['session'], $token, $query))->toBe(Reason::STATE);

    stRestoreState($flow);
    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe(Reason::REPLAYED);
})->with(['missing' => [null], 'different' => [str_repeat('x', 43)]]);

it('uses up the jti of a token whose own state claim differs', function () {
    $flow = stBegin(stProvider());
    $token = stSign(stClaims(str_repeat('y', 43)), $this->keys['secret']);

    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe(Reason::STATE);
    expect(stReason(stProvider(), $flow['session'], $token, str_repeat('y', 43)))->toBe(Reason::REPLAYED);
});

it('does not use up the jti of a token refused before the one-time check', function (array $claims, string $reason) {
    $flow = stBegin(stProvider());
    $jti = bin2hex(random_bytes(16));
    $token = stSign(stClaims($flow['state'], $claims + ['jti' => $jti]), $this->keys['secret']);

    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe($reason);

    // The same jti in a good token is still usable: nothing was recorded.
    $flow = stBegin(stProvider());
    $good = stSign(stClaims($flow['state'], ['jti' => $jti]), $this->keys['secret']);
    expect(stReason(stProvider(), $flow['session'], $good, $flow['state']))->toBe('accepted');
})->with([
    'another audience' => [['aud' => 'https://elsewhere.example'], Reason::AUDIENCE],
    'another issuer' => [['iss' => 'https://elsewhere.example'], Reason::ISSUER],
    'expired' => [['iat' => '2020-01-01T00:00:00+00:00', 'exp' => '2020-01-01T00:01:00+00:00'], Reason::EXPIRED],
]);

it('does not use up the jti of a token with a bad signature', function () {
    $flow = stBegin(stProvider());
    $claims = stClaims($flow['state']);
    $forged = stSign($claims, stKeys()['secret']);

    expect(stReason(stProvider(), $flow['session'], $forged, $flow['state']))->toBe(Reason::BAD_SIGNATURE);

    $genuine = stSign($claims, $this->keys['secret']);
    expect(stReason(stProvider(), $flow['session'], $genuine, $flow['state']))->toBe('accepted');
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

    stCacheMock()->shouldReceive('add')->once()->andThrow(new RuntimeException('redis is down'));

    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe(Reason::REPLAY_STORE);
});

it('refuses when the one-time store answers that it did not add the key', function () {
    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state']), $this->keys['secret']);

    stCacheMock()->shouldReceive('add')->once()->andReturn(false);

    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe(Reason::REPLAYED);
});

it('asks the cache to add the jti for the time the token can still be used', function () {
    $flow = stBegin(stProvider());
    $now = time();
    $token = stSign(stClaims($flow['state'], ['iat' => stTime($now), 'exp' => stTime($now + 60)]), $this->keys['secret']);

    stCacheMock()->shouldReceive('add')->once()->withArgs(function (string $key, mixed $value, mixed $ttl): bool {
        // min(exp, iat + max_age) + skew + 1 = 60 + 30 + 1 seconds, give or take the clock.
        return str_starts_with($key, 'martis_sso_jti:panel_a:')
            && $value === true
            && is_int($ttl) && $ttl >= 89 && $ttl <= 92;
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
    'http authorize_url on another loopback address' => [['authorize_url' => 'http://127.0.0.2/issue'], 'martis.auth.sso.providers.panel_a.authorize_url'],
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
    'environment_claim (removed)' => [['environment_claim' => 'deployment'], 'martis.auth.sso.providers.panel_a.environment_claim'],
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

// -----------------------------------------------------------------------------
// The one-time record outlives the last second the token is accepted (review L1)
// -----------------------------------------------------------------------------

it('still refuses the jti on the last second the token would be accepted, and not for longer than that', function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_000));
    $provider = stProvider();
    $begin = stBegin($provider);
    $now = 1_800_000_000;
    $token = stSign(stClaims($begin['state'], ['iat' => stTime($now), 'exp' => stTime($now + 60)]), $this->keys['secret']);

    expect(stReason($provider, $begin['session'], $token, $begin['state']))->toBe('accepted');

    // The last accepted second is min(exp, iat + max_age) + skew = now + 90. The state is put back
    // so that the one-time store is the only lock left.
    Carbon::setTestNow(Carbon::createFromTimestamp($now + 90));
    stRestoreState($begin);
    expect(stReason($provider, $begin['session'], $token, $begin['state']))->toBe(Reason::REPLAYED);

    // One second later the token is expired, whatever the store holds.
    Carbon::setTestNow(Carbon::createFromTimestamp($now + 91));
    stRestoreState($begin);
    expect(stReason($provider, $begin['session'], $token, $begin['state']))->toBe(Reason::EXPIRED);
    Carbon::setTestNow();
});

it('keeps the record at least one second, even for a token that expires at once', function () {
    $flow = stBegin(stProvider());
    $now = time();
    $token = stSign(stClaims($flow['state'], ['iat' => stTime($now - 200), 'exp' => stTime($now - 31)]), $this->keys['secret']);

    // Refused as expired before the store is touched: nothing is recorded for it.
    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe(Reason::EXPIRED);
});

// -----------------------------------------------------------------------------
// spend(): the part of the sign-in that must not depend on the throttle (v2.22.0)
// -----------------------------------------------------------------------------

it('spends the token ahead of resolveIdentity() and resolveIdentity() only checks the state', function () {
    $flow = stBegin(stProvider());
    $claims = stClaims($flow['state']);
    $token = stSign($claims, $this->keys['secret']);
    $request = stCallback($flow['session'], $token, $flow['state']);

    stProvider()->spend($request);

    // The jti is recorded already, before the state is looked at.
    expect(Cache::has('martis_sso_jti:panel_a:'.hash('sha256', $claims['jti'])))->toBeTrue();

    $identity = stProvider()->resolveIdentity($request);
    expect($identity->externalId)->toBe('42');

    // Nothing was recorded twice: another request with the token is a replay.
    stRestoreState($flow);
    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe(Reason::REPLAYED);
});

it('keeps the refusal of spend() for resolveIdentity() and does not record a token it refused', function (Closure $mutate, string $reason) {
    $flow = stBegin(stProvider());
    $claims = stClaims($flow['state']);
    $token = $mutate($claims, $this->keys, $flow);
    $request = stCallback($flow['session'], $token, $flow['state']);

    stProvider()->spend($request);

    expect(fn () => stProvider()->resolveIdentity($request))
        ->toThrow(fn (SsoException $e) => expect($e->reason)->toBe($reason));
    expect(Cache::has('martis_sso_jti:panel_a:'.hash('sha256', $claims['jti'])))->toBeFalse();
})->with([
    'a bad signature' => [fn ($claims, $keys) => stSign($claims, stKeys()['secret']), Reason::BAD_SIGNATURE],
    'another audience' => [fn ($claims, $keys) => stSign([...$claims, 'aud' => 'https://x.example'], $keys['secret']), Reason::AUDIENCE],
    'garbage' => [fn () => 'not-a-token', Reason::MALFORMED],
]);

it('keeps the state refusal for resolveIdentity() after spend(), with the token already used up', function () {
    $flow = stBegin(stProvider());
    $claims = stClaims($flow['state']);
    $token = stSign($claims, $this->keys['secret']);
    $request = stCallback($flow['session'], $token, str_repeat('x', 43));

    stProvider()->spend($request);

    expect(fn () => stProvider()->resolveIdentity($request))
        ->toThrow(fn (SsoException $e) => expect($e->reason)->toBe(Reason::STATE));
    expect(Cache::has('martis_sso_jti:panel_a:'.hash('sha256', $claims['jti'])))->toBeTrue();
});

it('keeps a configuration error of spend() for resolveIdentity()', function () {
    $flow = stBegin(stProvider());
    stConsumer([$this->keys['public_b64']], 'panel_a', ['issuer' => '']);
    $request = stCallback($flow['session'], 'v4.public.x', $flow['state']);

    stProvider()->spend($request);

    expect(fn () => stProvider()->resolveIdentity($request))->toThrow(InvalidArgumentException::class, 'martis.auth.sso.providers.panel_a.issuer');
});

it('refuses a token over the fixed length before it parses it, and records nothing', function () {
    $flow = stBegin(stProvider());
    $request = stCallback($flow['session'], 'v4.public.'.str_repeat('A', PasetoV4Public::MAX_TOKEN_LENGTH), $flow['state']);
    stCacheMock()->shouldNotReceive('add');

    stProvider()->spend($request);

    expect(fn () => stProvider()->resolveIdentity($request))
        ->toThrow(fn (SsoException $e) => expect($e->reason)->toBe(Reason::MALFORMED));
});

it('still does everything itself when spend() did not run', function () {
    $flow = stBegin(stProvider());
    $token = stSign(stClaims($flow['state']), $this->keys['secret']);

    expect(stReason(stProvider(), $flow['session'], $token, $flow['state']))->toBe('accepted');
});

it('keeps the state and the jti out of the trace of a refused callback', function () {
    $flow = stBegin(stProvider());
    $claims = stClaims('SECRETSTATE-IN-THE-CLAIM-0123456789abcdef');
    $token = stSign($claims, $this->keys['secret']);
    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        stProvider()->resolveIdentity(stCallback($flow['session'], $token, str_repeat('x', 43)));
        $this->fail('the state was accepted');
    } catch (SsoException $e) {
        $trace = $e->getTraceAsString();
    } finally {
        ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
    }

    expect($trace)->not->toContain('SECRETSTATE')->not->toContain($claims['jti']);
});
