# Configuration

Martis is configured through `config/martis.php`. Publish it with the bundled wrapper (or the standard `vendor:publish` if you prefer):

```bash
php artisan martis:vendor-publish --config
# equivalent to:
# php artisan vendor:publish --tag=martis-config
```

This page documents every configuration option grouped by subsystem. The full env-var index lives at the bottom — that table is **generated** by `php artisan martis:list-env-vars` and reflects the live config surface (currently **130 env vars**).

> **Single source of truth.** Whenever you suspect an env var is documented incorrectly here, run `php artisan martis:list-env-vars` and compare. The command parses `config/martis.php` directly, so its output cannot drift. CI calls it during release-cut to refresh the table at the bottom of this page.

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
    'logo' => env('MARTIS_BRAND_LOGO'),
    'logo_dark' => env('MARTIS_BRAND_LOGO_DARK'),       // v1.7.0
    'icon' => env('MARTIS_BRAND_ICON'),
    'icon_dark' => env('MARTIS_BRAND_ICON_DARK'),       // v1.7.0
    'favicon' => env('MARTIS_FAVICON', null),
    'page_title' => env('MARTIS_PAGE_TITLE'),           // null | string | callable
    'version' => env('MARTIS_BRAND_VERSION'),
    'docs_url' => env('MARTIS_BRAND_DOCS_URL'),
    'logo_height' => [                                  // v1.7.0
        'menu' => env('MARTIS_BRAND_LOGO_HEIGHT_MENU'),
        'auth' => env('MARTIS_BRAND_LOGO_HEIGHT_AUTH'),
    ],
],
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `name` | `string` | `'Martis'` | Displayed in the sidebar header and used as the `{brand}` interpolation for the bundled page title translation. |
| `logo` | `?string` | `null` | **Full horizontal lockup** (icon + wordmark in one asset). When set, the SPA renders the lockup alone — the separate `brand.name` text next to it is hidden in the sidebar / topbar / auth frame to avoid a duplicated wordmark. Relative paths resolve against `public/` (`/img/logo.svg`); full URLs pass through unchanged. |
| `icon` | `?string` | `null` | **Small square brand icon** (replaces just the bundled cube next to `brand.name`). Use this when you want to keep the brand text rendered by Martis but swap the mark. Independent from `logo` — Martis prefers `logo` when both are set. |
| `favicon` | `?string` | `null` | Path to a custom favicon (relative to `public/`). When `null`, Martis serves its own default favicon from the package — no `vendor:publish` step required. |
| `page_title` | `string \| callable \| null` | `null` | Browser tab title shown in `<title>`. `null` uses the bundled translation (e.g. "Acme — Admin Control"). A plain string overrides it. A callable (invokable class or array callable) receives the current `Request` and returns the title. |
| `version` | `?string` | `null` | Optional version string printed in the sidebar footer (e.g. `v1.5.2`, `2026.04.29`). |
| `docs_url` | `?string` | `null` | Optional docs link rendered on the right-hand side of the sidebar footer. |

### Choosing your brand mode

Martis renders the brand row (sidebar header / topnav left / auth card top) in one of four modes. **Where to set each**: every knob is an `.env` key in your application's `.env` file. **No published config edits required** — once `config/martis.php` reads `env('MARTIS_BRAND_LOGO')` / `env('MARTIS_BRAND_ICON')` (default since v1.5.2 / v1.6.3), flipping modes is one line in `.env` plus `php artisan config:cache`.

#### Mode 1 — Logo only (recommended when your brand has a horizontal lockup)

```env
MARTIS_BRAND_LOGO=/img/edgeflow-lockup.svg
# leave MARTIS_BRAND_ICON unset
```

Renders the lockup image alone. The `brand.name` text is **hidden** next to it (the wordmark inside your asset replaces it). Best for brands like EdgeFlow where the SVG already contains the mark + wordmark in one piece.

```
+------------------------+
| [EdgeFlow lockup]      |
+------------------------+
```

The image is sized to ~40px tall in the sidebar / topnav and ~40px tall in the auth card, scaling width to preserve the asset's aspect ratio (capped at the column width so a wide lockup never overflows).

#### Mode 2 — Icon + brand name (recommended when you only have a square mark)

```env
MARTIS_BRAND_ICON=/img/edgeflow-icon.png
MARTIS_BRAND_NAME="EdgeFlow"
# leave MARTIS_BRAND_LOGO unset
```

Renders `[icon] {brand.name}`. Same shape as the bundled experience but with your icon. Best when the wordmark isn't part of your asset, or when the brand text needs to vary per locale (`MARTIS_BRAND_NAME` can be left empty so a translation file drives it).

```
+------------------------+
| [icon] EdgeFlow        |
+------------------------+
```

The icon is constrained to a 28×28 square in the sidebar / topnav, 32×32 in the auth card. Provide a square asset.

#### Mode 3 — Bundled Martis cube + your brand name (the default)

```env
# both MARTIS_BRAND_LOGO and MARTIS_BRAND_ICON unset
MARTIS_BRAND_NAME="Acme"
```

Renders `[Martis cube] Acme`. The bundled cube is preserved; only `brand.name` is replaced. Ship-ready out of the box, useful while you do not yet have brand assets.

#### Mode 4 — Both set

```env
MARTIS_BRAND_LOGO=/img/edgeflow-lockup.svg
MARTIS_BRAND_ICON=/img/edgeflow-icon.png
```

`logo` wins on every surface; `icon` sits queued. Flip from logo-only to icon mode by **clearing** `MARTIS_BRAND_LOGO` in `.env` — no code change, no rebuild.

### Where each mode appears

| Surface | Reads from |
|---|---|
| Sidebar header (`sidebar` preset) | `brand.logo` → `brand.icon` → bundled cube |
| Topnav header (`topnav` preset) | `brand.logo` → `brand.icon` → bundled cube |
| Auth card (Login, Register, Forgot password, Reset password, 2FA challenge, error screens) | `brand.logo` → `brand.icon` → bundled lockup (with wordmark) |
| Browser tab favicon | `brand.favicon` (independent — see "Customising the favicon" below) |
| Browser tab title | `brand.page_title` + `brand.name` (independent — see "Customising the page title" below) |

Three rows, three env vars, one consistent rendering rule. Pick the mode that matches the asset you have.

### Switching modes after deployment

After editing `.env`:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan martis:cache:clear
```

The config cache holds the env values; the Martis cache holds the navigation payload (which embeds the brand). Both must be cleared for the mode swap to land. The frontend bundle does NOT need rebuilding — `window.MartisConfig` is rendered on every request.

### Theme-aware variants (v1.7.0)

Each brand asset can ship a separate **dark-theme** variant. The SPA renders both `<img>` tags in the DOM and CSS hides one based on `<html data-theme>` — the toggle is instant (no React re-render, no fetch).

```env
MARTIS_BRAND_LOGO=/img/logo-light.svg
MARTIS_BRAND_LOGO_DARK=/img/logo-dark.svg
MARTIS_BRAND_ICON=/img/icon-light.png
MARTIS_BRAND_ICON_DARK=/img/icon-dark.png
```

Resolution rule per asset:

- Both set → render variant matching the active theme.
- Only one set → use it for both themes (backwards-compat with v1.6.x consumers that only have `MARTIS_BRAND_LOGO`).

The auth card honours the same rule. When the user toggles theme via the in-app PreferencesMenu, the variant flip is instant. Browser-OS dark mode (`prefers-color-scheme`) is not consulted because Martis's own toggle takes precedence over the OS.

### Asset sizing (v1.7.0)

The SPA renders brand assets at fixed heights. Override per surface:

```env
MARTIS_BRAND_LOGO_HEIGHT_MENU=40   # sidebar / topnav. Default 40. Range 20–56.
MARTIS_BRAND_LOGO_HEIGHT_AUTH=48   # auth card.        Default 48. Range 24–80.
```

The package clamps both values to the listed ranges — an absurd value cannot break the layout. The icon-mode size (28×28 in the menu, 32×32 in the auth card) is fixed and not configurable.

### Asset requirements (v1.8.0 — issue #127)

Brand assets must satisfy three constraints to render cleanly:

1. **Tight crop, no transparent padding.** The browser scales the entire canvas (visible + transparent) to the target height. A 200×200 PNG with the actual mark only occupying the centre 80×80 pixels will render at *less than half* the intended size. Crop to the visible bounds before exporting.
2. **Power-of-two PNGs OR scalable SVG.** SVG is preferred — it stays sharp at any height. For raster, export at 2× the largest target height (auth card is 48 px → ship at 96 px) so retina displays look crisp.
3. **Light- AND dark-theme variants.** When the user toggles theme, the inactive variant is hidden via CSS — both files ship in the bundle. If the brand mark works on both backgrounds, point `MARTIS_BRAND_LOGO` and `MARTIS_BRAND_LOGO_DARK` at the same file (zero overhead — same hash, dedup'd).

Recommended targets per surface:

| Surface | Logo height | Icon height |
|---|---|---|
| Sidebar (expanded) | 40 px (`MARTIS_BRAND_LOGO_HEIGHT_MENU` default) | 28 px |
| Sidebar (collapsed rail) | n/a — icon wins | 28 px |
| Auth card | 48 px (`MARTIS_BRAND_LOGO_HEIGHT_AUTH` default) | 32 px |

Tracking issue: [#127](https://github.com/Real-Edge-FX/martis-package/issues/127).

### Sidebar collapse behaviour (v1.7.0)

When the sidebar collapses (240 px → 64 px rail) **and** `brand.icon` is set, the icon wins regardless of `brand.logo`. A horizontal lockup at 64 px rail width gets distorted; the square icon fits cleanly. When only `brand.logo` is set, the logo shrinks (the v1.6.x fallback). The sidebar text (`brand.name`) is always hidden in collapsed mode.

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
    'text' => env('MARTIS_FOOTER_TEXT'),
],
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `enabled` | `bool` | `true` | Set `false` to hide the footer entirely. |
| `text` | `?string` | `null` | Custom footer text. When `null`, the bundled translation renders (`© {brand.name} · Powered by Martis`). |

> **i18n trade-off.** `MARTIS_FOOTER_TEXT` is a single string that overrides every locale. For per-locale footer copy, leave the env var unset and publish the lang files with `php artisan vendor:publish --tag=martis-lang`, then edit each locale file directly.

## Dashboard greeting & welcome card

The dashboard renders TWO different welcome strings — make sure you edit the right one:

| Visible string | Translation key | Customisation |
|---|---|---|
| `Hello, {name}` (greeting line) | `martis::resources.hello` | Lang files only (publish + edit) |
| **`Welcome to Martis Admin Engine.`** (subtitle under the greeting) | `martis::resources.welcome` | **Lang files only** (no env var) |
| `Welcome to Martis` (animated `<WelcomeCard>` heading, optional) | `martis::resources.welcome_card_heading` | `MARTIS_WELCOME_HEADING` env **or** lang files |
| Welcome card body | `martis::resources.welcome_card_description` | `MARTIS_WELCOME_DESCRIPTION` env **or** lang files |

The `<WelcomeCard>` is the animated hero shown when `dashboard.showWelcomeCard !== false` — it sits at the top of the dashboard and is what the env vars below control. The greeting line ("Hello, X" + "Welcome to Martis Admin Engine") is a **separate** simple text block; **only lang files override it**. Frequent confusion: setting `MARTIS_WELCOME_HEADING` does not change the greeting subtitle.

```php
'welcome' => [
    'heading' => env('MARTIS_WELCOME_HEADING'),
    'description' => env('MARTIS_WELCOME_DESCRIPTION'),
],
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `heading` | `?string` | `null` | Custom heading for the dashboard's animated `<WelcomeCard>`. When `null`, uses the bundled `welcome_card_heading` translation. |
| `description` | `?string` | `null` | Custom description shown below the heading. When `null`, uses the bundled `welcome_card_description` translation. |

### Customising the greeting subtitle ("Welcome to Martis Admin Engine.")

Publish the lang files and edit the `welcome` key:

```bash
php artisan vendor:publish --tag=martis-lang --force
```

Then `resources/lang/vendor/martis/{en,pt_BR,pt_PT}/resources.php`:

```php
<?php
return [
    'welcome' => 'Welcome to EdgeFlow — your market quality terminal.',
];
```

Laravel deep-merges with the package shipped file — only `welcome` changes; everything else falls through. After editing, `php artisan optimize:clear`.

### Resolution order for the `<WelcomeCard>`

(First non-null wins.)

1. Prop passed at render-time (rare — only if the host overrides the React component itself).
2. `welcome.heading` / `welcome.description` from `config/martis.php` (env-driven).
3. `martis::resources.welcome_card_heading` / `welcome_card_description` translations (which honour your published lang overrides if any).

> **i18n trade-off.** `MARTIS_WELCOME_HEADING` and `MARTIS_WELCOME_DESCRIPTION` are single strings that override every locale. For per-locale copy, leave both env vars unset and publish the lang files. The two paths are mutually exclusive — env wins when set.

### Example: branding the dashboard from `.env`

```env
MARTIS_BRAND_NAME="EdgeFlow"
MARTIS_BRAND_LOGO="/img/edgeflow-logo.svg"
MARTIS_FAVICON="brand/favicon.ico"
MARTIS_PAGE_TITLE="EdgeFlow"
MARTIS_FOOTER_TEXT="© 2026 EdgeFlow. All rights reserved."
MARTIS_WELCOME_HEADING="Welcome to EdgeFlow"
MARTIS_WELCOME_DESCRIPTION="NQ/ES/YM regime intelligence for futures traders."
```

No code changes, no `vendor:publish`. The SPA picks all of this up at boot via `window.MartisConfig`.

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
        'topbar'  => 'tenant-topbar',   // custom topbar auto-discovered from `resources/js/martis-extensions/overrides/`
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
        'compact_threshold' => env('MARTIS_NAV_COUNT_COMPACT_THRESHOLD', 10000),  // v1.8.0
    ],
    'badges_poll_interval' => (int) env('MARTIS_NAV_BADGES_POLL_MS', 300000),  // v1.8.8
],
```

- `counts.enabled` — master switch for the resource count badge (`Users 1,284`) rendered in the sidebar and top-nav dropdowns. When true, every resource publishes a count by default; per-resource opt-out via `showMenuCount(): bool` on the `Resource` class.
- `counts.compact_threshold` (v1.8.0) — value at or above which the badge switches from full digits to compact notation:
  - Below threshold: `1,284` (locale-aware separators).
  - At or above: `Intl.NumberFormat` compact notation — `10K`, `123.5K`, `1.2M`, `25M`.
  - Default `10000` keeps everyday counts readable while preventing badges from blowing up the sidebar at 50 K+. Set to `null` to always show full digits, `0` to always compact, or any positive integer for a custom cutoff.
- `badges_poll_interval` (v1.8.8) — how often (in milliseconds) the sidebar and top-nav re-fetch the **lightweight** badges endpoint (`/api/navigation/badges`). Default: 300000 (5 minutes). Set to `0` to disable badge polling. The full navigation tree is fetched once per session + on route mutations and is **not auto-polled** — menu structure rarely changes in production. The badges payload is a flat `{ uriKey: count }` map and is 5–10× cheaper server-side than the full tree. React Query pauses the interval when the tab is hidden and refetches on window focus independently.

> **Breaking change in v1.8.8.** The previous `MARTIS_NAV_POLL_MS` (which auto-polled `/api/navigation`) was removed. The full navigation tree is no longer auto-polled at all — only `/api/navigation/badges` is, on the new `badges_poll_interval`. Apps that still set `MARTIS_NAV_POLL_MS` will see the value silently ignored; rename to `MARTIS_NAV_BADGES_POLL_MS` (and pick a longer cadence — the default jumped from 60 s to 5 min because badges-only is cheap and menu structure rarely changes mid-session).

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
| `allowToggle` | `bool` | `true` | When `false`, the theme picker is hidden everywhere — the Theme section disappears from the preferences overlay and the theme cycle button is suppressed on every pre-login surface (login, register, 2FA, password reset). Use this to lock the entire shell to a single theme without removing the rest of the preferences. |
| `name` | `?string` | `null` | Custom theme name for the `martis:theme` artisan command. |

Custom themes are scaffolded via `php artisan martis:theme`. See [Theming](components.md#theming).

## Keyboard Shortcuts

```php
'keyboard_shortcuts' => [
    'enabled' => env('MARTIS_KEYBOARD_SHORTCUTS_ENABLED', true),
    'helpOverlay' => env('MARTIS_KEYBOARD_SHORTCUTS_HELP_OVERLAY', true),
],
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `enabled` | `bool` | `true` | Master switch for the entire keyboard-shortcuts registry. When `false`, every `addShortcut()` call returns a no-op disposer; no event listener is bound and the bundled `mod+k`, `/`, and `shift+?` combos do nothing. Use it when the host app ships a custom keyboard layer. |
| `helpOverlay` | `bool` | `true` | When `false`, the bundled `Shift+?` help overlay is not registered. `addShortcut()` itself stays live for every other combo, so this is the right knob if the host app wants to expose its own help UI instead of the dialog. |

Both flags are read live, so mid-session toggles via `window.MartisConfig` take effect on the next registration. See [Keyboard Shortcuts](keyboard-shortcuts.md) for the full API.

## User Menu

```php
'user_menu' => [
    'showThemeToggle' => true,
    'showProfile' => env('MARTIS_SHOW_PROFILE_MENU', true),
    // 'customItems' => [],
],
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `showThemeToggle` | `bool` | `true` | Show dark/light mode toggle. |
| `showProfile` | `bool` | `true` | Show profile page link. Override with `MARTIS_SHOW_PROFILE_MENU=false`. |
| `customItems` | `?array` | `null` | Array of custom menu items (see example below). |

> **The notifications bell** next to the profile is not a `user_menu` option — it is controlled by the `notifications.enabled` master switch (see the Notifications section). Set `MARTIS_NOTIFICATIONS_ENABLED=false` to hide it.

### Custom Menu Items

Each item accepts `label` (i18n key or literal), `icon` (**Phosphor icon name** — as of v1.29.0, not a PrimeIcons class), `url` (internal path or external URL), and an optional `position` (`'before'` / `'after'` the built-in Profile entry). Full reference in [Authentication → User Menu](authentication.md#user-menu).

```php
'customItems' => [
    ['label' => 'Settings', 'icon' => 'gear', 'url' => '/settings'],
    ['separator' => true],
    ['label' => 'Documentation', 'icon' => 'book-open', 'url' => 'https://docs.example.com'],
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
    'showWelcomeCard' => env('MARTIS_DASHBOARD_SHOW_WELCOME_CARD', true),
    'showMetrics' => true,
    'showResourceCards' => true,
],
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `showGreeting` | `bool` | `true` | Show personalised greeting (`Hello, {name}`). Override with `MARTIS_DASHBOARD_SHOW_GREETING=false`. |
| `showWelcome` | `bool` | `true` | Show welcome subtitle (`Welcome to Martis Admin Engine.`) below the greeting. Override with `MARTIS_DASHBOARD_SHOW_WELCOME=false`. |
| `showWelcomeCard` | `bool` | `true` | Show the animated WelcomeCard hero at the top of the dashboard. Override with `MARTIS_DASHBOARD_SHOW_WELCOME_CARD=false`. |
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
        'view' => env('MARTIS_DEFAULT_ROW_ACTION_VIEW', true),
        'edit' => env('MARTIS_DEFAULT_ROW_ACTION_EDIT', true),
        'delete' => env('MARTIS_DEFAULT_ROW_ACTION_DELETE', true),
    ],
    'row_click_opens_detail' => env('MARTIS_ROW_CLICK_OPENS_DETAIL', true),
    'default_trashed_filter' => env('MARTIS_DEFAULT_TRASHED_FILTER', 'active'),
],
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `default_row_actions.enabled` | `bool` | `true` | Master switch for the default row actions column (view/edit/delete and restore/force-delete on soft-delete models). When `false`, Martis never renders the column. |
| `default_row_actions.view` | `bool` | `true` | Per-action global kill-switch for the View icon. Flip to `false` to hide it across every resource. `MARTIS_DEFAULT_ROW_ACTION_VIEW`. |
| `default_row_actions.edit` | `bool` | `true` | Per-action global kill-switch for the Edit icon. `MARTIS_DEFAULT_ROW_ACTION_EDIT`. |
| `default_row_actions.delete` | `bool` | `true` | Per-action global kill-switch for the Delete icon. `MARTIS_DEFAULT_ROW_ACTION_DELETE`. |
| `row_click_opens_detail` | `bool` | `true` | When default row actions expose a "view" icon, clicking the row body becomes redundant. Set to `false` to disable row-click and keep the row informational. Override per resource with `rowClickOpensDetail(Request $request): ?bool`. |
| `default_trashed_filter` | `string` | `'active'` | Initial state of the trashed-filter dropdown on soft-delete resources (main index **and** relationship panels). One of `'active'` (hide deleted), `'with'` (include deleted alongside live), `'only'` (only deleted). Visibility of the dropdown itself is gated by [`Resource::canViewTrashed()`](resources.md#restricting-trashed-visibility-by-role). |

The three per-action sub-keys are global kill-switches. A resource-level `Resource::defaultRowActions(Request $request): bool|array` can subtract further but never force a globally-disabled action back on. It can return:

- `true` (default) — show all four (view, edit, delete, plus restore/force-delete on soft-delete models)
- `false` — opt the resource out entirely
- a subset array — e.g. `['view', 'edit']` to hide the destructive actions
- a closure-aware decision per request

See [Default Row Actions](default_row_actions.md) for the full guide.

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

## Tools Auto-Discovery

```php
'tools_path' => app_path('Martis/Tools'),
'tools_namespace' => 'App\\Martis\\Tools',
'discovery' => [
    'tools' => env('MARTIS_DISCOVERY_TOOLS', true),
],
```

Where Martis scans for `Martis\Tools\Tool` subclasses (since v1.8.20). Discovery merges with any `Martis::tools([...])` registration via dedup by class-string, so adopting it does not break apps that already registered Tools manually. Set `discovery.tools` to `false` (or `MARTIS_DISCOVERY_TOOLS=false`) to opt out and keep manual registration.

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

`martis:install` publishes the provider stub to `app/Providers/MartisServiceProvider.php` and wires it into `bootstrap/providers.php` automatically. Re-running `martis:install` is idempotent — the file is preserved and the bootstrap entry is not duplicated. Use `--force` to refresh the stub.

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

## Preferences (v1.7.0 defaults)

The preferences subsystem has been env-driven since v0.10. v1.7.0 surfaces three more knobs so a host can pick the look-and-feel that every brand-new user sees on first sign-in.

```env
MARTIS_DEFAULT_THEME=dark           # dark | light | system
MARTIS_DEFAULT_ACCENT=martis        # martis | blue | teal | violet | amber | <custom name>
MARTIS_DEFAULT_DENSITY=comfortable  # comfortable | dense
MARTIS_DEFAULT_LOCALE=en            # any locale shipped under resources/lang
```

Invalid values fall through to the safe defaults — a typo in `.env` never crashes the request.

### Custom accent colours

The accent swatches in the PreferencesMenu can be extended with arbitrary brand colours via a single env var:

```env
MARTIS_CUSTOM_ACCENTS="edgeflow:#1a73e8,sunset:#ff6b35,emerald:#10b981"
```

Format: comma-separated `name:hex` pairs. Whitespace around the separators is tolerated.

**Validation rules** (invalid entries are dropped silently with a `Log::warning`):

| Field | Pattern / rule |
|---|---|
| `name` | `[a-z][a-z0-9_-]{0,31}` — lowercase, alphanumeric + dash / underscore. Must NOT collide with a bundled enum value (`martis`, `blue`, `teal`, `violet`, `amber`, `custom`). |
| `hex` | `#RRGGBB` (6-digit hex with leading `#`). |
| Duplicates | Last-wins (env-override semantics). |
| Limit | Up to 24 custom accents. Beyond that the parser truncates. |

Each registered accent:

- Adds an extra swatch at the end of the PreferencesMenu accent picker.
- Becomes a valid value for `MARTIS_DEFAULT_ACCENT` (so a new user lands on it without having to click).
- Becomes a valid value for the user's persisted `accent` preference column.

### How the colour is rendered

Martis injects an inline `<style>` block at boot for every custom accent:

```css
html[data-accent="edgeflow"] {
  --martis-accent: #1a73e8;
  --martis-accent-hover:  color-mix(in srgb, #1a73e8 88%, black);
  --martis-accent-soft:   color-mix(in srgb, #1a73e8 18%, transparent);
  --martis-accent-strong: color-mix(in srgb, #1a73e8 92%, black);
  --martis-accent-text:   #ffffff;
}
```

Hover / soft / strong variants are derived from the base hex via `color-mix(in srgb, …)`, so the consumer only ships one colour per accent. Browser support: Chrome 111+, Safari 16.2+, Firefox 113+.

### Removing a custom accent

Drop the entry from `MARTIS_CUSTOM_ACCENTS` and `php artisan config:cache`. Users who had picked the removed name keep the value in their `user_preferences.accent` row, but the resolver falls back to `martis` on the next request — no migration needed.

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
    'account' => [
        // When false, the Account section renders the e-mail field
        // read-only (env MARTIS_PROFILE_EMAIL_EDITABLE). Pair with a
        // ProfileResource that rejects e-mail changes server-side.
        'email_editable' => true,
    ],
    'sections' => ['avatar', 'account', 'password', 'security', 'sessions'],
],
```

See [Authentication](authentication.md#user-profile) for full profile documentation.

## User Preferences

```php
'preferences' => [
    'enabled' => env('MARTIS_PREFERENCES_ENABLED', true),
    'allowBrandColor' => env('MARTIS_ALLOW_BRAND_COLOR', false),
    'locales' => ['en', 'pt_PT', 'pt_BR'],
    'locale_labels' => [
        // 'pt_BR' => 'Português (Brasil)',
    ],
],
```

| Key | Type | Default | Effect |
|---|---|---|---|
| `enabled` | bool | `true` | Master switch for the per-user preferences panel (theme, density, locale, accent). |
| `allowBrandColor` | bool | `false` | When true, exposes a custom-hex brand colour picker in the preferences panel. |
| `locales` | array | `['en','pt_PT','pt_BR']` | The exact locale codes the language picker offers — on **both** the login screen and the in-app Preferences panel. Restrict it (e.g. `['en','pt_PT']`) to hide a bundled locale. When empty, the SPA falls back to the three bundled locales. |
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

## Audit (v1.8.8)

The package can record three categories of administrative events into the `martis_action_events` table.

```php
'audit' => [
    'role_changes'   => env('MARTIS_AUDIT_ROLE_CHANGES', true),
    'impersonation'  => env('MARTIS_AUDIT_IMPERSONATION', true),
    'authz_denials'  => env('MARTIS_AUDIT_AUTHZ_DENIALS', false),
    'authz_denials_include_viewany' => env('MARTIS_AUDIT_AUTHZ_DENIALS_INCLUDE_VIEWANY', false),
],
```

| Key | Default | Effect |
|---|---|---|
| `role_changes` | `true` | Logs `role.attached` / `role.detached` rows whenever Spatie attaches or detaches a role. |
| `impersonation` | `true` | Logs `impersonation.started` / `impersonation.stopped`. |
| `authz_denials` | `false` | Records denied gate decisions as `authz.denied`. Off by default — turning it on can be noisy on a busy app. |
| `authz_denials_include_viewany` | `false` | When `authz_denials` is on, also record `viewAny` denials. Off by default because index pages probe `viewAny` on every request. |

The denial listener dedupes the same `(ability, model_class, model_id)` tuple within one request, so a sidebar that probes the same gate three times only emits one row.

## Authorization tuning (v1.8.8)

```php
'authz' => [
    'request_cache'              => env('MARTIS_AUTHZ_REQUEST_CACHE', false),
    'revoke_sessions_on_demote'  => env('MARTIS_AUTHZ_REVOKE_SESSIONS_ON_DEMOTE', false),
],
```

| Key | Default | Effect |
|---|---|---|
| `request_cache` | `false` | Memoises `(user, ability, model)` gate results for the current request. Wins when a single request evaluates the same gate from many surfaces (sidebar, schema authorization block, action visibility). Per-request only — never crosses request boundaries. Closure gates with non-Model arguments are skipped. |
| `revoke_sessions_on_demote` | `false` | When a role is detached from a user, force-logs out their existing browser sessions. Useful when promoting/demoting between admin tiers. |

## Magic-link sign-in (v1.8.8)

Passwordless login via signed email links.

```php
'auth' => [
    'magic_link' => [
        'enabled'       => env('MARTIS_AUTH_MAGIC_LINK_ENABLED', false),
        'ttl_minutes'   => (int) env('MARTIS_AUTH_MAGIC_LINK_TTL', 15),
        'auto_register' => env('MARTIS_AUTH_MAGIC_LINK_AUTO_REGISTER', false),
    ],
],
```

| Key | Default | Effect |
|---|---|---|
| `enabled` | `false` | Master switch. When on, the login page exposes a "Send magic link" form. |
| `ttl_minutes` | `15` | Validity window of the signed link. |
| `auto_register` | `false` | When on, an unknown email triggers a fresh user creation. Combine with a custom user model that handles defaults. |

## Email verification

```php
'auth' => [
    'email_verification' => [
        'enabled'    => env('MARTIS_AUTH_EMAIL_VERIFICATION_ENABLED', false),
        'notice_url' => env('MARTIS_AUTH_EMAIL_VERIFICATION_NOTICE_URL'),
    ],
],
```

When enabled, the package wires Laravel's `verified` middleware into the panel guards and exposes the verification notice page. `notice_url` lets you redirect to a custom page if you don't want the bundled one.

## Auth screen copy (v1.8.5)

Every auth surface (login, register, password forgot, password reset, invitation accept) lets you override the title and subtitle without touching translations.

| Variable | Default | Surface |
|---|---|---|
| `MARTIS_AUTH_LOGIN_TITLE` | translation | Login title |
| `MARTIS_AUTH_LOGIN_SUBTITLE` | translation | Login subtitle (password mode) |
| `MARTIS_AUTH_LOGIN_SUBTITLE_SSO` | translation | Login subtitle (SSO-only mode) |
| `MARTIS_AUTH_REGISTER_TITLE` | translation | Register title |
| `MARTIS_AUTH_REGISTER_SUBTITLE` | translation | Register subtitle |
| `MARTIS_AUTH_FORGOT_TITLE` | translation | Forgot password title |
| `MARTIS_AUTH_FORGOT_SUBTITLE` | translation | Forgot password subtitle |
| `MARTIS_AUTH_RESET_TITLE` | translation | Reset password title |
| `MARTIS_AUTH_RESET_SUBTITLE` | translation | Reset password subtitle |
| `MARTIS_AUTH_INVITATION_ACCEPT_TITLE` | translation | Invitation accept title |
| `MARTIS_AUTH_INVITATION_ACCEPT_SUBTITLE` | translation | Invitation accept subtitle |

Translations remain the recommended path for multi-locale apps. Use these env vars only when you want a single hard-coded string for every locale (e.g. an internal tool).

## Auth controls

```php
'auth' => [
    'controls' => [
        'theme'  => env('MARTIS_AUTH_CONTROL_THEME', true),
        'locale' => env('MARTIS_AUTH_CONTROL_LOCALE', true),
    ],
],
```

Toggles the theme switcher and locale picker that float on the auth pages.

## Password reset

```php
'auth' => [
    'passwordReset' => [
        'enabled' => env('MARTIS_AUTH_PASSWORD_RESET_ENABLED', false),
        'url'     => env('MARTIS_AUTH_PASSWORD_RESET_URL'),
        'broker'  => env('MARTIS_AUTH_PASSWORD_BROKER', 'users'),
    ],
],
```

Off by default. Enable to expose the "Forgot your password?" flow. `broker` matches the broker name in `config/auth.php`.

## Registration

```php
'auth' => [
    'registration' => [
        'enabled'      => env('MARTIS_AUTH_REGISTRATION_ENABLED', false),
        'url'          => env('MARTIS_AUTH_REGISTRATION_URL'),
        'default_role' => env('MARTIS_AUTH_REGISTRATION_DEFAULT_ROLE'),
    ],
],
```

Off by default. When on, exposes the register link and form. `default_role` (Spatie role name) is attached to fresh users.

## Login throttle

```php
'throttle' => [
    'login_attempts' => (int) env('MARTIS_LOGIN_THROTTLE_ATTEMPTS', 20),
    'login_minutes'  => (int) env('MARTIS_LOGIN_THROTTLE_MINUTES', 1),
],
```

These keys live in the same `throttle` block as the global panel limits (`MARTIS_THROTTLE_*`), but the `login_*` bucket is a separate brute-force guard on the login endpoint. Defaults to 20 attempts per minute.

## Impersonation extras

In addition to `MARTIS_IMPERSONATION_ENABLED` (covered above), the impersonation subsystem exposes:

| Variable | Default | Effect |
|---|---|---|
| `MARTIS_IMPERSONATION_GUARD` | `web` | Auth guard the impersonation operates on. |
| `MARTIS_IMPERSONATION_SESSION_KEY` | `martis.impersonation` | Session bag where the operator's id is stashed. |
| `MARTIS_IMPERSONATION_MAX_DURATION` | `0` | Maximum session length in minutes. `0` disables the timeout. |
| `MARTIS_IMPERSONATION_POLL_MS` | `120000` | Banner status poll interval in ms. Default 2 min — sessions change rarely. Set to `0` to disable polling (banner still mounts and reads state once per page load). v1.8.8. |

## API docs (Scramble)

```php
'api_docs' => [
    'enabled' => env('MARTIS_API_DOCS_ENABLED', false),
    'path'    => env('MARTIS_API_DOCS_PATH', 'api-docs'),
],
```

Off by default. When enabled, mounts the Scramble-generated OpenAPI 3.1 surface at `/{path}` (default `/api-docs`). See [API → Overview](api/overview.md) for the worker-restart caveat: PHP-FPM caches parsed env in process memory, so flipping the env requires restarting workers (or the PHP container) before the gate sees the new value.

## Notifications

```php
'notifications' => [
    'enabled'         => env('MARTIS_NOTIFICATIONS_ENABLED', true),
    'max_in_dropdown' => (int) env('MARTIS_NOTIFICATIONS_MAX_DROPDOWN', 10),
    // v1.8.8 — bumped from 60 s to 90 s. Single COUNT query, but no
    // need to poll faster than the average user notices a new entry.
    'poll_interval'   => (int) env('MARTIS_NOTIFICATIONS_POLL_INTERVAL', 90000),
],
```

Controls the in-app notifications subsystem. `enabled` is the master switch: set it to `false` (env `MARTIS_NOTIFICATIONS_ENABLED=false`) to hide the topbar bell icon next to the user profile entirely, stop polling, and make the REST endpoints return empty payloads. This is the single toggle for "show / enable system notifications" — there is no separate `user_menu` option for the bell.

## Cache (per-type tuning)

Beyond the global `MARTIS_CACHE_ENABLED` master switch, every surface that caches has its own enabled flag and TTL (in minutes; `null` = forever).

| Variable | Default | Surface |
|---|---|---|
| `MARTIS_CACHE_DASHBOARDS_ENABLED` | `true` | Dashboard renders |
| `MARTIS_CACHE_DASHBOARDS` / `MARTIS_CACHE_DASHBOARDS_TTL` | `null` | Dashboard TTL |
| `MARTIS_CACHE_METRICS_ENABLED` | `true` | Metric cards |
| `MARTIS_CACHE_METRICS` / `MARTIS_CACHE_METRICS_TTL` | `5` | Metrics TTL |
| `MARTIS_CACHE_NAVIGATION_ENABLED` | `true` | Sidebar navigation tree |
| `MARTIS_CACHE_NAVIGATION` / `MARTIS_CACHE_NAVIGATION_TTL` | `1` | Navigation TTL |
| `MARTIS_CACHE_SCHEMA_ENABLED` | `true` | Resource schema payload |
| `MARTIS_CACHE_SCHEMA` / `MARTIS_CACHE_SCHEMA_TTL` | `null` | Schema TTL |
| `MARTIS_CACHE_ADMIN_UI` | `true` | Cache admin UI in the System sidebar group |

The shorter names (`MARTIS_CACHE_DASHBOARDS`, etc.) and the explicit `_TTL` variants resolve to the same value — the `_TTL` form just makes intent unambiguous. Pick whichever reads better in your `.env`.

## Drawer

```php
'drawer' => [
    'expandable' => env('MARTIS_DRAWER_EXPANDABLE', true),
],
```

When on, the drawer that opens record detail can be expanded into a full overlay. Set to `false` to lock the drawer to its standard width.

## Sticky views

```php
'sticky_views' => [
    'enabled' => env('MARTIS_STICKY_VIEWS_ENABLED', true),
    'scope'   => env('MARTIS_STICKY_VIEWS_SCOPE', 'session'),
],
```

| Key | Default | Effect |
|---|---|---|
| `enabled` | `true` | Remember the last index URL (filter, sort, page) per resource so users return to where they left off. |
| `scope` | `session` | Where state is persisted: `session` — sessionStorage, wipes on tab close; `local` — localStorage, survives tab close. |

## Loader

```php
'loader' => [
    'disabled' => env('MARTIS_LOADER_DISABLED', false),
],
```

Hides the global pre-hydration loader. Useful when you embed Martis inside an outer shell that already shows its own splash screen.

## Search defaults

```php
'search' => [
    'mode'          => env('MARTIS_SEARCH_MODE', 'bar'),
    'mobile_mode'   => env('MARTIS_SEARCH_MOBILE_MODE', 'icon'),
    'default_limit' => (int) env('MARTIS_SEARCH_DEFAULT_LIMIT', 5),
    'min_query'     => (int) env('MARTIS_SEARCH_MIN_QUERY', 2),
],
```

| Key | Default | Effect |
|---|---|---|
| `mode` | `bar` | Topbar search mode on desktop. `bar`, `icon`, or `disabled`. |
| `mobile_mode` | `icon` | Equivalent for mobile viewports. |
| `default_limit` | `5` | Result rows per resource in the omnisearch dropdown. |
| `min_query` | `2` | Don't trigger a search until the query has this many characters. |

## Default row actions

```php
'index' => [
    'default_row_actions' => [
        'enabled' => env('MARTIS_DEFAULT_ROW_ACTIONS', true),
        'view'    => env('MARTIS_DEFAULT_ROW_ACTION_VIEW', true),
        'edit'    => env('MARTIS_DEFAULT_ROW_ACTION_EDIT', true),
        'delete'  => env('MARTIS_DEFAULT_ROW_ACTION_DELETE', true),
    ],
    'row_click_opens_detail' => env('MARTIS_ROW_CLICK_OPENS_DETAIL', true),
],
```

The View / Edit / Delete row actions appear by default on every Resource. Toggle individual ones globally, or disable the whole `default_row_actions` block and re-add per Resource via `actions()`. `row_click_opens_detail` controls whether clicking a row opens detail (or just selects the row).

## Locales and i18n

```php
'locales' => [
    'app_namespaces' => env('MARTIS_APP_LOCALE_NAMESPACES', ''),
    'fallback_chain' => env('MARTIS_LOCALE_FALLBACK_CHAIN', 'en'),
    'rtl_locales'    => env('MARTIS_RTL_LOCALES', 'ar,fa,he,ur'),
],
```

Each env var is a comma-separated string; Martis splits it into an array at boot.

| Key | Default | Effect |
|---|---|---|
| `locales.fallback_chain` | `en` | Comma-separated chain consulted when a translation is missing in the active locale. |
| `locales.app_namespaces` | `''` | Comma-separated list of consumer translation namespaces published into Martis surfaces (so your app can override package strings). |
| `locales.rtl_locales` | `ar,fa,he,ur` | Locales rendered right-to-left. |

## Dev tools (v1.8.8)

```php
'dev' => [
    'tools_enabled' => env('MARTIS_DEV_TOOLS', in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)),
],
```

Master switch for the Component Inspector overlay (Cmd/Ctrl+Shift+I) and other in-page debugging surfaces. Defaults to ON in `local` and `testing`, OFF in `production`. Force ON in production with `MARTIS_DEV_TOOLS=true` for short-lived diagnostic windows.

## Environment variables (auto-generated)

The table below is generated by `php artisan martis:list-env-vars` from the live `config/martis.php` and reflects **130 env vars** in the current build. Run the command yourself to refresh it; CI runs it during release-cut. The command also takes `--json` for machine consumption.

```bash
php artisan martis:list-env-vars             # markdown table
php artisan martis:list-env-vars --json      # JSON array
```

| Variable | Default |
|----------|---------|
| `MARTIS_2FA_ENABLED` | `true` |
| `MARTIS_2FA_RECOVERY_CODES` | `8` |
| `MARTIS_ACTION_EVENTS_ENABLED` | `true` |
| `MARTIS_ACTION_EVENTS_RESOURCE` | `true` |
| `MARTIS_ALLOW_BRAND_COLOR` | `false` |
| `MARTIS_API_DOCS_ENABLED` | `false` |
| `MARTIS_API_DOCS_PATH` | `'api-docs'` |
| `MARTIS_APP_LOCALE_NAMESPACES` | `''` |
| `MARTIS_ATTACHMENT_MAX_SIZE` | `10240` |
| `MARTIS_ATTACHMENT_MIMES` | `'jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip,mp4,mp3'` |
| `MARTIS_AUDIT_AUTHZ_DENIALS` | `false` |
| `MARTIS_AUDIT_AUTHZ_DENIALS_INCLUDE_VIEWANY` | `false` |
| `MARTIS_AUDIT_IMPERSONATION` | `true` |
| `MARTIS_AUDIT_ROLE_CHANGES` | `true` |
| `MARTIS_AUTHZ_REQUEST_CACHE` | `false` |
| `MARTIS_AUTHZ_REVOKE_SESSIONS_ON_DEMOTE` | `false` |
| `MARTIS_AUTH_CONTROL_LOCALE` | `true` |
| `MARTIS_AUTH_CONTROL_THEME` | `true` |
| `MARTIS_AUTH_EMAIL_VERIFICATION_ENABLED` | `false` |
| `MARTIS_AUTH_EMAIL_VERIFICATION_NOTICE_URL` | `(no default)` |
| `MARTIS_AUTH_FORGOT_SUBTITLE` | `(no default)` |
| `MARTIS_AUTH_FORGOT_TITLE` | `(no default)` |
| `MARTIS_AUTH_LOGIN_SUBTITLE` | `(no default)` |
| `MARTIS_AUTH_LOGIN_SUBTITLE_SSO` | `(no default)` |
| `MARTIS_AUTH_LOGIN_TITLE` | `(no default)` |
| `MARTIS_AUTH_MAGIC_LINK_AUTO_REGISTER` | `false` |
| `MARTIS_AUTH_MAGIC_LINK_ENABLED` | `false` |
| `MARTIS_AUTH_MAGIC_LINK_TTL` | `15` |
| `MARTIS_AUTH_PASSWORD_BROKER` | `'users'` |
| `MARTIS_AUTH_PASSWORD_RESET_ENABLED` | `false` |
| `MARTIS_AUTH_PASSWORD_RESET_URL` | `(no default)` |
| `MARTIS_AUTH_REGISTER_SUBTITLE` | `(no default)` |
| `MARTIS_AUTH_REGISTER_TITLE` | `(no default)` |
| `MARTIS_AUTH_REGISTRATION_DEFAULT_ROLE` | `(no default)` |
| `MARTIS_AUTH_REGISTRATION_ENABLED` | `false` |
| `MARTIS_AUTH_REGISTRATION_URL` | `(no default)` |
| `MARTIS_AUTH_RESET_SUBTITLE` | `(no default)` |
| `MARTIS_AUTH_RESET_TITLE` | `(no default)` |
| `MARTIS_AVATAR_COLUMN` | `'profile_picture'` |
| `MARTIS_AVATAR_DISK` | `'public'` |
| `MARTIS_AVATAR_ENABLED` | `true` |
| `MARTIS_AVATAR_MAX_SIZE` | `2048` |
| `MARTIS_AVATAR_PATH` | `'avatars'` |
| `MARTIS_BRAND_DOCS_URL` | `(no default)` |
| `MARTIS_BRAND_ICON` | `(no default)` |
| `MARTIS_BRAND_ICON_DARK` | `(no default)` |
| `MARTIS_BRAND_LOGO` | `(no default)` |
| `MARTIS_BRAND_LOGO_DARK` | `(no default)` |
| `MARTIS_BRAND_LOGO_HEIGHT_AUTH` | `(no default)` |
| `MARTIS_BRAND_LOGO_HEIGHT_MENU` | `(no default)` |
| `MARTIS_BRAND_NAME` | `'Martis'` |
| `MARTIS_BRAND_VERSION` | `(no default)` |
| `MARTIS_CACHE_ADMIN_UI` | `true` |
| `MARTIS_CACHE_DASHBOARDS` | `null` |
| `MARTIS_CACHE_DASHBOARDS_ENABLED` | `true` |
| `MARTIS_CACHE_DASHBOARDS_TTL` | `env('MARTIS_CACHE_DASHBOARDS', null)` |
| `MARTIS_CACHE_ENABLED` | `true` |
| `MARTIS_CACHE_METRICS` | `5` |
| `MARTIS_CACHE_METRICS_ENABLED` | `true` |
| `MARTIS_CACHE_METRICS_TTL` | `env('MARTIS_CACHE_METRICS', 5)` |
| `MARTIS_CACHE_NAVIGATION` | `1` |
| `MARTIS_CACHE_NAVIGATION_ENABLED` | `true` |
| `MARTIS_CACHE_NAVIGATION_TTL` | `env('MARTIS_CACHE_NAVIGATION', 1)` |
| `MARTIS_CACHE_SCHEMA` | `null` |
| `MARTIS_CACHE_SCHEMA_ENABLED` | `true` |
| `MARTIS_CACHE_SCHEMA_TTL` | `env('MARTIS_CACHE_SCHEMA', null)` |
| `MARTIS_CUSTOM_ACCENTS` | `(no default)` |
| `MARTIS_DASHBOARD_SHOW_GREETING` | `true` |
| `MARTIS_DASHBOARD_SHOW_WELCOME` | `true` |
| `MARTIS_DASHBOARD_SHOW_WELCOME_CARD` | `true` |
| `MARTIS_DEFAULT_ACCENT` | `'martis'` |
| `MARTIS_DEFAULT_DENSITY` | `'comfortable'` |
| `MARTIS_DEFAULT_LOCALE` | `'en'` |
| `MARTIS_DEFAULT_ROW_ACTIONS` | `true` |
| `MARTIS_DEFAULT_ROW_ACTION_DELETE` | `true` |
| `MARTIS_DEFAULT_ROW_ACTION_EDIT` | `true` |
| `MARTIS_DEFAULT_ROW_ACTION_VIEW` | `true` |
| `MARTIS_DEFAULT_THEME` | `'dark'` |
| `MARTIS_DEFAULT_TRASHED_FILTER` | `'active'` |
| `MARTIS_DEV_TOOLS` | `in_array(env('APP_ENV', 'production'), ['local', 'testing'], true),` |
| `MARTIS_DRAWER_EXPANDABLE` | `true` |
| `MARTIS_FAVICON` | `null` |
| `MARTIS_FOOTER_TEXT` | `(no default)` |
| `MARTIS_GUARD` | `null` |
| `MARTIS_IMPERSONATION_ENABLED` | `false` |
| `MARTIS_IMPERSONATION_GUARD` | `'web'` |
| `MARTIS_IMPERSONATION_MAX_DURATION` | `0` |
| `MARTIS_IMPERSONATION_POLL_MS` | `120000` |
| `MARTIS_IMPERSONATION_SESSION_KEY` | `'martis.impersonation'` |
| `MARTIS_INDEX_COLUMN_DEFAULTS` | `true` |
| `MARTIS_KEYBOARD_SHORTCUTS_ENABLED` | `true` |
| `MARTIS_KEYBOARD_SHORTCUTS_HELP_OVERLAY` | `true` |
| `MARTIS_LAYOUT` | `'sidebar'` |
| `MARTIS_LOADER_DISABLED` | `false` |
| `MARTIS_LOCALE` | `env('APP_LOCALE', 'en')` |
| `MARTIS_LOCALE_FALLBACK_CHAIN` | `'en'` |
| `MARTIS_LOGIN_THROTTLE_ATTEMPTS` | `20` |
| `MARTIS_LOGIN_THROTTLE_MINUTES` | `1` |
| `MARTIS_NAV_BADGES_POLL_MS` | `300000` |
| `MARTIS_NAV_COUNTS` | `true` |
| `MARTIS_NAV_COUNT_COMPACT_THRESHOLD` | `10000` |
| `MARTIS_NOTIFICATIONS_ENABLED` | `true` |
| `MARTIS_NOTIFICATIONS_MAX_DROPDOWN` | `10` |
| `MARTIS_NOTIFICATIONS_POLL_INTERVAL` | `90000` |
| `MARTIS_PAGE_TITLE` | `(no default)` |
| `MARTIS_PATH` | `'martis'` |
| `MARTIS_PREFERENCES_ENABLED` | `true` |
| `MARTIS_PROFILE_EMAIL_EDITABLE` | `true` |
| `MARTIS_PROFILE_ENABLED` | `true` |
| `MARTIS_ROW_CLICK_OPENS_DETAIL` | `true` |
| `MARTIS_RTL_LOCALES` | `'ar,fa,he,ur'` |
| `MARTIS_SEARCH_DEFAULT_LIMIT` | `5` |
| `MARTIS_SEARCH_MIN_QUERY` | `2` |
| `MARTIS_SEARCH_MOBILE_MODE` | `'icon'` |
| `MARTIS_SEARCH_MODE` | `'bar'` |
| `MARTIS_SHOW_PROFILE_MENU` | `true` |
| `MARTIS_SSO_AZURE_ENABLED` | `false` |
| `MARTIS_SSO_AZURE_LOGOUT_URL` | `(no default)` |
| `MARTIS_SSO_ENABLED` | `false` |
| `MARTIS_STICKY_VIEWS_ENABLED` | `true` |
| `MARTIS_STICKY_VIEWS_SCOPE` | `'session'` |
| `MARTIS_STORAGE_DISK` | `'public'` |
| `MARTIS_THEME` | `'dark'` |
| `MARTIS_THEME_NAME` | `null` |
| `MARTIS_THROTTLE_DECAY` | `1` |
| `MARTIS_THROTTLE_ENABLED` | `true` |
| `MARTIS_THROTTLE_MAX` | `120` |
| `MARTIS_TOAST_POSITION` | `'bottom-right'` |
| `MARTIS_USER_ID_COLUMN_TYPE` | `(no default)` |
| `MARTIS_WELCOME_DESCRIPTION` | `(no default)` |
| `MARTIS_WELCOME_HEADING` | `(no default)` |
## Next Steps

- [Installation Guide](installation-guide.md) — Initial setup
- [Authentication](authentication.md) — Login, 2FA, profile
- [Resources](resources.md) — Resource configuration
- [Override System](overrides.md) — Customize the UI
