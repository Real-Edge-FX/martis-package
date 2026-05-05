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

`lockedFor`'s closure returns `true` when the user **is locked**. The lock state propagates into the descriptor (`lock: { reason, modal }`); the SPA paints the lock icon, intercepts the click, and shows the modal. Direct URL access is stopped server-side: `MetricController::show` and `ToolsController::show` return `{ locked: true, lock: {...} }` so the page renders the same lock state full-page.

### Modal payload

| Key | Type | Default | Notes |
|-----|------|---------|-------|
| `title` | string | `Locked feature` (i18n) | Header text |
| `message` | string | i18n default | Body copy |
| `messageHtml` | bool | `false` | When `true`, the body is rendered with `dangerouslySetInnerHTML`. Trusted source only. |
| `cta` | `{label, url, target?}` | none | Primary action button; opens the URL on click |
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
    // any specific package — the closure below is what bridges them.
    //
    // Examples for each common stack:
    //
    //   Spatie roles (simplest; conflates RBAC with billing):
    //   fn ($u) => $u?->roles->pluck('name')->intersect(['admin','pro','starter','free'])->first()
    //
    //   Cashier subscription (Stripe-driven; richer state):
    //   fn ($u) => $u?->subscribed('default')
    //                  ? config('billing.price_to_plan')[$u->subscription('default')->stripe_price]
    //                  : 'free'
    //
    //   Custom column on the user (cheapest read):
    //   fn ($u) => $u?->plan_name ?? 'free'
    //
    //   Multi-tenant (plan lives on the tenant, not the user):
    //   fn ($u) => $u?->currentTeam?->plan_name ?? 'free'
    //
    'plan_resolver' => fn (?Authenticatable $user): ?string =>
        $user?->roles->pluck('name')
            ->intersect(['admin', 'pro', 'starter', 'free'])
            ->first(),

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
// In the entity class:
$this->withBadge('Pro', 'accent')
     ->requirePlan('pro')
     ->lockPreset('pro');
```

`requirePlan` evaluates `current_rank < required_rank → locked`. **Without a resolver configured, every user is treated as having no plan (rank −1) and is locked from every declared tier** — fail-closed, intentional. Hosts that call `requirePlan` without configuring the resolver get a permanently locked panel until they wire it up.

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
- `canSee` returns `true` AND `lockedFor` returns `true` → entry visible with badge + lock. Click shows modal. Direct URL → locked payload.
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

> v1.11.0 wires the policy check into `Dashboard` and `Tool`. Cards, Lenses, and Filters get the trait imported but unwired — they continue to use `canSee` only until v1.11.1 ships the auth-pipeline migration.
