<p align="center">
  <img src="resources/images/logo.png" width="120" alt="Martis Logo">
</p>

<h1 align="center">Martis</h1>

<p align="center">
  <strong>The Laravel Admin Engine</strong>
</p>

<p align="center">
  A modern, resource-driven admin panel for Laravel.<br>
  React frontend. Override-first architecture. Full escape hatches.
</p>

<p align="center">
  <a href="#installation">Installation</a> •
  <a href="#quick-start">Quick Start</a> •
  <a href="#features">Features</a> •
  <a href="#configuration">Configuration</a> •
  <a href="#resources">Resources</a> •
  <a href="#fields">Fields</a> •
  <a href="#overrides">Overrides</a> •
  <a href="#testing">Testing</a> •
  <a href="#license">License</a>
</p>

---

## Why Martis?

Existing Laravel admin solutions are powerful but structurally rigid. Deep customization requires workarounds. Swapping a page layout, changing how a field renders, or replacing the entire auth flow shouldn't require fighting the framework.

**Martis takes a different approach.** Everything works out of the box — authentication, CRUD, dashboards, navigation, search — but every layer is designed to be replaced. No hacks required.

| | Martis | Nova | Filament |
|---|---|---|---|
| **Frontend** | React + TypeScript | Vue | Livewire + Alpine |
| **Override depth** | Component, layout, field, page | Limited | Plugin-based |
| **Architecture** | Backend describes, frontend renders | Monolithic | Monolithic |
| **Installation** | Composer package | Licensed package | Composer package |
| **UI Library** | PrimeReact | Custom Vue | Blade + Alpine |

## Requirements

- PHP 8.2+
- Laravel 11.x or 12.x
- Node.js 18+ (for frontend asset compilation)

## Installation

```bash
composer require martis/martis
```

After installing the package, run the install command:

```bash
php artisan martis:install
```

This will:
- Publish the configuration file to `config/martis.php`
- Publish frontend assets to `public/vendor/martis/`
- Create the `app/Martis/` directory for your resources
- Set up routes and middleware

### Private Repository Setup

Add the repository to your `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/Real-Edge-FX/martis-package.git"
        }
    ]
}
```

Authenticate with GitHub:

```bash
composer config github-oauth.github.com YOUR_GITHUB_TOKEN
```

> Generate a Personal Access Token at **GitHub → Settings → Developer settings → Personal access tokens** with `repo` scope.

## Quick Start

### 1. Create an admin user

```bash
php artisan martis:user
```

### 2. Create your first resource

```bash
php artisan martis:resource PostResource
```

This generates `app/Martis/PostResource.php`:

```php
<?php

namespace App\Martis;

use Martis\Resource;
use Martis\Fields\Id;
use Martis\Fields\Text;
use Martis\Fields\DateTime;

class PostResource extends Resource
{
    public static string $model = \App\Models\Post::class;

    public function fields(): array
    {
        return [
            Id::make('ID'),
            Text::make('Title')->sortable()->searchable(),
            Text::make('Slug')->hideFromIndex(),
            DateTime::make('Created At')->sortable(),
        ];
    }
}
```

### 3. Visit the admin panel

Navigate to `http://your-app.test/martis` and log in.

## Features

### Resource-Driven CRUD

Define your admin panel through PHP Resource classes. Each resource maps to an Eloquent model and describes its fields, behavior, and relationships.

```php
class PostResource extends Resource
{
    public static string $model = Post::class;
    public static string $title = 'name';
    public static string $icon = 'file-text';
    public static string $group = 'Content';

    public function fields(): array { /* ... */ }
}
```

### Authentication

Built-in login/logout with configurable guard. Works with Laravel's default authentication out of the box.

### Dashboard

Auto-generated dashboard with resource metrics and quick-access cards. Fully configurable via `config/martis.php`.

### Global Search

Keyboard-shortcut activated (`/`) search across all resources and records. Debounced, grouped results.

### i18n

Ships with English, Brazilian Portuguese, and European Portuguese. Publish and extend:

```bash
php artisan vendor:publish --tag=martis-lang
```

### Dark & Light Themes

Toggle between themes from the user menu. Configurable default via config.

### Multiple Layouts

Choose from `sidebar`, `topnav`, or `minimal` layout presets — or register your own.

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=martis-config
```

Key options in `config/martis.php`:

| Option | Default | Description |
|---|---|---|
| `path` | `martis` | URL path for the admin panel |
| `guard` | `null` | Authentication guard (null = Laravel default) |
| `layout.preset` | `sidebar` | Layout: `sidebar`, `topnav`, `minimal`, `custom` |
| `theme.default` | `dark` | Default theme: `dark` or `light` |
| `locale` | `en-US` | Default locale |
| `search.enabled` | `true` | Enable/disable global search |
| `pagination.default_per_page` | `25` | Default records per page |
| `resources_path` | `app_path('Martis')` | Auto-discovery path for resources |

## Resources

Resources are the core building block. Each resource represents an Eloquent model in your admin panel.

### Resource Methods

```php
class PostResource extends Resource
{
    // Model binding
    public static string $model = Post::class;

    // Display configuration
    public static string $title = 'name';          // Column used as record title
    public static string $icon = 'file-text';       // Phosphor icon name
    public static string $group = 'Content';        // Sidebar group

    // Fields definition
    public function fields(): array { /* ... */ }

    // Index configuration
    public function fieldsForIndex(): array { /* ... */ }
    public function fieldsForDetail(): array { /* ... */ }
    public function fieldsForCreate(): array { /* ... */ }
    public function fieldsForUpdate(): array { /* ... */ }

    // Lifecycle hooks
    public function beforeSave(Model $model, array $data): array { /* ... */ }
    public function afterSave(Model $model, array $data): void { /* ... */ }
    public function beforeDelete(Model $model): void { /* ... */ }
    public function afterDelete(Model $model): void { /* ... */ }

    // Customization
    public function indexSearchable(): bool { return true; }
    public static function subtitle(): ?string { return null; }

    // Custom messages
    public static function singularLabel(): string { /* ... */ }
    public static function pluralLabel(): string { /* ... */ }
}
```

## Fields

Martis ships with a complete set of fields:

| Field | Description | Sortable | Searchable |
|---|---|---|---|
| `Id` | Auto-incrementing ID | Yes | No |
| `Text` | Single-line text input | Yes | Yes |
| `Textarea` | Multi-line text input | No | Yes |
| `Number` | Numeric input with min/max/step | Yes | No |
| `Email` | Email input with validation | Yes | Yes |
| `Password` | Password input (hidden on index/detail) | No | No |
| `Boolean` | Toggle switch | Yes | No |
| `Select` | Dropdown with options | Yes | No |
| `Date` | Date picker | Yes | No |
| `DateTime` | Date and time picker | Yes | No |
| `File` | File upload with drag & drop | No | No |
| `Image` | Image upload with preview | No | No |
| `BelongsTo` | Relationship dropdown with search | Yes | No |
| `Heading` | Section heading (display only) | No | No |
| `Hidden` | Hidden field | No | No |

### Field Methods

All fields support a fluent API:

```php
Text::make('Title')
    ->sortable()                    // Enable column sorting
    ->searchable()                  // Include in search queries
    ->placeholder('Enter title')    // Input placeholder
    ->rules('required', 'max:255')  // Validation rules
    ->hideFromIndex()               // Hide on index page
    ->hideFromDetail()              // Hide on detail page
    ->hideWhenCreating()            // Hide on create form
    ->hideWhenUpdating()            // Hide on update form
    ->readonly()                    // Read-only field
    ->withMeta(['key' => 'value'])  // Custom metadata
    ->resolveUsing(fn ($v) => $v)   // Transform resolved value
    ->displayUsing(fn ($v) => $v)   // Transform displayed value
    ->fillUsing(fn ($r, $m, $a, $an) => /* ... */)  // Custom fill logic
```

### Relationship Fields

```php
BelongsTo::make('Category', 'category', CategoryResource::class)
    ->searchable()
    ->displayAsLink()               // Link to related resource
    ->nullable()                     // Allow null
```

## Overrides

Martis is built for customization. Every component can be replaced.

### PHP-Level Overrides

```php
class PostResource extends Resource
{
    // Replace the index page component
    public function indexComponent(): ?string
    {
        return 'CustomPostIndex';
    }

    // Replace the detail page component
    public function detailComponent(): ?string
    {
        return 'CustomPostDetail';
    }

    // Replace the form component
    public function formComponent(): ?string
    {
        return 'CustomPostForm';
    }

    // Replace the layout for this resource
    public function layoutComponent(): ?string
    {
        return 'CustomLayout';
    }
}
```

### Frontend Registration

```typescript
import { registerComponent } from 'martis/componentRegistry';
import { registerLayout } from 'martis/layoutRegistry';

// Register a custom field component
registerComponent('custom-field', CustomFieldComponent);

// Register a custom layout
registerLayout('custom-layout', CustomLayoutComponent);
```

### Resolution Priority

1. Explicit override on the resource
2. Override by type (via registry)
3. Default Martis component

## Artisan Commands

```bash
php artisan martis:install          # Install Martis in your project
php artisan martis:resource         # Create a new resource class
php artisan martis:field            # Create a custom field class
php artisan martis:user             # Create an admin user
php artisan martis:component        # Scaffold a React component
php artisan martis:theme            # Create a custom theme
php artisan martis:vendor-publish   # Publish vendor assets
```

## Testing

Martis includes comprehensive test suites:

```bash
# PHP tests (Pest)
./vendor/bin/pest

# Frontend tests (Vitest)
npx vitest run

# Static analysis (PHPStan level 8)
./vendor/bin/phpstan analyse

# Code style (Laravel Pint)
./vendor/bin/pint --test
```

## Tech Stack

- **Backend:** PHP 8.2+, Laravel 11/12
- **Frontend:** React 18, TypeScript, Vite
- **UI Components:** PrimeReact
- **Icons:** Phosphor Icons
- **Styling:** Tailwind CSS
- **Tests:** Pest (PHP), Vitest (JS)
- **Analysis:** PHPStan level 8, ESLint

## License

Proprietary. All rights reserved.

Built by [RealEdgeFX](https://realedgefx.com).
