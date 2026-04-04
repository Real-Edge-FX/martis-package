# Installation Guide

## Requirements

| Dependency | Version |
|------------|---------|
| PHP        | 8.2+    |
| Laravel    | 11+ or 12+ |
| Node.js    | 20+     |
| pnpm       | 8+      |

## Step 1 — Install via Composer

```bash
composer require martis/martis
```

## Step 2 — Run the Installer

```bash
php artisan martis:install
```

This command:

- Publishes the Martis configuration file to `config/martis.php`
- Publishes frontend assets
- Scaffolds the admin panel in your Laravel application

## Step 3 — Create an Admin User

```bash
php artisan martis:user
```

Follow the prompts to set the name, email, and password.

## Step 4 — Access the Panel

Start your Laravel development server and navigate to:

```
http://localhost:8000/martis
```

The default path is `/martis`. You can change it in `config/martis.php`:

```php
'path' => 'admin',  // now accessible at /admin
```

## Configuration

Publish the config file if you haven't already:

```bash
php artisan vendor:publish --tag=martis-config
```

Key configuration options in `config/martis.php`:

| Option | Default | Description |
|--------|---------|-------------|
| `path` | `martis` | URL prefix for the admin panel |
| `guard` | `null` | Authentication guard (null = Laravel default) |
| `middleware` | `['web']` | Base middleware for all routes |
| `brand.name` | `Martis` | Panel brand name |
| `brand.logo` | `null` | Custom logo path |
| `locale` | `en-US` | Default locale (`en-US`, `pt-BR`) |
| `theme.default` | `dark` | Default theme (`dark` or `light`) |
| `theme.allowToggle` | `true` | Show theme toggle in user menu |
| `layout.preset` | `sidebar` | Layout preset (`sidebar`, `topnav`, `minimal`, `custom`) |
| `pagination.default_per_page` | `25` | Default items per page |
| `storage.disk` | `public` | Filesystem disk for uploads |
| `resources_path` | `app_path('Martis')` | Auto-discovery path for resource classes |

## Creating Your First Resource

```bash
php artisan martis:resource PostResource
```

This generates `app/Martis/PostResource.php`. Classes in `app/Martis/` are auto-discovered — no manual registration required.

```php
namespace App\Martis;

use App\Models\Post;
use Illuminate\Http\Request;
use Martis\Fields\Text;
use Martis\Fields\Textarea;
use Martis\Fields\DateTime;
use Martis\Resource;

class PostResource extends Resource
{
    public static function model(): string
    {
        return Post::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->sortable()->searchable()->required(),
            Textarea::make('body')->hideFromIndex(),
            DateTime::make('created_at')->sortable(),
        ];
    }
}
```

## Next Steps

- [Fields Reference](fields.md) — all 27 built-in field types
- [Resources Reference](resources.md) — resource configuration and lifecycle hooks
- [Override System](overrides.md) — customize components without forking
- [Tutorial (PT-BR)](tutorial-pt-br.md) — step-by-step tutorial in Portuguese
