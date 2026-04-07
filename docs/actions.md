# Actions

Actions allow users to perform tasks on one or more resource records. Martis implements the full Nova v5 actions API with additional Martis-exclusive features like dry-run preview and closure-based actions.

## Defining Actions

Create an action class in your `app/Martis/Actions` directory:

```bash
php artisan martis:action PublishPost
php artisan martis:action ArchivePost --destructive
```

Or manually:

```php
<?php

namespace App\Martis\Actions;

use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Illuminate\Support\Collection;

class PublishPost extends Action
{
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        foreach ($models as $model) {
            $model->update(['status' => 'published']);
        }

        return ActionResponse::message('Posts published successfully.');
    }
}
```

## Registering Actions

Register actions in your resource's `actions()` method:

```php
use App\Martis\Actions\PublishPost;
use App\Martis\Actions\ArchivePost;
use Illuminate\Http\Request;

public function actions(Request $request): array
{
    return [
        PublishPost::make()->showInline(),
        ArchivePost::make(),
    ];
}
```

## Destructive Actions

For dangerous operations, extend `DestructiveAction`. The UI renders these with red styling and a warning icon:

```php
use Martis\Actions\DestructiveAction;

class DeleteRecords extends DestructiveAction
{
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        foreach ($models as $model) {
            $model->forceDelete();
        }

        return ActionResponse::danger('Records permanently deleted.');
    }
}
```

## Action Fields

Actions can define fields for user input before execution:

```php
use Martis\Fields\Text;
use Martis\Fields\Textarea;
use Martis\Fields\Select;
use Illuminate\Http\Request;

public function fields(Request $request): array
{
    return [
        Text::make('Subject')->rules('required', 'max:255'),
        Textarea::make('Message')->rules('required'),
        Select::make('Priority')->options([
            'low' => 'Low',
            'medium' => 'Medium',
            'high' => 'High',
        ]),
    ];
}
```

## Action Responses

The `ActionResponse` class provides several response types:

```php
// Success message (green toast)
ActionResponse::message('Operation completed.');

// Danger message (red toast)
ActionResponse::danger('Something went wrong.');

// Redirect (full page navigation)
ActionResponse::redirect('/dashboard');

// Visit (SPA navigation)
ActionResponse::visit('/resources/posts');

// Open URL in new tab
ActionResponse::openInNewTab('https://example.com/report.pdf');

// Download file
ActionResponse::download('report.csv', '/exports/report-2026.csv');

// Emit client-side event
ActionResponse::emit('post-published', ['id' => $post->id]);

// Open custom modal component
ActionResponse::modal('ConfirmationDialog', ['title' => 'Done']);
```

## Visibility

Control where actions appear in the UI:

```php
// Show on index table only
PublishPost::make()->onlyOnIndex();

// Show on detail page only
PublishPost::make()->onlyOnDetail();

// Show as inline action (per-row button)
PublishPost::make()->onlyInline();

// Exclude from specific views
PublishPost::make()->exceptOnIndex();
PublishPost::make()->exceptOnDetail();
PublishPost::make()->exceptInline();

// Show on both (default)
PublishPost::make()->showOnIndex()->showOnDetail();
```

## Execution Modes

### Standalone Actions

Actions that don't require selected records:

```php
ExportReport::make()->standalone();
```

Standalone actions appear in the resource header, not in the bulk action toolbar.

### Sole Actions

Actions that require exactly one selected record:

```php
SendNotification::make()->sole();
```

## Confirmation

By default, actions with fields show a confirmation modal. You can customize it:

```php
PublishPost::make()
    ->confirmText('Are you sure you want to publish these posts?')
    ->confirmButtonText('Publish Now')
    ->cancelButtonText('Go Back');
```

To skip confirmation entirely:

```php
PublishPost::make()->withoutConfirmation();
```

### Modal Size

```php
PublishPost::make()->size(ModalSize::Large);     // 'lg'
PublishPost::make()->size(ModalSize::ExtraLarge); // 'xl'
PublishPost::make()->fullscreen();
```

Available sizes: `Small`, `Medium` (default), `Large`, `ExtraLarge`, `TwoXL`, `ThreeXL`, `FourXL`, `FiveXL`, `SixXL`, `SevenXL`.

## Authorization

### canSee / canRun

Control visibility and executability with callbacks:

```php
PublishPost::make()
    ->canSee(fn (Request $request) => $request->user()->isEditor())
    ->canRun(fn (Request $request, $model) => $model->status === 'draft');
```

### Policy Integration

The action controller checks Laravel policies automatically:

- `runAction($user)` — called for normal actions
- `runDestructiveAction($user)` — called for destructive actions

If these policy methods are not defined, it falls back to `update` and `delete` respectively.

## Action Events (Audit Log)

By default, actions log to the `action_events` table. Use the `Actionable` trait on your model:

```php
use Martis\Actions\Actionable;

class Post extends Model
{
    use Actionable;
}
```

Disable logging for specific actions:

```php
PublishPost::make()->withoutActionEvents();
```

## Queued Actions

For long-running operations, implement `ShouldQueue`:

```php
use Illuminate\Contracts\Queue\ShouldQueue;

class GenerateReport extends Action implements ShouldQueue
{
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        // This runs in the background
        foreach ($models as $model) {
            $model->generateReport();
        }

        return ActionResponse::message('Report generation started.');
    }
}
```

## Closure Actions

For simple one-off actions, use the `using()` factory:

```php
public function actions(Request $request): array
{
    return [
        Action::using('Mark as Draft', function (ActionFields $fields, Collection $models) {
            foreach ($models as $model) {
                $model->update(['status' => 'draft']);
            }
            return ActionResponse::message('Marked as draft.');
        })->showInline(),
    ];
}
```

## Dry-Run Preview

Martis-exclusive feature. Enable preview mode to let users see what would happen before executing:

```php
PublishPost::make()->withDryRun();
```

When enabled, the UI shows a "Preview" button alongside the confirm button.

## API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/resources/{resource}/actions` | List available actions |
| GET | `/api/resources/{resource}/actions/{action}/fields` | Get action fields |
| POST | `/api/resources/{resource}/actions/{action}` | Execute bulk action |
| POST | `/api/resources/{resource}/{id}/actions/{action}` | Execute single-record action |

### Execute Request Body

```json
{
  "resources": [1, 2, 3],
  "fields": {
    "subject": "Hello",
    "message": "World"
  },
  "dryRun": false
}
```

### Execute Response

```json
{
  "response": {
    "type": "message",
    "data": {
      "message": "3 posts published successfully."
    }
  }
}
```

## Frontend Components

- **ActionModal**: Confirmation dialog with field rendering, response handling, dry-run support
- **ActionDropdown**: Dropdown trigger for selecting actions (used in both index and detail views)

Both components follow the Martis theming system and support light/dark themes.
