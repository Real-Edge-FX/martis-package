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

- `MenuSection::make($label, [...])`
- `items([...])`
- `add(...)`
- `icon(...)`
- `collapsable(bool)`
- `canSee(...)`
- `withMeta([...])`

### `MenuItem`

- `MenuItem::link($label, $url)`
- `MenuItem::externalLink($label, $url)`
- `MenuItem::resource(ResourceClass::class)`
- `label(...)`
- `icon(...)`
- `path(...)`
- `external(bool)`
- `canSee(...)`
- `withMeta([...])`

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
