# Installation Guide

Add Martis to any existing Laravel application as a Composer package.

## Requirements

| Requirement | Minimum Version |
|-------------|-----------------|
| PHP | 8.3+ |
| Laravel | 11.x, 12.x, or 13.x |
| Node.js | 20+ (contributors only) |
| PNPM | 9+ (or npm/yarn, contributors only) |
| MySQL | 8.0+ |
| Redis | 7+ (optional, for cache/queue) |

The CI matrix runs Pest against PHP 8.3 and 8.4 on Laravel 11, 12, and 13. End-user apps consume the precompiled frontend assets — no Node toolchain required.

## Quick Install

The fastest way to get started is the `martis:install` Artisan command. It handles everything in one step:

```bash
composer require martis/martis
php artisan martis:install
```

This is the complete end-user install flow. The consuming Laravel application does not need to run Vite, install Node dependencies, or build frontend assets for Martis.

This command performs the following steps automatically:

1. **Creates the directory structure** — `app/Martis/` for your resource definitions.
2. **Publishes the config file** — `config/martis.php` with all customizable settings.
3. **Publishes the host MartisServiceProvider** — `app/Providers/MartisServiceProvider.php` and wires it into `bootstrap/providers.php`. This is where consumer code that cannot live in `config/martis.php` (closures, gate definitions, menu items, dashboards, runtime cache layers) is registered. See [Host MartisServiceProvider](#host-martisserviceprovider) below.
4. **Publishes frontend assets** — precompiled React app to `public/vendor/martis/`.
5. **Publishes the core migrations** — `create_martis_action_events_table` and `create_martis_user_preferences_table`.
6. **Publishes translation files** — `en`, `pt_BR`, `pt_PT` to `lang/vendor/martis/`.
7. **Runs database migrations** — creates the `martis_action_events` and `martis_user_preferences` tables.

> **Upgrading from pre-0.7.0**: If you already have an `action_events` table, the new migration detects it and performs an in-place `RENAME` to `martis_action_events`. No data loss. The `martis_` prefix keeps every package-owned table in one namespace so it never collides with an app's own tables.

After installation, create an admin user:

```bash
php artisan martis:user
# Visit http://your-app.test/martis
```

### Install Options

| Flag | Effect |
|------|--------|
| `--force` | Overwrite previously published config, migrations, and translations |
| `--with-profile` | Publish the optional Martis profile migration for avatar + 2FA columns |
| `--with-2fa` | Publish only the 2FA columns migration (subset of `--with-profile`) |
| `--avatar-column=<column>` | Customize which `users` table column Martis should use for avatar paths |
| `--existing-avatar-column` | Use an existing avatar column on `users` instead of publishing a migration |

### Optional Profile Support

By default, `martis:install` always installs the core Martis package, the `martis_action_events` audit log, and the `martis_user_preferences` table (theme/accent/density/locale/reduced-motion persistence). Profile support is optional because some applications already have their own avatar column strategy.

To install Martis with profile support:

```bash
php artisan martis:install --with-profile
```

That flag publishes both granular migration stubs (`add_two_factor_columns` and `add_profile_picture_column`). Each stub is idempotent:

- the avatar column is added only if it does not already exist
- the 2FA columns are added only if they do not already exist
- re-running `martis:install --with-profile` does not create duplicate migration files

If you want only the 2FA columns and not the avatar migration, use `--with-2fa`.

If your application already stores avatar paths in a different `users` column, pass that column name when installing:

```bash
php artisan martis:install --with-profile --avatar-column=avatar_path
```

Then set the same column in your environment:

```env
MARTIS_AVATAR_COLUMN=avatar_path
```

If the column already exists on `users` and you do **not** want a migration:

```bash
php artisan martis:install --with-profile --existing-avatar-column --avatar-column=avatar_path
```

In non-interactive environments such as CI, Docker setup scripts, or deployment hooks, `--no-interaction` skips the optional profile prompt. If you want the profile migration there as well, pass `--with-profile` explicitly:

```bash
php artisan martis:install --force --no-interaction --with-profile
```

## Manual Install (Step by Step)

If you prefer granular control, you can run each publish step individually.

### Step 1: Install via Composer

```bash
composer require martis/martis
```

Martis registers its service provider automatically via Laravel's package discovery.

### Step 2: Publish Configuration

```bash
php artisan vendor:publish --tag=martis-config
```

This creates `config/martis.php`. The most commonly customized keys:

- `path` — Admin panel URL prefix (default: `martis`)
- `guard` — Authentication guard (default: `null`, falls back to `config('auth.defaults.guard')`)
- `resources_path` — Directory scanned for resource classes (default: `app_path('Martis')`)
- `extensions_path` — Folder under `resources/` holding consumer React extensions (default: `martis-extensions`)
- `theme.default` — Default theme: `dark`, `light`, or `system`
- `theme.allowToggle` — Allow users to switch themes from the preferences panel
- `locale` — Default locale (defaults to `config('app.locale')`)
- `brand.name` / `brand.logo` / `brand.icon` / `brand.favicon` — Brand block (see [Configuration](configuration.md))
- `layout.preset` — Layout preset (`sidebar`, `topnav`, `minimal`)
- `footer` / `search` / `auth` / `preferences` / `cache` — Subsystem configuration

> **Locales**: Martis ships translations for `en`, `pt_BR`, `pt_PT`. There is no `available_locales` config key — Laravel's own locale resolution applies, and any extra locales you add to `lang/vendor/martis/<locale>/` are picked up automatically. The picker shown on the user preferences panel is built from `meta.locales` returned by the preferences resolver, which inspects what locales actually have files.

### Step 3: Publish the Host MartisServiceProvider

```bash
php artisan vendor:publish --tag=martis-provider
```

Creates `app/Providers/MartisServiceProvider.php`. The `martis:install` command runs this and wires it into `bootstrap/providers.php` automatically. See [Host MartisServiceProvider](#host-martisserviceprovider) for what to put in it.

### Step 4: Publish Frontend Assets

```bash
php artisan martis:publish-assets
```

This copies the precompiled React application to `public/vendor/martis/`. End users do not need to run Vite in the consuming Laravel app.

The command **wipes `public/vendor/martis/` first** so stale Vite-hashed chunks from previous package versions never accumulate. Laravel's stock `vendor:publish --tag=martis-assets --force` is a merge-style copy and would otherwise pile up tens of thousands of orphan files across upgrades — enough on macOS Docker bind mounts to slow every PHP-FPM request to several seconds. Pass `--no-wipe` to opt back into the legacy merge behaviour if you have a specific reason to.

Equivalent: `php artisan martis:vendor-publish --assets` performs the same wipe-then-publish flow.

Important:

- `composer require`, `composer install`, and `composer update` do **not** automatically refresh files already published into `public/vendor/martis/`
- after installing or upgrading Martis, run `martis:install` or `martis:publish-assets`
- this is standard behavior for Laravel packages that publish static files into the host application

### Step 5: Publish and Run Migrations

The package ships migration stubs across **four** independent tags so consumers can opt in to each subsystem.

| Tag | Publishes |
|---|---|
| `martis-migrations` | `create_martis_action_events_table` (audit log — required) |
| `martis-preferences-migration` | `create_martis_user_preferences_table` (per-user theme / locale / density / accent / reduced-motion) |
| `martis-2fa-migration` | `add_two_factor_columns` to `users` (TOTP secret + recovery codes) |
| `martis-avatar-migration` | `add_profile_picture_column` to `users` (filename of the uploaded avatar) |

Recommended sequence for a manual install:

```bash
php artisan vendor:publish --tag=martis-migrations
php artisan vendor:publish --tag=martis-preferences-migration
php artisan migrate
```

The action-events stub is idempotent — if you already have a legacy `action_events` table, it is renamed in place to `martis_action_events`.

If you also want avatar + 2FA support:

```bash
php artisan vendor:publish --tag=martis-2fa-migration
php artisan vendor:publish --tag=martis-avatar-migration
php artisan migrate
```

The `martis:install` command runs all four behind the scenes when invoked with `--with-profile`.

### Step 6: Publish Translations (Optional)

```bash
php artisan vendor:publish --tag=martis-lang
```

Copies language files to `lang/vendor/martis/` so you can override Martis-shipped strings (auth copy, dashboard greeting, validation messages, sidebar labels, …). Available locales out of the box: `en`, `pt_BR`, `pt_PT`. Laravel deep-merges the published files with the package shipped originals — only override the keys you need.

### Step 7: Publish Views (Optional)

```bash
php artisan vendor:publish --tag=martis-views
```

Copies the Martis blade templates to `resources/views/vendor/martis/`. Rare — only required if you need to fork the SPA shell template itself. The component override system (see [Override System](overrides.md)) is the preferred extension path.

### Step 8: Create Your First Resource

Create a resource file at `app/Martis/UserResource.php`:

```php
<?php

namespace App\Martis;

use App\Models\User;
use Illuminate\Http\Request;
use Martis\Fields\Boolean;
use Martis\Fields\DateTime;
use Martis\Fields\Email;
use Martis\Fields\Id;
use Martis\Fields\Password;
use Martis\Fields\Text;
use Martis\Resource;

class UserResource extends Resource
{
    public static function model(): string
    {
        return User::class;
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function icon(): string
    {
        return 'users';
    }

    public function fields(Request $request): array
    {
        return [
            Id::make('id'),
            Text::make('name')->sortable()->searchable()->required(),
            Email::make('email')->sortable()->searchable()->required()
                ->unique(['users', 'email'], 'Email already exists.'),
            Password::make('password')->hideFromIndex()->hideFromDetail(),
            Boolean::make('is_admin', 'Administrator'),
            DateTime::make('created_at', 'Registered')->hideFromForms()->sortable(),
        ];
    }
}
```

Resources are **auto-discovered** — no manual registration needed. Martis scans `config('martis.resources_path')` (default `app/Martis/`) recursively. Subdirectories (`Resources/`, `Lenses/`, `Filters/`, …) are a convention for large projects — they are NOT created or required by the install. Place each class wherever its namespace puts it.

### Step 9: Access the Admin Panel

Navigate to `http://your-app.test/martis` and log in with any user from your application.

## Host MartisServiceProvider

`martis:install` publishes `app/Providers/MartisServiceProvider.php` and wires it into `bootstrap/providers.php`. This file is where consumer code that **cannot live in `config/martis.php`** is registered:

- The main menu (`Martis::menu(...)`) — closures cannot survive `config:cache`
- Dashboards (`Martis::dashboards([...])`) — same reason
- Tools (`Martis::tools([...])`)
- Cache layer registrations
- Authorization gates (`Gate::define(...)`)
- Custom event listeners

Re-publish the stub manually with:

```bash
php artisan vendor:publish --tag=martis-provider
```

Without this provider, you can still ship a working Martis install relying purely on `config/martis.php` and auto-discovered resources, but every closure-driven feature will be unavailable.

> **`martis:install --force` does not touch this file (v1.10.2+).** The default `--force` flag refreshes the extension scaffold (Vite config, shim files, generator stubs) but never overwrites the host provider, where dashboards, menus, gates, and cache-layer registrations live. To republish the stub on top of your customisations, opt in explicitly with `--force-provider`:
>
> ```bash
> php artisan martis:install --force-provider
> ```
>
> The same split applies to `config/martis.php` (`--force-config`). Each flag is independent, so refreshing the extension scaffold never destroys host-app customisations by accident.

## Custom React extensions (zero-config, v1.9.0+)

Custom React components — Tools, override components, field renderers, custom Cards — register at runtime through the **runtime extension loader** (v1.8.19+) wired up to a **filesystem auto-discovery convention** (v1.9.0+). The dev runs a generator and a build; everything else is plumbing the package handles.

### What `martis:install` publishes

Once you run `php artisan martis:install`, the consumer app gets the entire extension scaffold for free:

```
your-app/
├── vite.extensions.config.ts                  # Vite library mode, react externalised
├── tsconfig.extensions.json                   # TS config for the bundle
├── package.json                               # gains: "build:extensions": "vite build --config vite.extensions.config.ts"
├── .env                                       # gains: MARTIS_EXTENSIONS=/vendor/martis-user/extensions.js
└── resources/js/martis-extensions/
    ├── index.ts                               # auto-discovery entry — picks up everything below
    ├── tools/                                 # martis:tool --with-component drops files here
    ├── fields/                                # martis:field drops files here
    ├── cards/                                 # martis:card drops files here
    └── overrides/                             # martis:component drops files here
```

The published `index.ts` uses `import.meta.glob` to register every `.tsx` under the four buckets against `window.Martis.componentRegistry`. The component key is derived from the filename:

| File path                             | Registered key       |
|---------------------------------------|----------------------|
| `tools/Charts.tsx`                    | `tool:charts`        |
| `tools/SystemHealth.tsx`              | `tool:system-health` |
| `cards/RevenueGauge.tsx`              | `card:revenue-gauge` |
| `fields/PriceTag.tsx` (`Display`/`Input` named exports) | `field:price-tag`    |
| `overrides/Sidebar.tsx`               | `layout:sidebar`     |
| `overrides/LoginPage.tsx`             | `auth:login`         |

The PHP side binds to the same key via `withComponent('tool:charts')` etc., so filename and key stay in lock-step. **No manual `componentRegistry.register(...)` calls** — drop the file in the right bucket, run `npm run build:extensions`, and the component is live.

### Vite + `@vitejs/plugin-react` compatibility (v1.12.1+)

`martis:install` adds the npm devDependencies the extension build needs (`@vitejs/plugin-react`, `vite`, `typescript`, `@types/*`, `@phosphor-icons/react`). The `vite` + `@vitejs/plugin-react` pair is peer-dep-coupled: a new major of `@vitejs/plugin-react` typically requires a new major of `vite`, and a mismatch produces either `npm install` `ERESOLVE` errors or runtime build crashes.

To keep installs safe, the installer reads your host's existing `devDependencies.vite` and picks the matching `@vitejs/plugin-react` range from a known-compat table:

| Host Vite major | `@vitejs/plugin-react` range written |
|-----------------|--------------------------------------|
| `^4`            | `^4`                                 |
| `^5`            | `^4 \|\| ^5`                          |
| `^6`            | `^5`                                  |
| `^7` (Laravel 12 default) | `^5`                        |
| `^8`            | `^6`                                  |
| `^9`            | `^7`                                  |

If your host runs a Vite major that is NOT in the table (e.g. you upgraded ahead of the installed Martis release), the installer **skips** writing `@vitejs/plugin-react` and prints a loud warning with a paste-ready unblock recipe. Existing `@vitejs/plugin-react` entries are never overwritten.

#### Overriding the table

If you know which `@vitejs/plugin-react` release is compatible with your Vite major but Martis does not, set the `MARTIS_PLUGIN_REACT_RANGE` env var before running the installer. The resolver writes the value verbatim and skips the table lookup entirely:

```bash
# Vite 10 + plugin-react 7 (hypothetical example)
MARTIS_PLUGIN_REACT_RANGE='^7' php artisan martis:install
```

The override beats every other code path including the unknown-Vite warning. Mal-formed values are rejected (with a clear message) and the installer falls back to "skip + warn" — it never writes a constraint it cannot parse.

### The five-minute Tool path

```bash
php artisan martis:tool Charts --with-component
npm run build:extensions
```

That's it. The Tool is auto-registered (since v1.8.20), the React component is auto-registered, and the sidebar surfaces it under the default "Tools" header (or whatever you pass to `withMenuSection('Operations')` in the constructor).

### Collision detection

Each generator (`martis:tool`, `martis:field`, `martis:card`, `martis:component`) checks for both the destination PHP file AND the destination TSX file before writing. When either exists, the command lists the conflicting paths and asks `[y/N]` whether to overwrite. `--force` skips the prompt. In a non-interactive shell (e.g. CI) the command aborts with an error code unless `--force` was passed.

### How the registry is exposed

At SPA boot, `app.tsx` writes:

```js
window.Martis = {
  componentRegistry,   // import('@/lib/componentRegistry') equivalent
  react,               // the React module instance bundled with Martis
  version,             // "1.9.0" etc.
  shortcuts,           // global keyboard-shortcut helpers
}
```

A consumer extension reads this global to register components without bundling its own copy of `componentRegistry` or React.

### Configuring multiple bundle URLs

The auto-published `MARTIS_EXTENSIONS` line points at a single bundle (`/vendor/martis-user/extensions.js`). To load additional bundles — e.g. a Composer-distributed package's prebuilt extensions, or a separate dev/staging override — comma-separate them:

```env
MARTIS_EXTENSIONS=/vendor/martis-user/extensions.js,/vendor/another/lib.js
```

The blade view emits the resolved array as `window.MartisConfig.extensions`. The SPA loops over it and dynamic-imports each via `import(url)`. Failures are isolated — one broken extension can't take down the whole panel; the error is logged with the URL.

### Upgrading from v1.8.18 or earlier

If your app shipped a `resources/js/martis/boot.ts` from the legacy build-time mechanism, the file is silently ignored from v1.8.19 onwards. `martis:install` detects it and prints a one-time warning so you know the file is dead. To migrate:

1. Move each `componentRegistry.register('tool:foo', FooTool)` call's component into `resources/js/martis-extensions/tools/Foo.tsx` (filename = key suffix in PascalCase).
2. Drop the `register(...)` call entirely — the auto-discovery entry handles it.
3. Delete `resources/js/martis/boot.ts` and any related Vite alias (`@user/martis/boot`).
4. Run `php artisan martis:install --force` to publish the new scaffold (your existing files in the buckets are not touched).

The package no longer ships the `@user/martis/boot` build-time alias or the `@user` Vite alias resolution — those mechanisms were removed in v1.8.19 because they never actually worked on the published bundle.

## Directory Structure After Installation

```
your-laravel-app/
├── app/
│   ├── Martis/                                       # Your resource definitions (flat by default)
│   │   ├── UserResource.php
│   │   ├── PostResource.php
│   │   └── ...                                       # Subdirectories optional
│   └── Providers/
│       └── MartisServiceProvider.php                 # Host provider (closures, menu, gates)
├── bootstrap/
│   └── providers.php                                 # Auto-wired by martis:install
├── config/
│   └── martis.php                                    # Published configuration
├── database/
│   └── migrations/
│       ├── *_create_martis_action_events_table.php   # Audit log
│       ├── *_create_martis_user_preferences_table.php # Per-user prefs
│       ├── *_add_two_factor_columns.php              # 2FA (with --with-profile / --with-2fa)
│       └── *_add_profile_picture_column.php          # Avatar (with --with-profile)
├── lang/
│   └── vendor/
│       └── martis/                                   # Published translations (martis-lang)
│           ├── en/
│           ├── pt_BR/
│           └── pt_PT/
├── public/
│   └── vendor/
│       └── martis/                                   # Published frontend assets (martis-assets)
└── resources/
    └── js/
        └── martis-extensions/                            # Consumer React extensions (v1.9+)
            ├── index.ts                                  # Auto-discovery entry — ships with martis:install
            ├── .shims/                                   # Vite alias shims (react, runtime, etc.)
            ├── tools/                                    # `martis:tool --with-component` outputs land here
            ├── fields/                                   # `martis:field` outputs
            ├── cards/                                    # `martis:card` outputs
            └── overrides/                                # `martis:component` outputs (shell, sidebar, auth pages, etc.)
```

## Upgrading

When upgrading Martis, always re-publish the frontend assets. Recommended command:

```bash
composer update martis/martis
php artisan martis:install --force
```

Equivalent manual asset-only upgrade:

```bash
composer update martis/martis
php artisan martis:publish-assets
```

Why this second step exists:

- Composer updates package files inside `vendor/martis/martis`
- Martis serves published files from `public/vendor/martis`
- The host app keeps using the old published files until you publish again

Use the asset-only command if you only want to refresh static files. Use the install command with `--force` if you want the full recommended refresh:

```bash
php artisan martis:install --force
```

If your application uses the optional profile migration, re-run the install command with the same profile options after upgrading:

```bash
composer update martis/martis
php artisan martis:install --force --with-profile --avatar-column=avatar_path
```

## Vendor Publish Tags Reference

The package exposes the following `--tag` values for `vendor:publish`:

| Tag | What it publishes | Destination |
|---|---|---|
| `martis-config` | `config/martis.php` (all knobs) | `config/martis.php` |
| `martis-provider` | Host service provider stub | `app/Providers/MartisServiceProvider.php` |
| `martis-assets` | Precompiled React frontend | `public/vendor/martis/` |
| `martis-views` | Blade SPA shell template | `resources/views/vendor/martis/` |
| `martis-lang` | Translation files (en, pt_BR, pt_PT) | `lang/vendor/martis/` |
| `martis-migrations` | Action-events audit log table | `database/migrations/*_create_martis_action_events_table.php` |
| `martis-preferences-migration` | User preferences table | `database/migrations/*_create_martis_user_preferences_table.php` |
| `martis-2fa-migration` | 2FA columns on `users` | `database/migrations/*_add_two_factor_columns.php` |
| `martis-avatar-migration` | Profile picture column on `users` | `database/migrations/*_add_profile_picture_column.php` |

`martis:install` runs the appropriate combination based on its flags. Direct `vendor:publish` calls are for advanced manual workflows.

## Available Artisan Commands

The package ships 28 commands. The full list:

### Setup & maintenance

| Command | Description |
|---|---|
| `martis:install` | Full installation (directories, config, provider, assets, core migrations, translations, auto-migrate) |
| `martis:user` | Create an admin user |
| `martis:vendor-publish` | Wrapper around `vendor:publish` with Martis-aware defaults and prompts |
| `martis:stubs` | List or scaffold the customizable stubs used by the make commands |
| `martis:list-overrides` | Print every component / layout / field override active in the current install |

### Cache control

| Command | Description |
|---|---|
| `martis:cache:status` | Show enabled / disabled state for each Martis cache subsystem |
| `martis:cache:clear` | Flush every Martis cache subsystem |
| `martis:cache:enable` | Enable a Martis cache subsystem at runtime (survives until disabled) |
| `martis:cache:disable` | Disable a Martis cache subsystem at runtime |

### Resource scaffolding

| Command | Description |
|---|---|
| `martis:resource` | Scaffold a new resource class |
| `martis:field` | Scaffold a custom field class |
| `martis:action` | Scaffold an action class |
| `martis:filter` | Scaffold a filter class |
| `martis:lens` | Scaffold a lens class |
| `martis:policy` | Scaffold an authorization policy |
| `martis:tool` | Scaffold a Tool (free-form sidebar page) |
| `martis:roles` | Scaffold the Spatie roles + permissions admin (resources, policies, seeder) |

### Metrics & dashboards

| Command | Description |
|---|---|
| `martis:dashboard` | Scaffold a dashboard class |
| `martis:value` | Scaffold a Value metric |
| `martis:trend` | Scaffold a Trend metric |
| `martis:partition` | Scaffold a Partition metric |
| `martis:progress` | Scaffold a Progress metric |
| `martis:activity-feed` | Scaffold an Activity Feed metric |
| `martis:endpoint-table` | Scaffold an Endpoint Table metric |
| `martis:card` | Scaffold a custom dashboard card |

### Frontend & branding

| Command | Description |
|---|---|
| `martis:component` | Scaffold a React override (TSX) under `resources/js/martis-extensions/overrides/`. Every `--type` auto-registers on the next `npm run build:extensions`. See [Overrides](overrides.md#6-creating-custom-components-artisan) for the filename → key table |
| `martis:theme` | Scaffold a custom theme override |
| `martis:sso` | Scaffold an SSO provider configuration block |

## Next Steps

- [Resources](resources.md) — Learn how to define and configure resources
- [Fields Reference](fields.md) — Explore the 50+ field types Martis ships
- [Override System](overrides.md) — Customize the UI without forking
