<p align="center">
  <img src="resources/images/logo.png" alt="Martis" width="400">
</p>

<p align="center">
  <strong>A modern, open-source admin engine for Laravel.</strong><br>
  React-first. Context-aware. Built for developers who ship.
</p>

<p align="center">
  <a href="https://github.com/Real-Edge-FX/martis/releases"><img src="https://img.shields.io/github/v/tag/Real-Edge-FX/martis?style=flat-square&label=version" alt="Version"></a>
  <img src="https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP 8.2+">
  <img src="https://img.shields.io/badge/Laravel-11%2B-FF2D20?style=flat-square&logo=laravel&logoColor=white" alt="Laravel 11+">
  <img src="https://img.shields.io/badge/React-18-61DAFB?style=flat-square&logo=react&logoColor=black" alt="React 18">
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

### 32 Built-in Field Types

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
| `HasOne` | Inline panel for one-to-one relationships (detail-only, create/edit/delete) |
| `HasMany` | Inline DataTable for one-to-many relationships (detail-only, CRUD) |
| `BelongsToMany` | Many-to-many pivot relationship with attach/detach, pivot fields, and search |
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
| `Trix` | Rich-text HTML editor (Trix) with file uploads, toolbar size config, auto-protocol links |
| `Country` | ISO 3166-1 country select with optional emoji flags (Martis extension) |
| `Currency` | Monetary input with currency formatting, badge/text display modes (Martis extension) |
| `Sparkline` | Inline SVG mini chart (line/bar) for trend visualization |
| `Gravatar` | Avatar from Gravatar service based on email hash |
| `MorphTo` | Polymorphic relationship selector with type + ID resolution |
| `MorphOne` | Inline panel for polymorphic one-to-one relationships (detail-only) |
| `MorphMany` | Inline DataTable for polymorphic one-to-many relationships (detail-only, CRUD) |
| `MorphToMany` | Polymorphic many-to-many with attach/detach, pivot fields, and search |

All fields support: `placeholder()`, `sortable()`, `searchable()`, `required()`, `nullable()`, `readonly()`, `rules()`, `help()`, `default()`, `withMeta()`, and PrimeReact prop passthrough.

#### Trix Configuration

```php
use Martis\Enums\ToolbarSize;
use Martis\Enums\ClickBehavior;

Trix::make('content')
    ->withFiles('public')              // Enable file/image uploads
    ->alwaysShow()                     // Show content on detail without toggle
    ->toolbarSize(ToolbarSize::Small)  // ToolbarSize::Small, ::Medium (default), ::Large
    ->imageClickBehavior(ClickBehavior::Modal)  // ClickBehavior::Modal, ::NewTab, ::SamePage
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
    ->perPageOptions([5, 10, 25, 50])                // Per-page selector options
    ->canCreate(true)                                // Show "Create" button
    ->canUpdate(true)                                // Show edit actions
    ->canDelete(true)                                // Show delete actions
    ->relationSearchable(true)                       // Enable search in inline listing
```

Signature: `HasMany::make(string $name, ?string $relationship = null, ?string $relatedResourceClass = null)`

- `$name` — Display label (also used to infer the Eloquent relationship method)
- `$relationship` — Explicit relationship method name (optional)
- `$relatedResourceClass` — Related resource class for URI key resolution (optional)


#### HasOne Configuration

```php
use Martis\Fields\HasOne;

HasOne::make('Profile')                     // label + relationship inferred
HasOne::make('Profile', 'profile')          // explicit relationship method
HasOne::make('SEO Meta', 'postMeta')        // custom label + relationship
    ->relatedResource('post-metas')         // explicit related resource URI key
    ->canCreate(false)                      // hide 'Create' button when no record exists
    ->canUpdate(false)                      // hide 'Edit' button
    ->canDelete(false)                      // hide 'Delete' button
```

Signature: `HasOne::make(string $name, ?string $relationship = null, ?string $relatedResourceClass = null)`

**HasOne** fields are **detail-only by default** (Nova v5 behavior). When a related record exists, it displays a card showing the related model's fields with optional Edit and Delete actions. When no related record exists, an empty state is shown with an optional Create button. The related resource is managed via a separate REST controller:

- `GET /api/resources/{resource}/{id}/has-one/{relationship}` — fetch related record (or null)
- `POST /api/resources/{resource}/{id}/has-one/{relationship}` — create related record
- `PUT /api/resources/{resource}/{id}/has-one/{relationship}` — update related record
- `DELETE /api/resources/{resource}/{id}/has-one/{relationship}` — delete related record

HasMany fields are **detail-only by default** (Nova v5 behavior). Use `->showOnIndex()` to display a count badge on the index page. The inline DataTable on the detail page supports pagination, search, sorting, and full CRUD of related records within the parent context.


#### BelongsToMany Configuration

```php
use Martis\Enums\ModalSize;

BelongsToMany::make('Tags')
    ->searchable()                                    // Enable search in attach modal
    ->collapsable()                                   // Allow panel collapse on detail
    ->collapsedByDefault()                            // Start collapsed
    ->allowDuplicateRelations()                       // Allow same record attached twice
    ->showCreateRelationButton()                      // Show "+" to create inline
    ->modalSize(ModalSize::TwoExtraLarge)             // Attach modal size
    ->modalSize(ModalSize::Large, '70vh')             // With custom height
    ->relatableQueryUsing(fn($request, $q) => $q->where('active', 1))  // Filter attachable records
    ->dontReorderAttachables()                        // Keep original order
    ->withSubtitles()                                 // Show subtitles in attach search
    ->perPage(10)                                     // Rows per page in inline table
    ->canAttach(true)                                 // Show attach button
    ->canDetach(true)                                 // Show detach button
    ->fields(fn () => [                               // Define pivot fields
        Text::make('notes', 'Notes'),
    ])
    ->actions(fn () => [                              // Define pivot actions
        Action::using('Approve', fn ($fields, $models) => ActionResponse::message('Approved')),
    ])
```

Signature: `BelongsToMany::make(string $name, ?string $relationship = null, ?string $relatedResourceClass = null)`

- `$name` — Display label (relationship method name inferred from label if omitted)
- `$relationship` — Eloquent relationship method name on the parent model
- `$relatedResourceClass` — Related resource class for URI key resolution (optional)

BelongsToMany fields are **detail-only by default** (Nova v5 behavior). On the index page, a count badge is displayed. The detail panel includes a searchable DataTable with pagination, attach/detach buttons, and pivot field columns.


### Enums (Type-Safe Parameters)

All methods that accept a fixed set of values use PHP 8.1+ backed enums instead of strings. This provides IDE autocomplete, type-safety, and prevents invalid values at compile time.

| Enum | Cases | Used in |
|------|-------|---------|
| `ClickBehavior` | Modal, NewTab, SamePage | Trix |
| `ToolbarSize` | Small, Medium, Large | Trix |
| `CodeLanguage` | Php, Javascript, Yaml, + 13 more | Code |
| `MarkdownPreset` | Default, Commonmark, Zero | Markdown |
| `CurrencyCode` | USD, EUR, BRL, + 24 more (ISO 4217) | Currency |
| `CurrencyDisplayMode` | Text, Badge, BadgeText | Currency |
| `ModalSize` | Small, Medium, Large, ... SevenExtraLarge (10 sizes) | Tag |
| `AvatarShape` | Rounded, Squared | Gravatar |
| `ChartType` | Line, Bar | Sparkline |
| `TableSize` | Normal, Small, Large | Resource |
| `ErrorDisplayMode` | Inline, Toast, Both | Resource |
| `HasManyIndexDisplay` | Count | HasMany |
| `HasManyRedirectMode` | Parent, Detail | HasMany |

All enums live in the `Martis\Enums` namespace:

```php
use Martis\Enums\CurrencyCode;
use Martis\Enums\CurrencyDisplayMode;

Currency::make("price")
    ->currency(CurrencyCode::BRL)
    ->displayMode(CurrencyDisplayMode::Badge);
```

### Authorization

Full policy-based authorization with Laravel Nova v5 parity. Define policies per resource to control every operation at the resource, action, relationship, and field level.

#### Policy Resolution

Martis resolves policies using a four-step chain (first match wins):

1. **Explicit `$policy`** — Set `public static ?string $policy` on your Resource class
2. **Auto-discovery** — Looks for `{PolicyNamespace}\{ResourceBaseName}Policy` (configurable via `martis.policy_namespace`)
3. **Laravel Gate** — Falls back to `Gate::getPolicyFor(Model::class)`
4. **Permissive** — If no policy is found, all operations are allowed

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
    ->canSeeWhen('viewSensitiveData', $this->model),
```

#### Authorization Metadata

Every API response includes authorization metadata so the frontend can show/hide UI elements:

- **Per-record:** `_authorization` object with `authorizedToView`, `authorizedToUpdate`, `authorizedToDelete`, `authorizedToReplicate`, etc.
- **Collection-level:** `authorization` object in `/schema` with `authorizedToCreate`, `authorizedToViewAny`

#### ForceDelete & Replicate Endpoints

For resources with soft deletes:
- `DELETE /api/resources/{resource}/{id}/force` — Permanently deletes a soft-deleted record

**Resource Replication (Nova v5 parity):**
- `GET /api/resources/{resource}/{id}/replicate` — Returns pre-filled field values for the create form (File fields excluded)

The frontend "Replicate" button navigates to the create form with `?fromResourceId={id}`, which fetches pre-fill data via GET and lets the user modify before saving. The legacy POST replicate (instant clone) has been removed.

#### Inline Create Endpoints

BelongsTo fields can show a "+" button to create related records inline via modal:
- `GET /api/resources/{resource}/inline-create-schema` — Returns fields for inline create form
- `POST /api/resources/{resource}/inline-create` — Creates record and returns `{id, title}` for immediate selection

Nesting is limited to 1 level (blocked via `X-Martis-Inline-Create-Depth` header).

```php
// Enable inline create on a BelongsTo field
BelongsTo::make('category_id', 'Category')
    ->relatedResource('categories')
    ->showCreateRelationButton()
    ->modalSize('lg')
```

#### Resource Icon & Subtitle in Modal (BUG04)

By default, the inline create modal shows a generic `+` icon. You can configure the modal header to use the related resource's icon:

```php
use Martis\Enums\PhosphorIcon;

BelongsTo::make('author_id', 'Author')
    ->relatedResource('users')
    ->showCreateRelationButton()
    // Show the related resource's icon() in the modal header:
    ->resourceIcon()
    // OR override with a specific Phosphor icon:
    ->resourceIcon(PhosphorIcon::User)
    // Show the related resource's subtitle() in the modal header:
    ->resourceSubtitle()
    // OR use a fixed subtitle string:
    ->resourceSubtitle('Select or create an author')
```

The `PhosphorIcon` enum covers all 1512 icons from `@phosphor-icons/react` v2.

#### Custom Create Button Icon & Color (BUG05)

Customize the inline create `+` button with a different icon or color:

```php
BelongsTo::make('category_id', 'Category')
    ->relatedResource('categories')
    ->showCreateRelationButton()
    ->createButtonIcon(PhosphorIcon::FolderPlus)    // custom icon
    ->createButtonColor('#10B981')                  // hex color
```

Falls back to the default `Plus` icon and primary theme color when not configured.

#### Resource Icon Color

Resources can define a custom icon color returned by the API:

```php
class PostResource extends Resource
{
    public function icon(): string
    {
        return PhosphorIcon::Newspaper->value; // 'newspaper'
    }

    public function iconColor(): ?string
    {
        return '#F59E0B'; // amber — or null to use theme primary
    }
}
```

### Search

#### Global Search (Cmd+K / Ctrl+K) — Nova v5 Parity

Open the global search modal with **Cmd+K** (Mac) or **Ctrl+K** (Windows/Linux). It searches across all registered resources and returns grouped, debounced results with subtitle support.

**Endpoint:** `GET /api/search?q=term`

Results grouped by resource:

```json
{
  "results": [
    {
      "resource": "users",
      "label": "Users",
      "items": [
        { "id": 1, "title": "John Doe", "subtitle": "john@example.com", "url": "/resources/users/1" }
      ]
    }
  ]
}
```

**Opt out a resource from global search:**

```php
public static function globallySearchable(): bool
{
    return false; // excludes this resource from Cmd+K results
}
```

**Add a per-record subtitle:**

```php
use Illuminate\Database\Eloquent\Model;

public function searchSubtitle(Model $model): ?string
{
    return $model->email; // shown below the record title in search results
}
```

**UX features:**
- Arrow keys ↑↓ navigate results; Enter selects; Esc closes
- Results grouped by resource with section labels
- Subtitle shown below each result title (secondary text, muted)
- Loading spinner while fetching
- Minimum 2 characters, 300ms debounce
- Maximum 5 results per resource

#### Index Search

Per-resource search bar within the index listing. Configurable via `indexSearchable()`. Debounced, grouped results with 2+ character threshold.

#### Scout Integration (Nova v5 Parity)

Martis supports [Laravel Scout](https://laravel.com/docs/scout) for full-text search. When a resource's model uses the `Searchable` trait, Martis automatically routes searches through Scout instead of database LIKE queries.

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
public static function scoutQuery(Request $request, mixed $query): mixed
{
    return $query->where('status', 'published');
}
```

**Limit Scout results:**
```php
public static ?int $scoutSearchResults = 50;
```

The `SearchResolver` class centralises the decision between Scout and database search. See `src/SearchResolver.php`.

### Localization

Full i18n support via Laravel lang files. Ships with `pt-BR` and `en`, extensible to any locale.

### API Documentation (Development)

Auto-generated OpenAPI/Swagger documentation via [Scramble](https://scramble.dedoc.co) (dev dependency). Install it in your project to get `/docs/api`:

```bash
composer require dedoc/scramble --dev
```

## Artisan Commands

## Error Handling

### Backend Exceptions

Martis provides a set of typed exceptions for structured error handling. These are automatically converted to JSON API responses when thrown from resources, actions, or controllers.

| Exception | HTTP Status | Use Case |
|-----------|-------------|----------|
| `MartisException` | 500 | Base class for all Martis errors |
| `ValidationException` | 422 | Input validation failures with per-field errors |
| `AuthorizationException` | 403 | Unauthorized access attempts |
| `ResourceNotFoundException` | 404 | Record or resource not found |

```php
use Martis\Exceptions\ValidationException;
use Martis\Exceptions\AuthorizationException;
use Martis\Exceptions\ResourceNotFoundException;

// In an Action — per-field validation errors:
throw ValidationException::fromFieldErrors([
    'title' => ['The title must be at least 5 characters.'],
    'slug'  => ['The slug is already taken.'],
]);

// Single-field shorthand:
throw ValidationException::forField('slug', 'Slug is already taken.', 'unique');

// Authorization:
throw AuthorizationException::forAction('delete', 'post');

// Not found:
throw ResourceNotFoundException::forRecord('posts', $id);
```

### Frontend — React Event Bus

The Martis Event Bus enables decoupled communication between components without prop drilling.

```tsx
import { useEventBus } from '@/lib/useEventBus'

const { on, emit } = useEventBus()

// Subscribe (auto-cleaned up on unmount):
useEffect(() => {
  return on('martis:record-created', ({ resourceKey, id }) => {
    console.log('New record', id, 'in', resourceKey)
  })
}, [on])

// Emit:
emit('martis:record-created', { resourceKey: 'posts', id: 1 })
```

Built-in events: `martis:record-created`, `martis:record-updated`, `martis:record-deleted`, `martis:record-restored`, `martis:action-executed`, `martis:refresh-index`

### Frontend — useError Hook

Centralised error state management for forms and page components.

```tsx
import { useError } from '@/lib/useError'

const { errors, setError, clearErrors, hasErrors } = useError()

try {
  await api.post('/api/posts', data)
} catch (err) {
  setError(err) // Handles ApiError, Error, or string
}

// In JSX:
{errors.message && <p className="text-destructive">{errors.message}</p>}
{errors.fieldErrors.title && <p className="text-destructive">{errors.fieldErrors.title}</p>}
```


| Command | Description |
|---------|-------------|
| `martis:install` | Install the Martis admin panel |
| `martis:resource` | Create a new resource class |
| `martis:field` | Create a custom field (PHP + React TSX) |
| `martis:component` | Generate a React component with auto-registration |
| `martis:theme` | Scaffold a custom theme (dark + light mode) |
| `martis:user` | Create a new admin user |
| `martis:make-policy` | Generate an authorization policy for a resource |
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
- **API Throttle** — configurable rate limiting (enable/disable, max attempts, decay window)

## UI & Frontend

- **React 18** + **PrimeReact** — modern component library with full theme support
- **Tailwind CSS** — utility-first styling with CSS custom properties
- **Phosphor Icons** — consistent iconography
- **Dark / Light Mode** — toggle with persistent user preference
- **Responsive DataTable** — striped rows, rounded corners, hover effects
- **Breadcrumbs** — contextual navigation with resource icons
- **Toast Notifications** — success, error, and info feedback on all operations
- **Loader** — configurable loading indicator with spinner, icon, or logo; overlay mode for table refetch; fully replaceable via component registry
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

### Custom Action Components

Replace the default fields form inside an action modal with a fully custom React component. Works for both **inline actions** (per-row buttons) and **regular/bulk actions** (dropdown menu).

#### Step 1 — Create the React component

```tsx
// packages/martis/resources/js/components/Actions/MyActionComponent.tsx
import { useState } from "react"

interface MyActionComponentProps {
  // Props passed from PHP via ->component()
  title: string
  options: string[]
  // Auto-injected by Martis — call to send field values to the PHP handler
  onFieldsChange?: (fields: Record<string, unknown>) => void
}

export function MyActionComponent({ title, options, onFieldsChange }: MyActionComponentProps) {
  const [selected, setSelected] = useState<string | null>(null)

  function handleSelect(opt: string) {
    setSelected(opt)
    onFieldsChange?.({ selectedOption: opt }) // becomes $fields->selectedOption in PHP
  }

  return (
    <div className="space-y-4">
      <p style={{ color: "var(--martis-text)" }}>{title}</p>
      {options.map((opt) => (
        <button key={opt} type="button" onClick={() => handleSelect(opt)}
          style={{ backgroundColor: selected === opt ? "var(--martis-accent)" : "var(--martis-surface)" }}>
          {opt}
        </button>
      ))}
    </div>
  )
}
```

> Always use CSS theme variables (`var(--martis-text)`, `var(--martis-accent)`, `var(--martis-surface)`, etc.) — never hardcode colors. This ensures compatibility with both light and dark themes.

#### Step 2 — Register the component

In `packages/martis/resources/js/app.tsx`:

```tsx
import { MyActionComponent } from '@/components/Actions/MyActionComponent'

componentRegistry.register('my-action-component', MyActionComponent as never)
```

#### Step 3 — Use the component in a PHP Action

```php
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;

Action::using('My Custom Action', function (ActionFields $fields, Collection $models) {
    // Field values come from onFieldsChange() calls inside the React component
    $option = $fields->selectedOption ?? 'none';
    return ActionResponse::message("Selected: {$option}");
})
    ->component('my-action-component', [
        'title'   => 'Choose an option:',
        'options' => ['A', 'B', 'C'],
    ])
    ->showInline()                    // optional: show as inline per-row button
    ->confirmButtonText('Confirm')
    ->cancelButtonText('Cancel');
```

#### Auto-injected props

| Prop | Type | Description |
|------|------|-------------|
| `onFieldsChange` | `(fields: Record<string, unknown>) => void` | Call to update fields sent to the PHP handler on confirm |
| `onSuccess` | `() => void` | Programmatically close modal and trigger success state |
| `onHide` | `() => void` | Programmatically cancel and close the modal |

#### Working example

See `ActionsDemoResource.php` in the playground for two live examples:
- **Custom Preview (#19):** inline action — `->showInline()->component('demo-custom-action', ...)`
- **Custom Component Demo (#20):** regular bulk action with the same component

### Action Response Types

The `handle()` method returns an `ActionResponse` to control what happens after execution:

| Method | Effect |
|--------|--------|
| `ActionResponse::message('Success!')` | Green success toast |
| `ActionResponse::danger('Error!')` | Red error toast |
| `ActionResponse::redirect('https://...')` | Redirect to external URL |
| `ActionResponse::visit('/path')` | Navigate to internal route |
| `ActionResponse::openInNewTab('https://...')` | Open URL in new tab |
| `ActionResponse::download('file.csv', '/url')` | Trigger file download |
| `ActionResponse::emit('event-name', $data)` | Trigger a client-side event |
| `ActionResponse::modal('ComponentName', $data)` | Open a custom modal component |
| `ActionResponse::openCreate('resource-key')` | Open the create drawer for a resource |
| `ActionResponse::openDetail('resource-key', $id)` | Open the detail drawer for a record |

#### Opening Drawers from Actions

```php
// Open the create drawer for a resource (useful as standalone action)
Action::using('New Post', function (ActionFields $fields, Collection $models) {
    return ActionResponse::openCreate('posts');
})->standalone()->onlyOnIndex();

// Open the detail drawer of the selected record (useful as inline action)
Action::using('View Details', function (ActionFields $fields, Collection $models) {
    $model = $models->first();
    return ActionResponse::openDetail('posts', $model->id);
})->showInline();
```

See **Actions Demo (#21, #22)** in the playground for live examples.

## Testing

```bash
make test        # PHP (Pest) + JS (Vitest)
make ci          # Full CI: lint + typecheck + PHPStan + tests
```

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 8.2+, Laravel 11/12 |
| Frontend | React 18, TypeScript, PrimeReact, Tailwind CSS |
| Icons | Phosphor Icons |
| Build | Vite, pnpm |
| Testing | Pest (PHP), Vitest (JS), PHPStan Level 8 |
| API Docs | Scramble (OpenAPI / Swagger, dev dependency) |

## Documentation

Full documentation lives in the [`docs/`](docs/) directory. Each guide is standalone and cross-linked.

### Getting Started

| Document | Description |
|----------|-------------|
| [Installation Guide](docs/installation-guide.md) | Step-by-step setup: Composer, assets, config, first resource |
| [Quick Start](docs/setup/quickstart.md) | Development workflow, dev server, hot reload, first CRUD |
| [Troubleshooting](docs/setup/troubleshooting.md) | Common issues, error messages, and solutions |

### Core Concepts

| Document | Description |
|----------|-------------|
| [Resources](docs/resources.md) | Resource classes, model binding, lifecycle hooks, authorization, search, pagination, soft deletes |
| [Fields Reference](docs/fields.md) | All 32 field types — configuration, visibility flags, validation, relationships, enums |
| [Relationships](docs/relationships.md) | BelongsTo, HasOne, HasMany, BelongsToMany (pivot fields, attach/detach), MorphTo, MorphOne, MorphMany, MorphToMany |
| [Actions](docs/actions.md) | Complete Actions system — inline, bulk, destructive, queued, pivot, authorization, action fields, audit log |
| [Override System](docs/overrides.md) | 4-tier component resolution: replace any view, field, layout, or drawer without forking |
| [Built-in Components](docs/components.md) | Every UI component shipped in the frontend: DataTable, forms, modals, search, navigation |
| [Authentication](docs/authentication.md) | Login, 2FA, user profile, avatar uploads, user menu configuration |
| [Configuration](docs/configuration.md) | Complete `config/martis.php` reference — every option documented |

### Architecture & API

| Document | Description |
|----------|-------------|
| [Technology Stack](docs/architecture/stack.md) | PHP, Laravel, React, PrimeReact, Tailwind, Vite, testing tools |
| [Architectural Decisions](docs/architecture/decisions.md) | 15 ADRs: why Inertia, why PrimeReact, why contracts, and other design choices |
| [REST API Overview](docs/api/overview.md) | All endpoints, request/response formats, authentication, error handling |

### Project Status

| Document | Description |
|----------|-------------|
| [Nova v5 Parity Map](docs/PARITY_MAP.md) | Feature-by-feature tracker: what is done, in progress, and planned vs Laravel Nova v5 | + Actions System + Martis Differentials
| [Documentation Index](docs/README.md) | Full documentation hub with quick links and project overview |

---

## User Profile

Martis includes a built-in user profile system with a configurable page, avatar support, and two-factor authentication (2FA).

### Profile Page

The profile page is accessible at `/martis/profile` and renders the following sections (all configurable):

- **Account Information** — Edit name and email
- **Change Password** — Update password with confirmation
- **Profile Picture** — Upload, preview, and remove avatar (conditional)
- **Two-Factor Authentication** — Enable/disable 2FA via TOTP wizard (conditional)

### User Menu Integration

A Profile entry is automatically added to the user dropdown menu. The label and icon are configurable via `config/martis.php`.


### Feature Flags

Each profile sub-feature can be toggled independently via `config/martis.php` — see `profile.avatar.enabled`, `profile.two_factor.enabled`, and `profile.sections`.


### Two-Factor Authentication

When enabled, users can set up TOTP-based 2FA via a guided wizard:

1. Scan the QR code with an authenticator app (Google Authenticator, Authy, etc.)
2. Enter the 6-digit verification code
3. Save the recovery codes in a safe place

On subsequent logins, users with 2FA enabled are redirected to a challenge screen before accessing the dashboard.

### API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/martis/api/profile` | Get current user profile data |
| `PATCH` | `/martis/api/profile` | Update name and email |
| `POST` | `/martis/api/profile/password` | Change password |
| `POST` | `/martis/api/profile/avatar` | Upload avatar (multipart/form-data) |
| `DELETE` | `/martis/api/profile/avatar` | Remove avatar |
| `POST` | `/martis/api/profile/2fa/setup` | Initialize 2FA setup (returns QR code SVG + secret) |
| `POST` | `/martis/api/profile/2fa/confirm` | Confirm OTP and get recovery codes |
| `DELETE` | `/martis/api/profile/2fa` | Disable 2FA |
| `POST` | `/martis/api/2fa/challenge` | Submit 2FA challenge during login |

### i18n

All profile text is fully translatable via the `martis::profile` namespace (EN, PT-BR, PT-PT included).


## UI Standards

### Tooltip Standard (PrimeReact)

All tooltips in Martis **must** use [`primereact/tooltip`](https://primereact.org/tooltip/). Native HTML `title=` attributes and custom tooltip implementations are **prohibited**.

#### Global provider

A global Tooltip provider is registered in `Layout.tsx` targeting `[data-pr-tooltip]`:

```tsx
import { Tooltip } from 'primereact/tooltip';
// Inside Layout render:
<Tooltip target="[data-pr-tooltip]" showDelay={400} />
```

This means any element with `data-pr-tooltip` will automatically have a tooltip — no need to add a per-component `<Tooltip>` instance.

#### Simple tooltip (recommended)

```tsx
<button
  data-pr-tooltip="Delete this record"
  data-pr-position="top"
>
  <Trash size={16} />
</button>
```

#### Tooltip on a specific element (ref-based)

Use this when you need per-element configuration (custom delay, HTML content, etc.):

```tsx
import { Tooltip } from 'primereact/tooltip';
import { useRef } from 'react';

const btnRef = useRef(null);

<button ref={btnRef}>Save</button>
<Tooltip target={btnRef} content="Save record" position="top" />
```

#### Rules

| Rule | Detail |
|------|--------|
| ❌ Never use `title=` | Native browser tooltips are inconsistent across themes |
| ❌ Never build custom tooltip divs | Breaks dark/light mode consistency |
| ✅ Always use `data-pr-tooltip` for simple text | Covered by global provider |
| ✅ Use ref-based `<Tooltip>` for complex/HTML tooltips | Full PrimeReact API available |
| ✅ Use `data-pr-position` to control placement | `"top"` \| `"bottom"` \| `"left"` \| `"right"` |

---

## License

MIT — see [LICENSE](LICENSE).
