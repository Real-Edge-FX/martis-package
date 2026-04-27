# Custom Tools

> Free-form sidebar pages — non-resource, non-dashboard, non-lens. Use Tools when you need an admin page that isn't backed by a model: import wizards, system status, ad-hoc reports, third-party embeds, generated docs, etc.
> Shipped in v0.10. Closes the "Custom Tools" gap from the Nova v5 compatibility audit.

## When to use a Tool vs. a Lens vs. a Dashboard

| Surface | Backed by | Picks the renderer | Typical example |
|---|---|---|---|
| **Resource** | An Eloquent model | Built-in `ResourceIndex` / `ResourceDetail` / `ResourceCreate` / `ResourceUpdate` | "Posts" CRUD |
| **Lens** | An Eloquent model + a custom query | Built-in lens shell with summary / cards / actions | "Posts published in the last 30 days" |
| **Dashboard** | Metric cards + filters | Built-in dashboard grid | "Operations KPIs" |
| **Tool** | Anything else | Your own React component (registered via `componentRegistry`) | "Import wizard", "System status", "On-call rotation viewer" |

Pick a Tool when the page does not naturally map to a list of records.

## What ships out of the box

- `Martis\Tools\Tool` — base class.
- `Martis\Contracts\ToolContract` — interface (so you can roll your own base if you ever need to).
- `Martis::tools([...])` — global registration on the manager.
- `Martis::resolveTools($request)` — resolved + authorised list per request.
- `Martis::findTool($request, $uriKey)` — lookup by uriKey.
- `GET /martis/api/tools` — REST list.
- `GET /martis/api/tools/{uriKey}` — REST single.
- `MenuItem::tool($class|$instance)` — menu factory.

## Quick start

### 0. (Optional) scaffold with `martis:tool`

```bash
php artisan martis:tool SystemStatus
```

Generates `app/Martis/Tools/SystemStatus.php`. Useful flags:

| Flag | Effect |
|---|---|
| `--with-component` | Also drops a paired TSX stub at `resources/js/tools/{Name}Tool.tsx` and binds the PHP `withComponent(...)` to a matching key. |
| `--component-key=foo` | Use `foo` as the React component key instead of the auto-generated `tool:{kebab-name}`. |
| `--use-bundled` | Bind to the package-bundled `martis:tool:system-status-demo` component so the Tool renders out of the box without writing TSX. |
| `--menu-section="Operations"` | Embed `withMenuSection('Operations')` in the generated stub. |
| `--icon=wrench` | Phosphor icon for the menu entry (default `wrench`). |
| `--force` | Overwrite the file if it already exists. |

After the command finishes it prints a "next steps" block with the `Martis::tools([...])` snippet and the `componentRegistry.register(...)` line, so you do not have to alt-tab to this doc.

### 1. Subclass `Tool`

```php
<?php

namespace App\Martis\Tools;

use Martis\Tools\Tool;

class SystemStatus extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: __('System Status'),
            uriKey: 'system-status',
        );

        $this->withIcon('pulse')
            ->withComponent('tool:system-status')
            ->withMenuSection(__('Operations'));
    }
}
```

`name()`, `uriKey()`, and the visual hooks (`icon()`, `component()`, `menuSection()`) are the surface the framework reads. `component()` is the React key registered in your `boot.ts`.

### 2. Register the React component

```ts
// resources/js/martis/boot.ts
import { componentRegistry } from '@martis/admin'
import { SystemStatusPage } from './tools/SystemStatusPage'

componentRegistry.register('tool:system-status', SystemStatusPage)
```

The component receives the standard Martis page props (the resolved layout, the current request, the page title hook). It renders inside the standard layout — the topbar, sidebar, breadcrumbs, and theme are wired automatically.

### 3. Register the Tool instance from a service provider

```php
use Martis\Facades\Martis;
use App\Martis\Tools\SystemStatus;

class MartisServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Martis::tools([
            new SystemStatus(),
            // class-strings work too — they are instantiated per-request
            App\Martis\Tools\Backups::class,
        ]);
    }
}
```

### 4. Surface it in the menu (optional)

If you do not customise the menu, Tools register themselves under their `menuSection()` via the navigation builder. To pin one explicitly:

```php
use Martis\Facades\Martis;
use Martis\Menu\MenuSection;
use Martis\Menu\MenuItem;

Martis::mainMenu(function ($request, $menu) {
    return [
        MenuSection::make(__('Operations'), [
            MenuItem::tool(\App\Martis\Tools\SystemStatus::class),
            MenuItem::tool(new \App\Martis\Tools\Backups()),
        ])->icon('wrench'),
    ];
});
```

## Authorisation

Every Tool is checked twice:

1. The menu drops Tools whose `authorizedToSee($request)` returns false.
2. `GET /martis/api/tools/{uriKey}` returns **404** (not 403) when the user lacks access — indistinguishable from "tool does not exist", so an unauthorised user cannot probe which tools the app ships.

Wire authorisation with `canSee()`:

```php
class FinanceImports extends Tool
{
    public function __construct()
    {
        parent::__construct(name: __('Finance Imports'), uriKey: 'finance-imports');

        $this->canSee(fn ($request) => $request->user()?->can('finance.import'));
    }
}
```

## REST surface

| Method | Path | Returns |
|---|---|---|
| `GET` | `/martis/api/tools` | Array of every authorised tool — `[{ type, name, uriKey, icon, component, menuSection, meta }, ...]` |
| `GET` | `/martis/api/tools/{uriKey}` | Single tool metadata, or 404 |

The frontend hits these endpoints to build navigation entries and to validate deep-links into `/martis/tools/{uriKey}`. The actual page render is the SPA's job — the catch-all route delegates to React.

## Menu integration

`MenuItem::tool($class|$instance)` produces a menu entry with:

- `label`: `tool->name()` (override via `->label('...')`)
- `url`: `/tools/{uriKey}` (override via `->path('...')`)
- `icon`: `tool->icon()` (override via `->icon('...')`)
- `uriKey` and `component` — passed through so the frontend can short-circuit the API round-trip

## Frontend conventions

Tools render through the standard Martis layout. Inside your React component you have access to:

- `useMartisRequest()` — the current request envelope.
- `useTranslation('resources')` — Martis i18n.
- The full PrimeReact + theme system.
- The component-override registry — your tool's component CAN itself be replaced by a downstream consumer using the same 4-tier override mechanism that resources use.

## Lifecycle: `boot()`

Tools have a per-tool `boot()` hook (Nova-parity, shipped in v0.10). Override it on your subclass to register tool-owned routes, event listeners, view namespaces, gates, scheduled tasks, etc. — anything that would normally live in a ServiceProvider's `boot()` works here:

```php
class FinanceImports extends Tool
{
    public function __construct()
    {
        parent::__construct(name: __('Finance Imports'), uriKey: 'finance-imports');
        $this->withIcon('upload')->withComponent('tool:finance-imports');
    }

    public function boot(): void
    {
        // Tool-owned routes — mounted alongside the standard Martis SPA.
        \Route::middleware(['web', 'martis.auth'])
            ->prefix('martis/api/tools/finance-imports')
            ->group(function () {
                \Route::post('/upload', [FinanceImportsController::class, 'upload']);
                \Route::get('/status/{job}', [FinanceImportsController::class, 'status']);
            });

        // Listen for an app-wide event.
        \Event::listen(InvoicePaid::class, RecordImportSuccess::class);
    }
}
```

The hook fires once during the host application's boot, AFTER Martis itself has loaded its routes / views / config — so tool-owned routes register on top of an already-initialised package. Exceptions thrown from `boot()` are logged and swallowed so a broken tool cannot bring down the whole admin panel.

## Asset & file publishing

Two helpers on the `Tool` base class let a Tool ship config, migrations, lang files, and compiled JS / CSS without touching the host service provider:

```php
public function boot(): void
{
    // Standard "publish-with-tag" pattern. Composer-package tools
    // typically ship config + lang + migrations from their own
    // package directory.
    $this->publishes([
        __DIR__.'/../config/finance-imports.php' => config_path('finance-imports.php'),
    ], 'finance-imports-config');

    $this->publishes([
        __DIR__.'/../database/migrations' => database_path('migrations'),
    ], 'finance-imports-migrations');

    // Convenience for compiled JS / CSS — publishes to
    // `public/vendor/martis-tools/{uriKey}/` so the SPA can lazy-load.
    $this->publishesAssets(__DIR__.'/../public', 'finance-imports-assets');
}
```

Run `php artisan vendor:publish --tag=finance-imports-config` (or any of the other tags) to copy the files. The publishables register through Laravel's standard `ServiceProvider::$publishes` map — `vendor:publish --provider=...` works the same way it does for any Laravel package.

## Distributing a Tool as a Composer package

For Tools that ship as standalone Composer packages, subclass `Martis\Tools\ToolServiceProvider` and let Laravel's package auto-discovery wire it up:

```php
namespace YourVendor\YourTool;

use Martis\Tools\ToolServiceProvider;

class YourToolServiceProvider extends ToolServiceProvider
{
    protected function tools(): array
    {
        return [new YourTool()];
    }

    public function boot(): void
    {
        parent::boot(); // registers the tools with Martis

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'your-tool');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
```

Add the provider to your package's `composer.json`:

```json
{
    "extra": {
        "laravel": {
            "providers": ["YourVendor\\YourTool\\YourToolServiceProvider"]
        }
    }
}
```

The consumer never touches their own service provider — `composer require your-vendor/your-tool` is enough. The per-tool `boot()` lifecycle still fires on top, so the tool can register routes, listeners, and publishables the same way an in-app tool does.

## Anti-patterns

- **Don't use a Tool to render a Resource.** If you need a custom CRUD UI for a model, override the resource page or define a Lens.
- **Don't put behaviour-critical state in `meta()`.** Use it for presentation hints; pull authoritative data from your own endpoint inside the React component.
- **Don't bypass `canSee()`.** A Tool that hides itself from the menu but still answers `/api/tools/{uriKey}` to anyone is a real exfiltration vector.

## Tests

The Task-18 ParitySurface tripwire (`tests/Feature/ParitySurfaceTest.php`) asserts that `Tool`, `Martis::tools()`, `Martis::resolveTools()`, and `MenuItem::tool()` keep their public signatures. Behaviour-level coverage lives in `tests/Feature/ToolsControllerTest.php`.
