<?php

declare(strict_types=1);

namespace Martis\Cache;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Martis\Models\CacheState;

/**
 * Central cache service for Martis subsystems.
 *
 * Handles four named cache types — `metrics`, `navigation`, `dashboards`,
 * `schema` — with three control planes:
 *
 *   1. Config (`config/martis.php` → `cache.{type}`).
 *   2. Env vars (one per type plus a master `MARTIS_CACHE_ENABLED`).
 *   3. Runtime — toggle persisted to the `martis_cache_state` table,
 *      survives restarts, set via Artisan or the admin panel.
 *
 * Invalidation uses a per-type version counter stored in the
 * `martis_cache_state` table. Bumping the counter makes every key
 * `martis:cache:{type}:v{N}:...` orphaned at once, which works on every
 * cache backend (file, array, redis, memcached, db) without needing
 * tagging support. Old entries linger until natural expiration or
 * eviction; that's fine because the version is part of the key.
 *
 * **Why DB-backed metadata?** v1.8.7 and earlier stored the version
 * counter, `cleared_at` stamp and runtime override flag in the cache
 * itself. That made them volatile — `php artisan cache:clear`,
 * `redis-cli FLUSHDB`, container restarts and LRU eviction all wiped
 * them, leaving the admin UI showing "V1 · cleared at —" right after
 * the operator had explicitly invalidated to V2. v1.8.8 moves them
 * to a dedicated `martis_cache_state` table so they survive every
 * cache-backend reset. The cache entries themselves still live in
 * `Cache::store()`.
 *
 * Per-request bypass — header `X-Martis-No-Cache: 1` or query
 * `?nocache=1` (or `?nocache=true`). Useful for debugging without
 * flipping config.
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

    /**
     * Custom layers registered via `extend()`. Map of name => default
     * config. Built-in layers always exist regardless of this list.
     *
     * @var array<string, array{enabled: bool, ttl: int|null}>
     */
    protected static array $extensions = [];

    /**
     * Per-instance state cache: `[type => ['version' => int, 'cleared_at' => ?string, 'override' => ?bool]]`.
     * Hydrated lazily by `loadStates()` on first read. Container binds
     * this class as `scoped()` so the cache is reset between requests.
     *
     * @var array<string, array{version: int, cleared_at: ?string, override: ?bool}>|null
     */
    protected ?array $states = null;

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
     *
     * Persists to the `martis_cache_state` table so the version and
     * `cleared_at` stamp survive Cache::flush(), redis-cli FLUSHDB,
     * container restarts and LRU eviction. The cache entries
     * themselves remain in Cache::store() — they become orphans
     * (keys reference the previous version) and either expire by TTL
     * or get evicted under memory pressure.
     */
    public function clear(?string $type = null): void
    {
        $types = $type === null ? static::types() : [$type];
        $now = Date::now();

        foreach ($types as $t) {
            $this->assertKnownType($t);

            $current = $this->state($t);
            $next = [
                'version' => $current['version'] + 1,
                'cleared_at' => $now->toIso8601String(),
                'override' => $current['override'],
            ];

            $this->writeState($t, $next);
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

        $current = $this->state($type);
        $this->writeState($type, [
            'version' => $current['version'],
            'cleared_at' => $current['cleared_at'],
            'override' => null,
        ]);
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
        $out = [];

        foreach (static::types() as $type) {
            $cfg = $this->normalizedConfig($type);
            $st = $this->state($type);

            $out[] = [
                'type' => $type,
                'enabled' => $this->enabled($type),
                'ttl' => $cfg['ttl'],
                'config_enabled' => (bool) $cfg['enabled'],
                'runtime_override' => $st['override'],
                'version' => $st['version'],
                'cleared_at' => $st['cleared_at'],
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
        return 'martis:cache:'.$type.':v'.$this->state($type)['version'].':'.$key;
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
     * Map of `[type => override]` for layers with a non-null override.
     * Backward-compat helper kept for the few external callers and the
     * test suite — `enabled()` and `status()` go through `state()`
     * directly.
     *
     * @return array<string, bool>
     */
    protected function overrides(): array
    {
        $out = [];
        foreach (static::types() as $type) {
            $st = $this->state($type);
            if ($st['override'] !== null) {
                $out[$type] = (bool) $st['override'];
            }
        }

        return $out;
    }

    protected function setOverride(string $type, bool $enabled): void
    {
        $this->assertKnownType($type);

        $current = $this->state($type);
        $this->writeState($type, [
            'version' => $current['version'],
            'cleared_at' => $current['cleared_at'],
            'override' => $enabled,
        ]);
    }

    /**
     * Read the operational state for one cache layer, hydrating the
     * per-instance cache from the `martis_cache_state` table on first
     * call. Returns the historical defaults when the table is missing
     * (gracefully covers the upgrade window between deploying the
     * v1.8.8 code and running `php artisan migrate`).
     *
     * @return array{version: int, cleared_at: ?string, override: ?bool}
     */
    protected function state(string $type): array
    {
        if ($this->states === null) {
            $this->states = $this->loadStates();
        }

        return $this->states[$type] ?? ['version' => 1, 'cleared_at' => null, 'override' => null];
    }

    /**
     * Load every state row in one query and index by type. Falls back
     * to an empty map when the table is missing — `state()` then
     * applies the historical defaults on lookup.
     *
     * @return array<string, array{version: int, cleared_at: ?string, override: ?bool}>
     */
    protected function loadStates(): array
    {
        try {
            $rows = CacheState::query()->get(['type', 'version', 'cleared_at', 'override']);
        } catch (QueryException) {
            // Table does not exist — host has not run the v1.8.8
            // migration yet. Defaults reproduce historical behaviour.
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->type] = [
                'version' => (int) $row->version,
                'cleared_at' => $row->cleared_at?->toIso8601String(),
                'override' => $row->override === null ? null : (bool) $row->override,
            ];
        }

        return $out;
    }

    /**
     * Persist a state record (upsert) and update the in-instance
     * cache so subsequent reads in the same request see the new
     * values without an extra SELECT.
     *
     * @param  array{version: int, cleared_at: ?string, override: ?bool}  $next
     */
    protected function writeState(string $type, array $next): void
    {
        try {
            CacheState::query()->updateOrInsert(
                ['type' => $type],
                [
                    'version' => $next['version'],
                    'cleared_at' => $next['cleared_at'],
                    'override' => $next['override'],
                    'updated_at' => Date::now(),
                    'created_at' => Date::now(),
                ],
            );
        } catch (QueryException) {
            // Migration pending — silently degrade. The historical
            // semantics (state lost on Cache::flush()) reapply until
            // the consumer runs `php artisan migrate`.
            return;
        }

        if ($this->states === null) {
            $this->states = [];
        }
        $this->states[$type] = $next;
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
     * store AND truncate the operational state table so suites that
     * hit `array` driver + in-memory SQLite stay isolated.
     * Intentionally untouched by the public API; only test setups
     * call it.
     */
    public function flushAllForTesting(): void
    {
        try {
            CacheState::query()->delete();
        } catch (QueryException) {
            // Table not migrated — nothing to flush.
        }

        $this->states = null;
    }

    /**
     * Force-reload the in-instance state cache. Useful for long-lived
     * processes (queue workers, Octane) that want to pick up changes
     * made by other workers without waiting for the next request
     * boundary.
     */
    public function refreshState(): void
    {
        $this->states = null;
    }
}
