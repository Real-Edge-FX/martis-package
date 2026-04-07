# Installation Guide

Add Martis to any existing Laravel application as a Composer package.

## Requirements

| Requirement | Minimum Version |
|-------------|-----------------|
| PHP | 8.2+ |
| Laravel | 12.x |
| Node.js | 20+ |
| PNPM | 9+ (or npm/yarn) |
| MySQL | 8.0+ |
| Redis | 7+ (optional, for cache/queue) |

## Step 1: Install via Composer

```bash
composer require martis/martis
```

Martis registers its service provider automatically via Laravel's package discovery.

## Step 2: Publish Configuration

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
- `available_locales` — Supported locales (`en`, `pt-BR`, `pt-PT`)
- `uploads.disk` — File upload disk (default: `public`)
- `uploads.path` — Upload path prefix
- `brand` — Application name shown in sidebar
- `logo` — Custom logo URL
- `layout.preset` — Layout preset (`sidebar`, `topnav`, `minimal`)
- `footer` — Footer configuration
- `search` — Search bar configuration

## Step 3: Publish Frontend Assets

```bash
php artisan vendor:publish --tag=martis-assets --force
```

This copies the compiled React application to `public/vendor/martis/`.

## Step 4: Publish Translations (Optional)

```bash
php artisan vendor:publish --tag=martis-translations
```

Copies language files to `lang/vendor/martis/` for customization. Available locales: `en`, `pt-BR`, `pt-PT`.

## Step 5: Create Your First Resource

Create the resources directory:

```bash
mkdir -p app/Martis
```

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

## Step 6: Access the Admin Panel

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

## Directory Structure After Installation

```
your-laravel-app/
├── app/
│   └── Martis/                    # Your resource definitions
│       ├── UserResource.php
│       ├── PostResource.php
│       └── ...
├── config/
│   └── martis.php                 # Published configuration
├── lang/
│   └── vendor/
│       └── martis/                # Published translations (optional)
│           ├── en/
│           ├── pt-BR/
│           └── pt-PT/
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

When upgrading Martis, always re-publish the frontend assets:

```bash
composer update martis/martis
php artisan vendor:publish --tag=martis-assets --force
```

## Next Steps

- [Resources](resources.md) — Learn how to define and configure resources
- [Fields Reference](fields.md) — Explore all 31 field types
- [Override System](overrides.md) — Customize the UI without forking
- [Quick Start](setup/quickstart.md) — Development workflow
