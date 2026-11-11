# Cache (⭐ Martis differential)

> Per-subsystem cache layer with three control planes (config / env / runtime), per-request bypass, atomic version-key invalidation, custom-layer extensibility, and a built-in admin page. Lives at `Sistema → Cache`.

Most admin panels make caching opaque: TTLs hidden in code, invalidation by deploy, no kill-switch. Martis flips that: every cache layer has a name, an effective state, and a button.

Developers read the config and trust it. Ops have a switch they can flip in production without redeploying. Apps add their own layers in three lines.

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
| **System → Cache (built-in by Martis)** | Gated by Laravel's Gate `manage-martis-cache`. **The default DENIES everyone** — flushing/disabling the cache is destructive and privileged, so you must grant the ability explicitly (see below). The entry also disappears wholesale when `cache.admin_ui = false`, and is not appended when your own `Martis::mainMenu(...)` already links to `/system/cache` (v1.38.0+). Its position inside the System section is `cache.admin_ui_order` (default `1000`, last); see [Menus → Order inside the System section](menus.md#order-inside-the-system-section-v1380). |

In short: **if a user lacks the policy, the sidebar entry never renders, the route returns 403, and the API endpoint behind it returns 403 too.** The three layers are checked independently.

### Granting the cache admin gate

The `manage-martis-cache` gate **denies by default** — out of the box nobody reaches `/martis/system/cache`, the sidebar entry is hidden, and `/martis/api/cache/*` returns 403. To grant access, define the gate from your `MartisServiceProvider` (published by `martis:install` at `app/Providers/MartisServiceProvider.php`):

```php
// app/Providers/MartisServiceProvider.php
protected function registerGates(): void
{
    Gate::define('manage-martis-cache', fn ($user) => $user->is_admin);
}
```

Calling `Gate::define()` from the host app replaces Martis's default closure, so order doesn't matter. Until you define it, the cache admin surface stays locked: this is intentional — cache flush/disable is a destructive operation and the package will not grant it implicitly.

## The four built-in layers

| Type | What it caches | Default TTL | Per-user? |
|------|----------------|-------------|-----------|
| `metrics` | Computed metric results (Value, Trend, Partition, Progress, Activity feed, Endpoint table). | 5 minutes | **Yes** — keyed on the user, the locale, the range and the filters (guests share one entry). Before v2.0.0 the key had no user, so a `calculate()` scoped to the user served the first user's value to everyone for the TTL |
| `navigation` | Sidebar / top-nav structure. | 1 minute | **Yes** — different policies, different menus |
| `dashboards` | Dashboard list + per-dashboard definition (cards/filters metadata). Metric values are NOT cached here — that's `metrics`. | 5 minutes | **Yes** — and the list key carries a fingerprint of the set the user is authorized to see (see below) |
| `schema` | Resource schema payload (fields, filters, lenses, cards, actions). Heavy to compute, stable across requests. | 1440 min (one day, v2.0; before, no expiration) | **Yes** |

TTL `null` means "no expiration" — the entry stays cached until explicitly cleared (the version key trick: see [Invalidation](#invalidation) below).

**Metric results are cached per user (v2.0.0+).** A metric's `calculate()` receives the request and commonly scopes its query to the authenticated user, their tenant or their permissions, so a result cached without the user was served to everyone who opened the same card within the TTL: the first user's revenue, counts or totals leaked to the others. Laravel Nova keys its metric cache without the user; Martis puts the user the Martis guard signed in (`MARTIS_GUARD`) in the key of both metric cache paths (the `metrics` layer and a per-class `cacheFor()`), and guests share one entry; a user whose identifier is not an int, a string or `Stringable` gets no cached entry at all. What this means for an existing installation:

- **No previous entry is reused.** Every metric key changes at the upgrade, so the first request of each user computes the metric again; the old entries expire by their TTL (or `php artisan martis:cache:clear metrics` stops serving the layer's at once, leaving them as orphans for `martis:cache:prune` on the database and file stores; `cacheFor()` entries live in Laravel's cache and expire by their own lifetime).
- **More entries, fewer hits.** A metric whose value is the same for everyone is now computed and stored once per user instead of once in total. With many users and heavy global metrics, raise the TTL (`MARTIS_CACHE_METRICS_TTL`, or a longer `cacheFor()`), precompute the value (a scheduled job writing to a table the metric reads), or, for a metric whose result does not depend on who asks, leave the user out of its key with `$cachePerUser`:

```php
class TotalRevenue extends ValueMetric
{
    // The same value for everyone: one cached entry for all users.
    // Only for a calculate() that reads nothing of the user, their
    // tenant or their permissions.
    protected bool $cachePerUser = false;
}
```

**The dashboards list is an authorization snapshot that keeps itself fresh.** `MetricController::dashboards()` resolves the user's dashboards through `authorizedToSee()` on every request and caches only their serialized shape under `list:{user}:{locale}:{fingerprint}`, where the fingerprint hashes the class and `uriKey` of every dashboard that passed the gate. A dashboard registered after a user's list was cached, one removed at deploy, or a user whose state changes what `authorizedToSee()` answers therefore land on a fresh key immediately, with no `martis:cache:clear dashboards` and no TTL to wait out. The finite default TTL (5 minutes, `MARTIS_CACHE_DASHBOARDS_TTL`) bounds the orphaned entries and lets the cached shape itself (name, icon, badge, per-dashboard cards/filters metadata) converge after a release; set it to `null` to go back to "until cleared".

**Per-user scoping** is the convention used by Martis's own cached entries (`navigation`, `dashboards`, `schema`, `metrics`). Each one derives the auth identifier and puts it in the cache key — see `NavigationController`, `MetricController`, `ResourceController` and `Metric::resultCacheKey()`. `MartisCache::remember()` itself takes the key verbatim, so **custom layers must include the user identifier in their own keys** if they want the same isolation. The `OrdersController` example below shows the standard shape: `"show:{$id}:{$userKey}"`.

## Scoping the keys by host or tenant (v2.10.0)

Martis keys what it caches by the user and the locale, plus what each layer adds. It cannot know what else your code reads from the request:

| Layer | What the key carries today |
|-------|----------------------------|
| `navigation` | the user and the locale (`badges:` prefix for the sidebar counts) |
| `schema` | the resource, the user and the locale (and which locked cards and filters the user sees) |
| `dashboards` | the dashboard (or the list with the fingerprint of the authorized set), the user and the locale |
| `metrics` | the metric, the range, the filters, the locale and, unless `$cachePerUser = false`, the user; the same key serves a per-class `cacheFor()` |
| a lens with `cacheFor()` | the lens, the user, the search, the sort, the filters, the page and the locale |

A `Martis::mainMenu()` resolver, a field's options, a dashboard's cards, a metric's `calculate()` or a lens's `query()` that also depends on the **host** (one tenant per subdomain), a **tenant header**, a route parameter or a session value is cached as if it did not: the same user on `tenant-a.example` and `tenant-b.example` gets whichever payload was built first, for the TTL, and a metric without `$cachePerUser` shares its result across the users of every tenant. `Martis::cacheScopeUsing()` adds what the request carries to **every** key:

```php
use Illuminate\Http\Request;
use Martis\Facades\Martis;

// app/Providers/MartisServiceProvider.php, in boot()

// One tenant per host:
Martis::cacheScopeUsing(fn (Request $request): ?string => $request->getHost());

// The tenant a tenancy package resolved (here stancl/tenancy):
Martis::cacheScopeUsing(fn (Request $request): ?string => tenant()?->getTenantKey());

// A tenant header:
Martis::cacheScopeUsing(fn (Request $request): ?string => $request->header('X-Tenant'));
```

The resolver receives the current request (the request the layer is serving) and answers a string, or `null` (or `''`) for "no scope". Its answer is hashed into the key (`:s` and 32 hex characters), so any string is safe in any store and the key length stays bounded; the scope is part of the key before the 191-character limit is applied, so a long key still differs per scope. `Martis::cacheScopeUsing(null)` removes the resolver.

- **Without a resolver every key is what it was**, byte for byte: an app that does not call it needs no action.
- **It covers every layer**: `navigation` (and the badges), `schema`, `dashboards`, `metrics`, a custom layer that calls `MartisCache::remember()`, a metric's own `cacheFor()` and a lens's `cacheFor()`. A custom layer that builds its key itself and writes to Laravel's cache directly is not: add `app(MartisCache::class)->scopeSegment()` to its key.
- **Each scope has its own entries**, so the number of entries grows with the number of scopes, and a metric without `$cachePerUser` is shared by every user **of the same scope**, not by every user.
- **`martis:cache:clear` (and the admin button) still clears every scope of the `MartisCache` layers**: the layer's version sits in the key's prefix, ahead of the scope, so one increment orphans the entries of every host. A metric's `cacheFor()` entries and a lens's entries are written with `Cache::remember()` directly, and `martis:cache:clear` never clears them (with or without a scope): they expire by their TTL. `martis:cache:prune` treats scoped keys as live for the same reason.
- **A resolver that answers anything else than a string or `null` throws** (`UnexpectedValueException`), and so does an exception it raises. A scope that fell back to "none" without a word would share one tenant's entries with another, so Martis fails instead. The resolver runs on every cache read of the request: keep it cheap (read a value the request already resolved, not the database). In a console command or a queue job the resolver also runs, with the placeholder request Laravel's console kernel binds from `APP_URL` (no user, no route, no headers): write it null-safe (`$request->user()?->tenant_id`, not `$request->user()->tenant_id`). A value cached there is keyed by what the resolver returns for that request. Martis does not skip the scope when `runningInConsole()` is true: an Octane worker reports it as well, and would lose the scope on web requests.
- **There is no default scope, not even the host.** Nova has no equivalent, every app's keys would change at the upgrade, and a host scope by default would let any client multiply the entries of an app without trusted hosts (`TrustHosts`) through the `Host` header. The app that needs a scope declares it.

The panel's own navigation query (`['navigation']` in the browser) needs nothing: it lives in one tab on one host and does not cross hosts.

## Badge counts and metric TTLs in seconds (v2.20.0)

Four opt-in hooks, all off by default (every key, TTL and query count stays what it was): a cache of its own for one sidebar count, a TTL for the badge map in seconds, a batch resolver for several counts, and a TTL in seconds for metrics.

### One count, cached on its own: `menuCountCache()`

By default the count of a resource or tool is computed when the user's badge map is, and lives and dies with that map (`navigation` layer, one entry per user and locale). N admins on the panel therefore run every count N times per TTL. Declare `menuCountCache()` to cache the count by itself:

```php
class CustomerResource extends Resource
{
    // The same for every admin allowed to see the badge: one entry in total.
    public static function menuCountCache(): ?array
    {
        return ['scope' => 'shared', 'ttl' => 30];   // ttl in seconds
    }
}

class SupportQueue extends Tool
{
    // The tool's instance method, same shape.
    public function menuCountCache(): ?array
    {
        return ['scope' => 'user', 'ttl' => 15];
    }
}
```

| Key | Values | Default |
|-----|--------|---------|
| `scope` | `shared`: one entry for every user. `user`: one entry per user | `user` |
| `ttl` | whole seconds, 1 or more | `60` |

Return `null` (the default) to keep today's behaviour. An unusable declaration (an unknown scope, a `ttl` below 1 or not an integer) is a failure of that one badge: it is reported as a `MenuCountFailedException` like a throwing `menuCount()` (see [Menus](menus.md#when-a-badge-is-missing)) and the badge is skipped.

- **Use `shared` only when the count is the same for everyone who can see the badge**: a row total. A `scopes()` or `indexQuery()` that reads the user, a tenant or a permission makes the count differ per user, and a shared entry would serve the first user's number to the others. Queues a person works from belong on `user` or on no `menuCountCache()` at all.
- The entry lives in the `navigation` layer through `MartisCache`, so `martis:cache:clear navigation`, the enabled switches and the per-request bypass (`X-Martis-No-Cache` with the `bypass-martis-cache` gate) apply to it, and the scope an app registered with `Martis::cacheScopeUsing()` is part of the key **even for a `shared` count**: two tenants never see each other's number.
- The key names the resource (or tool) and, for `user`, the user. It leaves the locale out: a count does not depend on it.
- A `null` count (a hidden badge) is cached too, so it is not recomputed on every map.
- It applies to the counts of both `GET /api/navigation/badges` and the menu `GET /api/navigation` embeds.

### The badge map in seconds: `badge_ttl_seconds`

The per-user badge map is cached for the navigation TTL, which counts whole minutes (default 1). `martis.cache.navigation.badge_ttl_seconds` (env `MARTIS_CACHE_BADGE_TTL_SECONDS`) sets it in seconds instead; `null` (the default) keeps the navigation TTL in minutes. A value that is set but is not a whole number of seconds, 1 or more, throws and names the key: it is never silently ignored.

The map and the counts are two caches in series, so a count can be as old as its `ttl` plus the age of the map that read it. Set `badge_ttl_seconds` to the shortest `ttl` you declare (30 for a 30 second shared count), or a short `ttl` is held back by a longer map. The setting does not apply to the menu `GET /api/navigation` (it stays on the navigation TTL).

### Several counts in one query: `Martis::menuCountsUsing()`

```php
// app/Providers/AppServiceProvider.php
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Martis\Facades\Martis;

Martis::menuCountsUsing(function (Request $request, Collection $resources): array {
    // $resources: the resource classes about to be counted.
    // One UNION ALL for all of them; answer [ResourceClass => int|null].
    return MyCounts::unionFor($resources->all());
});
```

The resolver receives the current request and the class names of the resources whose badge is about to be resolved: those that show in the navigation, are routable, opt into `showMenuCount()`, pass `authorizedToViewAny()` and are not locked, so an unauthorised resource is never offered to it. It answers `[ResourceClass::class => ?int]`. A class it leaves out is counted by its own `menuCount()`, and `null` hides that badge.

- It is app-level (a closure) and not a static on `Resource`, because resources have no common owner the controller could ask: your base resource class, a service or a query builder is what the closure calls.
- It runs once per badge map built, lazily: a map whose counts all come from `menuCountCache()` entries never calls it. When it does run, it is offered every candidate, including those a cached entry would answer. What it answers for a resource that declares `menuCountCache()` is stored under that cache like any other count.
- It does not apply to tools, nor to the counts the menu `GET /api/navigation` embeds.
- A failure (it throws, or answers something that is not an array) is reported as a `MenuCountFailedException` for the badge key `batch` (the `martis.navigation.counts.report_failures` switch applies; with dev tools on it also shows under `_failed`) and every resource is then counted by its own `menuCount()`.
- `Martis::forgetMenuCounts()` removes it. Stale-while-revalidate (serving the cached map at once and recomputing in the background) is not part of this.

### Metrics: a TTL in seconds, `live()`

The `metrics` layer counts minutes (5 by default). Three opt-ins, in order of precedence:

1. `live()` / `protected bool $live = true`: the metric is computed on every request, never cached, whatever else is set.
2. A metric's own `protected ?int $cacheSeconds` (or `->cacheForSeconds(60)`), in seconds.
3. `martis.cache.metrics.default_ttl_seconds` (env `MARTIS_CACHE_METRICS_DEFAULT_TTL_SECONDS`), the seconds for every metric that sets none. `null` (the default) keeps `ttl` in minutes. A value that is set but is not a whole number of seconds, 1 or more, throws and names the key.

A metric that overrides `cacheFor()` keeps its pre-2.20 path (Laravel's cache store, outside this layer); `$cacheSeconds` and the default go through the `metrics` layer, so `martis:cache:clear metrics`, the switches, the bypass and the app cache scope apply to them. The entry stays per user unless the metric sets `$cachePerUser = false`. See [Metrics: caching](metrics.md#caching).

| Card | Cache | Use when |
|------|-------|----------|
| Money, balances, a just-edited figure | `live()` | the number must be current; the rest of the dashboard stays cached |
| A busy aggregate several admins open | `$cacheSeconds = 60`, `$cachePerUser = false` if it does not depend on the user | a minute-old number is fine; one query for all admins |
| Most metrics | the default (`default_ttl_seconds`, or `ttl` in minutes) | no per-card decision needed |

Custom `Card` classes (not metrics) have no server-side compute: a card is serialised with the dashboard shape (`dashboards` layer, per user) and its `meta` is whatever the card built then. There is nothing for a card-level TTL to cache, so `$cacheSeconds` and `live()` exist on metrics only. To serve a card with a cached, shared or live value, make it a `Metric` (its `calculate()` is computed by its own request and follows the rules above).

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
    'dashboards' => ['enabled' => true, 'ttl' => 5],     // 5 minutes
    'schema'     => ['enabled' => true, 'ttl' => 1440],  // one day (v2.0)

    'admin_ui'   => true,
    'admin_ui_order' => 1000,   // position of the "System cache" link in the System section (v1.38.0+)
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
MARTIS_CACHE_DASHBOARDS_TTL=5

MARTIS_CACHE_SCHEMA_ENABLED=true
MARTIS_CACHE_SCHEMA_TTL=1440

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
php artisan martis:cache:prune                    # delete the entries older versions left behind (v2.0)
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

Both signals are **gated by the `bypass-martis-cache` ability, which denies by default** — an ordinary authenticated user cannot force expensive metric / navigation / schema re-computation on every request. Grant it to privileged users in your service provider:

```php
Gate::define('bypass-martis-cache', fn ($user) => $user->is_admin);
```

Once granted, the header / query param skip the cache layer on every cached endpoint. Useful for testing whether an issue is cache-related before changing config.

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

Per-layer **version key**: `martis:cache:version:{type}`. Every cached entry is keyed by `martis:cache:{type}@{installed}:v{version}:{rest}`. Clearing a layer just increments the counter — every old key becomes orphaned and the next request recomputes. O(1) on every cache backend (no tagging support required).

`{installed}` is the `martis/martis` version Composer installed (what `composer show martis/martis` reports, e.g. `v2.0.0`; a dev version (a `dev-*` branch, or a numbered branch or alias such as `2.x-dev`) keeps its name across `composer update`, so its commit reference is appended: `dev-main@0123456789ab`, `2.x-dev@0123456789ab`), so **an upgrade rebuilds every layer** on its own, `schema` included, which would otherwise keep serving the previous version's payload until it expires. On a **path repository** (a local checkout linked with `"type": "path"`, like the Playground) Composer keeps the version of the last `composer install` / `update`, so editing the linked package does not change the key: run `php artisan martis:cache:clear` after pulling changes there. When Composer has no record of the package the segment is left out (`martis:cache:{type}:v{version}:{rest}`).

```bash
# Atomic invalidation, no traversal of the cache store needed
php artisan martis:cache:clear schema
```

The button in the admin panel does the same.

Old keys linger until natural expiration (or until the store evicts them under pressure). On Redis with `maxmemory-policy: allkeys-lru` they get evicted quickly; on the file driver they sit on disk until expired. Functionally the cache is invalidated immediately; physically the orphans go away later.

**Orphans after an upgrade.** Each upgrade (or `martis:cache:clear`) leaves the previous keys behind, in every layer. What removes them depends on the store:

| Store | An expired orphan | What to do |
|-------|-------------------|------------|
| `redis`, `memcached` | Dropped by the server on its own | Nothing, as long as the layer has a TTL; a layer set to `null` keeps its orphans until the server evicts them under memory pressure. |
| `database` (Laravel's default store since 11), `file` | **Never dropped**: Laravel deletes an expired entry only when that same key is read again, which an orphan never is | Run `php artisan martis:cache:prune` after an upgrade (or schedule it daily). |
| `array` | Gone at the end of the process | Nothing. |

`martis:cache:prune` (v2.0) deletes, on the database store, every `martis:cache:*` row that is expired or whose key no longer carries a live `{type}@{installed}:v{N}:` prefix (a hashed long key is kept until it expires), and on the file store every expired entry (the file names are hashes of the keys, so Martis entries cannot be told apart; an expired entry is dead for everyone). On any other store it reports that there is nothing to prune.

The `schema` layer ships a one-day TTL since v2.0 (`MARTIS_CACHE_SCHEMA_TTL=1440`), so its orphans are at least expired, which is what `martis:cache:prune` and Redis act on; it had none before. An empty value (`MARTIS_CACHE_SCHEMA_TTL=`, the `.env` block these docs showed up to 1.x) still means **no expiration**, and a `config/martis.php` published before v2.0 still reads `env('MARTIS_CACHE_SCHEMA_TTL', env('MARTIS_CACHE_SCHEMA', null))`: set `MARTIS_CACHE_SCHEMA_TTL=1440` to get the new default there.

**Long keys.** A key that would pass 191 characters once the store adds its own prefix (`CACHE_PREFIX`; the length a database cache column indexed under `utf8mb4` holds; memcached stops at 250 bytes) is hashed whole: `martis:cache:{type}:h:{sha256}`. The hash covers the installed version and the counter, so upgrades and `clear()` still invalidate it.

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

For long-running processes (Octane, queue workers) the per-instance cache is reset between requests / jobs through Laravel's request lifecycle (`forgetScopedInstances()`), the same mechanism that resets the policy-resolution memo described in [Authorization → How policy instances are resolved](authorization.md#how-policy-instances-are-resolved). Use `MartisCache::refreshState()` if a worker holds the instance across multiple jobs and wants to pick up changes made by other workers.

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
| Schema changes don't show up | `schema` cache hit (after an upgrade only on a path repository, whose installed version does not change) | Clear it: `php artisan martis:cache:clear schema` |
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

// Service (resolved from the container, bound with scoped(): one instance per request / job).
$cache->masterEnabled(): bool;                  // master switch state
$cache->enabled(string $type): bool;            // effective state
$cache->ttl(string $type): ?int;                // minutes, null = no expiration
$cache->remember(string $type, string $key, Closure $cb, ?int $ttl = null);
$cache->rememberFor(string $type, string $key, Closure $cb, int $ttlSeconds); // v2.20.0: TTL in seconds, same key and switches
$cache->clear(?string $type = null);            // null = clear every layer
$cache->disable(string $type);                  // runtime override
$cache->enable(string $type);
$cache->clearOverride(string $type);
$cache->status(): array;                        // full snapshot
$cache->bypassed(?Request $r = null): bool;     // header / query check
$cache->scopeSegment(?Request $r = null): string; // the Martis::cacheScopeUsing() segment (v2.10.0), '' without a scope
```

`remember()` and `rememberFor()` short-circuit when the layer is disabled or the request is bypassed: your callback runs but the result is not cached.
