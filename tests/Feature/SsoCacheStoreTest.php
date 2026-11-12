<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Http;
use Martis\Auth\GuardCatalog;
use Martis\Sso\Providers\AzureProvider;
use Martis\Sso\SsoCache;
use Martis\Sso\SsoException;
use Martis\Sso\SsoManager;
use Martis\Sso\SsoSession;

require_once __DIR__.'/../Support/signed-token.php';

// ===========================================================================
// martis.auth.sso.cache_store (v2.22.0): the store of every cache entry the
// SSO subsystem makes (the one-time jti, the revocation, the origin nonces,
// the ended-session markers and the Azure tenant lookup). Null is the default
// store; a name that is not in `cache.stores` throws, naming the key.
// ===========================================================================

class SsoCacheStoreUser extends User
{
    protected $guarded = [];
}

beforeEach(function () {
    config()->set('cache.stores.sso_shared', ['driver' => 'array']);
    config()->set('martis.auth.sso.cache_store', 'sso_shared');
    config()->set('martis.auth.sso.enabled', true);
    Cache::store('array')->flush();
    Cache::store('sso_shared')->flush();
});

/** The entry of the shared store, and what the default store holds under the same key. */
function ssoCacheLanded(string $key): array
{
    return [Cache::store('sso_shared')->has($key), Cache::store('array')->has($key)];
}

it('names the default store when no cache_store is set', function () {
    config()->set('martis.auth.sso.cache_store', null);

    expect(SsoCache::store())->toBe(Cache::store());
});

it('names the configured store', function () {
    expect(SsoCache::store())->toBeInstanceOf(Repository::class)
        ->and(SsoCache::store())->toBe(Cache::store('sso_shared'))
        ->and(SsoCache::store())->not->toBe(Cache::store('array'));
});

it('throws, naming the key, for a cache_store that is not a configured store', function (mixed $value) {
    config()->set('martis.auth.sso.cache_store', $value);

    expect(fn () => SsoCache::store())->toThrow(InvalidArgumentException::class, 'martis.auth.sso.cache_store');
})->with([
    'a name that is not in cache.stores' => ['nope'],
    'a number' => [3],
    'a list' => [['redis']],
    'true' => [true],
]);

it('treats an empty or blank cache_store as unset, as env() gives for KEY=', function (string $value) {
    config()->set('martis.auth.sso.cache_store', $value);

    expect(SsoCache::store())->toBe(Cache::store());
})->with(['empty' => [''], 'blank' => ['   ']]);

it('records the one-time jti in the configured store and not in the default one', function () {
    $keys = stKeys();
    stConsumer([$keys['public_b64']]);
    $provider = app(SsoManager::class)->driver('panel_a');
    $flow = stBegin($provider);
    $claims = stClaims($flow['state']);

    $provider->resolveIdentity(stCallback($flow['session'], stSign($claims, $keys['secret']), $flow['state']));

    $key = 'martis_sso_jti:panel_a:'.hash('sha256', $claims['jti']);
    expect(ssoCacheLanded($key))->toBe([true, false]);
});

it('refuses a replay through the configured store, which the default store knows nothing about', function () {
    $keys = stKeys();
    stConsumer([$keys['public_b64']]);
    $provider = app(SsoManager::class)->driver('panel_a');
    $flow = stBegin($provider);
    $token = stSign(stClaims($flow['state']), $keys['secret']);

    $provider->resolveIdentity(stCallback($flow['session'], $token, $flow['state']));
    // The default store is wiped (a deploy): the configured one still knows the jti.
    Cache::store('array')->flush();
    stRestoreState($flow);

    expect(fn () => $provider->resolveIdentity(stCallback($flow['session'], $token, $flow['state'])))
        ->toThrow(fn (SsoException $e) => expect($e->reason)->toBe('replayed'));
});

it('throws, naming the key, instead of refusing the token as a store failure, for an unknown store', function () {
    $keys = stKeys();
    stConsumer([$keys['public_b64']]);
    $provider = app(SsoManager::class)->driver('panel_a');
    $flow = stBegin($provider);
    $token = stSign(stClaims($flow['state']), $keys['secret']);
    config()->set('martis.auth.sso.cache_store', 'nope');

    expect(fn () => $provider->resolveIdentity(stCallback($flow['session'], $token, $flow['state'])))
        ->toThrow(InvalidArgumentException::class, 'martis.auth.sso.cache_store');
});

it('keeps the revocation in the configured store', function () {
    SsoSession::revokeUser('7');

    $key = 'martis.sso-revoked.'.sha1(GuardCatalog::martis().'|7');
    expect(ssoCacheLanded($key))->toBe([true, false])
        ->and(SsoSession::isRevoked('7', 1))->toBeTrue();

    // Wiping the default store does not lift it, wiping the configured one does.
    Cache::store('array')->flush();
    expect(SsoSession::isRevoked('7', 1))->toBeTrue();
    Cache::store('sso_shared')->flush();
    expect(SsoSession::isRevoked('7', 1))->toBeFalse();
});

it('throws, naming the key, for an unknown store instead of reporting a revocation failure', function () {
    config()->set('martis.auth.sso.cache_store', 'nope');

    expect(fn () => SsoSession::revokeUser('7'))->toThrow(InvalidArgumentException::class, 'martis.auth.sso.cache_store')
        ->and(fn () => SsoSession::isRevoked('7', 1))->toThrow(InvalidArgumentException::class, 'martis.auth.sso.cache_store');
});

it('keeps the origin nonce of a remembered sign-in in the configured store', function () {
    $request = Request::create('/martis/sso/azure/callback');
    $request->setLaravelSession(stSession());
    $user = new SsoCacheStoreUser;
    $user->forceFill(['id' => 9]);

    SsoSession::start($request, $user, 'azure', null, true);

    $key = 'martis.sso-origin.'.sha1(GuardCatalog::martis().'|9');
    expect(ssoCacheLanded($key))->toBe([true, false]);
});

it('keeps the ended-session marker in the configured store', function () {
    $request = Request::create('/martis/api/tools');
    $request->setLaravelSession(stSession());
    $oldId = str_repeat('a', 40);

    SsoSession::markEnded($request, 'azure', $oldId);

    $key = 'martis.sso-ended.'.sha1($oldId);
    expect(ssoCacheLanded($key))->toBe([true, false]);

    // Read back by a request that carries the old session id, and dropped from the same store.
    $next = Request::create('/martis/api/tools');
    $session = stSession();
    $session->setId($oldId);
    $next->setLaravelSession($session);
    expect(SsoSession::endedProvider($next))->toBe('azure');

    SsoSession::forgetEnded($next);
    expect(Cache::store('sso_shared')->has($key))->toBeFalse();
});

it('keeps the Azure tenant lookup in the configured store', function () {
    $tenant = '11111111-2222-3333-4444-555555555555';
    Http::fake(['login.microsoftonline.com/*' => Http::response(['issuer' => 'https://login.microsoftonline.com/'.$tenant.'/v2.0'])]);

    $provider = new class extends AzureProvider
    {
        public function lookup(string $tenant): ?string
        {
            return $this->tenantIdOf($tenant);
        }
    };

    expect($provider->lookup('contoso.onmicrosoft.com'))->toBe($tenant);

    $key = 'martis.sso.azure.tenant-id.'.sha1('contoso.onmicrosoft.com');
    expect(ssoCacheLanded($key))->toBe([true, false]);
    // Asked once: the second answer comes from the configured store.
    $provider->lookup('contoso.onmicrosoft.com');
    Http::assertSentCount(1);
});

// -----------------------------------------------------------------------------
// With SSO off, the non-SSO paths never touch the SSO cache (v2.22.0)
// -----------------------------------------------------------------------------

it('lets password sign-in, sign-out and the plain 401 run on a bad cache_store while SSO is off', function () {
    config()->set('martis.auth.sso.enabled', false);
    config()->set('martis.auth.sso.cache_store', 'nope');
    $user = new SsoCacheStoreUser;
    $user->forceFill(['id' => 5]);

    $request = Request::create('/x');
    $request->setLaravelSession(stSession());
    SsoSession::forget($request, $user);
    SsoSession::forgetEnded($request);

    expect(SsoSession::endedProvider($request))->toBeNull();
    $this->getJson('/martis/api/dashboards')->assertUnauthorized();
});

it('does not recognise an origin cookie while SSO is off, and does while it is on', function () {
    config()->set('martis.auth.sso.enabled', true);
    $user = new SsoCacheStoreUser;
    $user->forceFill(['id' => 5]);
    $first = Request::create('/x');
    $first->setLaravelSession(stSession());
    SsoSession::start($first, $user, 'azure', null, true);
    $cookie = Cookie::queued(SsoSession::COOKIE);

    $next = Request::create('/x');
    $next->setLaravelSession(stSession());
    $next->cookies->set(SsoSession::COOKIE, $cookie->getValue());
    expect(SsoSession::provider($next, $user))->toBe('azure');

    config()->set('martis.auth.sso.enabled', false);
    config()->set('martis.auth.sso.cache_store', 'nope');
    $again = Request::create('/x');
    $again->setLaravelSession(stSession());
    $again->cookies->set(SsoSession::COOKIE, $cookie->getValue());
    expect(SsoSession::provider($again, $user))->toBeNull();
});

it('still throws, naming the key, on the same paths while SSO is on', function () {
    config()->set('martis.auth.sso.enabled', true);
    config()->set('martis.auth.sso.cache_store', 'nope');
    $user = new SsoCacheStoreUser;
    $user->forceFill(['id' => 5]);
    $request = Request::create('/x');
    $request->setLaravelSession(stSession());

    expect(fn () => SsoSession::forget($request, $user))->toThrow(InvalidArgumentException::class, 'martis.auth.sso.cache_store')
        ->and(fn () => SsoSession::forgetEnded($request))->toThrow(InvalidArgumentException::class, 'martis.auth.sso.cache_store')
        ->and(fn () => SsoSession::endedProvider($request))->toThrow(InvalidArgumentException::class, 'martis.auth.sso.cache_store');
});

// -----------------------------------------------------------------------------
// A store that cannot hold security state is refused outside the test suite
// -----------------------------------------------------------------------------

it('refuses an array or null store while SSO is on, naming the key', function (string $driver) {
    config()->set('cache.stores.sso_weak', ['driver' => $driver]);
    config()->set('martis.auth.sso.cache_store', 'sso_weak');
    config()->set('martis.auth.sso.enabled', true);
    app()['env'] = 'production';

    expect(fn () => SsoCache::store())
        ->toThrow(InvalidArgumentException::class, 'martis.auth.sso.cache_store')
        ->and(fn () => SsoCache::store())
        ->toThrow(InvalidArgumentException::class, $driver);

    app()['env'] = 'testing';
})->with(['array', 'null']);

it('names cache.default too when cache_store is unset and the default store is weak', function () {
    config()->set('martis.auth.sso.cache_store', null);
    config()->set('cache.default', 'array');
    config()->set('martis.auth.sso.enabled', true);
    app()['env'] = 'production';

    expect(fn () => SsoCache::store())
        ->toThrow(InvalidArgumentException::class, 'cache.default')
        ->and(fn () => SsoCache::store())
        ->toThrow(InvalidArgumentException::class, 'martis.auth.sso.cache_store');

    app()['env'] = 'testing';
});

it('accepts a shared store and any store while SSO is off or in the test suite', function () {
    config()->set('cache.stores.sso_shared_file', ['driver' => 'file', 'path' => sys_get_temp_dir()]);
    config()->set('martis.auth.sso.cache_store', 'sso_shared_file');
    config()->set('martis.auth.sso.enabled', true);
    app()['env'] = 'production';
    expect(SsoCache::store())->toBeInstanceOf(Repository::class);

    // A weak store with SSO off: nothing to guard.
    config()->set('martis.auth.sso.cache_store', 'sso_shared');
    config()->set('martis.auth.sso.enabled', false);
    expect(SsoCache::store())->toBeInstanceOf(Repository::class);

    // The test suite's own array store, SSO on.
    app()['env'] = 'testing';
    config()->set('martis.auth.sso.enabled', true);
    expect(SsoCache::store())->toBeInstanceOf(Repository::class);
});
