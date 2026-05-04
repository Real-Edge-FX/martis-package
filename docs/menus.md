# Menus

Martis exposes a declarative, package-native navigation API.

Use it in two layers:

1. resource-level menu items via `menuItem()`
2. application-level menu composition via `Martis::mainMenu(...)`

## Resource Menu Items

Every resource can customize how it appears in navigation by overriding `menuItem()`:

```php
use Illuminate\Http\Request;
use Martis\Menu\MenuItem;
use Martis\Resource;

class TicketResource extends Resource
{
    public function menuItem(Request $request): MenuItem
    {
        return MenuItem::resource(static::class)
            ->label('Help Desk')
            ->icon('lifebuoy')
            ->path('/support/help-desk');
    }
}
```

If you do not override `menuItem()`, Martis generates a resource item automatically from:

- `label()`
- `singularLabel()`
- `subtitle()`
- `icon()`
- `group()`
- `displayInNavigation()`

## Main Menu Builder

Use the `Martis` facade to compose the main menu:

```php
use Illuminate\Http\Request;
use Martis\Facades\Martis;
use Martis\Menu\Menu;
use Martis\Menu\MenuItem;
use Martis\Menu\MenuSection;

Martis::mainMenu(function (Request $request, Menu $menu): Menu {
    return $menu->prepend(
        MenuSection::make('Quick Links', [
            MenuItem::link('Dashboard', '/')->icon('squares-four'),
            MenuItem::externalLink('Documentation', 'https://example.com/docs')->icon('book-open'),
        ])->collapsable(false)
    );
});
```

## Available Builders

### `Menu`

- `Menu::make([...])`
- `append(...)`
- `prepend(...)`
- `sections([...])`

### `MenuSection`

- `MenuSection::make(?string $label = null, array $items = [])` — `$label` is optional; omit it for an unnamed cluster
- `items([...])`
- `add(MenuItem|MenuGroup|class-string $item)` — accepts leaves OR a nested [`MenuGroup`](#nested-menugroup)
- `icon(...)`
- `collapsable(bool)`
- `path(?string $url)` — when set, the section header is rendered as a link to this URL (see [Clickable headers](#clickable-headers))
- `section(?string $section)` — assign this group to a higher-level section divider (see [Sections](#sections))
- `canSee(...)`
- `withMeta([...])`

### `MenuGroup`

Mid-level cluster nested **inside** a `MenuSection`. See [Nested MenuGroup](#nested-menugroup).

- `MenuGroup::make(string $label, array $items = [])`
- `items([...])`
- `add(...)`
- `icon(...)`
- `collapsable(bool)`
- `path(?string $url)` — clickable group label; see [Clickable headers](#clickable-headers)
- `canSee(...)`
- `withMeta([...])`

### `MenuItem`

- `MenuItem::make($label, $url)` — alias for `link()`
- `MenuItem::link($label, $url)`
- `MenuItem::externalLink($label, $url)`
- `MenuItem::resource(ResourceClass::class)`
- `MenuItem::tool(ToolClass::class | $toolInstance)` — see [Tool menu items](#tool-menu-items)
- `MenuItem::dashboard(DashboardClass::class | $dashboardInstance)` — see [Dashboard, Lens & Filter items](#dashboard-lens--filter-items)
- `MenuItem::lens(ResourceClass::class, LensClass::class)` — see [Dashboard, Lens & Filter items](#dashboard-lens--filter-items)
- `MenuItem::filter(string $label, ResourceClass::class)->applies(FilterClass::class, $value)` — see [Dashboard, Lens & Filter items](#dashboard-lens--filter-items)
- `label(...)`
- `icon(...)`
- `path(...)`
- `external(bool)`
- `withBadge(string $text, string $tone = 'neutral')` — decorative chip ("New", "Beta", "Pro"). See [Decorative badges](#decorative-badges)
- `canSee(...)`
- `withMeta([...])`

### Tool menu items

Since v1.8.20, every registered Tool is auto-grouped into the sidebar by default. A Tool that declares `withMenuSection('Operations')` lands in the "Operations" section; everything else goes under the localised "Tools" header (translation key `martis::messages.tools_section`, default English label `Tools`). You only need to call `MenuItem::tool(...)` when you build a fully custom main menu via `Martis::mainMenu(...)` and want a Tool placed alongside hand-rolled links.

```php
use App\Martis\Tools\HealthCheck;
use Martis\Menu\MenuItem;
use Martis\Menu\MenuSection;

// Custom main menu — auto-Tools sections are produced upstream and
// passed in via `$menu`. `MenuItem::tool(...)` lets you cherry-pick.
Martis::mainMenu(function ($request, $menu) {
    return $menu->prepend(
        MenuSection::make('Pinned', [
            MenuItem::tool(HealthCheck::class),
            MenuItem::tool(HealthCheck::class)->label('Status')->icon('pulse'),
        ])
    );
});
```

`MenuItem::tool()` accepts either a class-string or a tool instance, and reads `name()`, `uriKey()`, `icon()`, and `authorizedToSee()` lazily at request time. The rendered menu always reflects the live state of the tool — including authorization checks, which silently drop the item when the user is not allowed to see it.

Any combination of `label()`, `icon()`, and `path()` overrides the tool's defaults. Otherwise the item resolves to `/tools/<uriKey>` automatically.

#### Default Tools section

The auto-grouping pass runs **before** your custom `Martis::mainMenu(...)` resolver, so the resolver receives both the resource sections and the Tool sections in `$menu`. You can mutate, reorder, or filter them — same ergonomics as Resources. Manually placing a Tool with `MenuItem::tool(...)` after the auto-grouping ran will produce a duplicate entry; either drop the manual placement, or build sections from scratch and rely on `MenuItem::tool(...)` exclusively.

### Dashboard, Lens & Filter items

Three first-class factories save you from hand-writing URLs and replicating
authorization checks. All three resolve lazily at request time, mirroring
the lazy semantics of `MenuItem::tool()`.

```php
use App\Martis\Dashboards\SalesDashboard;
use App\Martis\Filters\StatusFilter;
use App\Martis\Resources\TicketResource;
use App\Martis\Resources\OverdueLens;
use Martis\Menu\MenuItem;
use Martis\Menu\MenuSection;

MenuSection::make('Operations', [
    // Resolves /dashboards/<uriKey>; respects Dashboard::authorizedToSee().
    MenuItem::dashboard(SalesDashboard::class),

    // Resolves /resources/tickets/lens/overdue; respects both
    // Resource::authorizedToViewAny() and Lens::authorizedToSee().
    MenuItem::lens(TicketResource::class, OverdueLens::class),

    // Builds /resources/tickets?filters={"status":"open"} (URL-encoded).
    MenuItem::filter('Open tickets', TicketResource::class)
        ->applies(StatusFilter::class, 'open')
        ->icon('lifebuoy'),
]);
```

Each factory accepts `label()`, `icon()`, `path()`, `withBadge()` and
`canSee()` like any other `MenuItem`. The factory-emitted URL is a
sensible default — call `path()` to override it.

The serialised `type` distinguishes them on the wire:

| Factory                    | Emitted `type` | URL pattern                                              |
|----------------------------|----------------|----------------------------------------------------------|
| `MenuItem::tool()`         | `tool`         | `/tools/<uriKey>`                                        |
| `MenuItem::dashboard()`    | `dashboard`    | `/dashboards/<uriKey>`                                   |
| `MenuItem::lens()`         | `lens`         | `/resources/<resource>/lens/<lensUriKey>`                |
| `MenuItem::filter()`       | `filter`       | `/resources/<resource>?filters=<json>` (URL-encoded JSON) |

### Decorative badges

`MenuItem::withBadge('New', 'success')` paints a small textual chip next
to the item label. Distinct from the [resource count badge](#count-badges):
the count badge is numeric and tenancy-aware; this one is purely decorative
and consumer-controlled.

```php
MenuSection::make('Workspace', [
    MenuItem::link('What is new', '/changelog')->withBadge('New', 'success'),
    MenuItem::link('AI Console', '/ai')->withBadge('Beta', 'warning'),
    MenuItem::resource(TicketResource::class)->withBadge('Pro', 'accent'),
]);
```

Available tones: `neutral` (default), `info`, `success`, `warning`,
`danger`, `accent`. They map to the same semantic palette used by the
Badge field.

### Clickable headers

By default a `MenuSection` (or `MenuGroup`) header is a collapse toggle.
Calling `->path('/url')` turns the header label into a link to that URL —
useful when the cluster has a dedicated landing page (Reports overview,
Settings index) you want users to reach with one click:

```php
MenuSection::make('Reports', [
    MenuItem::link('Daily', '/reports/daily'),
    MenuItem::link('Weekly', '/reports/weekly'),
])->path('/reports'); // header now links to /reports
```

The same API exists on `MenuGroup`. When `path()` is set, the collapse
chevron is omitted: the header is a single tap target.

### Nested `MenuGroup`

A `MenuSection` can host `MenuGroup` containers as items, giving you a
third level of nesting for dense sidebars:

```
MenuSection ── label, optional section divider
  └── MenuGroup ── mid-level cluster (icon, collapsable, path)
        └── MenuItem (resource / link / lens / dashboard / ...)
```

Reach for `MenuGroup` when a section gets too long to scan at a glance —
typical example, a "Settings" section with separate Auth, Tenancy and
Billing clusters:

```php
use Martis\Menu\MenuGroup;
use Martis\Menu\MenuItem;
use Martis\Menu\MenuSection;

MenuSection::make('Settings', [
    MenuGroup::make('Auth', [
        MenuItem::resource(UserResource::class),
        MenuItem::resource(RoleResource::class),
        MenuItem::resource(PermissionResource::class),
    ])->icon('lock-key')->path('/settings/auth'),

    MenuGroup::make('Tenancy', [
        MenuItem::resource(WorkspaceResource::class),
        MenuItem::resource(InviteResource::class),
    ])->icon('buildings'),

    MenuItem::link('General', '/settings/general'),
]);
```

The frontend renders nested groups as a smaller, indented sub-cluster
under the parent section header, with their own collapse toggle. Mixing
flat `MenuItem` entries and nested `MenuGroup` entries inside the same
section is allowed and renders in declared order.

`MenuGroup` is currently 2-level only — a `MenuGroup` cannot itself
contain another `MenuGroup`. If you need deeper nesting open an issue
with the use case.

## Authorization and Visibility

Resource menu items still respect:

- `displayInNavigation()`
- `authorizedToViewAny($request)`

Declarative link items can be hidden with `canSee(...)`.

## Sections

Groups can sit under a higher-level **section** divider (think "Resources",
"Platform") to split a dense sidebar into clusters without nesting another
collapsible. Sections render as small all-caps headings above their groups
and stay visually subtler than group labels so the two levels remain
distinguishable.

Declared only on `MenuSection` inside a custom `Martis::mainMenu(...)`
builder — i.e. the same service provider where you build the menu.
Run every user-facing string through `__(...)` so the heading localises
with the rest of the UI:

```php
Martis::mainMenu(function ($request, $menu) {
    $workspace = __('menu.section_workspace');
    $platform  = __('menu.section_platform');

    return $menu->sections([
        MenuSection::make(__('menu.operations'), [...])->section($workspace),
        MenuSection::make(__('menu.talents'),    [...])->section($workspace),
        MenuSection::make(__('menu.audit'),      [...])->section($platform),
        MenuSection::make(__('menu.settings'),   [...])->section($platform),
    ]);
});
```

Leave `->section(...)` off to skip the section heading for that group.

## Count Badges

Each resource publishes a count badge next to its label (`Users 1,284`).
The value comes from the same `indexQuery()` hook that powers the listing
page, so tenancy and policy scopes apply automatically.

Opt out per-resource:

```php
public static function showMenuCount(): bool
{
    return false;
}
```

Customise the value (useful for "unread", "pending", or computed counts):

```php
use Illuminate\Http\Request;

public static function menuCount(Request $request): ?int
{
    return Ticket::where('assignee_id', $request->user()->id)
        ->whereNull('resolved_at')
        ->count();
}
```

Return `null` from `menuCount()` to hide the badge without turning off
`showMenuCount()`.

Disable badges globally with:

```php
// config/martis.php
'navigation' => [
    'counts' => ['enabled' => false],
],
```

or the `MARTIS_NAV_COUNTS=false` env var. The global switch wins over
per-resource opt-ins.

### Compact notation

Counts above the configured threshold render in compact notation (`10K`,
`1.2M`) so dense sidebars stay readable. Default threshold is `10000`:

```php
// config/martis.php
'navigation' => [
    'count_compact_threshold' => env('MARTIS_NAV_COUNT_COMPACT_THRESHOLD', 10000),
],
```

Lower the value (e.g. `1000`) to compact earlier, or set it to a very
large number to disable compaction entirely.

### Live polling

The frontend keeps counts fresh through a **lightweight badges
endpoint** that does not re-pull the full navigation tree.

- `/martis/api/navigation` is fetched **once** per session (and on
  route mutations). Menu structure rarely changes in production, so
  there is no auto-poll.
- `/martis/api/navigation/badges` is polled at the configured
  cadence and returns a flat `{ uriKey: count }` map. The SPA merges
  it into the cached navigation tree on each tick. 5–10× cheaper
  server-side than the full tree.

```php
'navigation' => [
    'badges_poll_interval' => (int) env('MARTIS_NAV_BADGES_POLL_MS', 300000),  // v1.8.8
],
```

The default is **300 000 ms (5 minutes)**. Set it to `0` to disable
badge polling entirely — counts then refresh only on full navigation
or on the user's own mutations (which invalidate the badges query via
the React Query MutationCache).

> **Breaking change in v1.8.8.** `MARTIS_NAV_POLL_MS` /
> `navigation.poll_interval` was removed. Renaming to
> `MARTIS_NAV_BADGES_POLL_MS` is enough to migrate, but consider
> raising the cadence (the new default is 5 min, up from 60 s)
> because badges-only is cheap and menu structure rarely changes
> mid-session.

## Navigation API Response

`GET /martis/api/navigation` returns an array of menu sections:

```json
[
  {
    "label": "Quick Links",
    "icon": null,
    "collapsable": false,
    "items": [
      {
        "type": "link",
        "label": "Dashboard",
        "url": "/",
        "icon": "squares-four",
        "external": false
      }
    ]
  },
  {
    "label": "Support",
    "icon": null,
    "collapsable": true,
    "items": [
      {
        "type": "resource",
        "uriKey": "tickets",
        "label": "Help Desk",
        "singularLabel": "Ticket",
        "subtitle": "Customer support queue",
        "titleAttribute": "id",
        "softDeletes": false,
        "group": "Support",
        "icon": "lifebuoy",
        "url": "/support/help-desk",
        "external": false,
        "count": 42
      }
    ],
    "section": "Resources"
  },
  {
    "label": "Platform",
    "icon": "stack",
    "collapsable": true,
    "section": "Platform",
    "items": [
      {
        "type": "resource",
        "uriKey": "queues",
        "label": "Queues",
        "url": "/resources/queues",
        "count": 0
      }
    ]
  }
]
```

`items` is the canonical shape consumed by the Martis frontend. The list
is heterogeneous: a section's `items` may contain leaf items (`type` ∈
`link | resource | tool | dashboard | lens | filter`) and nested groups
(`type === "group"`) mixed in declared order.

A nested `MenuGroup` serialises as:

```json
{
  "type": "group",
  "label": "Auth",
  "icon": "lock-key",
  "collapsable": true,
  "path": "/settings/auth",
  "items": [
    { "type": "resource", "uriKey": "users", "label": "Users", "url": "/resources/users", "count": 0 },
    { "type": "resource", "uriKey": "roles", "label": "Roles", "url": "/resources/roles", "count": 0 }
  ]
}
```

Decorative badges attached via `withBadge()` add a `badge` field on the
emitted item:

```json
{
  "type": "link",
  "label": "AI Console",
  "url": "/ai",
  "icon": "sparkle",
  "external": false,
  "badge": { "text": "Beta", "tone": "warning" }
}
```

## Badges-Only API (v1.8.8)

`GET /martis/api/navigation/badges` returns a flat `{ uriKey: count }`
map keyed by the resource `uriKey`. Resources that opt out of
`showMenuCount()` or fail per-user authorization are excluded; broken
`menuCount()` calls are silently skipped so a single counter cannot
take the whole endpoint down.

```json
{
  "users": 1284,
  "invoices": 7,
  "tickets": 42
}
```

The endpoint shares the `navigation` cache layer with `/api/navigation`
but lives under a separate `badges:` cache prefix, so flushing one
does not poison the other (`php artisan martis:cache:clear navigation`
still wipes both). Default poll cadence is **300 000 ms (5 minutes)**;
override per environment with `MARTIS_NAV_BADGES_POLL_MS` or per
boot via `config('martis.navigation.badges_poll_interval', ...)`.
