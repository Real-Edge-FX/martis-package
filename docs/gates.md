# Soft-gates and badges

> Available since v1.11.0.

Martis ships two complementary primitives for controlling who sees what in the panel:

- **Hard hide via `canSee(Closure)`** — the entity is filtered out before the menu is built. Pre-existing behaviour; suitable for "this user is not an admin".
- **Soft-gate via `lockedFor(Closure)`** — the entity stays visible (with a configurable badge + lock icon), the click is intercepted, and a customisable modal opens instead of navigating. Suitable for "this is a Pro feature, upgrade to unlock".

Plus the decorative companion:

- **Tag pill via `withBadge(string $text, string $tone)`** — adds a small label next to the entry in the sidebar (and any other surface that lists the entity). Decorative; does not affect access.

All three primitives are available on `Dashboard`, `Tool`, `Resource`, `Card`, `Lens`, and `Filter`.

## Tags

`withBadge(string $text, string $tone = 'neutral')` attaches a pill rendered next to the item label.

```php
class ProLabDashboard extends Dashboard
{
    public function __construct()
    {
        parent::__construct(name: 'Pro Lab', uriKey: 'pro-lab');
        $this->withBadge('Pro', 'accent');
    }
}
```

Tones map to the Badge field palette: `neutral` (default), `info`, `success`, `warning`, `danger`, `accent`.

When both the entity class **and** a custom `MenuItem::withBadge(...)` set a badge, the `MenuItem` builder wins — same precedence rule used for the rest of the menu item config.

## Soft-gates

```php
class ProLabDashboard extends Dashboard
{
    public function __construct()
    {
        parent::__construct(name: 'Pro Lab', uriKey: 'pro-lab');

        $this->withBadge('Pro', 'accent')
             ->lockedFor(fn (Request $r) =>
                 ! ($r->user()?->hasRole('pro') || $r->user()?->hasRole('admin'))
             )
             ->lockModal([
                 'title' => __('edgeflow.gates.pro.title'),
                 'message' => __('edgeflow.gates.pro.message'),
                 'cta' => [
                     'label' => __('edgeflow.gates.pro.cta'),
                     'url' => '/billing/upgrade?plan=pro',
                 ],
             ]);
    }
}
```

`lockedFor`'s closure returns `true` when the user **is locked**. The lock state propagates into the descriptor (`lock: { reason, modal }`); the SPA paints the lock icon, intercepts the click, and shows the modal. Direct URL access is stopped server-side, on the page endpoint and on every endpoint that serves the entity's data: the page endpoints of a dashboard and a tool return `{ locked: true, lock: {...} }` so the page renders the same lock state full-page, and the data endpoints answer `403` with the same `lock` (see [What a lock stops](#what-a-lock-stops-on-the-server)).

### What a lock stops on the server

> Every data endpoint refuses a locked entity since v2.4.0. Before, only the two page endpoints (`GET /api/dashboards/{uriKey}` and `GET /api/tools/{uriKey}`) did: a user a plan locked out of an entity could still read its data, and write through it, with the URLs the page never calls.

A lock withholds the entity's **data**, not only its page. For a user the lock closes out:

| Entity | Page endpoint | Data endpoints (`403` with the lock) |
|--------|---------------|--------------------------------------|
| `Dashboard` | `GET /api/dashboards/{uriKey}`: `200 { locked: true, lock }`, no cards or filters; its `meta` is left out of that page and of the dashboard list | `GET /api/dashboards/{uriKey}/cards/{card}` |
| `Tool` | `GET /api/tools/{uriKey}`: `200 { locked: true, lock }`; its `meta` is left out of that page and of the tool list | `GET /api/tools/{uriKey}/fields`, `GET /api/tools/{uriKey}/fields/{field}/options`, and every route the tool loads itself (`martis.tool`, see [Tools](tools.md#tool-routes-and-their-middleware)); a request that does not expect JSON gets a plain `403` |
| `Resource` | none: the sidebar intercepts the click, and the SPA opens the modal when a page's request answers the lock | every route under `/api/resources/{resource}`: the schema, the list, a record's read and write (create, update, delete, restore, replicate, peek), its relationship routes (panels, attach, detach, pivot), its actions, lenses and cards, its pickers and option searches, inline create, sync and the slug check |
| `Lens` | | `GET /api/resources/{resource}/lenses/{lens}` and the lens action routes (list, fields, pickers, run) |
| `Card` | | `GET /api/resources/{resource}/cards/{card}` and `GET /api/dashboards/{uriKey}/cards/{card}`; the card's `meta` is left out of the dashboard and schema payloads |
| `Filter` | | none: a locked filter is **not applied**, whatever `?filters=` names (resource index, lens, dashboard cards); its `options` (`options()` is not even called) and `meta` are left out of the schema and dashboard payloads, the descriptor and the `lock` staying |

A `Resource` declares its lock in its constructor (the middleware builds it without a record, as the sidebar does):

```php
class ForecastResource extends Resource
{
    public function __construct(?Model $model = null)
    {
        parent::__construct($model);

        $this->requirePlan('pro')->lockPreset('pro');
    }
}
```

The refusal is one shape on every data endpoint: `403` with the error envelope and the lock, so a client reads the same `lock` it reads on the page endpoint:

```json
{
    "message": "This feature is locked for your account.",
    "errors": [],
    "locked": true,
    "lock": { "reason": "plan:pro", "modal": { "title": "...", "message": "...", "cta": { "label": "...", "url": "..." } } }
}
```

The SPA raises the window event `martis:locked` (`detail` is the `lock`) when a request answers it, and the gate modal opens, so a user who lands on a locked resource's URL sees the upsell the sidebar shows.

The records of a locked resource are also withheld where **another** resource lists them: the global search leaves the resource out, a picker (`BelongsTo`, `MorphTo`, `Tag`) and an attach list that would list its records answer `403`, a write that names one of its records answers `422` (the picker never offered it), a relationship panel (`HasMany`, `BelongsToMany`, ...) that lists them is left out of the detail page and its routes answer `403` with the lock payload (`locked: true`, `lock`), as every endpoint of the locked resource does (a user who may not list the related resource, its `viewAny`, gets the plain `403` instead), and the sidebar shows no live count for it. What a parent resource shows of a related record inside its own row (the title a `BelongsTo` column prints) is the parent's data: hide it with the field's `canSee()`.

The lock of a card is looked up only on a route that names the card (`/cards/{card}`): `cards()` is not built on the index, show or write routes of a resource or dashboard for the lock's sake.

The lock is evaluated on every request, never cached, so a plan change lands on the next one. The payloads cached per user that depend on it carry the lock state in their cache key (the dashboard list, a dashboard and a resource's schema, which leave a locked card's, tool's, dashboard's or filter's `meta` and a locked filter's `options` out), and a metric's cached result is keyed on the dashboard filters that are applied, never on one a lock skips, nor on a `filters` value that applies nothing (not JSON, not a map, empty, an unknown key): those leave the key as it is with no filters. The sidebar's count badges follow the [navigation cache](cache.md): a locked resource shows none once its entry expires.

`canSee()` and the policies win over the lock, as in [`canSee` vs `lockedFor` precedence](#cansee-vs-lockedfor-precedence): a user who may not see the entity (a Resource's `viewAny`, a Dashboard's, Tool's, Lens's or card's `authorizedToSee()`) is answered as before, a `404` or a `403` without a lock, and the lock is only told to a user who may see it. An entity that is not locked answers as before.

The routes of your own that name a Martis resource, dashboard or tool (`Route::get('/x/{resource}', ...)` on the [`martis.api`](tools.md#tool-routes-and-their-middleware) group) are not gated: add the `martis.gate` middleware to a route whose `{resource}`, `{lens}`, `{card}`, `{dashboard}` or `{uriKey}` parameter names the entity, and it refuses a locked one. A `Metric` class has no lock API of its own: lock the dashboard, the resource or the `Card` that holds it. A custom card class that uses `Martis\Concerns\HasGate` is refused on its compute route like a `Card`.

### Modal payload

| Key | Type | Default | Notes |
|-----|------|---------|-------|
| `title` | string | `Locked feature` (i18n) | Header text |
| `message` | string | i18n default | Body copy |
| `messageHtml` | bool | `false` | When `true`, the body is rendered as HTML. Links, bold, code, lists and line breaks stay; scripts, event handlers, unsafe URLs, the `style` attribute, `id`, `name`, forms and form controls are removed (v2.4.0), but keep user data out of it. |
| `cta` | `{label, url, target?}` | none | Primary action button; opens the URL on click. The URL is a web link (`http(s)`), a path, or `mailto:` / `tel:`: since v2.4.0 a link with any other scheme (`javascript:`, `data:`, ...) is rendered without an `href`. |
| `dismiss` | bool | `true` | When `false` the modal can only be closed via the CTA |
| `icon` | string (Phosphor name) | `lock` | Header icon |

### Presets

For repeated upsells, declare a preset in `config/martis.php` and apply it with `lockPreset(string $name)`:

```php
// config/martis.php
'gates' => [
    'presets' => [
        'pro' => [
            'badge' => ['text' => 'Pro', 'tone' => 'accent'],
            'modal' => [
                'title' => 'This is a Pro feature',
                'message' => 'Upgrade to unlock the Pro Lab and ML forecasts.',
                'cta' => [
                    'label' => 'Upgrade to Pro',
                    'url' => '/billing/upgrade?plan=pro',
                    'target' => '_self',
                ],
                'dismiss' => true,
            ],
        ],
    ],
],

// In the entity class:
$this->lockedFor(fn ($r) => ! $r->user()?->hasRole('pro'))
     ->lockPreset('pro');  // applies badge + modal in one call
```

### Plan rank shortcut — for linear-tier SaaS

`requirePlan(string $tier)` is a convenience over `lockedFor` for the **most common SaaS shape**: a linear hierarchy of plans where each tier strictly includes everything below it (free ⊂ starter ⊂ pro ⊂ admin). This is the right tool for ~60–70% of SaaS panels. For non-linear models (feature flags, add-ons sold separately, multi-tenant tenant-plan, seat-based access) reach for the lower-level `lockedFor(Closure)` instead — same gate machinery, no opinion about hierarchy.

When you want to use it, declare the resolver and the rank table:

```php
// config/martis.php
'gates' => [
    // The plan resolver is the only integration point with the host
    // app's billing layer. The package never imports Spatie / Cashier /
    // any specific package: the class below is what bridges them.
    'plan_resolver' => \App\Martis\PlanResolver::class,

    // Hierarquia. `requirePlan('pro')` locks every user whose resolved
    // plan ranks below the 'pro' entry. Higher rank = higher tier.
    // The package ships an EMPTY default; the host MUST declare its
    // own tiers — names are app-specific.
    'plan_rank' => [
        'free'    => 0,
        'starter' => 1,
        'pro'     => 2,
        'admin'   => 3,
    ],
],
```

```php
// app/Martis/PlanResolver.php
namespace App\Martis;

use Illuminate\Contracts\Auth\Authenticatable;

class PlanResolver
{
    public function __invoke(?Authenticatable $user): ?string
    {
        // Spatie roles (simplest; conflates RBAC with billing):
        return $user?->roles->pluck('name')
            ->intersect(['admin', 'pro', 'starter', 'free'])
            ->first();

        // Cashier subscription (Stripe-driven; richer state):
        //   return $user?->subscribed('default')
        //       ? config('billing.price_to_plan')[$user->subscription('default')->stripe_price]
        //       : 'free';
        //
        // Custom column on the user (cheapest read):
        //   return $user?->plan_name ?? 'free';
        //
        // Multi-tenant (plan lives on the tenant, not the user):
        //   return $user?->currentTeam?->plan_name ?? 'free';
    }
}
```

```php
// In the entity class:
$this->withBadge('Pro', 'accent')
     ->requirePlan('pro')
     ->lockPreset('pro');
```

`requirePlan` evaluates `current_rank < required_rank → locked`. **Without a resolver configured, every user is treated as having no plan (rank −1) and is locked from every declared tier** — fail-closed, intentional. Hosts that call `requirePlan` without configuring the resolver get a permanently locked panel until they wire it up.

#### `config:cache` and the resolver shape

`php artisan config:cache` serialises the config with `var_export()`, which keeps only strings, numbers, booleans and arrays. Two shapes of resolver survive it:

```php
// 1. The name of an invokable class. Martis builds it through the
//    container, so its constructor can take dependencies.
'plan_resolver' => \App\Martis\PlanResolver::class,

// 2. A [Class::class, 'method'] array naming a public static method.
'plan_resolver' => [\App\Martis\PlanResolver::class, 'resolve'],
```

A closure, or an instance such as `new PlanResolver`, fails the cache: `config:cache` stops with `Your configuration files could not be serialized because the value at "martis.gates.plan_resolver" is non-serializable`. Setting the closure from a service provider's `boot()` with `config()->set()` fails the same way, because `config:cache` boots every provider before it writes the cache. A closure inline in `config/martis.php` therefore only works while the config is not cached, and production deploys typically cache it.

A resolver that resolves to no callable (a misspelt class name, a class without `__invoke()`, an instance method in the array form) throws an `InvalidArgumentException` naming `martis.gates.plan_resolver`, instead of locking every user the way a missing resolver does. [Config keys that take a callable](configuration.md#config-keys-that-take-a-callable) lists the rules every callable key shares.

### When NOT to use `requirePlan`

The plan ranker assumes:

- **Linear hierarchy**: every higher tier strictly includes every lower tier.
- **One tier per user**: the resolver returns one plan name string.
- **Snapshot**: evaluated per request; no time window awareness (trial, grace period, etc.) beyond what the resolver itself encodes.

Fall back to `lockedFor(Closure)` directly when:

- You sell **add-ons** orthogonal to the tier ("Pro includes Analytics, Voice is bought separately").
- You use **feature flags** (LaunchDarkly, Unleash) — the gate is "has the flag" not "ranks high enough".
- The plan lives on the **tenant** and the user has different plans per team.
- You need **per-feature gating** independent of plan ("this user has been allow-listed for the beta").

In those cases, `lockedFor(fn ($r) => ! $r->user()?->canAccessFeature('pro-lab'))` keeps the same UI affordance (badge + modal + route guard) without forcing your access model into a linear rank.

## `canSee` vs `lockedFor` precedence

Two mechanisms for two different intents. They compose with explicit precedence: **`canSee` wins**.

- `canSee` returns `false` → entry filtered out before the menu is built. User never sees it. `lockedFor` is not evaluated.
- `canSee` returns `true` AND `lockedFor` returns `true` → entry visible with badge + lock. Click shows modal. Direct URL → locked payload, and the data endpoints answer `403` with it ([What a lock stops](#what-a-lock-stops-on-the-server)).
- `canSee` returns `true` AND `lockedFor` returns `false` → normal access.

Pick one per entity:

- **`canSee`** for "this user should not even know this exists" (admin-only resources, multi-tenant scoping).
- **`lockedFor`** for "this user can see what they would buy" (plan-gated features, upsell surfaces).

## Policy binding (Dashboard, Tool)

`Martis\Concerns\HasPolicy` lets `Dashboard` and `Tool` consume Laravel Policy classes the same way `Resource` does:

```php
class ProLabDashboard extends Dashboard
{
    public static ?string $policy = ProLabPolicy::class;

    public function __construct()
    {
        parent::__construct(name: 'Pro Lab', uriKey: 'pro-lab');
    }
}

// app/Martis/Policies/ProLabPolicy.php
class ProLabPolicy
{
    public function view(User $user): bool
    {
        return $user->hasAnyRole(['pro', 'admin']);
    }
}
```

Auto-discovery follows the same `martis.policy_namespace` config the Resource resolver uses, with the entity suffix stripped (`ProLabDashboard` → `ProLabPolicy`). When a policy is configured, `authorizedToSee()` consults `Policy::view` first; the `canSee(Closure)` closure remains available as a fallback for hosts that do not use Laravel Policies.

Resolution order (v1.36.0): explicit `$policy`, then the convention, then a policy the host registered for the entity class itself with `Gate::policy(ProLabDashboard::class, ...)` or that Laravel guesses. The check runs through Laravel's Gate (`Gate::before()` / `after()` hooks and `GateEvaluated` listeners apply), and Martis registers the resolved policy for the entity class with the Gate on the first check, so declaring `$policy` is enough. Before v1.36.0 the entity was silently hidden unless the host also called `Gate::policy()` by hand.

> v1.11.0 wires the policy check into `Dashboard` and `Tool`. Cards, Lenses, and Filters get the trait imported but unwired — they continue to use `canSee` only until v1.11.1 ships the auth-pipeline migration.
