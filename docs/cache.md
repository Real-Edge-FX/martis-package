# Cache (⭐ Martis differential)

> Per-subsystem cache layer with three control planes (config / env / runtime), per-request bypass, atomic version-key invalidation, custom-layer extensibility, and a built-in admin page. Lives at `Sistema → Cache`.

Most admin panels make caching opaque: TTLs hidden in code, invalidation by deploy, no kill-switch. Martis flips that — every cache layer has a name, an effective state, and a button. Developers read the config and trust it. Ops have a switch they can flip in production without redeploying. Apps add their own layers in three lines.

## What's differentiated

| Feature | Martis | Typical Laravel admin |
|---|---|---|
| Three control planes (config / env / persistent runtime) | ✅ | ❌ usually only config |
| Atomic O(1) invalidation on every cache backend | ✅ (version-key trick) | ⚠ often `Cache::tags()` — only Redis/Memcached |
| Per-request bypass (`X-Martis-No-Cache`, `?nocache=1`) | ✅ | ❌ |
| Built-in admin page with toggle/clear/reset/version visibility | ✅ | ❌ |
| Per-user scoped caching for permission-aware payloads | ✅ | ⚠ usually missing — leaks data between users |
| Custom layers via `MartisCache::extend()` | ✅ | ❌ |
| Backwards-compatible legacy config shape | ✅ | n/a |
| Host-app Gate gating + sidebar policy gating built in | ✅ | ⚠ often DIY |

## Sidebar gating (yes — every entry is policy-aware)

The sidebar that surfaces "Sistema → Cache" — and every other navigation entry — runs through the same authorization check pipeline:

| Entry kind | Visibility rule |
|---|---|
| **Resource entries** (Clients, Projects, Action Events, …) | The user must pass `Resource::authorizedToViewAny($request)`. Internally that calls the policy's `viewAny` ability. Resources without `viewAny` are silently hidden. |
| **Resource that opts out** | Set `public static function displayInNavigation(): bool { return false; }` on the resource class. Always hidden. |
| **Custom links injected via `Martis::mainMenu(...)`** | Each `MenuItem` honours `->canSee(Closure|bool)`. The closure receives the `Request` and decides per-user. |
| **System → Cache (built-in by Martis)** | Gated by Laravel's Gate `manage-martis-cache`. The default closure allows any authenticated user; host apps tighten it to `is_admin` (see below). The entry also disappears wholesale when `cache.admin_ui = false`. |

In short: **if a user lacks the policy, the sidebar entry never renders, the route returns 403, and the API endpoint behind it returns 403 too.** The three layers are checked independently.

### Tightening the cache admin gate

Out of the box, any authenticated user reaches `/martis/system/cache`. For production, override the gate from your `MartisServiceProvider` (published by `martis:install` at `app/Providers/MartisServiceProvider.php`):

```php
// app/Providers/MartisServiceProvider.php
protected function registerGates(): void
{
    Gate::define('manage-martis-cache', fn ($user) => $user->is_admin);
}
```

Calling `Gate::define()` from the host app replaces Martis's default closure, so order doesn't matter. Once tightened, non-admins see no sidebar entry and `/martis/api/cache/*` returns 403.

## The four built-in layers

| Type | What it caches | Default TTL | Per-user? |
|------|----------------|-------------|-----------|
| `metrics` | Computed metric results (Value, Trend, Partition, Progress, Activity feed, Endpoint table). | 5 minutes | No (cached per locale + filters) |
| `navigation` | Sidebar / top-nav structure. | 1 minute | **Yes** — different policies, different menus |
| `dashboards` | Dashboard list + per-dashboard definition (cards/filters metadata). Metric values are NOT cached here — that's `metrics`. | No expiration | **Yes** |
| `schema` | Resource schema payload (fields, filters, lenses, cards, actions). Heavy to compute, stable across requests. | No expiration | **Yes** |

TTL `null` means "no expiration" — the entry stays cached until explicitly cleared (the version key trick: see [Invalidation](#invalidation) below).

**Per-user scoping** is the convention used by Martis's own cached endpoints (`navigation`, `dashboards`, `schema`). Each controller derives the auth identifier and prepends it to the cache key — see `NavigationController`, `MetricController`, `ResourceController`. `MartisCache::remember()` itself takes the key verbatim, so **custom layers must include the user identifier in their own keys** if they want the same isolation. The `OrdersController` example below shows the standard shape: `"show:{$id}:{$userKey}"`.

## Reading the admin page columns

| Column | Meaning |
|---|---|
| **Type** | Layer identifier — `metrics`, `navigation`, `dashboards`, `schema`, or any custom layer registered via `MartisCache::extend()`. |
| **Effective** | The live answer to "is this layer caching right now?". Computed from the master switch, the runtime override, and the config — in that order. This is what's actually applied, not just what the config says. |
| **TTL** | Time-to-live for entries in this layer, in minutes (`5m`) or `No expiration` when `null`. With no TTL, entries live until explicitly cleared. |
| **Config** | Static `enabled` value declared in `config/martis.php → cache.{type}` (or its env override). Ignores runtime toggles. Useful to spot drift between deploy-time config and runtime overrides. |
| **Runtime** | Persistent runtime override that survives restarts. Three states:<br />• **Inherit** — no override, the config wins.<br />• **Forced ON** — override = true, beats `config_enabled = false`.<br />• **Forced OFF** — override = false, beats `config_enabled = true`.<br />Persisted in the `martis_cache_state` DB table (v1.8.8), so it survives queue worker / fpm restarts AND `php artisan cache:clear` / `redis-cli FLUSHDB`. |
| **Version** | Per-layer version counter included in every cache key. Clearing the layer increments it; every key derived from the previous version becomes orphaned at once — atomic, O(1), no traversal. |
| **Last cleared** | ISO-8601 timestamp of the last clear operation (Artisan, REST, or admin click). Dash means it has not been cleared since the application started. |

Hover any column header in the admin UI for the same explanation as a tooltip.

## Three control planes

### 1. Config (`config/martis.php`)

```php
'cache' => [
    'enabled' => env('MARTIS_CACHE_ENABLED', true),

    'metrics'    => ['enabled' => true, 'ttl' => 5],     // 5 minutes
    'navigation' => ['enabled' => true, 'ttl' => 1],
    'dashboards' => ['enabled' => true, 'ttl' => null],  // no expiration
    'schema'     => ['enabled' => true, 'ttl' => null],

    'admin_ui'   => true,
],
```

`cache.enabled` is the master switch — when `false`, every layer is bypassed regardless of its individual `enabled` flag. Use this in development to disable Martis caching wholesale without touching individual TTLs.

The legacy shape (bare int = TTL with cache implicitly enabled, `null` = disabled) is still accepted, so apps that haven't migrated keep working.

### 2. Environment

```env
MARTIS_CACHE_ENABLED=true

MARTIS_CACHE_METRICS_ENABLED=true
MARTIS_CACHE_METRICS_TTL=5

MARTIS_CACHE_NAVIGATION_ENABLED=true
MARTIS_CACHE_NAVIGATION_TTL=1

MARTIS_CACHE_DASHBOARDS_ENABLED=true
MARTIS_CACHE_DASHBOARDS_TTL=

MARTIS_CACHE_SCHEMA_ENABLED=true
MARTIS_CACHE_SCHEMA_TTL=

MARTIS_CACHE_ADMIN_UI=true
```

Empty value = `null` = no expiration.

### 3. Runtime — the differential

Runtime overrides, the per-layer version counter and the `cleared_at` timestamp are **persisted in the dedicated `martis_cache_state` table** (one row per layer). They survive every cache-backend reset:

- `php artisan cache:clear` (deploy scripts often run this).
- `redis-cli FLUSHDB`.
- A container restart without a persistent volume.
- Redis memory pressure with `maxmemory-policy: allkeys-lru` evicting "forever" keys.

The cache entries themselves still live in `Cache::store()` — only the operational metadata is DB-backed. When the operator clears a layer, they care about the visibility trail ("V3 since 14:53"), not about the entries (those will rebuild lazily). Putting the trail in the same Redis bucket that gets flushed defeated the visibility — fixed in v1.8.8.

> **v1.8.7 and earlier** stored these three pieces of state in `Cache::store()` itself (`martis:cache:version:*`, `martis:cache:cleared-at:*`, `martis:cache:overrides`). The values were lost whenever the backend was wiped, leaving the admin UI showing "V1 · cleared at —" minutes after the operator had explicitly bumped it to V2. The DB-backed table fixes this without any change to the public API — same env vars, same Artisan commands, same admin UI.

**Artisan:**

```bash
php artisan martis:cache:status                  # table view of every layer
php artisan martis:cache:clear                    # clear every layer
php artisan martis:cache:clear metrics            # clear one layer only
php artisan martis:cache:disable navigation       # runtime kill-switch
php artisan martis:cache:enable navigation        # runtime force-on
```

**Admin panel** at `/martis/system/cache`:

- Master switch banner (green when ON, amber when OFF).
- Table per layer with effective state, TTL, config flag, runtime override pill, version, last-cleared timestamp.
- Inline buttons per row: toggle, "reset to config" (drop the runtime override), clear.
- "Clear all" button in the header.
- Tooltips on every header and every button explaining the exact effect.

### Per-request bypass

For ad-hoc debugging without flipping any switch:

- `X-Martis-No-Cache: 1` header.
- `?nocache=1` query parameter (also accepts `?nocache=true`).

Both work on every cached endpoint. Useful for testing whether an issue is cache-related before changing config.

## Adding your own cache layer

The four built-in layers cover the package's own surfaces. Apps that want the same control plane around their own cached endpoints can register additional layers — they automatically inherit Artisan commands, REST endpoints, the admin page, and the runtime override system.

### Step 1 — register the layer at boot

In `app/Providers/MartisServiceProvider.php` (published by `martis:install`):

```php
// name, default enabled, default TTL (minutes; null = no expiration)
protected function registerCacheLayers(): void
{
    MartisCache::extend('orders',  enabled: true, ttl: 30);
    MartisCache::extend('reports', enabled: true, ttl: null);
}
```

The signature is `MartisCache::extend(string $name, bool $enabled = true, ?int $ttl = null)`. The defaults take effect when the host app does not declare a matching `config/martis.php → cache.{name}` entry.

> Built-in names (`metrics`, `navigation`, `dashboards`, `schema`) are protected — calls that try to redefine them are silently ignored to keep the framework's own layers immutable.

### Step 2 — (optional) declare the layer in config

If you want admins or env to override the layer from outside code, add it to the published `config/martis.php`:

```php
'cache' => [
    // ...

    'orders' => [
        'enabled' => env('APP_CACHE_ORDERS_ENABLED', true),
        'ttl'     => env('APP_CACHE_ORDERS_TTL', 30),
    ],
],
```

When config and `extend()` defaults disagree, **config wins**. That's the standard precedence: env > config > extension default.

### Step 3 — use the service from your code

```php
use Martis\Cache\MartisCache;

class OrdersController
{
    public function show(Request $request, MartisCache $cache, int $id)
    {
        $userKey = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');

        $payload = $cache->remember('orders', "show:{$id}:{$userKey}", function () use ($id) {
            return Order::with('lines')->findOrFail($id)->toArray();
        });

        return response()->json($payload);
    }
}
```

`remember()` honours every plane: master switch, config, runtime override, request bypass. Disabled or bypassed = your closure runs but the result is not cached.

You can pass a per-call TTL override as the fourth argument when needed:

```php
$cache->remember('orders', $key, $callback, ttlMinutesOverride: 5);
```

### What you get for free

Once the layer is registered:

- `php artisan martis:cache:status` lists it alongside the built-ins.
- `php artisan martis:cache:clear orders` invalidates only that layer.
- `php artisan martis:cache:disable orders` kill-switches it at runtime.
- `POST /martis/api/cache/clear` with `{ "type": "orders" }` works.
- The admin page renders a row for it — toggle, reset, clear, version, cleared_at — without any extra UI code.

### Cleaning up extensions in tests

`MartisCache::extend()` writes to a static registry, so tests that register layers should clean up:

```php
beforeEach(fn () => MartisCache::extend('reports'));
afterEach(fn () => MartisCache::forgetExtension('reports'));
```

## Invalidation

Per-layer **version key**: `martis:cache:version:{type}`. Every cached entry is keyed by `martis:cache:{type}:v{version}:{rest}`. Clearing a layer just increments the counter — every old key becomes orphaned and the next request recomputes. O(1) on every cache backend (no tagging support required).

```bash
# Atomic invalidation, no traversal of the cache store needed
php artisan martis:cache:clear schema
```

The button in the admin panel does the same.

Old keys linger until natural expiration (or until the store evicts them under pressure). On Redis with `maxmemory-policy: allkeys-lru` they get evicted quickly; on the file driver they sit on disk until expired. Functionally the cache is invalidated immediately; physically the orphans go away later.

## Cache store

Martis uses Laravel's default cache store (`Cache::store()`). Every cache key is namespaced under `martis:` so it never collides with the host application's keys. To pin Martis to a specific store, point `CACHE_STORE` to a dedicated connection in `config/cache.php`.

## Operational metadata is DB-backed (v1.8.8)

The version counter, `cleared_at` timestamp and runtime override flag for every layer live in a dedicated `martis_cache_state` table rather than in the cache store itself. One row per layer:

| Column | Type | Purpose |
|---|---|---|
| `type` | `string` (PK) | Layer name (`metrics`, `navigation`, `dashboards`, `schema`, or any custom layer name registered via `MartisCache::extend()`). |
| `version` | `unsignedInteger` (default 1) | Per-layer version counter — bumped on every `clear()`. |
| `cleared_at` | `timestamp` (nullable) | Timestamp of the last `clear()` call. |
| `override` | `boolean` (nullable) | `null` = inherit config, `true` = forced ON, `false` = forced OFF. |
| `created_at` / `updated_at` | timestamps | Standard Eloquent audit columns. |

This is **transparent to consumer code** — the public `MartisCache` API, the four Artisan commands, the REST endpoints and the admin page all stayed identical. The only operator-visible change is that the table appears after the next `php artisan migrate`. Everything else (config keys, env vars, custom layer registration via `MartisCache::extend()`, runtime override semantics) works exactly as before.

**One-time migration**

Existing installs upgrading to v1.8.8 must run:

```bash
php artisan vendor:publish --tag=martis-cache-state-migration
php artisan migrate
```

`martis:install --force` already publishes this stub, so a fresh install picks it up automatically. Until the migration is applied, the new code falls back to the historical defaults (version=1, cleared_at=null, override=null) — the runtime override and version trail are simply not persisted, identical to the v1.8.7 behaviour after a `cache:clear`. No request will fail.

**Performance**

`MartisCache` is bound as `app->scoped()` (per-request singleton). On the first call to `remember()` / `clear()` / `enabled()` / `status()` in a given request, the service issues a single `SELECT type, version, cleared_at, override FROM martis_cache_state` and indexes the result in memory. Subsequent calls in the same request are zero-DB. Mutations (`clear()`, `disable()`, `enable()`, `clearOverride()`) issue one `UPDATE OR INSERT` and update the in-memory map directly.

For long-running processes (Octane, queue workers) the per-instance cache is reset between requests / jobs through Laravel's request lifecycle. Use `MartisCache::refreshState()` if a worker holds the instance across multiple jobs and wants to pick up changes made by other workers.

## REST API

Endpoints under `/{martis-path}/api/cache`. Every endpoint requires the `manage-martis-cache` Gate.

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/cache` | Status snapshot — every layer plus `master_enabled`. |
| `POST` | `/cache/clear` | Body: `{ type? }`. Clear one layer or every layer. |
| `POST` | `/cache/disable` | Body: `{ type }`. Runtime override = false. |
| `POST` | `/cache/enable` | Body: `{ type }`. Runtime override = true. |
| `POST` | `/cache/reset-override` | Body: `{ type }`. Drop the runtime override (fall back to config). |

Custom layers registered via `extend()` are valid `type` values for every endpoint.

## Diagnostic checklist

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Schema changes don't show up | `schema` cache hit | Clear it: `php artisan martis:cache:clear schema` |
| Slow first navigation render after deploy | `navigation` recomputed cold | Warm with a request, or pre-fetch on deploy |
| Stale metric values after data write | `metrics` TTL not elapsed | Clear metrics or call `MartisCache::clear('metrics')` from your write path |
| User A sees user B's nav | Cache key did not include the auth identifier | Confirm the controller (or your custom layer) prepends `$userKey` to the cache key, like `NavigationController` does |
| Admin says master is OFF in production | `MARTIS_CACHE_ENABLED=false` somewhere | Check env, then `php artisan config:clear` |
| Custom layer toggles don't appear in admin | `extend()` not called at boot | Add the call inside `MartisServiceProvider::registerCacheLayers()` (the stub published by `martis:install`) |
| Admin shows "V1 · cleared at —" right after I clicked Clear | Pre-v1.8.8 install, metadata still in cache → wiped by something | Upgrade to v1.8.8 and run `php artisan vendor:publish --tag=martis-cache-state-migration && php artisan migrate`. Metadata then survives every cache flush. |
| Runtime disable resets to "Inherit" after a deploy | Same as above (override stored in cache pre-1.8.8) | Same fix — DB-backed override survives deploys. |

## Reference: `MartisCache` API

```php
use Martis\Cache\MartisCache;

// Built-in list (frozen). Extensions add to it via static registry.
MartisCache::TYPES;                 // ['metrics', 'navigation', 'dashboards', 'schema']

// Live list including custom layers registered via extend().
MartisCache::types();               // list<string>

// Static registry — call from a service provider's boot().
MartisCache::extend(string $name, bool $enabled = true, ?int $ttl = null);
MartisCache::forgetExtension(string $name);

// Service (resolved from container as a singleton).
$cache->masterEnabled(): bool;                  // master switch state
$cache->enabled(string $type): bool;            // effective state
$cache->ttl(string $type): ?int;                // minutes, null = no expiration
$cache->remember(string $type, string $key, Closure $cb, ?int $ttl = null);
$cache->clear(?string $type = null);            // null = clear every layer
$cache->disable(string $type);                  // runtime override
$cache->enable(string $type);
$cache->clearOverride(string $type);
$cache->status(): array;                        // full snapshot
$cache->bypassed(?Request $r = null): bool;     // header / query check
```

`remember()` short-circuits when the layer is disabled or the request is bypassed — your callback runs but the result is not cached.
