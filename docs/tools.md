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
│ 2. Registration              Auto-discovered from `app/Martis/Tools/`     │
│    (ServiceProvider)          (since v1.8.20). Manual registration via    │
│                              `Martis::tools([X::class])` is still         │
│                              supported and merges with discovery (dedup   │
│                              by class-string). Composer-distributed       │
│                              tools may also self-register through their   │
│                              own `ToolServiceProvider`.                   │
├───────────────────────────────────────────────────────────────────────────┤
│ 3. HTTP / Backend            Auto-mounted REST endpoints                  │
│    (Martis package)           (`/martis/api/tools` + `/martis/api/tools/  │
│                              {uriKey}`) + the SPA catch-all that serves   │
│                              `/martis/tools/{uriKey}` URLs.               │
│                              Per-tool `boot()` hook lets your tool        │
│                              register its own routes / listeners / gates. │
├───────────────────────────────────────────────────────────────────────────┤
│ 4. React component           A `.tsx` you drop under `resources/js/martis-extensions/` under  │
│    (resources/js/martis-extensions/tools/)      the same component key the PHP tool         │
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

This produces `app/Martis/Tools/SystemStatus.php`. **No registration step required**: any concrete `Martis\Tools\Tool` subclass under `app/Martis/Tools/` is auto-discovered at boot (v1.8.20+) and added to `Martis::tools(...)` with dedup by class-string.

The Tool also surfaces in the sidebar by default — auto-grouped under the localised "Tools" header, or under whatever you pass to `withMenuSection('Operations')` inside the constructor. Call `withSystemSection()` instead to dock it in the bundled **System** section, next to the audit log and the Cache admin link (v1.35.0+, see [Place a Tool under "System"](#place-a-tool-under-system--withsystemsection-v1350)). Manual placement via `MenuItem::tool(SystemStatus::class)` is still available when you build a fully custom main menu via `Martis::mainMenu(...)`.

Done. Navigate to `/martis/tools/system-status`. The bundled `martis:tool:system-status-demo` React component renders the page inside the standard layout.

### Disabling auto-discovery

Set `martis.discovery.tools = false` (or `MARTIS_DISCOVERY_TOOLS=false`) when you prefer the pre-v1.8.20 behaviour and want to register every Tool by hand from your `MartisServiceProvider`:

```php
Martis::tools([
    SystemStatus::class,
    ImportWizard::class,
]);
```

Override the discovery path via `martis.tools_path` if your app keeps Tools outside the conventional location. The namespace is derived from Composer's PSR-4 map for that directory (since v1.36.0; `App\Martis\Tools` for the default layout); set `martis.tools_namespace` only to pin it or when the directory is not autoloaded.

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

If you omit `uriKey:`, Martis derives it from `Str::kebab($name)`. The `Tool::make($name, ?$uriKey)` static factory is a one-line alternative when you want to inline the construction:

```php
Tool::make(__('Finance Imports'))
    ->withIcon('upload')
    ->withComponent('tool:imports')
    ->withMenuSection('Operations');
```

| Method | Purpose |
|---|---|
| `name()` | Human-readable label getter. Wrap the construct-time argument with `__()` to localise. |
| `uriKey()` | URL slug getter — `/martis/tools/{uriKey}`. Defaults to kebab-case of name. |
| `icon()` | Currently-set Phosphor icon name, or `null`. Read by `MenuItem::tool()` and `toArray()`. |
| `component()` | Registered React component key, or `null` (config-only tools). |
| `menuSection()` | Active menu section label, or `null`. |
| `belongsToSystemSection()` | `true` when the Tool is docked in the bundled "System" sidebar section. Defaults to `false`. v1.35.0+. |
| `systemSectionOrder()` | Weight of the Tool inside the "System" section, lowest first. Defaults to `100`. v1.38.0+. |
| `menuCount(Request)` | Sidebar count badge for this tool. Returns `null` (no badge) by default. Override to return a count. v1.29.0+. |
| `showMenuCount()` | Whether the count badge renders. Defaults to `true`; the badge only appears when `menuCount()` also returns non-null. v1.29.0+. |
| `meta()` | The accumulated metadata array (mirrors what `withMeta()` set). |
| `withIcon(string)` | Phosphor icon for the menu entry. Chainable. |
| `withComponent(string)` | React component key. The frontend looks this up in `componentRegistry`. Chainable. |
| `withMenuSection(?string)` | Optional menu section label. Chainable. |
| `withSystemSection(bool $value = true, ?int $order = null)` | Dock the Tool in the bundled "System" section (with the audit log, the System-section resources and the Cache admin link). Wins over `withMenuSection()`. `order:` sets `systemSectionOrder()` (v1.38.0+); `null` keeps the current weight. Chainable. v1.35.0+. |
| `withMeta(array)` | Merge arbitrary descriptor data; surfaced verbatim to the React component. Chainable. |
| `breadcrumb()` | Breadcrumb override getter. Returns `null` when the breadcrumb tracks `name()` (default). v1.10.3+. |
| `withBreadcrumb(?string)` | Override the breadcrumb label without touching `name()`. Pass `null` to clear. Chainable. v1.10.3+. |

### Place a Tool under "System" — `withSystemSection()` (v1.35.0+)

Resources can opt into the package's own **System** sidebar section (the one that holds the audit log, `martis:roles` / `martis:invitations` resources and the "System cache" link) via `belongsToSystemSection()`. Tools have the same opt-in:

```php
class Settings extends Tool
{
    public function __construct()
    {
        parent::__construct(name: __('Settings'), uriKey: 'settings');

        $this->withIcon('sliders')
            ->withComponent('tool:settings')
            ->withSystemSection();
    }
}
```

Subclasses may override the getter instead (`public function belongsToSystemSection(): bool { return true; }`), exactly like a Resource.

What changes for an opted-in Tool:

- It renders inside the **single** bundled System section, after the System-section resources and before the "System cache" link. No section of its own is created, and `/api/navigation` carries one section labelled "System".
- Its place in that section is a weight: `withSystemSection(order: 10)` lists it before every entry that keeps the default `100` (the resources included), a weight above `1000` after the cache link. Equal weights keep the natural order. See [Menus → Order inside the System section](menus.md#order-inside-the-system-section-v1380) (v1.38.0+).
- `menuSection()` / `withMenuSection()` are ignored for it (the opt-in wins).
- The command palette (⌘K) tags it "System", the same label the sidebar uses.
- Its `menuCount()` badge keeps working, including the `/api/navigation/badges` poll (`tool:{uriKey}` key).
- Per-tool authorisation is unchanged: a user who fails `authorizedToSee()` does not get the entry; the section still renders for the other items that user can see.
- If you also build a custom `Martis::mainMenu(...)` and place the same Tool by hand with `MenuItem::tool(...)`, the System section does not repeat it (dedup by tool `uriKey`, mirroring the rule for System-section resources).

The opt-in is explicit on purpose. A Tool that merely returns the translated "System" label from `menuSection()` is **not** merged: it still produces its own section with that label, next to the bundled one. Switch it to `withSystemSection()`.

The generator scaffolds the call: `php artisan martis:tool Settings --system-section`.

### Customising the breadcrumb (v1.10.3+)

By default the breadcrumb on `/martis/tools/{uriKey}` reads `Tool::name()` — the page heading and the deepest crumb stay in lock-step. Set a dedicated label when you want them to diverge (compact crumb + verbose heading, branded crumb + neutral heading, etc.):

```php
class Charts extends Tool
{
    public function __construct()
    {
        parent::__construct(name: 'Charts', uriKey: 'charts');
        $this->withIcon('chart-line')
            ->withBreadcrumb('EdgeFlow · Charts')
            ->withComponent('tool:charts');
    }
}
```

The breadcrumb is the **only** thing that changes — `name()` still feeds the page heading, the sidebar entry, the `document.title`, and the menu shortcut from `MenuItem::tool()`.

For a translation-friendly variant, override `breadcrumb()` so the label re-resolves on every request and honours locale switches:

```php
public function breadcrumb(): ?string
{
    return (string) __('edgeflow.tools.charts.breadcrumb');
}
```

Return `null` to fall back to `name()`.

### Authorisation

```php
$this->canSee(fn (Request $request) =>
    $request->user()?->can('admin.system')
);
```

`canSee()` controls **both** menu visibility and route access. Tools that fail `authorizedToSee()` are silently dropped from the menu, and `GET /martis/api/tools/{uriKey}` returns **404** (not 403), intentionally indistinguishable from "tool does not exist" so an unauthorised user cannot probe which tools the app ships. The tool's own routes answer the same 404 to that user when they run [`routeMiddleware()`](#tool-routes-and-their-middleware), which `loadRoutes()` applies by default (v2.0).

| Method | Purpose |
|---|---|
| `canSee(Closure)` | Fluent setter. Closure receives a `Request` and returns `bool`. |
| `authorizedToSee(Request)` | Resolved boolean used by the controller and the menu builder. Useful in tests / dynamic menus that need to pre-filter. Defaults to `true` when no `canSee()` callback is set. |
| `public static ?string $policy` | Declarative Laravel Policy (v1.11.0+): its `view($user)` decides before `canSee()` is consulted. Resolved like a Resource's policy (explicit `$policy`, then `{policy_namespace}\{BaseName}Policy` with the `Tool` suffix stripped, then a policy registered with the Gate for the Tool class) and registered with the Gate on the first check, so no `Gate::policy()` call is needed (v1.36.0). See [Soft-gates → Policy binding](gates.md#policy-binding-dashboard-tool). |

### Menu count badge (v1.29.0+)

A Tool can publish a numeric count badge on its sidebar entry — the same badge a Resource shows next to its label (`Users 1,284`). Override `menuCount()` to return the number; return `null` to hide it. This mirrors the Resource contract, so a full-canvas Tool that owns list data can surface a live "pending" / "unread" count without a Resource behind it.

```php
class FinanceImports extends Tool
{
    // Count of imports waiting for review — shown as the sidebar badge.
    public function menuCount(Request $request): ?int
    {
        return Import::query()->where('status', 'pending')->count();
    }

    // Optional: hide the badge without dropping the count logic.
    public function showMenuCount(): bool
    {
        return true; // default
    }
}
```

Behaviour:

- The value serialises on the tool's nav item under the `count` key.
- It is also included in the lightweight `GET /api/navigation/badges` payload, keyed by the tool's `uriKey`, so tool counts **live-poll** on the same interval as resource counts (`MARTIS_NAV_BADGES_POLL_MS`, default 5 min).
- The badge respects `authorizedToSee()` — a user who can't see the Tool never receives its count, exactly like a resource count.
- `menuCount()` runs per request; keep the query cheap (it also fires on every badge poll). Cache it if it's expensive.

### Lifecycle: `boot()`

The most powerful per-tool hook. Runs **once per request lifecycle**, lazily on the first hit that needs the tools registry, **after** Martis has loaded its own routes / views / config — so tool-owned routes register on top of an initialised package.

> **When to put setup in `Tool::boot()` vs `AppServiceProvider::boot()`?** Read [tool-boot-patterns.md](tool-boot-patterns.md) — it has a decision rubric and 4 worked examples (routes, gates, schedules, event listeners) with the trade-offs spelt out.

```php
public function boot(): void
{
    // Tool-owned routes, mounted alongside the standard Martis SPA,
    // behind the middleware of the Martis API and this tool's gate.
    Route::middleware($this->routeMiddleware())
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

### Tool routes and their middleware

A tool's routes run `routeMiddleware()` (v2.0): the stack of every protected Martis API route, then the tool's own gate.

| Middleware | From | Refuses |
|---|---|---|
| `martis.middleware` | config, `['web']` | (the session, CSRF and cookies every Martis route runs) |
| `martis.auth_middleware` | config, `['martis.auth']` | a guest: `401` (JSON) or a redirect to the login page |
| `martis.impersonation.duration` | package | (stops an impersonation that ran past `MARTIS_IMPERSONATION_MAX_DURATION` minutes) |
| `martis.2fa` | package | a user who signed in but has not passed the 2FA challenge: `423 {"two_factor_required": true}` (JSON) or a redirect to the challenge |
| `martis.locale` | package | (applies the user's locale, so `__()` and validation messages follow it) |
| `martis.verified` | package | when `MARTIS_AUTH_EMAIL_VERIFICATION_ENABLED=true`, an unverified user: `409` (JSON) or a redirect to the notice |
| `throttle:{max},{decay}` | config, `martis.throttle.*` | past `MARTIS_THROTTLE_MAX` requests per `MARTIS_THROTTLE_DECAY` minutes per user, shared with the Martis API: `429`. Left out when `MARTIS_THROTTLE_ENABLED=false` |
| `martis.tool:{uriKey}` | package | a user this tool is hidden from (`canSee()`, its policy): `404` `{"message": "Tool not found."}`, as `GET /api/tools/{uriKey}` answers them |

The first seven are built in one place, `Martis\Http\RouteMiddleware::api()`, which the package's own routes use too, so a tool's route answers a request exactly as `GET /api/tools` does.

This follows Nova's model. A Nova tool's routes are guarded by the tool's `Authorize` middleware, which checks that the authenticated user can see the tool before any request runs ([Nova → Tools → Routing Authorization](https://nova.laravel.com/docs/v5/customization/tools#routing-authorization)), and Laravel's own Pennant tool for Nova runs its API routes behind Nova's API middleware, `config('nova.api_middleware')` ([laravel/nova-pennant](https://github.com/laravel/nova-pennant/blob/1.x/src/ToolServiceProvider.php)), the stack where Nova 5 enables email verification ([Nova → Authentication → Enabling Email Verification](https://nova.laravel.com/docs/v5/customization/authentication)). Two differences: Nova's tool gate answers `403` where Martis answers `404`, as Martis does for the tool itself; and the API routes of a tool Nova's generator scaffolds run the tool's `Authorize` but not Nova's authentication ([nova-issues#5495](https://github.com/laravel/nova-issues/issues/5495), "the current behavior" per [discussion #5496](https://github.com/laravel/nova-issues/discussions/5496)), which Martis does not copy.

Before v2.0 the default was `['web', 'martis.auth']`: a user who had signed in with a password but not passed the 2FA challenge, or not verified an email the app requires, reached every tool route; the locale, the impersonation expiry and the throttle did not apply; and a user the tool was hidden from reached its routes. See [Upgrading](upgrading.md#tool-routes-run-the-martis-api-middleware).

### Loading routes from a sibling file

For tools with more than two or three endpoints the inline `Route::middleware(...)->group(function () { ... })` block in `boot()` gets noisy. `loadRoutes()` is a thin wrapper that pre-applies the standard Martis prefix + `routeMiddleware()` and `require`s a sibling file:

```php
public function boot(): void
{
    // Loads `app/Martis/Tools/routes/finance-imports.php` under
    // the prefix `martis/api/tools/finance-imports`, behind
    // $this->routeMiddleware().
    $this->loadRoutes(__DIR__.'/routes/finance-imports.php');
}
```

Inside the routes file, write plain `Route::*` calls — the prefix and middleware are applied by the surrounding group:

```php
// app/Martis/Tools/routes/finance-imports.php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FinanceImportsController;

Route::post('/upload', [FinanceImportsController::class, 'upload']);
Route::get('/status/{job}', [FinanceImportsController::class, 'status']);
```

Signature:

```php
$this->loadRoutes(
    string $path,                           // path to the routes file
    ?array $middleware = null,              // null: $this->routeMiddleware()
    ?string $prefix = null,                 // defaults to 'martis/api/tools/{uriKey}'
);
```

A `$middleware` list replaces the stack and is used exactly as given, as before v2.0: pass `[...$this->routeMiddleware(), 'can:imports.run']` to add an ability, `Martis\Http\RouteMiddleware::api()` for the API guard without the tool gate, or a list without `martis.auth` for a route that must stay public (a webhook). A subclass that overrides `loadRoutes()` declares `?array $middleware = null` as well.

Missing files are skipped silently — convenient when shipping a Composer-package tool whose routes file is optional, or when a consumer publishes a stub they have not edited yet.

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
| `GET` | `/martis/api/tools` | Array of every authorised tool — `[{ type, name, uriKey, icon, component, menuSection, belongsToSystemSection, meta }, ...]` |
| `GET` | `/martis/api/tools/{uriKey}` | Single tool metadata, or 404 |
| `GET` | `/api/navigation/badges` | Lightweight badge poll — includes each tool's `count` keyed by `uriKey`, alongside resource counts. |

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
// resources/js/martis-extensions/index.ts
import { componentRegistry } from '@martis/runtime'
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
  belongsToSystemSection?: boolean // docked in the bundled "System" section (v1.35.0+)
  count?: number | null           // sidebar badge from menuCount(), live-polled
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

> **Reusing Martis Fields inside a Tool.** A Tool can host real Martis fields (`Slug`, `Text`, `BelongsTo`, …) with the exact behaviour they have in a Resource form — slug-from-source, `dependsOn`, validation display — via the shared `useMartisForm` + `FieldsForm` harness, optionally declaring the fields in PHP with `Tool::fields()`. See [tool-fields.md](tool-fields.md).

If your tool's `component()` returns a key that has no registration in `componentRegistry`, `ToolPage` renders a developer-friendly error UI (the tool name as the heading, then a translated message pointing you at the missing `componentRegistry.register(...)` call). The error is rendered to the DOM, not the console — it is hard to miss during local dev and is harmless in production beyond the visible message. To ship a config-only tool without a React body, simply do not call `withComponent()`; `ToolPage` then renders just the standard header and no content panel.

> **Navigating between tools.** Switching from one Tool to another (`/tools/:uriKey` → `/tools/:otherUriKey`) fully unmounts and remounts `ToolPage`, so the outgoing tool's state and in-flight fetch are torn down cleanly instead of flashing stale data from the previous tool. A top progress bar (mounted once in the shell layout) reflects any in-flight navigation or query across the whole app, tool pages included.

## The `martis:tool` generator

```bash
php artisan martis:tool SystemStatus [flags]
```

| Flag | Effect |
|---|---|
| `--with-component` | Also drop a TSX stub at `resources/js/martis-extensions/tools/{Name}.tsx` (no `Tool` filename suffix). The auto-discovery entry registers it against the derived key automatically; no manual `componentRegistry.register` call required. |
| `--component-key=foo` | Use `foo` as the React component key instead of the auto-generated `tool:{kebab-name}` (kebab case with an acronym kept whole: `SEOReport` is `tool:seo-report`). With `--with-component`, the entry still registers the TSX under `tool:{kebab-name}`, so the command prints the `register()` call that binds it to `foo` in `resources/js/martis-extensions/index.ts`. |
| `--use-bundled` | Bind to the package-bundled `martis:tool:system-status-demo` component so the Tool renders out of the box without writing TSX. |
| `--menu-section="Operations"` | Embed `withMenuSection('Operations')` in the generated stub. |
| `--system-section` | Embed `withSystemSection()` in the generated stub so the Tool docks in the bundled "System" section. Wins over `--menu-section` (a warning is printed when both are given). v1.35.0+. |
| `--icon=wrench` | Phosphor icon for the menu entry (default `wrench`). |
| `--force` | Overwrite the file if it already exists. |

After the command finishes, the CLI prints the next step: with `--with-component`, the build reminder (`npm run build:extensions`), preceded by the `componentRegistry.register(...)` call to add to `resources/js/martis-extensions/index.ts` when `--component-key` names a key the file name does not derive; with `--use-bundled`, a note that no build is needed; otherwise the two ways to bind a component to the key. The Tool class itself is auto-registered (see [the five-minute Tool path](installation-guide.md#the-five-minute-tool-path)).

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
php artisan martis:tool SystemStatus --with-component --system-section --icon=pulse
```

The generator drops:
- `app/Martis/Tools/SystemStatus.php`
- `resources/js/martis-extensions/tools/SystemStatus.tsx`

In the PHP class, restrict to admins:

```php
$this->canSee(fn ($request) => $request->user()?->is_admin);
```

In the React component, fetch `/martis/api/cache/status` and `/horizon/api/stats` and render them.

Register (or rely on auto-discovery under `app/Martis/Tools/`):

```php
Martis::tools([SystemStatus::class]);
```

That is all: `--system-section` scaffolds `withSystemSection()`, so the Tool renders inside the bundled **System** section between the System-section resources and the "System cache" link, with no `Martis::mainMenu(...)` needed. (Before v1.35.0 this recipe used `--menu-section="System"` plus a hand-built `MenuSection::make('System', [...])`, which produced a second section with the same header.)

### Recipe 2 — Import wizard with custom routes

Goal: a multi-step CSV import wizard with file upload, preview, commit.

```bash
php artisan martis:tool DataImport --with-component --menu-section="Imports"
```

In the Tool's `boot()`, register the upload + preview + commit endpoints:

```php
public function boot(): void
{
    Route::middleware($this->routeMiddleware())
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

        // Behind the Martis API middleware and this tool's gate, so a
        // user without backups.view gets a 404 on /run too.
        Route::middleware($this->routeMiddleware())
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

## Making a headless resource findable through its Tool

Pattern B in [Distribution patterns](#distribution-patterns) aside, the common in-app shape is: "a Tool owns the domain, and a Resource is kept around headless (`routable() === false`) purely as a relation target / data source" (see [`routable()`](resources.md#routable) in the Resources reference).

That headless Resource can still be **findable in global search**, and every link to one of its records can still **deep-link to the Tool**, by declaring `recordUrl()`:

```php
class ProjectResource extends Resource
{
    public static function routable(): bool { return false; }
    public static function recordUrl(): ?string { return '/tools/project-knowledge?id={id}'; }
}
```

With that in place, projects surface in the ⌘K palette and any `BelongsTo`/breadcrumb/search-result link to a project opens `/tools/project-knowledge?id={id}` instead of a 404. See [`recordUrl()`](resources.md#recordurl) in the Resources reference for the full contract (the dual role, the `routable()`/`globallySearchable()` interaction table, and the authorization guarantee).

## Anti-patterns

- **Don't use a Tool to render a Resource.** If you need a custom CRUD UI for a model, override the resource page or define a Lens.
- **Don't put behaviour-critical state in `meta()`.** Use it for presentation hints; pull authoritative data from your own endpoint inside the React component.
- **Don't bypass `canSee()`.** A Tool that hides itself from the menu but still answers `/api/tools/{uriKey}` to anyone is a real exfiltration vector.
- **Don't register routes outside `boot()`.** Routes registered in the constructor run at object-construction time (before the application has fully booted) and can leak across tests.
- **Don't depend on `boot()` running multiple times.** The hook is idempotent per registration. If you need re-runnable setup (e.g. resetting state in tests), trigger it explicitly.

## Differences from Nova 5

If you arrive at Martis Tools from Nova 5, the surface looks familiar but a few things diverge by design. None of these are "missing features" we plan to add; they are deliberate choices.

| Topic | Nova 5 | Martis | Why |
|---|---|---|---|
| Custom menu rendering | `Tool::menu(Request)` returns a `View` so the tool can render its own menu item HTML. | The menu entry is built from `name()` / `icon()` / `uriKey()` only. | Uniform menu styling. Custom menu HTML is the surface most likely to drift visually away from the rest of the admin. |
| In-page sub-navigation | `Tool::renderNavigation()` slot for tabs / sections. | Delegated to your React component. Build the sub-nav inline; the standard layout already provides breadcrumbs + page header. | React side has more flexibility to react to client state than a server-rendered fragment. |
| Route file auto-discovery | Tools may ship a `routes/tool.php` that Nova auto-loads. | `Tool::loadRoutes($path)` (since v1.8.8) — explicit one-liner from `boot()`. | Auto-discovery hides where routes come from. Keeping the call site explicit makes route registration trivially `grep`-able. |
| Tool route gate | The tool's `Authorize` middleware answers `403` to a user the tool is hidden from. | `martis.tool:{uriKey}` in `routeMiddleware()` answers `404` (v2.0). | The tool's page and metadata already answer `404` to that user, so the app does not reveal which tools it ships. |
| Per-tool resource isolation | Tools can register their own Resources so two tools can ship overlapping URI keys. | `Martis::resources()` is global; tools should use their own controllers + endpoints rather than registering Resources. | Resource registration is global anyway in Laravel routing; per-tool isolation is rarely needed and adds significant indirection. |
| Bundled webpack scaffolding | Nova ships a webpack config + `nova:tool` builds a tool package's JS. | `publishesAssets()` proxies the standard `vendor:publish` mechanism; the tool author owns the build pipeline (Vite, Webpack, anything). | Decouples tool packaging from the package's own build choices. |

## Tests

Behaviour-level coverage lives in `tests/Feature/ToolsControllerTest.php` (15 Feature tests covering registration, authorisation, the boot lifecycle, exception swallowing, publishing, route loading, and menu integration) and `tests/Feature/ToolRouteMiddlewareTest.php` (the middleware of a tool's routes: the same stack as the API routes, the 2FA challenge, email verification, the throttle, an explicit list, the tool gate). The ParitySurface tripwire (`tests/Feature/ParitySurfaceTest.php`) asserts that the public surface (`Tool`, `Tool::boot()`, `Tool::publishes()`, `Tool::publishesAssets()`, `Tool::loadRoutes()`, `ToolServiceProvider`, `Martis::tools()`, `Martis::resolveTools()`, `MartisManager::bootTools()`) keeps its contract.

Run the focused suites:

```bash
vendor/bin/pest tests/Feature/ToolsControllerTest.php
vendor/bin/pest tests/Feature/ParitySurfaceTest.php
```
