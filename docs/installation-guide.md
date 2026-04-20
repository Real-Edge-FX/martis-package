# Installation Guide

Add Martis to any existing Laravel application as a Composer package.

## Requirements

| Requirement | Minimum Version |
|-------------|-----------------|
| PHP | 8.2+ |
| Laravel | 12.x |
| Node.js | 20+ (contributors only) |
| PNPM | 9+ (or npm/yarn, contributors only) |
| MySQL | 8.0+ |
| Redis | 7+ (optional, for cache/queue) |

## Quick Install

The fastest way to get started is the `martis:install` Artisan command. It handles everything in one step:

```bash
composer require martis/martis
php artisan martis:install
```

This is the complete end-user install flow. The consuming Laravel application does not need to run Vite, install Node dependencies, or build frontend assets for Martis.

This command performs the following steps automatically:

1. **Creates the directory structure** — `app/Martis/` with subdirectories for Resources, Fields, Actions, Filters, Lenses, Dashboards, and Metrics.
2. **Publishes the config file** — `config/martis.php` with all customizable settings.
3. **Publishes frontend assets** — precompiled React app to `public/vendor/martis/`.
4. **Publishes database migrations** — `create_martis_action_events_table` and `create_martis_user_preferences_table` migrations to `database/migrations/`.
5. **Publishes translation files** — `en`, `pt_BR`, `pt_PT` to `lang/vendor/martis/`.
6. **Runs database migrations** — creates the `martis_action_events` and `martis_user_preferences` tables automatically.

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
| `--avatar-column=avatar_path` | Customize which users table column Martis should use for avatar paths |

### Optional Profile Support

By default, `martis:install` always installs the core Martis package, its `martis_action_events` audit log migration, and the `martis_user_preferences` table (theme/accent/density/locale/reduced-motion persistence). Profile support is optional because some applications already have their own avatar column strategy.

To install Martis with its profile migration included:

```bash
php artisan martis:install --with-profile
```

That migration is idempotent:

- it adds the avatar column only if it does not already exist
- it adds the 2FA columns only if they do not already exist
- re-running `martis:install --with-profile` does not create duplicate migration files

If your application already stores avatar paths in a different users table column, pass that column name when installing:

```bash
php artisan martis:install --with-profile --avatar-column=avatar_path
```

Then set the same column in your environment:

```bash
MARTIS_AVATAR_COLUMN=avatar_path
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

This creates `config/martis.php` where you can customize:

- `path` — Admin panel URL prefix (default: `/martis`)
- `guard` — Authentication guard (default: `web`)
- `user_model` — User model for auth
- `resources.paths` — Directories to scan for resource classes
- `theme.default` — Default theme (`dark` or `light`)
- `theme.allow_toggle` — Allow users to switch themes
- `locale` — Default locale
- `available_locales` — Supported locales (`en`, `pt_BR`, `pt_PT`)
- `uploads.disk` — File upload disk (default: `public`)
- `uploads.path` — Upload path prefix
- `brand` — Application name shown in sidebar
- `logo` — Custom logo URL
- `layout.preset` — Layout preset (`sidebar`, `topnav`, `minimal`)
- `footer` — Footer configuration
- `search` — Search bar configuration

### Step 3: Publish Frontend Assets

```bash
php artisan vendor:publish --tag=martis-assets --force
```

This copies the precompiled React application to `public/vendor/martis/`. End users do not need to run Vite in the consuming Laravel app.

Important:

- `composer require`, `composer install`, and `composer update` do not automatically refresh files already published into `public/vendor/martis/`
- after installing or upgrading Martis, run `martis:install` or `vendor:publish --tag=martis-assets --force`
- this is standard behavior for Laravel packages that publish static files into the host application

### Step 4: Publish and Run Migrations

```bash
php artisan vendor:publish --tag=martis-migrations
php artisan migrate
```

This publishes the `create_martis_action_events_table` migration and creates the `martis_action_events` table used by the action audit log. The stub is idempotent — if you already have a legacy `action_events` table, it is renamed in place.

If you also want Martis to provision its optional profile migration manually:

```bash
php artisan vendor:publish --tag=martis-profile-migration
php artisan migrate
```

For advanced/manual workflows, the package still exposes the narrower tags below:

```bash
php artisan vendor:publish --tag=martis-2fa-migration
php artisan vendor:publish --tag=martis-avatar-migration
```

### Step 5: Publish Translations (Optional)

```bash
php artisan vendor:publish --tag=martis-translations
```

Copies language files to `lang/vendor/martis/` for customization. Available locales: `en`, `pt_BR`, `pt_PT`.

### Step 6: Create Your First Resource

Create a resource file, e.g. `app/Martis/UserResource.php`:

```php
<?php

namespace App\Martis;

use App\Models\User;
use Illuminate\Http\Request;
use Martis\Fields\Id;
use Martis\Fields\Text;
use Martis\Fields\Email;
use Martis\Fields\Password;
use Martis\Fields\Boolean;
use Martis\Fields\DateTime;
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

Resources are **auto-discovered** — no manual registration needed. Martis scans the paths defined in `config/martis.php`.

### Step 7: Access the Admin Panel

Navigate to `http://your-app.test/martis` and log in with any user from your application.

## Customizing the Boot File

When you create custom components using the `martis:component` artisan command, a `boot.ts` file is generated at `resources/js/martis/boot.ts`. This file imports and registers your custom components into the component registry.

The boot file is automatically loaded by the Martis frontend during initialization. It is created the first time you run:

```bash
php artisan martis:component MyComponent
```

If `boot.ts` already exists, subsequent component commands append new registrations to it.

### Boot File Structure

```typescript
import { componentRegistry } from '@martis/martis/lib/componentRegistry'
import { MyComponent } from './components/MyComponent'

// Auto-registered by martis:component
componentRegistry.register('my-component', MyComponent)
```

### How the Boot File Is Found (Important)

The Martis SPA tries to dynamically import `@user/martis/boot` at startup. `@user` is a Vite alias that resolves to `$MARTIS_USER_DIR` at **build time**, falling back to `martis-package/resources/js/user` when unset.

**Consumer apps using the published (precompiled) assets**: You do **not** need to rebuild the package. The precompiled bundle that `vendor:publish --tag=martis-assets` copies already handles runtime component registration — use `martis:component`, commit the generated `resources/js/martis/boot.ts`, and the next asset publish picks it up. Apps that consume a published npm package (not this repo) follow the same flow.

**Developing custom components against this repo locally (monorepo setup)**: Point `@user` at your app's component folder by running the build with `MARTIS_USER_DIR` set:

```bash
cd vendor/martis/martis-package   # or wherever the package lives
MARTIS_USER_DIR=/absolute/path/to/your-app/resources/js npm run build
```

This only matters for local development of the Martis package itself. End users of a released Martis version never touch the package build — they consume the precompiled assets copied by `vendor:publish --tag=martis-assets`.

## Directory Structure After Installation

```
your-laravel-app/
├── app/
│   └── Martis/                    # Your resource definitions
│       ├── Resources/
│       ├── Fields/
│       ├── Actions/
│       ├── Filters/
│       ├── Lenses/
│       ├── Dashboards/
│       └── Metrics/
├── config/
│   └── martis.php                 # Published configuration
├── database/
│   └── migrations/
│       ├── *_create_martis_action_events_table.php       # Audit log
│       └── *_create_martis_user_preferences_table.php   # Per-user prefs (D2)
├── lang/
│   └── vendor/
│       └── martis/                # Published translations
│           ├── en/
│           ├── pt_BR/
│           └── pt_PT/
├── public/
│   └── vendor/
│       └── martis/                # Published frontend assets
├── resources/
│   └── js/
│       └── martis/                # Custom components (optional)
│           ├── boot.ts            # Component registry (auto-generated)
│           └── components/        # Your custom React components
└── ...
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
php artisan vendor:publish --tag=martis-assets --force
```

Why this second step exists:

- Composer updates package files inside `vendor/martis/martis`
- Martis serves published files from `public/vendor/martis`
- the host app keeps using the old published files until you publish again

Use the asset-only command if you only want to refresh static files. Use the install command with `--force` if you want the full recommended refresh:

```bash
php artisan martis:install --force
```

If your application uses the optional profile migration, re-run the install command with the same profile options after upgrading:

```bash
composer update martis/martis
php artisan martis:install --force --with-profile --avatar-column=avatar_path
```

## Available Artisan Commands

| Command | Description |
|---------|-------------|
| `martis:install` | Full installation (directories, config, assets, core migrations, translations, auto-migrate) |
| `martis:user` | Create an admin user |
| `martis:resource` | Scaffold a new resource class |
| `martis:field` | Scaffold a custom field class |
| `martis:action` | Scaffold an action class |
| `martis:component` | Scaffold a custom React component |
| `martis:policy` | Scaffold an authorization policy |
| `martis:theme` | Scaffold a custom theme |

## Next Steps

- [Resources](resources.md) — Learn how to define and configure resources
- [Fields Reference](fields.md) — Explore all 32 field types
- [Override System](overrides.md) — Customize the UI without forking
- [Quick Start](setup/quickstart.md) — Development workflow
