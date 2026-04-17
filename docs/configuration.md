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
],
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `name` | `string` | `'Martis'` | Displayed in the sidebar header and browser title. |
| `logo` | `?string` | `null` | Path to a custom logo image (relative to public/). |
| `favicon` | `?string` | `null` | Path to a custom favicon (relative to `public/`). When `null`, Martis serves its own default favicon from the package — no `vendor:publish` step required. |

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
],
```

| Key | Type | Default | Options |
|-----|------|---------|---------|
| `preset` | `string` | `'sidebar'` | `sidebar`, `topnav`, `minimal`, `custom` |

Layouts can be overridden via the [Layout Registry](overrides.md#layout-overrides).

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
],
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `default_row_actions.enabled` | `bool` | `true` | Master switch for the default row actions column (view/edit/delete). |
| `default_row_actions.view` | `bool` | `true` | Show the view icon. |
| `default_row_actions.edit` | `bool` | `true` | Show the edit icon. |
| `default_row_actions.delete` | `bool` | `true` | Show the delete icon. |
| `row_click_opens_detail` | `bool` | `true` | When default row actions expose a "view" icon, clicking the row body becomes redundant. Set to `false` to disable row-click and keep the row informational. Override per resource with `rowClickOpensDetail(Request $request): ?bool`. |

Override per-resource with the `defaultRowActions(Request $request): bool|array` and `rowClickOpensDetail(Request $request): ?bool` methods. See [Default Row Actions](default_row_actions.md) for the full guide.

## Pagination

```php
'pagination' => [
    'default_per_page' => 25,
    'max_per_page' => 100,
],
```

Override per-resource via `perPage()` and `perPageOptions()` on the resource class.

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

## Environment Variables

| Variable | Config Key | Default |
|----------|-----------|---------|
| `MARTIS_PATH` | `path` | `martis` |
| `MARTIS_GUARD` | `guard` | `null` |
| `MARTIS_BRAND_NAME` | `brand.name` | `Martis` |
| `MARTIS_FAVICON` | `brand.favicon` | `null` |
| `MARTIS_LAYOUT` | `layout.preset` | `sidebar` |
| `MARTIS_LOCALE` | `locale` | `en` |
| `MARTIS_THROTTLE_ENABLED` | `throttle.enabled` | `true` |
| `MARTIS_THROTTLE_MAX` | `throttle.max_attempts` | `120` |
| `MARTIS_THROTTLE_DECAY` | `throttle.decay_minutes` | `1` |
| `MARTIS_THEME` | `theme.default` | `dark` |
| `MARTIS_THEME_NAME` | `theme.name` | `null` |
| `MARTIS_SEARCH_MODE` | `search.mode` | `bar` |
| `MARTIS_TOAST_POSITION` | `toast.position` | `bottom-right` |
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
