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

## Customizing the Boot File (React extensions)

When you create custom React components using `martis:component`, a `boot.ts` file is generated at `resources/martis-extensions/martis/boot.ts`. This file imports and registers your custom components into the Martis component registry.

The base directory `resources/martis-extensions` is configurable:

```env
MARTIS_EXTENSIONS_PATH=martis-extensions   # default; relative to resources/
```

Or in `config/martis.php`:

```php
'extensions_path' => env('MARTIS_EXTENSIONS_PATH', 'martis-extensions'),
```

The boot file is auto-loaded by the Martis frontend at startup. It is created the first time you run:

```bash
php artisan martis:component MyComponent
```

If `boot.ts` already exists, subsequent component commands append new registrations to it.

### Boot File Structure

```typescript
import { componentRegistry } from '@/lib/componentRegistry'
import { MyComponent } from './components/MyComponent'

// Auto-registered by martis:component
componentRegistry.register('my-component', MyComponent)
```

The `@/lib/componentRegistry` import resolves through the Vite alias used inside the package build — when the published bundle loads your `boot.ts` at runtime, the `componentRegistry` symbol is already exposed.

### How the Boot File Is Found

The Martis SPA tries to dynamically import `@user/martis/boot` at startup. `@user` is a Vite alias resolved at **build time** in this priority order:

1. **`MARTIS_USER_DIR` env var** — explicit override (CI / Docker).
2. **Auto-discovery**: walks up from the package directory looking for the consumer's Laravel root (presence of an `artisan` file) AND a `resources/martis-extensions/` folder. When the package lives inside `vendor/martis/martis/` of a Laravel app, the walk finds the consumer's extensions folder without any env var.
3. **Fallback**: the package's own empty `resources/js/user/`. Used when the package itself is built standalone (no consumer involved).

**Consumer apps using the published assets**: You do **not** need to rebuild the package. The precompiled bundle that `vendor:publish --tag=martis-assets` copies already handles runtime component registration — use `martis:component`, commit the generated `resources/martis-extensions/martis/boot.ts`, and the next asset publish picks it up.

**Developing custom components against this repo locally (monorepo setup)**: Point `@user` at your app's component folder by running the build with `MARTIS_USER_DIR` set:

```bash
cd vendor/martis/martis-package   # or wherever the package lives
MARTIS_USER_DIR=/absolute/path/to/your-app/resources/martis-extensions npm run build
```

This only matters for local development of the Martis package itself. End users of a released Martis version never touch the package build — they consume the precompiled assets copied by `vendor:publish --tag=martis-assets`.

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
    └── martis-extensions/                            # Consumer React extensions
        └── martis/
            ├── boot.ts                               # Auto-generated by martis:component
            └── components/                           # Your custom React components
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
| `martis:component` | Scaffold a custom React component (TSX) and auto-register it in `boot.ts` |
| `martis:theme` | Scaffold a custom theme override |
| `martis:sso` | Scaffold an SSO provider configuration block |

## Next Steps

- [Resources](resources.md) — Learn how to define and configure resources
- [Fields Reference](fields.md) — Explore the 50+ field types Martis ships
- [Override System](overrides.md) — Customize the UI without forking
