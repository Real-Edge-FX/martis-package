# Custom Tools

> Free-form sidebar pages — non-resource, non-dashboard, non-lens. Use Tools when you need an admin page that isn't backed by a model: import wizards, system status, ad-hoc reports, third-party embeds, generated docs, etc.
>
> **Status:** shipped in v0.10. Includes a `boot()` per-tool lifecycle hook, a `ToolServiceProvider` for Composer-package distribution, asset/file publishing helpers, and a `martis:tool` artisan generator.

## When to use a Tool

Pick a Tool when your page does not naturally map to a list of records.

| Surface | Backed by | Picks the renderer | Typical example |
|---|---|---|---|
| **Resource** | An Eloquent model | Built-in `ResourceIndex` / `ResourceDetail` / `ResourceCreate` / `ResourceUpdate` | "Posts" CRUD |
| **Lens** | An Eloquent model + a custom query | Built-in lens shell with summary / cards / actions | "Posts published in the last 30 days" |
| **Dashboard** | Metric cards + filters | Built-in dashboard grid | "Operations KPIs" |
| **Tool** | Anything else | Your own React component (registered via `componentRegistry`) | "Import wizard", "System status", "On-call rotation viewer" |

## Architecture: the four layers

A Tool spans four layers. You write code at each, glued together by the framework:

```
┌───────────────────────────────────────────────────────────────────────────┐
│ 1. PHP Tool class           Subclass Martis\Tools\Tool — declares         │
│    (App\Martis\Tools\X.php) name, uriKey, icon, component key, menu       │
│                              section, canSee(), and the lifecycle hooks   │
│                              (boot, publishes, ...).                      │
├───────────────────────────────────────────────────────────────────────────┤
│ 2. Registration              `Martis::tools([X::class])` from your        │
│    (ServiceProvider)          MartisServiceProvider, OR via a packaged    │
│                              `ToolServiceProvider` (Composer-distributed  │
│                              tools).                                      │
├───────────────────────────────────────────────────────────────────────────┤
│ 3. HTTP / Backend            Auto-mounted REST endpoints                  │
│    (Martis package)           (`/martis/api/tools` + `/martis/api/tools/  │
│                              {uriKey}`) + the SPA catch-all that serves   │
│                              `/martis/tools/{uriKey}` URLs.               │
│                              Per-tool `boot()` hook lets your tool        │
│                              register its own routes / listeners / gates. │
├───────────────────────────────────────────────────────────────────────────┤
│ 4. React component           A `.tsx` you register in your boot.ts under  │
│    (resources/js/tools/)      the same component key the PHP tool         │
│                              binds via `withComponent('tool:foo')`.       │
│                              The Martis ToolPage shell renders it inside  │
│                              the standard layout.                         │
└───────────────────────────────────────────────────────────────────────────┘
```

## The five-minute path

The fastest way from "I want a System Status page" to a working tool is the artisan generator with `--use-bundled` (binds to a pre-built React component the package ships):

```bash
php artisan martis:tool SystemStatus --use-bundled --menu-section="Operations" --icon=pulse
```

This produces `app/Martis/Tools/SystemStatus.php`. Register it in your `MartisServiceProvider`:

```php
use App\Martis\Tools\SystemStatus;

Martis::tools([SystemStatus::class]);
```

Add a menu entry next to your resources:

```php
MenuSection::make('Tools', [
    MenuItem::tool(SystemStatus::class),
])->icon('wrench');
```

Done. Navigate to `/martis/tools/system-status`. The bundled `martis:tool:system-status-demo` React component renders the page inside the standard layout.

## Anatomy of a Tool class

### Identity & display

```php
parent::__construct(
    name: __('Finance Imports'),    // Shown in menu + page header. Localisable.
    uriKey: 'finance-imports',      // URL slug. Stable across releases.
);

$this->withIcon('upload')           // Phosphor icon name, surfaced in the menu.
    ->withComponent('tool:imports') // React componentRegistry key.
    ->withMenuSection('Operations'); // Optional menu section label.
```

| Method | Purpose |
|---|---|
| `name()` | Human-readable label. Wrap with `__()` to localise. |
| `uriKey()` | URL slug — `/martis/tools/{uriKey}`. Defaults to kebab-case of name. |
| `withIcon(string)` | Phosphor icon for the menu entry. |
| `withComponent(string)` | React component key. The frontend looks this up in `componentRegistry`. |
| `withMenuSection(?string)` | Optional menu section label. |
| `withMeta(array)` | Arbitrary descriptor data passed to the React component. |

### Authorisation

```php
$this->canSee(fn (Request $request) =>
    $request->user()?->can('admin.system')
);
```

`canSee()` controls **both** menu visibility and route access. Tools that fail `authorizedToSee()` are silently dropped from the menu, and `GET /martis/api/tools/{uriKey}` returns **404** (not 403) — intentionally indistinguishable from "tool does not exist" so an unauthorised user cannot probe which tools the app ships.

### Lifecycle: `boot()`

The most powerful per-tool hook. Runs **once** during host application boot, **after** Martis has loaded its own routes / views / config — so tool-owned routes register on top of an initialised package.

> **When to put setup in `Tool::boot()` vs `AppServiceProvider::boot()`?** Read [tool-boot-patterns.md](tool-boot-patterns.md) — it has a decision rubric and 4 worked examples (routes, gates, schedules, event listeners) with the trade-offs spelt out.

```php
public function boot(): void
{
    // Tool-owned routes — mounted alongside the standard Martis SPA.
    Route::middleware(['web', 'martis.auth'])
        ->prefix('martis/api/tools/finance-imports')
        ->group(function () {
            Route::post('/upload', [FinanceImportsController::class, 'upload']);
            Route::get('/status/{job}', [FinanceImportsController::class, 'status']);
        });

    // Listen for an app-wide event.
    Event::listen(InvoicePaid::class, RecordImportSuccess::class);

    // Define a per-tool gate the React UI can read via _authorization.
    Gate::define('finance-imports.start', fn ($user) => $user?->is_admin);

    // Schedule a recurring task.
    app(Schedule::class)->command('finance:reconcile')->dailyAt('02:00');

    // Bind a Blade view namespace (rare for Tools, useful for tools
    // shipping email templates / PDFs alongside the React UI).
    view()->addNamespace('finance-imports', __DIR__.'/../resources/views');
}
```

**Guarantees:**

- Default implementation is a no-op. Override only when needed.
- Runs exactly once per request lifecycle. Reset only when `Martis::tools([...])` is called again (e.g. in tests).
- Exceptions thrown from `boot()` are **logged and swallowed** — a single broken tool cannot bring down the whole admin panel. Watch your `laravel.log` for `[martis] Tool boot() threw` entries.
- The hook receives no arguments. Resolve dependencies via `app(...)` or facades.

### Asset & file publishing

Two helpers proxy to the standard `ServiceProvider::publishes` map so consumers can run `php artisan vendor:publish --tag=...`:

```php
public function boot(): void
{
    // Standard publish-with-tag pattern. Composer-package tools
    // typically ship config + lang + migrations from their own
    // package directory.
    $this->publishes([
        __DIR__.'/../config/finance-imports.php' => config_path('finance-imports.php'),
    ], 'finance-imports-config');

    $this->publishes([
        __DIR__.'/../database/migrations' => database_path('migrations'),
    ], 'finance-imports-migrations');

    // Convenience helper — publishes compiled JS/CSS to
    // `public/vendor/martis-tools/{uriKey}/`. Tools that bind a
    // custom React component via `withComponent()` typically pair
    // this with a build step in their own package.
    $this->publishesAssets(__DIR__.'/../public', 'finance-imports-assets');
}
```

The publishables register through Laravel's standard `ServiceProvider::$publishes` + `$publishGroups` static maps. `vendor:publish --provider="App\Martis\Tools\FinanceImports"` works the same way it does for any Laravel package.

## REST surface (built-in)

Two endpoints the package mounts automatically:

| Method | Path | Returns |
|---|---|---|
| `GET` | `/martis/api/tools` | Array of every authorised tool — `[{ type, name, uriKey, icon, component, menuSection, meta }, ...]` |
| `GET` | `/martis/api/tools/{uriKey}` | Single tool metadata, or 404 |

The frontend hits these to build navigation entries and validate deep links. The actual page render is the SPA's job — the catch-all route delegates to React.

## Menu integration

```php
Martis::mainMenu(function ($request, $menu) {
    return [
        MenuSection::make(__('Operations'), [
            // Class-string OR instance both work — class-strings
            // are instantiated lazily per-request.
            MenuItem::tool(SystemStatus::class),
            MenuItem::tool(new FinanceImports()),
        ])->icon('wrench'),
    ];
});
```

`MenuItem::tool($class|$instance)` produces a menu entry with:

- `label` — `tool->name()` (override via `->label('...')`)
- `url` — `/tools/{uriKey}` (override via `->path('...')`)
- `icon` — `tool->icon()` (override via `->icon('...')`)
- `uriKey` and `component` — passed through so the frontend can short-circuit the API round-trip

## React side

Bind a React component to the key your PHP tool declared:

```ts
// resources/js/martis/boot.ts
import { componentRegistry } from '@martis/admin'
import { FinanceImportsTool } from './tools/FinanceImportsTool'

componentRegistry.register('tool:imports', FinanceImportsTool)
```

The component receives the resolved Tool descriptor as a prop:

```tsx
interface ToolDescriptor {
  name: string
  uriKey: string
  icon: string | null
  component: string | null
  menuSection: string | null
  meta: Record<string, unknown>   // values you set via withMeta(...)
}

export function FinanceImportsTool({ tool }: { tool: ToolDescriptor }) {
  // Access withMeta() values via tool.meta
  const docsUrl = tool.meta.docs_url
  // Render whatever you want inside the standard layout shell.
  return <div>...</div>
}
```

The Martis `ToolPage` shell (route `/tools/:uriKey`) fetches `/api/tools/{uriKey}`, looks up the registered component by key, and mounts it inside the standard layout (topbar, sidebar, breadcrumbs, theme — all wired automatically).

If your tool's component key has no registration in `componentRegistry`, the page renders an empty shell and prints a console warning — the same fallback behaviour as an unregistered Resource component override.

## The `martis:tool` generator

```bash
php artisan martis:tool SystemStatus [flags]
```

| Flag | Effect |
|---|---|
| `--with-component` | Also drop a TSX stub at `resources/js/tools/{Name}Tool.tsx` with the `componentRegistry.register` call wired up. |
| `--component-key=foo` | Use `foo` as the React component key instead of the auto-generated `tool:{kebab-name}`. |
| `--use-bundled` | Bind to the package-bundled `martis:tool:system-status-demo` component so the Tool renders out of the box without writing TSX. |
| `--menu-section="Operations"` | Embed `withMenuSection('Operations')` in the generated stub. |
| `--icon=wrench` | Phosphor icon for the menu entry (default `wrench`). |
| `--force` | Overwrite the file if it already exists. |

After the command finishes, the CLI prints a "next steps" block with:

1. The exact `Martis::tools([...])` registration snippet for your service provider.
2. The `MenuItem::tool(...)` line to copy into your menu.
3. The `componentRegistry.register('...', ...)` call to add to `boot.ts` (or a note that you used `--use-bundled`).

You should not need to alt-tab to the docs after running it.

## Distribution patterns

There are two ways to ship Tools.

### Pattern A — In-app tool (most common)

The tool lives in your application's own codebase. Subclass `Tool`, register it in your `MartisServiceProvider`, write the React component in your `resources/js/`. Everything lives in the host app.

```php
// app/Providers/MartisServiceProvider.php
use App\Martis\Tools\SystemStatus;

Martis::tools([SystemStatus::class]);
```

This is what the `martis:tool` generator scaffolds.

### Pattern B — Composer-package tool

The tool ships as a standalone Composer package with its own service provider, view publishing, asset publishing, and migrations. Consumers `composer require your-vendor/your-tool` and the tool registers itself — they never touch their own service provider.

Subclass `Martis\Tools\ToolServiceProvider`:

```php
namespace YourVendor\YourTool;

use Martis\Tools\ToolServiceProvider;

class YourToolServiceProvider extends ToolServiceProvider
{
    /**
     * Tools to register with Martis.
     *
     * @return list<class-string<\Martis\Contracts\ToolContract>>
     */
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

Then ship the provider in your package's `composer.json`:

```json
{
    "extra": {
        "laravel": {
            "providers": ["YourVendor\\YourTool\\YourToolServiceProvider"]
        }
    }
}
```

Laravel auto-discovers the provider on `composer require`. The per-tool `boot()` lifecycle still fires on top of `ToolServiceProvider::boot()`, so the tool can register routes / listeners / publishables exactly like an in-app tool.

## Cookbook

### Recipe 1 — Internal status page (read-only)

Goal: render a page with the master cache health, queue depth, and Telescope link.

```bash
php artisan martis:tool SystemStatus --with-component --menu-section="System" --icon=pulse
```

The generator drops:
- `app/Martis/Tools/SystemStatus.php`
- `resources/js/tools/SystemStatusTool.tsx`

In the PHP class, restrict to admins:

```php
$this->canSee(fn ($request) => $request->user()?->is_admin);
```

In the React component, fetch `/martis/api/cache/status` and `/horizon/api/stats` and render them.

Register and add to the menu:

```php
Martis::tools([SystemStatus::class]);

// in mainMenu(...):
MenuSection::make('System', [
    MenuItem::tool(SystemStatus::class),
])->icon('gear');
```

### Recipe 2 — Import wizard with custom routes

Goal: a multi-step CSV import wizard with file upload, preview, commit.

```bash
php artisan martis:tool DataImport --with-component --menu-section="Imports"
```

In the Tool's `boot()`, register the upload + preview + commit endpoints:

```php
public function boot(): void
{
    Route::middleware(['web', 'martis.auth'])
        ->prefix('martis/api/tools/data-import')
        ->group(function () {
            Route::post('/upload', [ImportController::class, 'upload']);
            Route::post('/preview/{batch}', [ImportController::class, 'preview']);
            Route::post('/commit/{batch}', [ImportController::class, 'commit']);
        });
}
```

The React component drives the wizard UI and POSTs to those routes.

### Recipe 3 — Composer-package tool

Goal: ship a reusable "Backups" tool as `your-vendor/martis-backups`.

In the package:

```php
// src/Backups.php
class Backups extends Tool
{
    public function __construct()
    {
        parent::__construct(name: __('Backups'), uriKey: 'backups');
        $this->withIcon('archive')
            ->withComponent('martis-backups:page')
            ->canSee(fn ($r) => $r->user()?->can('backups.view'));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/martis-backups.php' => config_path('martis-backups.php'),
        ], 'martis-backups-config');

        Route::middleware(['web', 'martis.auth'])
            ->prefix('martis/api/tools/backups')
            ->group(function () {
                Route::get('/', [BackupsController::class, 'index']);
                Route::post('/run', [BackupsController::class, 'run']);
            });
    }
}

// src/BackupsServiceProvider.php
class BackupsServiceProvider extends ToolServiceProvider
{
    protected function tools(): array
    {
        return [new Backups()];
    }

    public function boot(): void
    {
        parent::boot();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
```

In `composer.json`:

```json
{
    "extra": {
        "laravel": {
            "providers": ["YourVendor\\MartisBackups\\BackupsServiceProvider"]
        }
    }
}
```

Consumer: `composer require your-vendor/martis-backups` and they get a working Backups page in their sidebar.

## Anti-patterns

- **Don't use a Tool to render a Resource.** If you need a custom CRUD UI for a model, override the resource page or define a Lens.
- **Don't put behaviour-critical state in `meta()`.** Use it for presentation hints; pull authoritative data from your own endpoint inside the React component.
- **Don't bypass `canSee()`.** A Tool that hides itself from the menu but still answers `/api/tools/{uriKey}` to anyone is a real exfiltration vector.
- **Don't register routes outside `boot()`.** Routes registered in the constructor run at object-construction time (before the application has fully booted) and can leak across tests.
- **Don't depend on `boot()` running multiple times.** The hook is idempotent per registration. If you need re-runnable setup (e.g. resetting state in tests), trigger it explicitly.

## Tests

Behaviour-level coverage lives in `tests/Feature/ToolsControllerTest.php` (13 Feature tests covering registration, authorisation, the boot lifecycle, exception swallowing, publishing, and menu integration). The Task-18 ParitySurface tripwire (`tests/Feature/ParitySurfaceTest.php`) asserts the public surface — `Tool`, `Tool::boot()`, `Tool::publishes()`, `Tool::publishesAssets()`, `ToolServiceProvider`, `Martis::tools()`, `Martis::resolveTools()`, `MartisManager::bootTools()` — keeps its contract.

Run the focused suites:

```bash
vendor/bin/pest tests/Feature/ToolsControllerTest.php
vendor/bin/pest tests/Feature/ParitySurfaceTest.php
```
