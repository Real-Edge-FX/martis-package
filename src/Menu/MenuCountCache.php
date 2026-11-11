<?php

declare(strict_types=1);

namespace Martis\Menu;

use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Martis\Cache\MartisCache;

/**
 * Caches one sidebar count on its own (v2.20.0), for a Resource or Tool that
 * declares `menuCountCache()`.
 *
 * The count is stored in the `navigation` cache layer through
 * `MartisCache::rememberFor()`, so it follows every rule of that layer: the
 * enabled switches, the per-request bypass, `martis:cache:clear navigation`
 * and the scope an app registered with `Martis::cacheScopeUsing()`, which
 * `MartisCache::buildKey()` appends to the key. The key names the subject
 * (and the user, for the `user` scope) and nothing else: a count does not
 * depend on the locale.
 *
 * A subject that declares nothing never reaches the cache: `remember()` runs
 * the counter and returns, exactly as before.
 */
final class MenuCountCache
{
    public const SCOPE_SHARED = 'shared';

    public const SCOPE_USER = 'user';

    /** The `ttl` of a declaration that leaves it out, in seconds. */
    public const DEFAULT_TTL = 60;

    /**
     * @param  string  $subjectClass  Resource or Tool class that owns the counter.
     * @param  string  $badgeKey  Badges-map key: `resource:{uriKey}` or `tool:{uriKey}`.
     * @param  array<string, mixed>|null  $declared  What `menuCountCache()` returned.
     * @param  Closure(): ?int  $count  The counter itself.
     *
     * @throws InvalidArgumentException when the declaration is unusable
     */
    public static function remember(string $subjectClass, string $badgeKey, ?array $declared, Request $request, Closure $count): ?int
    {
        if ($declared === null) {
            return $count();
        }

        ['scope' => $scope, 'ttl' => $ttl] = self::normalize($subjectClass, $declared);

        $key = 'count:'.$badgeKey.':'.$subjectClass.':'.($scope === self::SCOPE_USER ? self::userSegment($request) : self::SCOPE_SHARED);

        // The count is wrapped so a null one (a hidden badge) is cached too:
        // a cache store treats a stored null as a miss.
        $entry = app(MartisCache::class)->rememberFor(
            'navigation',
            $key,
            fn (): array => ['count' => $count()],
            $ttl,
        );

        return $entry['count'];
    }

    /**
     * @param  array<string, mixed>  $declared
     * @return array{scope: string, ttl: int}
     *
     * @throws InvalidArgumentException
     */
    public static function normalize(string $subjectClass, array $declared): array
    {
        $scope = $declared['scope'] ?? self::SCOPE_USER;
        if ($scope !== self::SCOPE_SHARED && $scope !== self::SCOPE_USER) {
            throw new InvalidArgumentException(sprintf(
                '%s::menuCountCache() must return a "scope" of "shared" or "user", %s given.',
                $subjectClass,
                is_string($scope) ? '"'.$scope.'"' : get_debug_type($scope),
            ));
        }

        $ttl = $declared['ttl'] ?? self::DEFAULT_TTL;
        if (! is_int($ttl) || $ttl < 1) {
            throw new InvalidArgumentException(sprintf(
                '%s::menuCountCache() must return a "ttl" of a whole number of seconds, 1 or more; %s given.',
                $subjectClass,
                is_scalar($ttl) ? var_export($ttl, true) : get_debug_type($ttl),
            ));
        }

        return ['scope' => $scope, 'ttl' => $ttl];
    }

    /** The user's segment of a `user` scope key; guests share one. */
    private static function userSegment(Request $request): string
    {
        $user = $request->user();
        if ($user === null) {
            return 'guest';
        }

        return 'u'.md5(serialize([$user::class, (string) $user->getAuthIdentifier()]));
    }
}
