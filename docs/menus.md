# Menus

Martis exposes a declarative navigation API inspired by Nova 5 while staying package-native.

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
        "external": false
      }
    ]
  }
]
```

`items` is the canonical shape consumed by the Martis frontend.
