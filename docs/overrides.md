# Override System

Martis uses a 4-tier component resolution system that lets you customize any React component without forking the package.

## Resolution Order

When the frontend renders a field, it checks four tiers in order. The first match wins:

```
1. Explicit component key   (PHP: ->component('custom-key'))
2. Resource-specific override (JS: componentRegistry.registerResourceFieldDisplay)
3. Global type override       (JS: componentRegistry.registerFieldDisplay)
4. Default built-in component
```

## Tier 1 — Explicit Component Key (PHP)

Set a custom component key directly on the field:

```php
Text::make('title')->component('fancy-title-input');
```

The frontend must register a component with the key `fancy-title-input`.

## Tier 2 — Resource-Specific Override (JS)

Override how a field type renders for a specific resource:

```tsx
import { componentRegistry } from '@martis/core';

componentRegistry.registerResourceFieldDisplay(
  'posts',    // resource URI key
  'title',    // field attribute
  MyCustomTitleDisplay
);
```

This override only applies to the `title` field on the `posts` resource.

## Tier 3 — Global Type Override (JS)

Override the default component for a field type across all resources:

```tsx
componentRegistry.registerFieldDisplay('text', MyTextDisplay);
```

All `Text` fields everywhere will now use `MyTextDisplay`.

## Tier 4 — Default Component

If no override is registered, Martis uses the built-in component for that field type.

## Layout Overrides

Customize the layout for specific resources:

```tsx
import { layoutRegistry } from '@martis/core';

layoutRegistry.register('posts', CustomPostLayout);
```

## Server-Side Hooks

Override lifecycle hooks in your Resource class:

```php
class PostResource extends Resource
{
    public function beforeSave($model, $request, bool $creating): void
    {
        if ($creating) {
            $model->author_id = $request->user()->id;
        }
    }

    public function afterSave($model, $request, bool $creating): void
    {
        // Custom logic after save
    }

    public function beforeDelete($model, $request): void
    {
        // Cleanup related data
    }

    public function afterDelete($model, $request): void
    {
        // Post-deletion tasks
    }
}
```

## Event Listeners

For decoupled logic, listen for Martis events:

```php
use Martis\Events\BeforeSave;
use Martis\Events\AfterSave;
use Martis\Events\BeforeDelete;
use Martis\Events\AfterDelete;
```

Each event carries: `resourceClass`, `model`, `request`, and (for save events) `creating`.

Register listeners in your `EventServiceProvider`:

```php
protected $listen = [
    AfterSave::class => [
        SendNotificationListener::class,
    ],
];
```

## Custom Fields

Generate a custom field with both PHP and React scaffolding:

```bash
php artisan martis:field RatingField
```

This creates:
- PHP field class in `app/Martis/Fields/RatingField.php`
- React component with hot reload support

## Custom Components

Generate a standalone React component:

```bash
php artisan martis:component StatsWidget
```

## Custom Themes

Scaffold a theme with dark and light mode support:

```bash
php artisan martis:theme
```
