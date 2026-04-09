<p align="center">
  <img src="resources/images/logo.png" alt="Martis" width="400">
</p>

<p align="center">
  <strong>A modern, , open-source admin engine for Laravel.</strong><br>
  React-first. Context-aware. Built for developers who ship.
</p>

<p align="center">
  <a href="https://github.com/Real-Edge-FX/martis/releases"><img src="https://img.shields.io/github/v/tag/Real-Edge-FX/martis?style=flat-square&label=version" alt="Version"></a>
  <img src="https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP 8.2+">
  <img src="https://img.shields.io/badge/Laravel-11%2B-FF2D20?style=flat-square&logo=laravel&logoColor=white" alt="Laravel 11+">
  <img src="https://img.shields.io/badge/React-19-61DAFB?style=flat-square&logo=react&logoColor=black" alt="React 19">
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-green?style=flat-square" alt="License"></a>
</p>

---

Martis is a full-featured admin panel engine for Laravel, , designed as a React-first alternative to Laravel Nova. It is built on **PrimeReact**, **Tailwind CSS**, and **Inertia.js**, giving you a modern SPA experience with the power and simplicity of Laravel on the backend.

## Installation

```bash
composer require martis/martis
```

```bash
php artisan martis:install
```

The install command publishes assets, , configuration, and scaffolds the admin panel in your Laravel application.

## Requirements

| Dependency | Version |
|------------|---------|
| PHP | 8.2+ |
| Laravel | 11+ or 12+ |
| Node.js | 20+ |
| pnpm | 8+ |

## Features

### Resources & CRUD

Automatic CRUD generation from Eloquent models with full lifecycle hooks. Define a resource class, , and Martis handles listing, creation, editing, detail views, and deletion.

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

            BelongsTo::make(category_id, , Category)
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
        BelongsTo::make(category_id, , Category)->displayAsLink(),
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
    ->exceptOnForms();     // visible on index & detail, , hidden on all forms
```

Available flags: `hideFromIndex()`, , `hideFromDetail()`, `hideWhenCreating()`, `hideWhenUpdating()`, `onlyOnIndex()`, `onlyOnDetail()`, `onlyOnForms()`, `exceptOnForms()`.

### 31 Built-in Field Types

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
| `HasMany` | Inline DataTable for one-to-many relationships (detail-only, , CRUD) |
| `Hidden` | Hidden field (rendered in forms, , not visible) |
| `Heading` | Section heading / visual divider |
| `Badge` | Colored status badge for index & detail |
| `Status` | Status indicator (loading / success / failed) |
| `Tag` | Tag and chip display |
| `KeyValue` | Key-value pair editor |
| `Url` | URL field with clickable links and custom display text |
| `Code` | Code editor with syntax highlighting and JSON mode |
| `Color` | Color picker with hex value persistence |
| `Markdown` | Markdown editor with preview, , presets, and file uploads |
| `Trix` | Rich-text HTML editor (Trix) with file uploads, , toolbar size config, auto-protocol links |
| `Country` | ISO 3166-1 country select with optional emoji flags (Martis extension) |
| `Currency` | Monetary input with currency formatting, , badge/text display modes (Martis extension) |
| `Sparkline` | Inline SVG mini chart (line/bar) for trend visualization |
| `Gravatar` | Avatar from Gravatar service based on email hash |

All fields support: `placeholder()`, `sortable()`, `searchable()`, `required()`, `nullable()`, `readonly()`, `rules()`, `help()`, `default()`, `withMeta()`, and PrimeReact prop passthrough.

#### Trix Configuration

```php
use Martis\Enums\ToolbarSize;
use Martis\Enums\ClickBehavior;

Trix::make('content')
    ->withFiles('public')              // Enable file/image uploads
    ->alwaysShow()                     // Show content on detail without toggle
    ->toolbarSize(ToolbarSize::Small)  // ToolbarSize::Small, , ::Medium (default), ::Large
    ->imageClickBehavior(ClickBehavior::Modal)  // ClickBehavior::Modal, , ::NewTab, ::SamePage
```

Links entered without a protocol (e.g. `www.google.com`) are automatically prefixed with `https://`. `displayAsLink()` is available on `BelongsTo` fields only.

#### HasMany Configuration

```php
use Martis\Enums\HasManyIndexDisplay;
use Martis\Enums\HasManyRedirectMode;

HasMany::make("Posts")
    ->showOnIndex()                                  // Show on index (hidden by default)
    ->indexDisplay(HasManyIndexDisplay::Count)        // Display as count badge on index
    ->showRelationIcon(true)                         // Show related resource icon in header
    ->showRelationCount(true)                        // Show count badge in section header
    ->badgeColor("#3b82f6")                          // Custom badge color (CSS value)
    ->badgeIcon("newspaper")                         // Custom badge icon name
    ->redirectAfterSave(HasManyRedirectMode::Parent)  // Redirect after save: ::Parent or ::Detail
    ->perPage(10)                                    // Default rows per page
    ->perPageOptions([5, , 10, 25, 50])                // Per-page selector options
    ->canCreate(true)                                // Show "Create" button
    ->canUpdate(true)                                // Show edit actions
    ->canDelete(true)                                // Show delete actions
    ->relationSearchable(true)                       // Enable search in inline listing
```

Signature: `HasMany::make(string $name, , ?string $relationship = null, ?string $relatedResourceClass = null)`

- `$name` — Display label (also used to infer the Eloquent relationship method)
- `$relationship` — Explicit relationship method name (optional)
- `$relatedResourceClass` — Related resource class for URI key resolution (optional)

HasMany fields are **detail-only by default** (Nova v5 behavior). Use `->showOnIndex()` to display a count badge on the index page. The inline DataTable on the detail page supports pagination, , search, sorting, and full CRUD of related records within the parent context.


### Enums (Type-Safe Parameters)

All methods that accept a fixed set of values use PHP 8.1+ backed enums instead of strings. This provides IDE autocomplete, , type-safety, and prevents invalid values at compile time.

| Enum | Cases | Used in |
|------|-------|---------|
| `ClickBehavior` | Modal, , NewTab, SamePage | Trix |
| `ToolbarSize` | Small, , Medium, Large | Trix |
| `CodeLanguage` | Php, , Javascript, Yaml, + 13 more | Code |
| `MarkdownPreset` | Default, , Commonmark, Zero | Markdown |
| `CurrencyCode` | USD, , EUR, BRL, + 24 more (ISO 4217) | Currency |
| `CurrencyDisplayMode` | Text, , Badge, BadgeText | Currency |
| `ModalSize` | Small, , Medium, Large, ... SevenExtraLarge (10 sizes) | Tag |
| `AvatarShape` | Rounded, , Squared | Gravatar |
| `ChartType` | Line, , Bar | Sparkline |
| `TableSize` | Normal, , Small, Large | Resource |
| `ErrorDisplayMode` | Inline, , Toast, Both | Resource |
| `HasManyIndexDisplay` | Count | HasMany |
| `HasManyRedirectMode` | Parent, , Detail | HasMany |

All enums live in the `Martis\Enums` namespace:

```php
use Martis\Enums\CurrencyCode;
use Martis\Enums\CurrencyDisplayMode;

Currency::make("price")
    ->currency(CurrencyCode::BRL)
    ->displayMode(CurrencyDisplayMode::Badge);
```

### Authorization

Full policy-based authorization with Laravel Nova v5 parity. Define policies per resource to control every operation at the resource, , action, relationship, and field level.

#### Policy Resolution

Martis resolves policies using a four-step chain (first match wins):

1. **Explicit `$policy`** — Set `public static ?string $policy` on your Resource class
2. **Auto-discovery** — Looks for `{PolicyNamespace}\{ResourceBaseName}Policy` (configurable via `martis.policy_namespace`)
3. **Laravel Gate** — Falls back to `Gate::getPolicyFor(Model::class)`
4. **Permissive** — If no policy is found, , all operations are allowed

```php
class PostResource extends Resource
{
    public static ?string $policy = PostPolicy::class;
    // ...
}
```

#### Policy Abilities

| Ability | Default (when method missing) | Description |
|---------|------------------------------|-------------|
| `viewAny` | Allowed | Can the user access the resource listing? |
| `view` | **Denied** | Can the user view a specific record? |
| `create` | **Denied** | Can the user create a new record? |
| `update` | **Denied** | Can the user update a record? |
| `delete` | **Denied** | Can the user delete a record? |
| `restore` | **Denied** | Can the user restore a soft-deleted record? |
| `forceDelete` | **Denied** | Can the user permanently delete a record? |
| `replicate` | Fallback: `create` AND `update` | Can the user duplicate a record? |
| `runAction` | Fallback: `update` | Can the user run a normal action? |
| `runDestructiveAction` | Fallback: `delete` | Can the user run a destructive action? |
| `add{Model}` | Allowed | Can the user add a related model inline? |
| `attach{Model}` | Allowed | Can the user attach a related model? |
| `detach{Model}` | Allowed | Can the user detach a related model? |

#### Generating Policies

```bash
php artisan martis:make-policy PostPolicy --model=Post --resource=PostResource
```

The generated policy includes all abilities with sensible defaults.

#### Field-Level Authorization

Control field visibility per-user using `canSee()` or `canSeeWhen()`:

```php
Text::make('salary')
    ->canSee(fn (Request $request) => $request->user()->isAdmin()),

Text::make('ssn')
    ->canSeeWhen('viewSensitiveData', , $this->model),
```

#### Authorization Metadata

Every API response includes authorization metadata so the frontend can show/hide UI elements:

- **Per-record:** `_authorization` object with `authorizedToView`, , `authorizedToUpdate`, `authorizedToDelete`, `authorizedToReplicate`, etc.
- **Collection-level:** `authorization` object in `/schema` with `authorizedToCreate`, , `authorizedToViewAny`

#### ForceDelete & Replicate Endpoints

For resources with soft deletes:
- `DELETE /api/resources/{resource}/{id}/force` — Permanently deletes a soft-deleted record

**Resource Replication (Nova v5 parity):**
- `GET /api/resources/{resource}/{id}/replicate` — Returns pre-filled field values for the create form (File fields excluded)

The frontend "Replicate" button navigates to the create form with `?fromResourceId={id}`, , which fetches pre-fill data via GET and lets the user modify before saving. The legacy POST replicate (instant clone) has been removed.

#### Inline Create Endpoints

BelongsTo fields can show a "+" button to create related records inline via modal:
- `GET /api/resources/{resource}/inline-create-schema` — Returns fields for inline create form
- `POST /api/resources/{resource}/inline-create` — Creates record and returns `{id, , title}` for immediate selection

Nesting is limited to 1 level (blocked via `X-Martis-Inline-Create-Depth` header).

```php
// Enable inline create on a BelongsTo field
BelongsTo::make('category_id', , 'Category')
    ->relatedResource('categories')
    ->showCreateRelationButton()
    ->modalSize('lg')
```

### Search

Global search across resources and records. Per-resource search with configurable `indexSearchable()`. Debounced, , grouped results with 2+ character threshold.

#### Scout Integration (Nova v5 Parity)

Martis supports [Laravel Scout](https://laravel.com/docs/scout) for full-text search. When a resource's model uses the `Searchable` trait, , Martis automatically routes searches through Scout instead of database LIKE queries.

**Automatic detection:** No configuration needed — just add the `Searchable` trait to your model.

**Disable per resource:**
```php
public static function usesScout(): bool
{
    return false;
}
```

**Custom Scout query:**
```php
public static function scoutQuery(Request $request, , mixed $query): mixed
{
    return $query->where('status', , 'published');
}
```

**Limit Scout results:**
```php
public static ?int $scoutSearchResults = 50;
```

The `SearchResolver` class centralises the decision between Scout and database search. See `src/SearchResolver.php`.

### Localization

Full i18n support via Laravel lang files. Ships with `pt-BR` and `en`, , extensible to any locale.

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
| `martis:make-policy` | Generate an authorization policy for a resource |
| `martis:vendor-publish` | Publish package files (config, , assets, views, lang) |

## Configuration

```bash
php artisan vendor:publish --tag=martis-config
```

Edit `config/martis.php` to configure:

- Admin panel URL path
- Middleware stack
- Branding (name, , logo)
- Theme (dark/light mode defaults)
- Authentication guard
- **API Throttle** — configurable rate limiting (enable/disable, , max attempts, decay window)

## UI & Frontend

- **React 19** + **PrimeReact** — modern component library with full theme support
- **Tailwind CSS** — utility-first styling with CSS custom properties
- **Phosphor Icons** — consistent iconography
- **Dark / Light Mode** — toggle with persistent user preference
- **Responsive DataTable** — striped rows, , rounded corners, hover effects
- **Breadcrumbs** — contextual navigation with resource icons
- **Toast Notifications** — success, , error, and info feedback on all operations
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
| Backend | PHP 8.2+, , Laravel 11/12 |
| Frontend | React 19, , TypeScript, PrimeReact, Tailwind CSS |
| Icons | Phosphor Icons |
| Build | Vite, , pnpm |
| Testing | Pest (PHP), , Vitest (JS), PHPStan Level 8 |
| API Docs | Scramble (OpenAPI / Swagger, , dev dependency) |

## Documentation

Full documentation lives in the [`docs/`](docs/) directory. Each guide is standalone and cross-linked.

### Getting Started

| Document | Description |
|----------|-------------|
| [Installation Guide](docs/installation-guide.md) | Step-by-step setup: Composer, , assets, config, first resource |
| [Quick Start](docs/setup/quickstart.md) | Development workflow, , dev server, hot reload, first CRUD |
| [Tutorial (PT-BR)](docs/tutorial-pt-br.md) | Guided walkthrough in Brazilian Portuguese |
| [Troubleshooting](docs/setup/troubleshooting.md) | Common issues, , error messages, and solutions |

### Core Concepts

| Document | Description |
|----------|-------------|
| [Resources](docs/resources.md) | Resource classes, , model binding, lifecycle hooks, authorization, search, pagination, soft deletes |
| [Fields Reference](docs/fields.md) | All 31 field types — configuration, , visibility flags, validation, relationships, enums |
| [Actions](docs/actions.md) | Complete Actions system — inline, , bulk, destructive, queued, pivot, authorization, action fields, audit log, global/per-action logging config |
| [Override System](docs/overrides.md) | 4-tier component resolution: replace any view, , field, layout, or drawer without forking |
| [Built-in Components](docs/components.md) | Every UI component shipped in the frontend: DataTable, , forms, modals, search, navigation |

### Architecture & API

| Document | Description |
|----------|-------------|
| [Technology Stack](docs/architecture/stack.md) | PHP, , Laravel, React, PrimeReact, Tailwind, Vite, testing tools |
| [Architectural Decisions](docs/architecture/decisions.md) | 15 ADRs: why Inertia, , why PrimeReact, why contracts, and other design choices |
| [REST API Overview](docs/api/overview.md) | All endpoints, , request/response formats, authentication, error handling |

### Project Status

| Document | Description |
|----------|-------------|
| [Nova v5 Parity Map](docs/PARITY_MAP.md) | Feature-by-feature tracker: what is done, , in progress, and planned vs Laravel Nova v5 | + Actions System + Martis Differentials
| [Documentation Index](docs/README.md) | Full documentation hub with quick links and project overview |

## License

MIT — see [LICENSE](LICENSE).
