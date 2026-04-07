# Resources — Complete Reference

The `Resource` class is the core building block of Martis. Each resource maps to an Eloquent model and defines its CRUD interface, fields, authorization, and behavior.

## Creating a Resource

Create a PHP class in `app/Martis/` (or any path configured in `config/martis.php`) that extends `Martis\Resource`:

```php
<?php

namespace App\Martis;

use App\Models\Post;
use Illuminate\Http\Request;
use Martis\Fields\Id;
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
            Id::make('id'),
            Text::make('title')->sortable()->searchable()->required(),
        ];
    }
}
```

Resources are **auto-discovered** by the `ResourceDiscovery` class. No manual registration is needed.

## Required Methods

### model()

Returns the fully-qualified class name of the Eloquent model.

```php
public static function model(): string
{
    return Post::class;
}
```

### fields()

Defines the fields displayed and editable in the admin panel. Receives the current HTTP request for conditional field display.

```php
public function fields(Request $request): array
{
    return [
        Id::make('id'),
        Text::make('title')->sortable()->searchable()->required(),
        Textarea::make('body')->hideFromIndex()->nullable(),
    ];
}
```

See the [Fields Reference](fields.md) for all 31 available field types.

## Configuration Methods

### titleAttribute()

Defines which model attribute is used to identify records in breadcrumbs, titles, and relationship dropdowns. Defaults to `id`.

```php
public static function titleAttribute(): string
{
    return 'name'; // Shows "John Doe" instead of "#1"
}
```

### label() / singularLabel()

Override the resource name shown in the UI. By default, Martis derives labels from the class name.

```php
public static function label(): string
{
    return 'Blog Posts';
}

public static function singularLabel(): string
{
    return 'Blog Post';
}
```

### uriKey()

The URL segment used for routing. Defaults to the kebab-case plural of the class name.

```php
public static function uriKey(): string
{
    return 'blog-posts'; // /martis/resources/blog-posts
}
```

### icon()

Sidebar icon. Accepts any [Phosphor Icon](https://phosphoricons.com/) name in kebab-case or PascalCase.

```php
public function icon(): string
{
    return 'newspaper'; // or 'Newspaper'
}
```

### subtitle()

Short description displayed below the resource name in the sidebar.

```php
public static function subtitle(): ?string
{
    return 'Manage blog posts and articles';
}
```

### group()

Groups resources in the sidebar navigation. Resources without a group appear at the top level.

```php
public function group(): ?string
{
    return 'Content';
}
```

### perPageOptions()

Configures the "per page" dropdown on the index page.

```php
public function perPageOptions(): array
{
    return [10, 25, 50, 100]; // default: [15, 25, 50]
}
```

### defaultPerPage()

Sets the initial number of records shown per page.

```php
public function defaultPerPage(): int
{
    return 25; // default: 15
}
```

### defaultSort() / defaultSortDirection()

Sets the initial sort column and direction on the index page.

```php
public function defaultSort(): string
{
    return 'created_at';
}

public function defaultSortDirection(): string
{
    return 'desc'; // 'asc' or 'desc'
}
```

## Table Configuration

### tableStriped() / tableShowGridlines() / tableSize() / tableRowHover()

Configure the appearance of the index table.

```php
public function tableStriped(): bool      { return true; }      // Alternating row colors
public function tableShowGridlines(): bool { return false; }     // Cell borders
public function tableSize(): string        { return 'normal'; }  // 'small', 'normal', 'large'
public function tableRowHover(): bool      { return true; }      // Highlight on hover
```

## Validation

### validationRules()

Define validation rules applied during create and update operations.

```php
public function validationRules(Request $request): array
{
    return [
        'title' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'unique:users,email'],
    ];
}
```

### creationValidationRules() / updateValidationRules()

Override validation rules for specific operations:

```php
public function creationValidationRules(Request $request): array
{
    return [
        'password' => ['required', 'min:8'],
    ];
}

public function updateValidationRules(Request $request): array
{
    return [
        'password' => ['nullable', 'min:8'],
    ];
}
```

### errorDisplay()

Controls how validation errors are shown in the frontend.

```php
public function errorDisplay(): string
{
    return 'inline'; // 'inline' (under each field) or 'toast' (notification popup)
}
```

## Lifecycle Hooks

### beforeSave()

Called before `$model->save()` on both create and update. The `$creating` parameter distinguishes between the two.

```php
public function beforeSave(Model $model, Request $request, bool $creating): void
{
    if ($creating) {
        $model->user_id = $request->user()?->id;
    }
    $model->slug = Str::slug($model->title);

    parent::beforeSave($model, $request, $creating); // Dispatches BeforeSave event
}
```

### afterSave()

Called after `$model->save()`.

```php
public function afterSave(Model $model, Request $request, bool $creating): void
{
    if ($creating) {
        $model->tags()->sync($request->input('tags', []));
    }
    Cache::forget("post:{$model->id}");

    parent::afterSave($model, $request, $creating); // Dispatches AfterSave event
}
```

### beforeDelete()

Called before deletion. Throw an exception to prevent deletion.

```php
public function beforeDelete(Model $model, Request $request): void
{
    if ($model->is_protected) {
        throw new \RuntimeException('This record cannot be deleted.');
    }

    parent::beforeDelete($model, $request); // Dispatches BeforeDelete event
}
```

### afterDelete()

Called after deletion.

```php
public function afterDelete(Model $model, Request $request): void
{
    AuditLog::record('deleted', static::class, $model->id);

    parent::afterDelete($model, $request); // Dispatches AfterDelete event
}
```

## Events

When hooks call `parent::hookName()`, Laravel events are dispatched automatically. Use event listeners for cross-cutting concerns:

```php
use Martis\Events\BeforeSave;
use Martis\Events\AfterSave;
use Martis\Events\BeforeDelete;
use Martis\Events\AfterDelete;

// In AppServiceProvider::boot()
Event::listen(AfterSave::class, function (AfterSave $event) {
    AuditLog::record('saved', $event->resourceClass, $event->model->id, $event->creating);
});
```

| Event | Trigger | Properties |
|-------|---------|------------|
| `BeforeSave` | Before `$model->save()` | `resourceClass`, `model`, `request`, `creating` |
| `AfterSave` | After `$model->save()` | `resourceClass`, `model`, `request`, `creating` |
| `BeforeDelete` | Before `$model->delete()` | `resourceClass`, `model`, `request` |
| `AfterDelete` | After `$model->delete()` | `resourceClass`, `model`, `request` |

## Custom Messages

Override the default notification messages shown after CRUD operations:

```php
public static function createdMessage(): string       { return 'Post created successfully!'; }
public static function updatedMessage(): string        { return 'Post updated.'; }
public static function deletedMessage(): string        { return 'Post removed.'; }
public static function deleteConfirmMessage(): string  { return 'Are you sure you want to delete this post?'; }
public static function validationMessage(): string     { return 'Please fix the errors in the form.'; }
```

## Soft Deletes

If your Eloquent model uses the `SoftDeletes` trait, Martis automatically:

- Shows an "Archive" button instead of "Delete" on the detail page
- Displays a badge on archived records
- Adds a "Restore" button to restore archived records
- Supports `?trashed=only` and `?trashed=with` query parameters on the index API

No additional configuration is needed — Martis detects `SoftDeletes` automatically.

## Search

Resources support global search when fields are marked as `searchable()`:

```php
Text::make('title')->searchable(),
Email::make('email')->searchable(),
```

The search bar on the index page queries all searchable fields using a `LIKE %term%` query. For more advanced search, Martis supports Laravel Scout integration via the `SearchResolver`.

## Authorization

Martis implements full **Nova v5 parity** for authorization with dedicated resource policies, auto-discovery, and comprehensive ability checking.

### Policy Resolution Chain

When checking authorization, Martis resolves the policy in this order:

1. **Explicit $policy property** on the Resource class
2. **Auto-discovery** by convention: {policy_namespace}\{ResourceBaseName}Policy
3. **Laravel Gate** policy registered for the model class
4. **No policy found** → permissive defaults (all allowed)

The policy namespace defaults to App\Martis\Policies and can be configured via config(martis.policy_namespace).

### Resource Abilities

| Ability | Controls | Default (no method) |
|---------|----------|-------------------|
| viewAny | Navigation + index access | allowed |
| view | Detail page access | forbidden |
| create | Create button + form | forbidden |
| update | Edit button + form | forbidden |
| replicate | Replicate button | fallback to create AND update |
| delete | Delete/archive button | forbidden |
| restore | Restore soft-deleted record | forbidden |
| forceDelete | Permanently delete soft-deleted record | forbidden |

### Action Abilities

| Ability | Controls | Default (no method) |
|---------|----------|-------------------|
| runAction | Normal (non-destructive) actions | fallback to update |
| runDestructiveAction | Destructive actions | fallback to delete |

### Relationship Abilities

| Ability | Controls | Default (no method) |
|---------|----------|-------------------|
| add{Model} | Inline create related record | allowed |
| attach{Model} | Attach specific related record | allowed |
| attachAny{Model} | Show attach button | allowed |
| detach{Model} | Detach related record | allowed |

### Authorization Metadata

Every API response includes _authorization metadata per record with boolean flags: authorizedToView, authorizedToUpdate, authorizedToDelete, authorizedToReplicate, authorizedToRunAction, authorizedToRunDestructiveAction, authorizedToRestore, authorizedToForceDelete.

Schema responses include collection-level authorization: authorizedToViewAny, authorizedToCreate.

The frontend uses this metadata to conditionally show/hide action buttons.

### Disabling Authorization

Set authorizable() to false on a resource to skip all policy checks.

### Field-Level Authorization

Fields support canSee(callable) and canSeeWhen(ability) for visibility control.

### before() Callback

Policies support a before() method that runs before any specific ability check. Return true to allow, false to deny, or null to fall through to the specific method.

### Generating Policies

Use php artisan martis:make-policy PolicyName --model=ModelName to generate a complete policy stub.

## CRUD Override System

Resources can override how create/update/detail views are rendered using drawer overrides:

```php
public function overrides(): array
{
    return [
        'create' => new Override('martis:drawer-create'),
        'update' => new Override('martis:drawer-update'),
        'detail' => new Override('martis:drawer-detail'),
    ];
}
```

See the [Override System](overrides.md) documentation for full details.

## Complete Example

```php
<?php

namespace App\Martis;

use App\Models\Post;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Martis\Fields\BelongsTo;
use Martis\Fields\Date;
use Martis\Fields\DateTime;
use Martis\Fields\HasMany;
use Martis\Fields\Heading;
use Martis\Fields\Id;
use Martis\Fields\Image;
use Martis\Fields\Text;
use Martis\Fields\Textarea;
use Martis\Resource;

class PostResource extends Resource
{
    public static function model(): string { return Post::class; }
    public static function titleAttribute(): string { return 'title'; }
    public static function subtitle(): ?string { return 'Blog posts and articles'; }
    public function icon(): string { return 'newspaper'; }
    public function group(): ?string { return 'Content'; }
    public function perPageOptions(): array { return [10, 25, 50]; }
    public function defaultSort(): string { return 'created_at'; }
    public function defaultSortDirection(): string { return 'desc'; }

    public function fields(Request $request): array
    {
        return [
            Id::make('id'),

            Heading::make('post_content', 'Post Content')
                ->content('Main post details'),

            Text::make('title')->sortable()->searchable()->required(),

            Textarea::make('body', 'Content')
                ->nullable()->hideFromIndex(),

            Image::make('cover_image', 'Cover')
                ->disk('public')->path('posts/covers')
                ->acceptedTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(5120),

            BelongsTo::make('user_id', 'Author')
                ->relatedResource('users')
                ->titleAttribute('name')
                ->nullable(),

            Date::make('published_at', 'Published')->nullable()->sortable(),

            HasMany::make('comments', 'Comments')
                ->relatedResource('comments'),

            DateTime::make('created_at', 'Created')->hideFromForms()->sortable(),
        ];
    }

    public function beforeSave(Model $model, Request $request, bool $creating): void
    {
        if (empty($model->slug) && !empty($model->title)) {
            $model->slug = Str::slug($model->title);
        }
        parent::beforeSave($model, $request, $creating);
    }
}
```
