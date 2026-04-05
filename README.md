<p align="center">
  <img src="resources/images/logo.png" alt="Martis" width="400">
</p>

<p align="center">
  <strong>A modern, open-source admin engine for Laravel.</strong><br>
  React-first. Context-aware. Built for developers who ship.
</p>

<p align="center">
  <a href="https://packagist.org/packages/martis/martis"><img src="https://img.shields.io/packagist/v/martis/martis?style=flat-square&label=version" alt="Version"></a>
  <a href="https://packagist.org/packages/martis/martis"><img src="https://img.shields.io/packagist/dt/martis/martis?style=flat-square" alt="Downloads"></a>
  <img src="https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP 8.2+">
  <img src="https://img.shields.io/badge/Laravel-11%2B-FF2D20?style=flat-square&logo=laravel&logoColor=white" alt="Laravel 11+">
  <img src="https://img.shields.io/badge/React-19-61DAFB?style=flat-square&logo=react&logoColor=black" alt="React 19">
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-green?style=flat-square" alt="License"></a>
</p>

---

Martis is a full-featured admin panel engine for Laravel, designed as a React-first alternative to Laravel Nova. It is built on **PrimeReact**, **Tailwind CSS**, and **Inertia.js**, giving you a modern SPA experience with the power and simplicity of Laravel on the backend.

## Installation

```bash
composer require martis/martis
```

```bash
php artisan martis:install
```

The install command publishes assets, configuration, and scaffolds the admin panel in your Laravel application.

## Requirements

| Dependency | Version |
|------------|---------|
| PHP | 8.2+ |
| Laravel | 11+ or 12+ |
| Node.js | 20+ |
| pnpm | 8+ |

## Features

### Resources & CRUD

Automatic CRUD generation from Eloquent models with full lifecycle hooks. Define a resource class, and Martis handles listing, creation, editing, detail views, and deletion.

```php
namespace App\Martis;

use App\Models\Post;
use Illuminate\Http\Request;
use Martis\Fields\Text;
use Martis\Fields\Textarea;
use Martis\Fields\BelongsTo;
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
            Text::make(title)
                ->sortable()
                ->searchable()
                ->required()
                ->placeholder(Enter post title),

            Textarea::make(body)
                ->hideFromIndex()
                ->rows(6),

            BelongsTo::make(category_id, Category)
                ->titleAttribute(name)
                ->searchable(),

            DateTime::make(published_at)
                ->sortable()
                ->nullable(),
        ];
    }
}
```

Classes in `app/Martis/` are auto-discovered — no manual registration required.

### Context-Aware Field Resolution

The backend is the **single source of truth** for which fields appear in each context. Override any context method to customize what the user sees:

| Context | Resolution Order |
|---------|-----------------|
| Index | `fieldsForIndex()` → `fields()` |
| Detail | `fieldsForDetail()` → `fields()` |
| Create | `fieldsForCreate()` → `fields()` |
| Update | `fieldsForUpdate()` → `fields()` |
| Inline Create | `fieldsForInlineCreate()` → `fieldsForCreate()` → `fields()` |
| Preview | `fieldsForPreview()` → `fields()` |

```php
// Show only key columns in the index table
public function fieldsForIndex(Request $request): array
{
    return [
        Text::make(title)->sortable(),
        BelongsTo::make(category_id, Category)->displayAsLink(),
        DateTime::make(published_at)->sortable(),
    ];
}
```

The `/schema` endpoint returns pre-filtered arrays per context — the frontend consumes them directly without additional filtering logic.

### Visibility Flags

Control field visibility per context with fluent methods — all resolved server-side:

```php
Text::make(slug)
    ->hideWhenCreating()   // hidden on create forms
    ->sortable();

DateTime::make(created_at)
    ->exceptOnForms();     // visible on index & detail, hidden on all forms
```

Available flags: `hideFromIndex()`, `hideFromDetail()`, `hideWhenCreating()`, `hideWhenUpdating()`, `onlyOnIndex()`, `onlyOnDetail()`, `onlyOnForms()`, `exceptOnForms()`.

### 27 Built-in Field Types

| Field | Description |
|-------|-------------|
| `Id` | Auto-incrementing primary key (hidden on forms) |
| `Text` | Single-line text input with placeholder support |
| `Textarea` | Multi-line text with configurable rows |
| `Number` | Numeric input with min/max/step |
| `Email` | Email input with icon and validation |
| `Password` | Password input with visibility toggle |
| `Boolean` | Toggle switch for boolean values |
| `Select` | Single-select dropdown |
| `MultiSelect` | Multi-select with chips display |
| `Date` | Date picker |
| `DateTime` | Date + time picker |
| `File` | File upload with drag & drop and download |
| `Image` | Image upload with preview and thumbnail |
| `BelongsTo` | Searchable relationship dropdown with async search |
| `Hidden` | Hidden field (rendered in forms, not visible) |
| `Heading` | Section heading / visual divider |
| `Badge` | Colored status badge for index & detail |
| `Status` | Status indicator (loading / success / failed) |
| `Tag` | Tag and chip display |
| `KeyValue` | Key-value pair editor |
| `Url` | URL field with clickable links and custom display text |
| `Code` | Code editor with syntax highlighting and JSON mode |
| `Color` | Color picker with hex value persistence |
| `Markdown` | Markdown editor with preview, presets, and file uploads |
| `Trix` | Rich-text HTML editor (Trix) with file uploads |

All fields support: `placeholder()`, `sortable()`, `searchable()`, `required()`, `rules()`, `help()`, `withMeta()`, and PrimeReact prop passthrough. `displayAsLink()` is available on `BelongsTo` fields only.

### Authorization

Integrates with Laravel policies and gates. Per-resource and per-action authorization out of the box.

### Search

Global search across resources and records. Per-resource search with configurable `indexSearchable()`. Debounced, grouped results with 2+ character threshold.

### Localization

Full i18n support via Laravel lang files. Ships with `pt-BR` and `en`, extensible to any locale.

### API Documentation (Development)

Auto-generated OpenAPI/Swagger documentation via [Scramble](https://scramble.dedoc.co) (dev dependency). Install it in your project to get `/docs/api`:

```bash
composer require dedoc/scramble --dev
```

## Artisan Commands

| Command | Description |
|---------|-------------|
| `martis:install` | Install the Martis admin panel |
| `martis:resource` | Create a new resource class |
| `martis:field` | Create a custom field (PHP + React TSX) |
| `martis:component` | Generate a React component with auto-registration |
| `martis:theme` | Scaffold a custom theme (dark + light mode) |
| `martis:user` | Create a new admin user |
| `martis:vendor-publish` | Publish package files (config, assets, views, lang) |

## Configuration

```bash
php artisan vendor:publish --tag=martis-config
```

Edit `config/martis.php` to configure:

- Admin panel URL path
- Middleware stack
- Branding (name, logo)
- Theme (dark/light mode defaults)
- Authentication guard

## UI & Frontend

- **React 19** + **PrimeReact** — modern component library with full theme support
- **Tailwind CSS** — utility-first styling with CSS custom properties
- **Phosphor Icons** — consistent iconography
- **Dark / Light Mode** — toggle with persistent user preference
- **Responsive DataTable** — striped rows, rounded corners, hover effects
- **Breadcrumbs** — contextual navigation with resource icons
- **Toast Notifications** — success, error, and info feedback on all operations
- **Global Search** — search resources and records from the top bar

## Extensibility

### Override System

Customize any React component without forking the package. Martis uses a 4-tier component resolution system:

**Project → Override → Custom → Default**

### Custom Fields

Generate a custom field with both PHP and React scaffolding:

```bash
php artisan martis:field MyCustomField
```

This creates the PHP field class and a React component with hot reload support.

## Testing

```bash
make test        # PHP (Pest) + JS (Vitest)
make ci          # Full CI: lint + typecheck + PHPStan + tests
```

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 8.2+, Laravel 11/12 |
| Frontend | React 19, TypeScript, PrimeReact, Tailwind CSS |
| Icons | Phosphor Icons |
| Build | Vite, pnpm |
| Testing | Pest (PHP), Vitest (JS), PHPStan Level 8 |
| API Docs | Scramble (OpenAPI / Swagger, dev dependency) |

## Documentation

- [Installation Guide](docs/installation-guide.md)
- [Tutorial (PT-BR)](docs/tutorial-pt-br.md)
- [API Overview](docs/api/overview.md)
- [Override System](docs/overrides.md)
- [Fields Reference](docs/fields.md)
- [Resources Reference](docs/resources.md)
- [Nova v5 Parity Map](docs/PARITY_MAP.md)

## License

MIT — see [LICENSE](LICENSE).
