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

**Per-user scoping** is automatic for `navigation`, `dashboards` and `schema` because the response shape depends on policy results. Two users with different permissions never share a cached payload — Martis includes the user identifier in the cache key.

## Reading the admin page columns

| Column | Meaning |
|---|---|
| **Type** | Layer identifier — `metrics`, `navigation`, `dashboards`, `schema`, or any custom layer registered via `MartisCache::extend()`. |
| **Effective** | The live answer to "is this layer caching right now?". Computed from the master switch, the runtime override, and the config — in that order. This is what's actually applied, not just what the config says. |
| **TTL** | Time-to-live for entries in this layer, in minutes (`5m`) or `No expiration` when `null`. With no TTL, entries live until explicitly cleared. |
| **Config** | Static `enabled` value declared in `config/martis.php → cache.{type}` (or its env override). Ignores runtime toggles. Useful to spot drift between deploy-time config and runtime overrides. |
| **Runtime** | Persistent runtime override that survives restarts. Three states:<br>• **Inherit** — no override, the config wins.<br>• **Forced ON** — override = true, beats `config_enabled = false`.<br>• **Forced OFF** — override = false, beats `config_enabled = true`.<br>Stored in cache itself under `martis:cache:overrides`, so it sticks across queue worker / fpm restarts. |
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

Runtime overrides are **persisted in the cache store itself** under a single `martis:cache:overrides` key. They survive restarts, queue worker reboots, php-fpm reloads, and `php artisan config:cache`. The only thing that wipes them is `php artisan cache:clear` — which is intentional (you want a way to fully reset state).

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
- `?nocache=1` query parameter.

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
| User A sees user B's nav | Should never happen — file an issue | Per-user scoping is automatic; this is a regression |
| Admin says master is OFF in production | `MARTIS_CACHE_ENABLED=false` somewhere | Check env, then `php artisan config:clear` |
| Custom layer toggles don't appear in admin | `extend()` not called at boot | Add the call inside `MartisServiceProvider::registerCacheLayers()` (the stub published by `martis:install`) |

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
