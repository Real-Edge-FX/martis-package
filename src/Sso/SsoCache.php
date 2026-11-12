<?php

declare(strict_types=1);

namespace Martis\Sso;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Martis\Sso\SignedToken\ConfigReader;

/**
 * The cache store of everything the SSO subsystem keeps in a cache (v2.22.0):
 * the signed token's one-time `jti`, the revocation and the origin nonces and
 * ended-session markers of {@see SsoSession}, and the Azure tenant lookup.
 *
 * `martis.auth.sso.cache_store` names a store of `cache.stores`; unset (null)
 * it is the application's default store. The store must be shared by every
 * server of the panel, must not evict early and must not be flushed on a
 * deploy: the one-time `jti` and the revocation are security state, and a
 * store that forgets them reopens a used token or an ended session.
 *
 * A name that is set but unusable throws an InvalidArgumentException naming
 * the key. Callers resolve the store BEFORE the `try` that turns a cache
 * failure into a refusal or a report, so a typo in the config is loud instead
 * of being read as an outage.
 */
final class SsoCache
{
    public const KEY = 'martis.auth.sso.cache_store';

    /** Drivers that cannot hold security state: per process, or nothing at all. */
    private const WEAK_DRIVERS = ['array', 'null'];

    /**
     * Whether SSO is on. With it off, the paths every panel runs (password
     * sign-in, sign-out, the plain 401) must not touch the SSO cache at all,
     * so a mistake in `cache_store` cannot break a panel that does not use SSO.
     */
    public static function enabled(): bool
    {
        return (bool) config('martis.auth.sso.enabled', false);
    }

    /**
     * An empty or blank value is unset (what `env()` yields for `KEY=`).
     * Anything else must name a store of `cache.stores`.
     *
     * @throws InvalidArgumentException
     */
    public static function store(): Repository
    {
        $value = config(self::KEY);
        if (is_string($value) && trim($value) === '') {
            $value = null;
        }

        $name = ConfigReader::optionalString($value, self::KEY);

        if ($name !== null) {
            $stores = config('cache.stores');
            if (! is_array($stores) || ! array_key_exists($name, $stores)) {
                throw new InvalidArgumentException('The ['.self::KEY."] config value names the cache store [{$name}], which is not defined in [cache.stores].");
            }
        }

        if (self::enabled() && ! app()->runningUnitTests()) {
            self::assertCanHoldSecurityState($name);
        }

        return Cache::store($name);
    }

    /**
     * With SSO on, the `array` store (per process) and the `null` store (holds
     * nothing) cannot record a one-time `jti` or a revocation: replay
     * protection would be void, with no error. Refused outside the test suite.
     *
     * @throws InvalidArgumentException
     */
    private static function assertCanHoldSecurityState(?string $name): void
    {
        $store = $name ?? config('cache.default');
        $driver = is_string($store) ? config("cache.stores.{$store}.driver") : null;

        if (is_string($driver) && in_array($driver, self::WEAK_DRIVERS, true)) {
            $where = $name === null
                ? "is unset, so the default store [{$store}] (cache.default) is used"
                : "names the store [{$store}]";

            throw new InvalidArgumentException('The ['.self::KEY."] config value {$where}, and that store uses the [{$driver}] driver, which cannot hold the one-time token record and the revocations SSO needs (per process, or nothing at all). Set ".self::KEY.' (MARTIS_SSO_CACHE_STORE) to a store every server shares, such as redis, database or memcached.');
        }
    }
}
