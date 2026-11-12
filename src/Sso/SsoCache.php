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

    /**
     * @throws InvalidArgumentException
     */
    public static function store(): Repository
    {
        $name = ConfigReader::optionalString(config(self::KEY), self::KEY);

        if ($name === null) {
            return Cache::store();
        }

        $stores = config('cache.stores');
        if (! is_array($stores) || ! array_key_exists($name, $stores)) {
            throw new InvalidArgumentException('The ['.self::KEY."] config value names the cache store [{$name}], which is not defined in [cache.stores].");
        }

        return Cache::store($name);
    }
}
