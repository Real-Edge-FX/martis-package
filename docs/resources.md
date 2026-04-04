# Resources Reference

A Resource is the central abstraction in Martis. It wraps an Eloquent model and declares its fields, authorization rules, display metadata, and lifecycle hooks.

## Creating a Resource

```bash
php artisan martis:resource PostResource
```

This creates `app/Martis/PostResource.php`. All classes in the configured `resources_path` (default: `app/Martis/`) are auto-discovered.

## Minimal Example

```php
namespace App\Martis;

use App\Models\Post;
use Illuminate\Http\Request;
use Martis\Fields\Text;
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
            Text::make('title')->sortable()->searchable(),
        ];
    }
}
```

## Identity Methods

| Method | Return | Description |
|--------|--------|-------------|
| `model()` | `string` | **Required.** Eloquent model class name. |
| `uriKey()` | `string` | URL segment (default: plural snake_case of model name) |
| `label()` | `string` | Plural label (default: derived from class name) |
| `singularLabel()` | `string` | Singular label |
| `subtitle()` | `?string` | Optional subtitle shown below label on index |
| `titleAttribute()` | `string` | Model attribute used as display title (default: `'name'`) |
| `title()` | `string` | Display title for a specific model instance |
| `icon()` | `string` | Phosphor icon name (default: `'newspaper'`) |
| `group()` | `?string` | Navigation group (null = default group) |

## Context-Aware Field Resolution

Override these methods to return different fields per context:

| Method | Falls Back To |
|--------|---------------|
| `fieldsForIndex(Request)` | `fields()` |
| `fieldsForDetail(Request)` | `fields()` |
| `fieldsForCreate(Request)` | `fields()` |
| `fieldsForUpdate(Request)` | `fields()` |
| `fieldsForInlineCreate(Request)` | `fieldsForCreate()` → `fields()` |
| `fieldsForPreview(Request)` | `fields()` |

## Index Configuration

| Method | Default | Description |
|--------|---------|-------------|
| `indexSearchable()` | `true` | Enable full-text search |
| `perPage()` | `25` | Default items per page |
| `perPageOptions()` | `[15, 25, 50]` | Pagination options |
| `searchPlaceholder()` | `null` | Custom placeholder (null = i18n default) |
| `softDeletes()` | `false` | Enable soft delete support |

## Table Display

| Method | Default | Description |
|--------|---------|-------------|
| `tableStriped()` | `true` | Striped rows |
| `tableShowGridlines()` | `false` | Show grid lines |
| `tableSize()` | `'normal'` | Density: `'small'`, `'normal'`, `'large'` |
| `tableRowHover()` | `true` | Highlight on hover |

## Authorization

Integrates with Laravel Policies and Gates. Override these methods to control access:

```php
public function authorizedToViewAny(Request $request): bool
{
    return true; // default: checks policy
}

public function authorizedToView(Request $request): bool { ... }
public function authorizedToCreate(Request $request): bool { ... }
public function authorizedToUpdate(Request $request): bool { ... }
public function authorizedToDelete(Request $request): bool { ... }
```

## Lifecycle Hooks

```php
public function beforeSave(Model $model, Request $request, bool $creating): void
{
    // Called before $model->save() on create or update
    // $creating is true for new records, false for updates
}

public function afterSave(Model $model, Request $request, bool $creating): void
{
    // Called after $model->save()
}

public function beforeDelete(Model $model, Request $request): void
{
    // Called before $model->delete()
}

public function afterDelete(Model $model, Request $request): void
{
    // Called after $model->delete()
}
```

Each hook dispatches a corresponding event (`BeforeSave`, `AfterSave`, `BeforeDelete`, `AfterDelete`) for decoupled logic.

## User-Facing Messages

Override these static methods to customize messages:

| Method | Default |
|--------|---------|
| `createdMessage()` | i18n: "Record created successfully" |
| `updatedMessage()` | i18n: "Record updated successfully" |
| `deletedMessage()` | i18n: "Record deleted successfully" |
| `restoredMessage()` | i18n: "Record restored successfully" |
| `deleteConfirmMessage()` | i18n: "Are you sure?" |
| `archiveConfirmMessage()` | i18n: "Are you sure?" |
| `errorDisplay()` | `'toast'` — options: `'toast'`, `'inline'`, `'both'` |
| `validationMessage()` | i18n: "Validation failed" |

## Serialization

`toArray()` returns the resource data as a JSON-serializable array for the API response, including resolved field values and authorization flags.
