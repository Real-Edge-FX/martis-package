<?php

declare(strict_types=1);

namespace Martis\Cache;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;

/**
 * Central cache service for Martis subsystems.
 *
 * Handles four named cache types — `metrics`, `navigation`, `dashboards`,
 * `schema` — with three control planes:
 *
 *   1. Config (`config/martis.php` → `cache.{type}`).
 *   2. Env vars (one per type plus a master `MARTIS_CACHE_ENABLED`).
 *   3. Runtime — toggle stored in cache, survives restarts, set via
 *      Artisan or the admin panel.
 *
 * Invalidation uses a per-type version key (`martis:cache:{type}:version`).
 * Bumping the version makes every key derived from it stale at once,
 * which works on every cache backend (file, array, redis, memcached, db)
 * without needing tagging support. Old entries linger until natural
 * expiration; that's fine because the version is part of the key.
 *
 * Per-request bypass — header `X-Martis-No-Cache: 1` or query
 * `?nocache=1`. Useful for debugging without flipping config.
 */
class MartisCache
{
    /**
     * Built-in cache layers. Apps can register additional layers via
     * `MartisCache::extend()` from their service provider — those merge
     * into the live `types()` list and show up in the admin page,
     * Artisan, REST endpoints, and the runtime override system.
     *
     * @deprecated Prefer `MartisCache::types()` — kept as a const for
     *             backward compatibility with code that already
     *             references this list.
     */
    public const TYPES = ['metrics', 'navigation', 'dashboards', 'schema'];

    /** Cache key holding the per-type runtime override map. */
    private const OVERRIDES_KEY = 'martis:cache:overrides';

    /** Cache key prefix for the per-type version counter. */
    private const VERSION_KEY_PREFIX = 'martis:cache:version:';

    /** Cache key prefix for the per-type "last cleared at" stamp. */
    private const CLEARED_AT_PREFIX = 'martis:cache:cleared-at:';

    /**
     * Custom layers registered via `extend()`. Map of name => default
     * config. Built-in layers always exist regardless of this list.
     *
     * @var array<string, array{enabled: bool, ttl: int|null}>
     */
    protected static array $extensions = [];

    public function __construct(private readonly Repository $store) {}

    /**
     * Register a custom cache layer. Call this from a service
     * provider's `boot()` method; the layer surfaces in every control
     * surface (Artisan, REST, admin page, runtime overrides). The
     * defaults below apply when the host app does not declare a
     * matching `config/martis.php → cache.{name}` entry.
     *
     *     // app/Providers/AppServiceProvider.php
     *     use Martis\Cache\MartisCache;
     *
     *     public function boot(): void
     *     {
     *         MartisCache::extend('orders', enabled: true, ttl: 30);
     *     }
     *
     * Then anywhere in your code:
     *
     *     app(MartisCache::class)->remember('orders', $key, $callback);
     *
     * `name` must be lowercase letters, digits and dashes only —
     * underscores and uppercase work too but the admin page typography
     * looks best with kebab-case.
     */
    public static function extend(string $name, bool $enabled = true, ?int $ttl = null): void
    {
        $name = trim($name);
        if ($name === '' || in_array($name, self::TYPES, true)) {
            return;
        }

        static::$extensions[$name] = ['enabled' => $enabled, 'ttl' => $ttl];
    }

    /**
     * Drop a previously registered custom layer. Mostly useful in
     * tests; production code rarely calls this.
     */
    public static function forgetExtension(string $name): void
    {
        unset(static::$extensions[$name]);
    }

    /**
     * Snapshot of every currently known cache layer name (built-in
     * plus host-app extensions). Order is stable: built-in first,
     * then extensions in registration order.
     *
     * @return list<string>
     */
    public static function types(): array
    {
        return array_values(array_unique(array_merge(self::TYPES, array_keys(static::$extensions))));
    }

    /** True when the master switch is on. False = every cache is bypassed. */
    public function masterEnabled(): bool
    {
        return (bool) config('martis.cache.enabled', true);
    }

    /**
     * Effective enabled state for a cache type.
     *
     * Order: master switch → runtime override → config (`enabled` flag).
     */
    public function enabled(string $type): bool
    {
        $this->assertKnownType($type);

        if (! $this->masterEnabled()) {
            return false;
        }

        $overrides = $this->overrides();
        if (array_key_exists($type, $overrides)) {
            return (bool) $overrides[$type];
        }

        $cfg = $this->normalizedConfig($type);

        return (bool) $cfg['enabled'];
    }

    /**
     * TTL in minutes for a cache type. Null means "no expiration"
     * (cached until manually invalidated by version bump).
     */
    public function ttl(string $type): ?int
    {
        $this->assertKnownType($type);

        $cfg = $this->normalizedConfig($type);

        return $cfg['ttl'];
    }

    /**
     * Cache wrapper. Computes the key, version-prefixes it, applies TTL,
     * skips the cache when disabled or bypassed.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function remember(string $type, string $key, Closure $callback, ?int $ttlMinutesOverride = null): mixed
    {
        $this->assertKnownType($type);

        if (! $this->enabled($type) || $this->bypassed()) {
            return $callback();
        }

        $ttlMinutes = $ttlMinutesOverride ?? $this->ttl($type);
        $expires = $ttlMinutes === null ? null : Date::now()->addMinutes($ttlMinutes);
        $cacheKey = $this->buildKey($type, $key);

        if ($expires === null) {
            return $this->store->rememberForever($cacheKey, $callback);
        }

        return $this->store->remember($cacheKey, $expires, $callback);
    }

    /**
     * Invalidate every entry for a cache type by bumping its version.
     * When `$type` is null every type is bumped at once.
     */
    public function clear(?string $type = null): void
    {
        $types = $type === null ? static::types() : [$type];

        foreach ($types as $t) {
            $this->assertKnownType($t);
            // Read-then-write rather than `increment()` because the
            // `?? 1` default in `buildKey()` means a never-touched
            // version starts at 1; a naive increment from a missing
            // key would leave it at 1 too on stores that initialize at
            // 0, so the post-clear key would still match the pre-clear
            // key. Take the current effective version and bump from
            // there. `forever()` keeps the counter outside the regular
            // TTL flow.
            $current = (int) ($this->store->get(self::VERSION_KEY_PREFIX.$t) ?? 1);
            $this->store->forever(self::VERSION_KEY_PREFIX.$t, $current + 1);
            $this->store->forever(self::CLEARED_AT_PREFIX.$t, Date::now()->toIso8601String());
        }
    }

    /** Persistently disable a cache type at runtime. Survives restarts. */
    public function disable(string $type): void
    {
        $this->setOverride($type, false);
    }

    /** Persistently re-enable a cache type at runtime. */
    public function enable(string $type): void
    {
        $this->setOverride($type, true);
    }

    /** Drop the runtime override for a type, falling back to config. */
    public function clearOverride(string $type): void
    {
        $this->assertKnownType($type);
        $overrides = $this->overrides();
        unset($overrides[$type]);
        $this->store->forever(self::OVERRIDES_KEY, $overrides);
    }

    /**
     * Return a snapshot of every cache type and its current effective state.
     *
     * @return array<int, array{
     *   type: string,
     *   enabled: bool,
     *   ttl: int|null,
     *   config_enabled: bool,
     *   runtime_override: bool|null,
     *   version: int,
     *   cleared_at: string|null,
     * }>
     */
    public function status(): array
    {
        $overrides = $this->overrides();
        $out = [];

        foreach (static::types() as $type) {
            $cfg = $this->normalizedConfig($type);
            $runtime = array_key_exists($type, $overrides) ? (bool) $overrides[$type] : null;

            $out[] = [
                'type' => $type,
                'enabled' => $this->enabled($type),
                'ttl' => $cfg['ttl'],
                'config_enabled' => (bool) $cfg['enabled'],
                'runtime_override' => $runtime,
                'version' => (int) ($this->store->get(self::VERSION_KEY_PREFIX.$type) ?? 1),
                'cleared_at' => $this->store->get(self::CLEARED_AT_PREFIX.$type),
            ];
        }

        return $out;
    }

    /**
     * Per-request bypass detection. Read from the current request when
     * one is bound — falls back to false in console / queue contexts.
     */
    public function bypassed(?Request $request = null): bool
    {
        $request ??= $this->currentRequest();

        if (! $request instanceof Request) {
            return false;
        }

        if ($request->headers->get('X-Martis-No-Cache') === '1') {
            return true;
        }

        $param = $request->query('nocache');

        return $param === '1' || $param === 'true';
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Build the final cache key including the per-type version so a
     * single `clear()` invalidates everything derived from it.
     */
    public function buildKey(string $type, string $key): string
    {
        $version = (int) ($this->store->get(self::VERSION_KEY_PREFIX.$type) ?? 1);

        return 'martis:cache:'.$type.':v'.$version.':'.$key;
    }

    /**
     * Normalize the config value for a type. Accepts the modern array
     * shape and the legacy "bare int = TTL" / "null = disabled" forms.
     *
     * @return array{enabled: bool, ttl: int|null}
     */
    protected function normalizedConfig(string $type): array
    {
        $raw = config('martis.cache.'.$type);
        $extension = static::$extensions[$type] ?? null;

        // Modern shape: ['enabled' => bool, 'ttl' => int|null]. The
        // extension defaults (when set) win over the framework
        // defaults but lose to whatever the host app put in config.
        if (is_array($raw)) {
            return [
                'enabled' => (bool) ($raw['enabled'] ?? $extension['enabled'] ?? true),
                'ttl' => $this->normalizeTtl($raw['ttl'] ?? $extension['ttl'] ?? null),
            ];
        }

        // Legacy `null`. For built-in layers this means "disabled" (we
        // cannot tell apart "no config" from "explicitly null"). For
        // extensions, fall back to the registered defaults — the
        // extension is the source of truth when no config exists.
        if ($raw === null) {
            if ($extension !== null) {
                return $extension;
            }

            return ['enabled' => false, 'ttl' => null];
        }

        // Legacy bare int / numeric string = enabled with TTL minutes.
        return [
            'enabled' => true,
            'ttl' => $this->normalizeTtl($raw),
        ];
    }

    protected function normalizeTtl(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $minutes = (int) $value;

        return $minutes > 0 ? $minutes : null;
    }

    /**
     * @return array<string, bool>
     */
    protected function overrides(): array
    {
        $value = $this->store->get(self::OVERRIDES_KEY);

        return is_array($value) ? $value : [];
    }

    protected function setOverride(string $type, bool $enabled): void
    {
        $this->assertKnownType($type);
        $overrides = $this->overrides();
        $overrides[$type] = $enabled;
        $this->store->forever(self::OVERRIDES_KEY, $overrides);
    }

    protected function assertKnownType(string $type): void
    {
        $known = static::types();
        if (! in_array($type, $known, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Martis cache type "%s". Known types: %s.',
                $type,
                implode(', ', $known),
            ));
        }
    }

    /**
     * Resolve the active request, returning null when running outside an
     * HTTP context (CLI, queue worker).
     */
    protected function currentRequest(): ?Request
    {
        try {
            $resolved = app('request');

            return $resolved instanceof Request ? $resolved : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Test helper — drop every Martis cache key out of the underlying
     * store so suites that hit `array` driver state stay isolated.
     * Intentionally untouched by the public API; only test setups call it.
     */
    public function flushAllForTesting(): void
    {
        $this->store->forget(self::OVERRIDES_KEY);
        foreach (static::types() as $type) {
            $this->store->forget(self::VERSION_KEY_PREFIX.$type);
            $this->store->forget(self::CLEARED_AT_PREFIX.$type);
        }
    }
}
