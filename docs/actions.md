# Actions

> Last updated: 2026-04-08 (REA-1102 — Actions System)

## Overview

Martis Actions provide a way to run custom operations on resources — inline per-row, bulk on selected records, standalone without models, or grouped into menus.

Full Nova v5 parity plus Martis extensions: icons, menu grouping with submenus, dry-run preview, and custom components.

## Defining Actions

```php
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;

class PublishPost extends Action
{
    public ?string $name = 'Publish Post';

    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        foreach ($models as $post) {
            $post->update(['status' => 'published']);
        }
        return ActionResponse::message($models->count() . ' post(s) published.');
    }
}
```

### Destructive Actions

```php
use Martis\Actions\DestructiveAction;

class ArchivePost extends DestructiveAction
{
    public ?string $name = 'Archive Post';
    // Requires confirmation, shown with red styling (red border, red header accent)
}
```

### Artisan Command

```bash
php artisan martis:action MyAction
php artisan martis:action MyAction --destructive
```

## Icons

Set a Phosphor icon name on any action. All 1500+ Phosphor icons are supported.

```php
PublishPost::make()->icon('rocket-launch')
ArchivePost::make()->icon('archive-box')
ExportPosts::make()->icon('file-arrow-down')
```

Icons appear in:
- **Action dropdown menus** (index and detail)
- **Inline action buttons** (per-row in the table)
- **Action modal header** (when executing)

## Menu Grouping

Group actions into dropdown menus and submenus using `group()`. Dot-notation creates nested submenus.

```php
public function actions(Request $request): array
{
    return [
        // Ungrouped — appears at top level of dropdown
        PublishPost::make()->icon('rocket-launch')->showInline(),

        // Grouped under "Export" submenu
        ExportPosts::make()->icon('file-arrow-down')->group('Export')->standalone(),
        Action::using('Export as PDF', fn($f, $m) => ActionResponse::message('PDF export started.'))
            ->icon('file-pdf')
            ->group('Export'),

        // Nested submenu: "Notifications.Email"
        SendEmail::make()->icon('envelope')->group('Notifications.Email'),
        SendSMS::make()->icon('device-mobile')->group('Notifications.SMS'),
    ];
}
```

This renders:
- `Publish Post` (top-level with rocket icon)
- `Export >` submenu containing: `Export All Posts`, `Export as PDF`
- `Notifications >` submenu containing: `Email >` and `SMS >` sub-submenus

## Registering Actions

```php
public function actions(Request $request): array
{
    return [
        PublishPost::make()->showInline(),
        ArchivePost::make(),
        ExportPosts::make()->standalone()->onlyOnIndex(),
    ];
}
```

## Visibility

| Method | Effect |
|--------|--------|
| `showOnIndex()` | Visible in index bulk actions |
| `showOnDetail()` | Visible on detail page |
| `showInline()` | Per-row button on index |
| `onlyOnIndex()` | Index only |
| `onlyOnDetail()` | Detail only |
| `onlyInline()` | Inline only |
| `exceptOnIndex()` | Hidden from index |
| `exceptOnDetail()` | Hidden from detail |
| `exceptInline()` | Hidden from inline |

## Execution Modes

| Method | Effect |
|--------|--------|
| `standalone()` | No model selection required |
| `sole()` | Exactly one model must be selected |
| (default) | One or more models |

## Action Fields

```php
public function fields(Request $request): array
{
    return [
        Select::make('format', 'Export Format')->options([
            'csv' => 'CSV',
            'json' => 'JSON',
        ]),
        Text::make('subject', 'Subject')->required(),
    ];
}
```

Fields are displayed in the confirmation modal before execution.

## Confirmation Modal

```php
$action->confirmText('Are you sure?')
       ->confirmButtonText('Yes, do it')
       ->cancelButtonText('Cancel')
       ->size(ModalSize::Lg)
       ->fullscreen()
       ->withoutConfirmation()  // skip modal entirely
```

## Authorization

Actions integrate fully with Laravel Policies through a multi-layer authorization chain. Every action execution is validated server-side; the frontend uses metadata to show, hide, or disable actions for a better UX.

### Layer 1: Visibility — canSee()

Controls whether the action appears in the UI at all. Evaluated once when listing actions.

```php
PublishPost::make()->canSee(fn (Request $request) => $request->user()->isAdmin());
```

If `canSee()` returns false, the action is filtered out of the API response entirely — the user never sees it.

### Layer 2: Per-Model Execution — canRun()

Controls whether a specific model can be acted upon. Evaluated per-model at execution time.

```php
PublishPost::make()->canRun(fn (Request $request, $model) => $model->status === 'draft');
```

### Layer 3: Policy Integration

The resource's authorization layer delegates to Laravel Policies using a resolution chain:

1. **Explicit policy**: `protected static string $policy = PostPolicy::class;` on the Resource
2. **Auto-discovery**: Laravel's standard `Gate::getPolicyFor(Model::class)`
3. **No policy found**: permissive (returns true)

For actions specifically, the controller checks TWO policy methods:

**Normal actions** — `authorizedToRunAction()`:
```
Policy::runAction($user) → exists? use it
  ↓ (fallback)
Policy::update($user, $model) → exists? use it
  ↓ (fallback)
true (permissive)
```

**Destructive actions** — `authorizedToRunDestructiveAction()`:
```
Policy::runDestructiveAction($user) → exists? use it
  ↓ (fallback)
Policy::delete($user, $model) → exists? use it
  ↓ (fallback)
true (permissive)
```

### Full Authorization Chain on Execution

When a user executes an action, the `ActionController` enforces this sequence:

1. `action.authorizedToSee($request)` — can the user see this action?
2. For each selected model:
   - `action.authorizedToRun($request, $model)` — per-model canRun callback
   - `resource.authorizedToRunAction($request)` — policy chain (normal actions)
   - `resource.authorizedToRunDestructiveAction($request)` — policy chain (destructive actions)

If any check fails, the API returns a 404 error (not 403, to avoid information leakage).

### Example Policy

```php
namespace App\Policies;

use App\Models\Post;
use App\Models\User;

class PostPolicy
{
    // Controls normal action execution on posts
    public function runAction(User $user): bool
    {
        return $user->hasRole('editor');
    }

    // Controls destructive action execution on posts
    public function runDestructiveAction(User $user): bool
    {
        return $user->hasRole('admin');
    }

    // Fallback for runAction if not defined
    public function update(User $user, Post $post): bool
    {
        return $user->id === $post->author_id || $user->hasRole('editor');
    }

    // Fallback for runDestructiveAction if not defined
    public function delete(User $user, Post $post): bool
    {
        return $user->hasRole('admin');
    }
}
```

### Resource-Level Policy Binding

```php
use Martis\Resource;

class PostResource extends Resource
{
    // Explicit policy (optional — auto-discovery works too)
    protected static string $policy = \App\Policies\PostPolicy::class;

    public static function model(): string
    {
        return \App\Models\Post::class;
    }

    public function actions(Request $request): array
    {
        return [
            PublishPost::make()
                ->canSee(fn ($r) => $r->user()->can('update', Post::class))
                ->canRun(fn ($r, $m) => $m->status === 'draft')
                ->showInline()
                ->icon('rocket-launch'),

            ArchivePost::make()
                ->canSee(fn ($r) => $r->user()->hasRole('admin'))
                ->icon('archive-box'),
        ];
    }
}
```

### Standalone Actions and Authorization

Standalone actions (no model selection) skip the per-model `authorizedToRun()` check since there are no models. Only `authorizedToSee()` applies.

## Action Responses

| Response | Effect |
|----------|--------|
| `ActionResponse::message('Done')` | Success toast |
| `ActionResponse::danger('Failed')` | Error toast |
| `ActionResponse::redirect($url)` | Redirect browser |
| `ActionResponse::visit($path)` | Navigate SPA |
| `ActionResponse::openInNewTab($url)` | New tab |
| `ActionResponse::download($url, $filename)` | Download file |
| `ActionResponse::emit($event, $data)` | Client-side event |
| `ActionResponse::modal($component, $props)` | Open custom modal |

## Action Events (Audit Log)

Actions log to the `action_events` table automatically. Each event records:
- `batch_id` — UUID grouping bulk action executions
- `user_id` — who ran the action
- `name` — action name
- `actionable_type` / `actionable_id` — target model
- `fields` — JSON of submitted field values
- `status` — `completed`, `failed`, or `queued`
- `exception` — error message on failure
- Timestamps

Disable logging for specific actions:

```php
PublishPost::make()->withoutActionEvents();
```

Use the `Actionable` trait on models to query their action history:

```php
use Martis\Actions\Actionable;

class Post extends Model
{
    use Actionable;
}

// Query action history
$post->actions()->latest()->get();
```

## Queued Actions

```php
class HeavyExport extends Action implements ShouldQueue
{
    use Queueable;
    // Runs in Laravel queue, not blocking
}
```

Queued actions log an event with status `queued` immediately, then update to `completed` or `failed` when the job finishes.

## Closure Actions

```php
Action::using('Quick Action', function (ActionFields $fields, Collection $models) {
    return ActionResponse::message('Done!');
})->icon('lightning')->showInline()
```

Not queueable, but convenient for simple one-off actions.

## Dry-Run Preview (Martis Extension)

```php
$action->withDryRun()

public function dryRun(ActionFields $fields, Collection $models): array
{
    return ['preview' => 'Would affect ' . $models->count() . ' records.'];
}
```

When enabled, the UI shows a "Preview" button alongside the confirm button.

## Custom Component (Martis Extension)

```php
$action->component('my-custom-component', ['param' => 'value'])
```

Renders a custom React component inside the action modal instead of the default fields form.

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
  "data": {
    "type": "message",
    "data": {
      "message": "3 posts published successfully."
    }
  }
}
```

## Frontend Components

- **ActionModal**: Confirmation dialog with field rendering, response handling, dry-run support. Destructive actions render with red border + accent.
- **ActionDropdown**: Dropdown trigger with icon support and group/submenu rendering.
- **Inline Actions**: Per-row action buttons in the DataTable.

All components follow the Martis theming system and support light/dark themes.
