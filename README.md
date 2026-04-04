# Martis

[![Build](https://img.shields.io/github/actions/workflow/status/Real-Edge-FX/martis/ci.yml?branch=develop)](https://github.com/Real-Edge-FX/martis/actions)
[![License](https://img.shields.io/github/license/Real-Edge-FX/martis)](LICENSE)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://php.net)
[![Laravel 11+](https://img.shields.io/badge/Laravel-11%2B-red)](https://laravel.com)
[![React 19](https://img.shields.io/badge/React-19-61dafb)](https://react.dev)

**Martis** is a modern, open-source admin engine for Laravel — a React-first alternative to Laravel Nova, built on PrimeReact, Tailwind CSS, and Inertia.js.

## Documentation

- [Installation Guide](docs/installation-guide.md) — step-by-step setup for new projects (EN)
- [Tutorial PT-BR](docs/tutorial-pt-br.md) — guia completo em português
- [API Overview](docs/api/overview.md) — REST API reference
- [Override System](docs/overrides.md) — how to customize components without forking
- [Parity Map](docs/PARITY_MAP.md) — Nova v5 feature coverage
- [Fields Reference](docs/fields.md) — complete field type documentation
- [Resources Reference](docs/resources.md) — Resource class methods and hooks

## Requirements

- PHP 8.2+
- Laravel 11+ or 12+
- Node.js 20+
- pnpm 8+

## Installation

```bash
composer require martis/martis
```

Run the install command to publish assets, config, and scaffold the admin panel:

```bash
php artisan martis:install
```

## Features

### Core Engine

- **Resources** — Automatic CRUD from Eloquent models with full lifecycle hooks
- **Context-Aware Fields** — Backend is the single source of truth for field resolution per context (`index`, `detail`, `create`, `update`, `inline-create`, `preview`) with cascading fallback chain
- **Visibility Flags** — `hideFromIndex()`, `hideFromDetail()`, `hideWhenCreating()`, `hideWhenUpdating()`, `onlyOnIndex()`, `onlyOnDetail()`, `onlyOnForms()`, `exceptOnForms()` — all resolved server-side
- **Authorization** — Laravel policies and gates, per-resource and per-action
- **Search** — Global search across resources + records, per-resource search with configurable `indexSearchable()`
- **Authentication** — Laravel auth integration with custom guards
- **Localization** — Full i18n support via Laravel lang files (`pt-BR`, `en`, extensible)
- **Swagger / OpenAPI** — Auto-generated API documentation via Scramble

### Field Types (22 types)

| Field | Description |
|-------|-------------|
| `Id` | Auto-incrementing primary key (hidden on forms) |
| `Text` | Single-line text input with placeholder support |
| `Textarea` | Multi-line text with configurable rows |
| `Number` | Numeric input with min/max/step |
| `Email` | Email input with icon and validation |
| `Password` | Password input with visibility toggle |
| `Boolean` | Toggle switch for boolean values |
| `Select` | Single-select dropdown with options |
| `MultiSelect` | Multi-select with chips display |
| `Date` | Date picker |
| `DateTime` | Date + time picker |
| `File` | File upload with drag & drop, size validation, download link |
| `Image` | Image upload with preview, thumbnail, drag & drop |
| `BelongsTo` | Searchable relationship dropdown with async search, debounce, and `titleAttribute` |
| `Hidden` | Hidden field (rendered in forms but not visible) |
| `Heading` | Section heading / divider (non-data) |
| `Badge` | Colored status badge (index/detail display) |
| `Status` | Status indicator with loading/success/failed states |
| `Tag` | Tag/chip display |
| `KeyValue` | Key-value pair editor |

All fields support: `placeholder()`, `sortable()`, `searchable()`, `required()`, `rules()`, `help()`, `withMeta()`, `displayAsLink()`, customizable visibility per context, and PrimeReact prop passthrough.

### Relationships

- **BelongsTo** — Searchable dropdown with async search, debounce, clear button, `titleAttribute()`, `displayAsLink()`

### Artisan Commands

| Command | Description |
|---------|-------------|
| `martis:install` | Install the Martis admin panel |
| `martis:resource` | Create a new resource class |
| `martis:field` | Create a custom field (PHP + React TSX) |
| `martis:component` | Generate a React component with auto-registration |
| `martis:theme` | Scaffold a custom theme (dark + light mode) |
| `martis:user` | Create a new admin user |
| `martis:vendor-publish` | Publish package files (config, assets, views, lang) |

### UI & Frontend

- **React 19** + **PrimeReact** — modern component library with dark/light theme
- **Tailwind CSS** — utility-first styling with theme variables
- **Phosphor Icons** — consistent iconography across the admin panel
- **Global Search** — searches resources and records (2+ chars, debounce, grouped results)
- **Responsive DataTable** — striped rows, rounded corners, hover effects, configurable per resource
- **Breadcrumbs** — consistent navigation with resource icons
- **Toast Notifications** — success/error/info feedback on all operations
- **Dark / Light Mode** — full theme support with CSS custom properties

### Developer Experience

- **Override System** — customize any React component without forking the package
- **Custom Fields** — `martis:field` scaffolds PHP class + React component with hot reload
- **4-Tier Component Resolution** — project → override → custom → default
- **Stubs** — all generators use customizable stubs
- **PHPStan Level 8** — strict static analysis on the entire codebase
- **Pest + Vitest** — comprehensive PHP and JS test suites

## Configuration

After installation, publish the config file:

```bash
php artisan vendor:publish --tag=martis-config
```

Edit `config/martis.php` to configure your admin path, middleware, branding, and theme options.

## Quick Start

### Defining a Resource

Create a resource class in `app/Martis/`. Martis auto-discovers all classes in that directory:

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
            Text::make('title')
                ->sortable()
                ->searchable()
                ->required()
                ->placeholder('Enter post title'),

            Textarea::make('body')
                ->hideFromIndex()
                ->rows(6),

            BelongsTo::make('category_id', 'Category')
                ->titleAttribute('name')
                ->searchable(),

            DateTime::make('published_at')
                ->sortable()
                ->nullable(),
        ];
    }

    // Optional: override fields for specific contexts
    public function fieldsForIndex(Request $request): array
    {
        return [
            Text::make('title')->sortable(),
            BelongsTo::make('category_id', 'Category')->displayAsLink(),
            DateTime::make('published_at')->sortable(),
        ];
    }
}
```

No manual registration needed — classes in `app/Martis/` are registered automatically on boot.

### Context-Aware Field Resolution

The backend resolves fields per context using this precedence chain:

| Context | Resolution Order |
|---------|-----------------|
| Index | `fieldsForIndex()` → `fields()` |
| Detail | `fieldsForDetail()` → `fields()` |
| Create | `fieldsForCreate()` → `fields()` |
| Update | `fieldsForUpdate()` → `fields()` |
| Inline Create | `fieldsForInlineCreate()` → `fieldsForCreate()` → `fields()` |
| Preview | `fieldsForPreview()` → `fields()` |

The `/schema` endpoint returns pre-filtered arrays (`fieldsForIndex`, `fieldsForDetail`, etc.) — the frontend consumes them directly without additional filtering.

## Development Setup

Clone and bootstrap the local environment:

```bash
git clone https://github.com/Real-Edge-FX/martis.git
cd martis

# Install PHP and JS dependencies
make install

# Start Docker services (MySQL + Redis)
make start

# Configure the playground app
cp playground/.env.example playground/.env
cd playground && php artisan key:generate && php artisan migrate --seed && cd ..

# Build frontend assets
make build
```

The playground admin panel is available at `http://localhost/martis`.
Default credentials: `admin@martis.local` / `password`

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
| API Docs | Scramble (OpenAPI/Swagger) |
| CI | GitHub Actions |

## Contributing

1. Fork the repository
2. Create a feature branch: `git checkout -b feat/my-feature`
3. Write tests for your change
4. Ensure CI passes: `make ci`
5. Open a pull request against `develop`

Code style is enforced via [Laravel Pint](https://laravel.com/docs/pint) (PHP) and [ESLint](https://eslint.org) (TypeScript/React).

## License

MIT — see [LICENSE](LICENSE).
