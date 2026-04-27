# Configuration

Martis is configured through `config/martis.php`. Publish it with:

```bash
php artisan vendor:publish --tag=martis-config
```

This page documents every configuration option.

## Base Path

```php
'path' => env('MARTIS_PATH', 'martis'),
```

The URL prefix for the admin panel. The panel will be accessible at `/{path}` (e.g., `http://yourdomain.com/martis`).

## Authentication

```php
'guard' => env('MARTIS_GUARD', null),
'middleware' => ['web'],
'auth_middleware' => ['martis.auth'],
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `guard` | `?string` | `null` | Authentication guard. `null` uses Laravel's default guard. |
| `middleware` | `array` | `['web']` | Applied to all Martis routes (public and protected). |
| `auth_middleware` | `array` | `['martis.auth']` | Applied to protected routes only. |

## Brand

```php
'brand' => [
    'name' => env('MARTIS_BRAND_NAME', 'Martis'),
    'logo' => null,
    'favicon' => env('MARTIS_FAVICON', null),
    'page_title' => env('MARTIS_PAGE_TITLE'), // null | string | callable
],
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `name` | `string` | `'Martis'` | Displayed in the sidebar header and used as the `{brand}` interpolation for the bundled page title translation. |
| `logo` | `?string` | `null` | Path to a custom logo image (relative to public/). |
| `favicon` | `?string` | `null` | Path to a custom favicon (relative to `public/`). When `null`, Martis serves its own default favicon from the package — no `vendor:publish` step required. |
| `page_title` | `string \| callable \| null` | `null` | Browser tab title shown in `<title>`. `null` uses the bundled translation (e.g. "Acme — Admin Control"). A plain string overrides it. A callable (invokable class or array callable) receives the current `Request` and returns the title. |

### Customising the page title

Three levels of control:

**1. Bundled translation (no configuration).** The default title uses `martis::navigation.page_title_default` interpolated with `brand.name`:

| Locale | Rendered title |
|--------|----------------|
| `en` | `Acme — Admin Control` |
| `pt_PT` | `Acme — Centro de Administração` |
| `pt_BR` | `Acme — Central de Administração` |

**2. Static override** — set a literal string in `.env` or config:

```env
MARTIS_PAGE_TITLE="Acme Back Office"
```

```php
'brand' => [
    'page_title' => 'Acme Back Office',
],
```

**3. Dynamic per-route title** — register a closure in your `AppServiceProvider::boot()`:

```php
use Illuminate\Http\Request;
use Martis\Facades\Martis;

public function boot(): void
{
    Martis::pageTitleUsing(function (Request $request): string {
        return match (true) {
            str_starts_with($request->path(), 'martis/resources/clients') => 'Clients · Acme',
            str_starts_with($request->path(), 'martis/resources/invoices') => 'Invoices · Acme',
            default => 'Acme Admin',
        };
    });
}
```

> Closures cannot live directly in `config/martis.php` because `php artisan config:cache` fails to serialise them. Use the facade/manager from a service provider instead.

**4. Automatic per-route inference (no configuration)** — Martis looks at the current request path and inserts the matching resource label, dashboard name, or section, e.g.:

| URL | Title rendered |
|-----|----------------|
| `/martis` | Dashboard name · brand (e.g. `Operations · Acme`) |
| `/martis/resources/clients` | `Clients · Acme` |
| `/martis/resources/clients/new` | `Create Client · Acme` |
| `/martis/resources/clients/42` | `Client · Acme` |
| `/martis/resources/clients/42/edit` | `Edit Client · Acme` |
| `/martis/profile` | `Profile · Acme` |

Resource labels honour the authenticated user's locale preference, so the first paint is already in the right language.

For **client-side navigation** inside the SPA (react-router), each page uses the [`usePageTitle`](overrides.md#page-title-hook) hook to keep `document.title` in sync without a full reload.

Resolution precedence (highest first):

1. `Martis::pageTitleUsing(Closure)` — registered at runtime.
2. `config('martis.brand.page_title')` — string or `is_callable`.
3. Automatic inference from the request path (resource label, dashboard name, profile).
4. `__('martis::navigation.page_title_default', ['brand' => config('martis.brand.name')])` — bundled fallback.

### Customising the favicon

1. Drop the file into your app's `public/` directory — any filename and extension are accepted (`.ico`, `.png`, `.svg`). Example: `public/brand/favicon.ico`.
2. Point the config at it, relative to `public/`:

   ```env
   MARTIS_FAVICON=brand/favicon.ico
   ```

   Or set it directly in `config/martis.php`:

   ```php
   'brand' => [
       'favicon' => 'brand/favicon.ico',
   ],
   ```
3. Visit `/{martis-path}/favicon.ico` — the configured file is served. If the file is missing, the route falls back to the package default, so broken paths never 404.

> Path rules: values must stay inside `public/`. Absolute paths (`/etc/...`) and traversal (`../`) are rejected with `400 Bad Request`.

## Footer

```php
'footer' => [
    'enabled' => true,
    'text' => null,
],
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `enabled` | `bool` | `true` | Set `false` to hide the footer entirely. |
| `text` | `?string` | `null` | Custom footer text. When `null`, displays "Powered by Martis". |

## Layout

```php
'layout' => [
    'preset' => env('MARTIS_LAYOUT', 'sidebar'),
    'components' => [
        'shell'   => null,
        'sidebar' => null,
        'topbar'  => null,
        'footer'  => null,
    ],
],
```

### Presets

| Preset | What it renders | When to pick it |
|--------|-----------------|-----------------|
| `sidebar` (default) | Left nav column, topbar, content, in-flow footer. The collapsible shell described in [theming.md](theming.md) and [overrides.md](overrides.md). | Most admin apps. Handles mobile drawer, collapsed rail, accent pill, density tokens. |
| `topnav` | Horizontal top navigation (no sidebar). Topbar renders the full menu inline. | Apps with ≤ 8 top-level resources or a focus-mode where the left column wastes space. |
| `minimal` | No chrome — just the route outlet. No topbar, no sidebar, no footer. | Embedded dashboards, marketing screens, print-friendly surfaces. |
| `custom` | Strict registry-only mode: requires a component registered under `layout:shell` (or referenced by `layout.components.shell`). If none is registered, Martis renders a red error panel instead of silently falling back to `sidebar` — so a missing registration fails loudly during development. | Apps shipping their own shell that want to guarantee the bundled fallback is never used. |

Pick the preset via `MARTIS_LAYOUT` env or `config('martis.layout.preset')`. The string must match one of the four above (typos fall back to `sidebar`).

### Piece-by-piece component overrides

`layout.components` lets the PHP config point each shell piece at a specific registry key. Each value is either `null` (use the bundled default) or a string matching a key registered via `componentRegistry.register(...)` in the frontend.

```php
'layout' => [
    'preset' => 'sidebar',
    'components' => [
        'shell'   => null,              // whole shell override; skips the grid + mobile drawer
        'sidebar' => null,              // only the left column
        'topbar'  => 'tenant-topbar',   // custom topbar registered in boot.ts
        'footer'  => 'tenant-footer',
    ],
],
```

Resolution order per piece: `config.layout.components.<piece>` → `layout:<piece>` (default registry key) → bundled component.

Full wiring examples, prop contracts, and the rationale for piece-by-piece vs full-shell overrides live in [overrides.md](overrides.md#shell-piece-by-piece-overrides).

## Navigation

```php
'navigation' => [
    'counts' => [
        'enabled' => env('MARTIS_NAV_COUNTS', true),
    ],
    'poll_interval' => (int) env('MARTIS_NAV_POLL_MS', 60000),
],
```

- `counts.enabled` — master switch for the resource count badge (`Users 1,284`) rendered in the sidebar and top-nav dropdowns. When true, every resource publishes a count by default; per-resource opt-out via `showMenuCount(): bool` on the `Resource` class.
- `poll_interval` — how often (in milliseconds) the sidebar and top-nav re-fetch `/api/navigation` while the tab is focused. Keeps badges in sync when a second user mutates data in parallel. Default: 60000 (60 seconds). Set to `0` to disable polling. React Query pauses the interval when the tab is hidden and refetches on window focus independently.

See [menus.md](menus.md#count-badges) for the badge API (including `menuCount()` for custom values) and [menus.md](menus.md#sections) for the section heading API.

## Localization

```php
'locale' => env('MARTIS_LOCALE', 'en'),
```

Default locale for the admin panel. Translations are loaded from `resources/lang/{locale}/` files. Publish translations with:

```bash
php artisan vendor:publish --tag=martis-lang
```

Shipped locales: `en` (English), `pt_BR` (Brazilian Portuguese), `pt_PT` (European Portuguese).

## API Throttle

```php
'throttle' => [
    'enabled' => env('MARTIS_THROTTLE_ENABLED', true),
    'max_attempts' => (int) env('MARTIS_THROTTLE_MAX', 120),
    'decay_minutes' => (int) env('MARTIS_THROTTLE_DECAY', 1),
],
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `enabled` | `bool` | `true` | Set `false` to disable API rate limiting. |
| `max_attempts` | `int` | `120` | Maximum requests per window. |
| `decay_minutes` | `int` | `1` | Rate limit window in minutes. |

## Theme

```php
'theme' => [
    'default' => env('MARTIS_THEME', 'dark'),
    'allowToggle' => true,
    'name' => env('MARTIS_THEME_NAME', null),
],
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `default` | `string` | `'dark'` | Default theme: `'dark'` or `'light'`. |
| `allowToggle` | `bool` | `true` | Show the theme toggle in the user menu. |
| `name` | `?string` | `null` | Custom theme name for the `martis:theme` artisan command. |

Custom themes are scaffolded via `php artisan martis:theme`. See [Theming](components.md#theming).

## User Menu

```php
'user_menu' => [
    'showThemeToggle' => true,
    'showProfile' => true,
    'showNotifications' => true,
    // 'customItems' => [],
],
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `showThemeToggle` | `bool` | `true` | Show dark/light mode toggle. |
| `showProfile` | `bool` | `true` | Show profile page link. |
| `showNotifications` | `bool` | `true` | Show notifications indicator. |
| `customItems` | `?array` | `null` | Array of custom menu items (see example below). |

### Custom Menu Items

```php
'customItems' => [
    ['label' => 'Settings', 'icon' => 'pi pi-cog', 'url' => '/settings'],
    ['separator' => true],
    ['label' => 'Documentation', 'icon' => 'pi pi-book', 'url' => 'https://docs.example.com'],
],
```

## Global Search

```php
'search' => [
    'enabled' => true,
    'placeholder' => null,
    'mode' => env('MARTIS_SEARCH_MODE', 'bar'),
    'mobileMode' => env('MARTIS_SEARCH_MOBILE_MODE', 'icon'),
],
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `enabled` | `bool` | `true` | Enable/disable global search. |
| `placeholder` | `?string` | `null` | Custom placeholder text (`null` = i18n default). |
| `mode` | `string` | `'bar'` | Desktop mode: `'bar'`, `'icon'`, or `'disabled'`. |
| `mobileMode` | `string` | `'icon'` | Mobile mode: `'bar'`, `'icon'`, or `'disabled'`. |

## Dashboard

```php
'dashboard' => [
    'showGreeting' => env('MARTIS_DASHBOARD_SHOW_GREETING', true),
    'showWelcome' => env('MARTIS_DASHBOARD_SHOW_WELCOME', true),
    'showMetrics' => true,
    'showResourceCards' => true,
],
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `showGreeting` | `bool` | `true` | Show personalised greeting (`Hello, {name}`). Override with `MARTIS_DASHBOARD_SHOW_GREETING=false`. |
| `showWelcome` | `bool` | `true` | Show welcome subtitle (`Welcome to Martis Admin Engine.`) below the greeting. Override with `MARTIS_DASHBOARD_SHOW_WELCOME=false`. |
| `showMetrics` | `bool` | `true` | Show summary metrics row (total resources, groups). |
| `showResourceCards` | `bool` | `true` | Show resource quick-access cards grid. |

## Toast Notifications

```php
'toast' => [
    'position' => env('MARTIS_TOAST_POSITION', 'bottom-right'),
],
```

Options: `'top-right'`, `'top-left'`, `'bottom-right'`, `'bottom-left'`, `'top-center'`, `'bottom-center'`.

## Index (Resource Listing)

```php
'index' => [
    'default_row_actions' => [
        'enabled' => env('MARTIS_DEFAULT_ROW_ACTIONS', true),
        'view'    => env('MARTIS_DEFAULT_ROW_ACTION_VIEW', true),
        'edit'    => env('MARTIS_DEFAULT_ROW_ACTION_EDIT', true),
        'delete'  => env('MARTIS_DEFAULT_ROW_ACTION_DELETE', true),
    ],
    'row_click_opens_detail' => env('MARTIS_ROW_CLICK_OPENS_DETAIL', true),
    'default_trashed_filter' => env('MARTIS_DEFAULT_TRASHED_FILTER', 'active'),
],
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `default_row_actions.enabled` | `bool` | `true` | Master switch for the default row actions column (view/edit/delete). |
| `default_row_actions.view` | `bool` | `true` | Show the view icon. |
| `default_row_actions.edit` | `bool` | `true` | Show the edit icon. |
| `default_row_actions.delete` | `bool` | `true` | Show the delete icon. |
| `row_click_opens_detail` | `bool` | `true` | When default row actions expose a "view" icon, clicking the row body becomes redundant. Set to `false` to disable row-click and keep the row informational. Override per resource with `rowClickOpensDetail(Request $request): ?bool`. |
| `default_trashed_filter` | `string` | `'active'` | Initial state of the trashed-filter dropdown on soft-delete resources (main index **and** relationship panels). One of `'active'` (hide deleted), `'with'` (include deleted alongside live), `'only'` (only deleted). Visibility of the dropdown itself is gated by [`Resource::canViewTrashed()`](resources.md#restricting-trashed-visibility-by-role). |

Override per-resource with the `defaultRowActions(Request $request): bool|array` and `rowClickOpensDetail(Request $request): ?bool` methods. See [Default Row Actions](default_row_actions.md) for the full guide.

## Pagination

```php
'pagination' => [
    'default_per_page' => 25,
    'max_per_page' => 100,
],
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `default_per_page` | `int` | `25` | Fallback for `Resource::perPage()` and `Lens::perPage()` when the resource/lens does not override the method. |
| `max_per_page` | `int` | `100` | Upper bound enforced by the ResourceController when the client passes `?perPage=N` on the URL. Protects against pathological page sizes. |

Override per-resource via `perPage()` and `perPageOptions()` on the resource class. When the returned `perPage()` is not present in `perPageOptions()`, Martis silently clamps to `perPageOptions()[0]` — see [resources.md — Effective per-page](resources.md#effective-per-page).

## Storage

```php
'storage' => [
    'disk' => env('MARTIS_STORAGE_DISK', 'public'),
],
```

Default filesystem disk for file uploads. Individual `File` and `Image` fields can override this via `->disk('s3')`.

## Resources Path

```php
'resources_path' => app_path('Martis'),
```

Directory where auto-discovery looks for resource classes. Martis scans this path recursively for classes that extend `Martis\Resource`.

## Policy Namespace

```php
'policy_namespace' => 'App\\Martis\\Policies',
```

Namespace for auto-discovery of resource policies. When a resource does not define an explicit `$policy` property, Martis looks for `{policy_namespace}\{ResourceBaseName}Policy`.

## Attachments

```php
'attachments' => [
    'allowed_mimes' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf', ...],
    'allowed_disks' => ['public', 'local'],
    'max_size' => 10240,  // KB (10MB)
],
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `allowed_mimes` | `array` | See config | Allowed MIME types for Trix/Markdown file uploads. |
| `allowed_disks` | `array` | `['public', 'local']` | Storage disks the upload endpoint accepts. |
| `max_size` | `int` | `10240` | Maximum file size in KB. |

## Action Events (Audit Log)

```php
'action_events' => [
    'enabled' => true,
    'resource' => true,
],
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `enabled` | `bool` | `true` | Record action events to the database. |
| `resource` | `bool` | `true` | Register ActionEvent as a browsable resource in the sidebar. |

Individual actions can opt out via `->withoutActionEvents()`.

## Code-side registrations: `app/Providers/MartisServiceProvider.php`

`config/martis.php` cannot hold closures — Laravel's `config:cache` fails to serialize them. So Martis splits configuration into two layers:

| Layer | What it holds | File |
|---|---|---|
| **Static config** | Paths, throttle, theme, profile, cache TTLs, drawer widths, sticky-views scope, notifications poll interval, … | `config/martis.php` |
| **Code registrations** | Main menu resolver, dashboards, custom cache layers, gate definitions, page-title closures | `app/Providers/MartisServiceProvider.php` |

`martis:install` publishes the provider stub to `app/Providers/MartisServiceProvider.php` and wires it into `bootstrap/providers.php` (Laravel 11+) or `config/app.php` (Laravel 10) automatically. Re-running `martis:install` is idempotent — the file is preserved and the bootstrap entry is not duplicated. Use `--force` to refresh the stub.

The stub ships every section commented-out, so an unmodified provider registers nothing and Martis runs on its built-in defaults. Uncomment what you need:

```php
// app/Providers/MartisServiceProvider.php

protected function registerMainMenu(): void
{
    // Layout-agnostic — feeds sidebar / topnav / minimal presets equally.
    Martis::mainMenu(function ($request, $menu) {
        return $menu->sections([
            MenuSection::make('Operations', [
                MenuItem::resource(\App\Martis\Resources\ClientResource::class),
            ])->icon('briefcase'),
        ]);
    });
}

protected function registerDashboards(): void
{
    Martis::dashboards([
        \App\Martis\Dashboards\OperationsDashboard::class,
    ]);
}

protected function registerCacheLayers(): void
{
    MartisCache::extend('orders', enabled: true, ttl: 30);
}

protected function registerGates(): void
{
    Gate::define('manage-martis-cache', fn ($user) => $user->is_admin);
}
```

Why a dedicated provider instead of `AppServiceProvider`?
- Separation of concerns — Martis bootstrap stays out of host-app providers.
- Self-documenting — section names (`registerMainMenu`, `registerDashboards`, `registerCacheLayers`, `registerGates`) act as a catalogue of every code-side hook Martis exposes.
- Easier upgrades — when Martis adds a new registration point, the stub gains a section; `AppServiceProvider` is never touched.
- Mirrors the dedicated-provider pattern other admin engines use.

To republish the stub manually (e.g. after package upgrade adds a new section): `php artisan vendor:publish --tag=martis-provider --force`.

## Cache

```php
'cache' => [
    'enabled'    => true,
    'metrics'    => ['enabled' => true, 'ttl' => 5],
    'navigation' => ['enabled' => true, 'ttl' => 1],
    'dashboards' => ['enabled' => true, 'ttl' => null],
    'schema'     => ['enabled' => true, 'ttl' => null],
    'admin_ui'   => true,
],
```

Per-subsystem cache layer with three control planes (config / env / runtime), bypass header, and admin page. See [Cache](cache.md) for the full reference.

## Profile

```php
'profile' => [
    'enabled' => true,
    'resource' => null,
    'menu' => ['label' => null, 'icon' => 'user'],
    'avatar' => [
        'enabled' => true,
        'disk' => 'public',
        'path' => 'avatars',
        'max_size_kb' => 2048,
        'column' => 'profile_picture',
        'url_resolver' => null,
    ],
    'two_factor' => [
        'enabled' => true,
        'recovery_codes' => 8,
    ],
    'sections' => ['account', 'password', 'avatar', 'security'],
],
```

See [Authentication](authentication.md#user-profile) for full profile documentation.

## User Preferences

```php
'preferences' => [
    'enabled' => env('MARTIS_PREFERENCES_ENABLED', true),
    'allowBrandColor' => env('MARTIS_PREFERENCES_ALLOW_BRAND_COLOR', false),
    'locale_labels' => [
        // 'pt_BR' => 'Português (Brasil)',
    ],
],
```

| Key | Type | Default | Effect |
|---|---|---|---|
| `enabled` | bool | `true` | Master switch for the per-user preferences panel (theme, density, locale, accent). |
| `allowBrandColor` | bool | `false` | When true, exposes a custom-hex brand colour picker in the preferences panel. |
| `locale_labels` | array | `[]` | Override the human-readable labels surfaced in the locale dropdown. |

See [User Preferences](preferences.md) for the D1/D2/D3 spec and [i18n](i18n.md) for the locale layer.

## Sticky Views

```php
'sticky_views' => [
    'enabled' => env('MARTIS_STICKY_VIEWS_ENABLED', true),
    'scope' => env('MARTIS_STICKY_VIEWS_SCOPE', 'session'),
    'persist' => [
        'filters' => true,
        'sorting' => true,
        'pagination' => true,
        'per_page' => true,
        'columns' => true,
        'scroll' => false,
    ],
],
```

| Key | Type | Default | Effect |
|---|---|---|---|
| `enabled` | bool | `true` | Master switch. Falsey disables the entire feature. |
| `scope` | enum | `session` | Where state lives: `session` (sessionStorage, wipes on tab close) or `local` (localStorage, survives). |
| `persist.filters` | bool | `true` | Remember filter values per resource. |
| `persist.sorting` | bool | `true` | Remember sort column + direction. |
| `persist.pagination` | bool | `true` | Remember current page. |
| `persist.per_page` | bool | `true` | Remember per-page selector. |
| `persist.columns` | bool | `true` | Remember visible columns toggle. |
| `persist.scroll` | bool | `false` | Remember scroll position on the index. |

See [Sticky Views](sticky_views.md) for the full behaviour spec.

## Loader

```php
'loader' => [
    'disabled' => env('MARTIS_LOADER_DISABLED', false),
],
```

| Key | Type | Default | Effect |
|---|---|---|---|
| `disabled` | bool | `false` | Globally suppress the page loader. Useful in tests or for apps that ship their own loading UI. |

See [Loader](loader.md) for the surface-by-surface behaviour matrix.

## Impersonation

```php
'impersonation' => [
    'enabled' => env('MARTIS_IMPERSONATION_ENABLED', false),
    'guard' => env('MARTIS_IMPERSONATION_GUARD', 'web'),
    'session_key' => env('MARTIS_IMPERSONATION_SESSION_KEY', 'martis.impersonation'),
],
```

| Key | Type | Default | Effect |
|---|---|---|---|
| `enabled` | bool | `false` | Master switch. Default OFF — opt-in. When false, the impersonation REST endpoints return 503 and the banner short-circuits its mount-time fetch. |
| `guard` | string | `web` | Auth guard the impersonation operates on. |
| `session_key` | string | `martis.impersonation` | Session bag where the operator's id is stashed. Change for cross-tenant isolation. |

The package ships **no default Gate**. Define `martis-impersonate` in your `AuthServiceProvider` to gate which users can start an impersonation session. See [Impersonation](impersonation.md).

## Environment Variables

| Variable | Config Key | Default |
|----------|-----------|---------|
| `MARTIS_PATH` | `path` | `martis` |
| `MARTIS_GUARD` | `guard` | `null` |
| `MARTIS_BRAND_NAME` | `brand.name` | `Martis` |
| `MARTIS_FAVICON` | `brand.favicon` | `null` |
| `MARTIS_PAGE_TITLE` | `brand.page_title` | `null` (uses translation) |
| `MARTIS_LAYOUT` | `layout.preset` | `sidebar` |
| `MARTIS_NAV_COUNTS` | `navigation.counts.enabled` | `true` |
| `MARTIS_NAV_POLL_MS` | `navigation.poll_interval` | `60000` |
| `MARTIS_LOCALE` | `locale` | `en` |
| `MARTIS_THROTTLE_ENABLED` | `throttle.enabled` | `true` |
| `MARTIS_THROTTLE_MAX` | `throttle.max_attempts` | `120` |
| `MARTIS_THROTTLE_DECAY` | `throttle.decay_minutes` | `1` |
| `MARTIS_THEME` | `theme.default` | `dark` |
| `MARTIS_THEME_NAME` | `theme.name` | `null` |
| `MARTIS_SEARCH_MODE` | `search.mode` | `bar` |
| `MARTIS_TOAST_POSITION` | `toast.position` | `bottom-right` |
| `MARTIS_DEFAULT_TRASHED_FILTER` | `index.default_trashed_filter` | `active` |
| `MARTIS_INDEX_COLUMN_DEFAULTS` | `index.column_defaults` | `true` |
| `MARTIS_STORAGE_DISK` | `storage.disk` | `public` |
| `MARTIS_ATTACHMENT_MIMES` | `attachments.allowed_mimes` | (see config) |
| `MARTIS_ATTACHMENT_MAX_SIZE` | `attachments.max_size` | `10240` |
| `MARTIS_ACTION_EVENTS_ENABLED` | `action_events.enabled` | `true` |
| `MARTIS_ACTION_EVENTS_RESOURCE` | `action_events.resource` | `true` |
| `MARTIS_PROFILE_ENABLED` | `profile.enabled` | `true` |
| `MARTIS_AVATAR_ENABLED` | `profile.avatar.enabled` | `true` |
| `MARTIS_AVATAR_DISK` | `profile.avatar.disk` | `public` |
| `MARTIS_AVATAR_PATH` | `profile.avatar.path` | `avatars` |
| `MARTIS_AVATAR_MAX_SIZE` | `profile.avatar.max_size_kb` | `2048` |
| `MARTIS_AVATAR_COLUMN` | `profile.avatar.column` | `profile_picture` |
| `MARTIS_2FA_ENABLED` | `profile.two_factor.enabled` | `true` |
| `MARTIS_2FA_RECOVERY_CODES` | `profile.two_factor.recovery_codes` | `8` |

## Next Steps

- [Installation Guide](installation-guide.md) — Initial setup
- [Authentication](authentication.md) — Login, 2FA, profile
- [Resources](resources.md) — Resource configuration
- [Override System](overrides.md) — Customize the UI
