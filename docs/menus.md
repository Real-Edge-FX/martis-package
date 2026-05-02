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
- `add(...)`
- `icon(...)`
- `collapsable(bool)`
- `section(?string $section)` — assign this group to a higher-level section divider (see [Sections](#sections))
- `canSee(...)`
- `withMeta([...])`

### `MenuItem`

- `MenuItem::make($label, $url)` — alias for `link()`
- `MenuItem::link($label, $url)`
- `MenuItem::externalLink($label, $url)`
- `MenuItem::resource(ResourceClass::class)`
- `MenuItem::tool(ToolClass::class | $toolInstance)` — see [Tool menu items](#tool-menu-items)
- `label(...)`
- `icon(...)`
- `path(...)`
- `external(bool)`
- `canSee(...)`
- `withMeta([...])`

### Tool menu items

`MenuItem::tool()` builds a navigation entry from a registered Martis Tool.
The factory accepts either a class-string or a tool instance, and reads
`name()`, `uriKey()`, `icon()`, and `authorizedToSee()` lazily at request
time, so the rendered menu always reflects the live state of the tool —
including authorization checks, which silently drop the item when the user
is not allowed to see it.

```php
use App\Martis\Tools\HealthCheck;
use Martis\Menu\MenuItem;
use Martis\Menu\MenuSection;

MenuSection::make('Operations', [
    MenuItem::tool(HealthCheck::class),
    MenuItem::tool(HealthCheck::class)->label('Status')->icon('pulse'),
]);
```

Any combination of `label()`, `icon()`, and `path()` overrides the tool's
defaults. Otherwise the item resolves to `/tools/<uriKey>` automatically.

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

The frontend polls `/martis/api/navigation` while a tab is focused so
counts stay fresh without a full page reload. The cadence is controlled
in `config/martis.php`:

```php
'navigation' => [
    'poll_interval' => (int) env('MARTIS_NAV_POLL_MS', 60000),
],
```

The default is 60 seconds. Set it to `0` to disable polling entirely —
counts then refresh only on full navigation.

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

`items` is the canonical shape consumed by the Martis frontend.
