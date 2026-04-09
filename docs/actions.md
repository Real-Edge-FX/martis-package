# Actions

> Last updated: 2026-04-09 (REA-1184 — Actions documentation overhaul)

## Overview

Martis Actions allow you to run custom operations on resources. Four distinct action types are supported:

| Type | Description | Selection required? |
|------|-------------|---------------------|
| **Standard (Bulk)** | Runs on one or more selected records | Yes (1+) |
| **Inline** | Button rendered directly on each table row | No (auto-targets row) |
| **Standalone** | Runs without any record selection | No |
| **Sole** | Requires exactly one record to be selected | Yes (exactly 1) |

Full Nova v5 parity plus Martis extensions: icons, menu grouping with submenus, dry-run preview, pivot actions, and custom components.

---

## Artisan Generator

```bash
# Standard action
php artisan martis:action PublishPost

# Destructive action (red UI, stricter authorization)
php artisan martis:action ArchivePost --destructive
```

Generated files are placed in `app/Martis/Actions/`.

---

## Standard (Bulk) Actions

Bulk actions run on **one or more selected records**. The user selects rows using checkboxes in the index table, then triggers the action from the Actions dropdown. The action receives the full `Collection` of selected models.

### Defining a Bulk Action

```php
<?php

namespace App\Martis\Actions;

use Illuminate\Support\Collection;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;

class PublishPosts extends Action
{
    public ?string $name = 'Publish Posts';

    /**
     * Execute on all selected models.
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        foreach ($models as $post) {
            $post->update(['status' => 'published', 'published_at' => now()]);
        }

        return ActionResponse::message("{$models->count()} post(s) published successfully.");
    }
}
```

### Registering on a Resource

```php
use Illuminate\Http\Request;
use Martis\Resource;

class PostResource extends Resource
{
    public static function model(): string
    {
        return \App\Models\Post::class;
    }

    public function actions(Request $request): array
    {
        return [
            PublishPosts::make(),
        ];
    }
}
```

### How it looks

- Checkbox column appears on the index table
- User selects one or more rows
- "Actions" dropdown appears in the table toolbar
- User picks an action — confirmation modal opens
- `handle()` receives a `Collection` with all selected models

---

## Inline Actions

Inline actions appear **as buttons directly on each table row** — no checkbox selection required. Clicking triggers the action for that single row.

### Defining an Inline Action

```php
<?php

namespace App\Martis\Actions;

use Illuminate\Support\Collection;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;

class ApproveComment extends Action
{
    public ?string $name = 'Approve';

    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        foreach ($models as $comment) {
            $comment->update(['approved' => true]);
        }

        return ActionResponse::message('Comment approved.');
    }
}
```

### Registering as Inline

Call `showInline()` when registering the action. Pair with `icon()` for best visual results.

```php
public function actions(Request $request): array
{
    return [
        ApproveComment::make()
            ->showInline()
            ->icon('check-circle')
            ->withoutConfirmation(),   // optional: skip confirmation modal

        // Inline + visible in the dropdown too (default index behaviour)
        FlagComment::make()
            ->showInline()
            ->showOnIndex()
            ->icon('flag'),
    ];
}
```

### Visibility control

| Method | Effect |
|--------|--------|
| `showInline()` | Also show as a per-row button |
| `onlyInline()` | Show **only** as per-row button (hidden from dropdown) |
| `exceptInline()` | Never show as per-row button |

### How it looks

- Each row shows a small icon button (no checkbox needed)
- Clicking the button immediately targets that row's model
- Tooltip shows the action name on hover
- Confirmation modal opens (unless `withoutConfirmation()` is set)

---

## Standalone Actions

Standalone actions run **without any record selection**. They appear in the Actions dropdown but do not require the user to select rows first. Useful for resource-wide operations like export-all or import.

### Defining a Standalone Action

```php
<?php

namespace App\Martis\Actions;

use Illuminate\Support\Collection;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;

class ExportAllPosts extends Action
{
    public ?string $name = 'Export All Posts';

    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        // $models is empty for standalone actions — query directly
        $csv = \App\Services\PostExporter::exportAll();

        return ActionResponse::download('posts.csv', $csv->url());
    }
}
```

### Registering as Standalone

```php
public function actions(Request $request): array
{
    return [
        ExportAllPosts::make()
            ->standalone()
            ->icon('file-arrow-down')
            ->onlyOnIndex(),
    ];
}
```

### How it looks

- Action appears in the dropdown regardless of row selection
- No "select rows first" requirement
- `$models` collection is always empty — query data yourself inside `handle()`

---

## Destructive Actions

Destructive actions extend `DestructiveAction` instead of `Action`. The UI renders with **red styling** (border and header accent) and the confirmation button is labeled destructively. Laravel authorization policies check `runDestructiveAction()` or fall back to `delete()`.

### Defining a Destructive Action

```php
<?php

namespace App\Martis\Actions;

use Illuminate\Support\Collection;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Actions\DestructiveAction;

class DeleteSelectedPosts extends DestructiveAction
{
    public ?string $name = 'Delete Posts';

    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        $count = $models->count();

        foreach ($models as $post) {
            $post->delete();
        }

        return ActionResponse::message("{$count} post(s) deleted.");
    }
}
```

### Registering a Destructive Action

```php
public function actions(Request $request): array
{
    return [
        DeleteSelectedPosts::make()
            ->icon('trash')
            ->confirmText('This will permanently delete the selected posts.')
            ->confirmButtonText('Delete')
            ->cancelButtonText('Keep'),
    ];
}
```

---

## Queued (Batch) Actions

For heavy operations (sending emails, generating reports, processing large datasets), implement `ShouldQueue` on the action class. The action dispatches as a real Laravel job and returns immediately to the user with a "queued" toast.

### Defining a Queued Action

```php
<?php

namespace App\Martis\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;

class SendNewsletterToSubscribers extends Action implements ShouldQueue
{
    use Queueable;

    public ?string $name = 'Send Newsletter';

    // Optional: target a specific queue
    public string $queue = 'emails';
    public string $connection = 'redis';

    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        foreach ($models as $subscriber) {
            \Mail::to($subscriber->email)->send(
                new \App\Mail\Newsletter($fields->subject, $fields->body)
            );
        }

        return ActionResponse::message('Newsletter sent to all selected subscribers.');
    }

    /**
     * Fields collected from the user before queuing.
     */
    public function fields(\Illuminate\Http\Request $request): array
    {
        return [
            \Martis\Fields\Text::make('subject', 'Subject')->required(),
            \Martis\Fields\Textarea::make('body', 'Body')->required(),
        ];
    }
}
```

### Registering a Queued Action

```php
public function actions(Request $request): array
{
    return [
        SendNewsletterToSubscribers::make()
            ->icon('envelope')
            ->confirmText('This will send an email to all selected subscribers.'),
    ];
}
```

### Behavior

- User selects records, fills in fields, clicks Confirm
- API responds immediately with `"Action has been queued for processing."`
- A `queued` entry is written to `action_events`
- The Laravel job processes the records asynchronously
- `action_events` status updates to `completed` or `failed` when done

---

## Action Fields

Fields let you collect user input inside the confirmation modal before the action runs. Any Martis field type can be used.

```php
use Illuminate\Http\Request;
use Martis\Fields\Boolean;
use Martis\Fields\Select;
use Martis\Fields\Text;
use Martis\Fields\Textarea;

public function fields(Request $request): array
{
    return [
        Text::make('reason', 'Reason')
            ->required()
            ->help('Explain why this action is being taken.'),

        Select::make('format', 'Export Format')
            ->options([
                'csv'  => 'CSV',
                'json' => 'JSON',
                'xlsx' => 'Excel',
            ])
            ->default('csv'),

        Textarea::make('message', 'Message'),

        Boolean::make('notify', 'Notify users')->default(false),
    ];
}
```

Access field values in `handle()`:

```php
public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
{
    $reason  = $fields->reason;         // dynamic property access
    $format  = $fields->get('format');  // explicit get()
    $all     = $fields->all();          // full associative array
}
```

---

## Confirmation Modal

```php
PublishPosts::make()
    ->confirmText('Are you sure you want to publish these posts?')
    ->confirmButtonText('Yes, publish')
    ->cancelButtonText('Cancel')
    ->size(\Martis\Enums\ModalSize::Lg)     // sm | md | lg | xl | 2xl
    ->fullscreen()                           // overrides size
    ->withoutConfirmation()                  // skip modal entirely (runs immediately)
```

---

## Icons

Set any [Phosphor icon](https://phosphoricons.com) name. Icons appear in the dropdown, inline buttons, and the action modal header.

```php
PublishPosts::make()->icon('rocket-launch')
DeleteSelectedPosts::make()->icon('trash')
ExportAllPosts::make()->icon('file-arrow-down')
SendNewsletterToSubscribers::make()->icon('envelope')
ApproveComment::make()->icon('check-circle')
```

---

## Menu Grouping

Group actions into dropdown submenus using `group()`. Use dot-notation for nested submenus.

```php
public function actions(Request $request): array
{
    return [
        // Top-level (no group)
        PublishPosts::make()->icon('rocket-launch'),

        // "Export" submenu
        ExportAllPosts::make()->icon('file-arrow-down')->group('Export')->standalone(),
        Action::using('Export as PDF', fn ($f, $m) => ActionResponse::message('PDF started.'))
            ->icon('file-pdf')
            ->group('Export'),

        // Nested: "Notifications > Email"
        SendNewsletterToSubscribers::make()->group('Notifications.Email'),
    ];
}
```

---

## Visibility Options

| Method | Index dropdown | Detail dropdown | Per-row button |
|--------|:--------------:|:---------------:|:--------------:|
| `showOnIndex()` | ✅ | — | — |
| `showOnDetail()` | — | ✅ | — |
| `showInline()` | — | — | ✅ |
| `onlyOnIndex()` | ✅ | ❌ | ❌ |
| `onlyOnDetail()` | ❌ | ✅ | ❌ |
| `onlyInline()` | ❌ | ❌ | ✅ |
| `exceptOnIndex()` | ❌ | — | — |
| `exceptOnDetail()` | — | ❌ | — |
| `exceptInline()` | — | — | ❌ |

Defaults: `showOnIndex = true`, `showOnDetail = true`, `showInline = false`.

---

## Execution Modes

| Method | Behaviour |
|--------|-----------|
| _(default)_ | Runs on 1+ selected models |
| `standalone()` | Runs with no models — selection not required |
| `sole()` | Requires exactly 1 selected model |

---

## Authorization

### Layer 1 — canSee()

Controls whether the action appears in the UI at all. Evaluated when listing actions.

```php
PublishPosts::make()->canSee(fn (Request $request) => $request->user()->isAdmin());
```

### Layer 2 — canRun()

Controls whether a specific model can be acted on. Evaluated per model at execution.

```php
PublishPosts::make()->canRun(fn (Request $request, $model) => $model->status === 'draft');
```

### Layer 3 — Policy integration

The controller resolves authorization via a fallback chain on the resource's policy:

**Normal actions** fall back through:
1. `Policy::runAction($user)`
2. `Policy::update($user, $model)`
3. Permissive (returns `true`)

**Destructive actions** fall back through:
1. `Policy::runDestructiveAction($user)`
2. `Policy::delete($user, $model)`
3. Permissive (returns `true`)

### Example Policy

```php
namespace App\Policies;

use App\Models\Post;
use App\Models\User;

class PostPolicy
{
    public function runAction(User $user): bool
    {
        return $user->hasRole('editor');
    }

    public function runDestructiveAction(User $user): bool
    {
        return $user->hasRole('admin');
    }
}
```

### Binding the policy to a resource

```php
class PostResource extends Resource
{
    protected static string $policy = \App\Policies\PostPolicy::class;

    // ...
}
```

---

## Action Responses

| Response | Effect |
|----------|--------|
| `ActionResponse::message('Done')` | Green success toast |
| `ActionResponse::danger('Failed')` | Red error toast |
| `ActionResponse::redirect($url)` | Full-page browser redirect |
| `ActionResponse::visit($path)` | SPA navigation (no reload) |
| `ActionResponse::openInNewTab($url)` | Opens URL in new tab |
| `ActionResponse::download($filename, $url)` | Triggers file download |
| `ActionResponse::emit($event, $data)` | Fires a client-side event |
| `ActionResponse::modal($component, $props)` | Opens a custom modal |

---

## Action Events (Audit Log)

Every action execution is logged to `action_events` automatically. Publish the migration to create the table:

```bash
php artisan vendor:publish --tag=martis-migrations
php artisan migrate
```

Each record contains:

| Column | Description |
|--------|-------------|
| `batch_id` | UUID grouping bulk executions |
| `user_id` | Who triggered the action |
| `name` | Action display name |
| `actionable_type` / `actionable_id` | Target model |
| `fields` | JSON of submitted field values |
| `status` | `completed`, `failed`, or `queued` |
| `exception` | Error message on failure |

Disable logging for a specific action:

```php
PublishPosts::make()->withoutActionEvents()
```

Query action history via the `Actionable` trait:

```php
use Martis\Concerns\Actionable;

class Post extends Model
{
    use Actionable;
}

// Latest 10 actions run on a specific post
$post->actions()->latest()->limit(10)->get();
```

---

## Closure Actions

For simple one-off actions, use `Action::using()` without creating a dedicated class.

```php
Action::using('Quick Approve', function (ActionFields $fields, Collection $models) {
    $models->each->update(['approved' => true]);
    return ActionResponse::message("{$models->count()} record(s) approved.");
})
->icon('check-circle')
->showInline()
->withoutConfirmation()
```

> Closure actions are **not queueable**. For heavy work, create a dedicated class implementing `ShouldQueue`.

---

## Dry-Run Preview (Martis Extension)

Show users what the action *would* do before committing:

```php
class BulkArchivePosts extends Action
{
    public ?string $name = 'Archive Posts';

    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        foreach ($models as $post) {
            $post->update(['status' => 'archived']);
        }
        return ActionResponse::message("{$models->count()} post(s) archived.");
    }

    public function dryRun(ActionFields $fields, Collection $models): array
    {
        return [
            'preview' => "Would archive {$models->count()} post(s).",
            'affected_ids' => $models->pluck('id')->all(),
        ];
    }
}
```

Enable dry-run when registering:

```php
BulkArchivePosts::make()->withDryRun()
```

The UI shows a **Preview** button alongside the Confirm button. Clicking Preview calls `dryRun()` and displays the result without running `handle()`.

---

## Custom Component (Martis Extension)

Replace the default fields form inside the action modal with a custom React component.

```php
MyCustomAction::make()->component('my-custom-component', ['param' => 'value'])
```

Register the component in the frontend registry. The component receives `action`, `selectedIds`, `props`, and `onSuccess`/`onHide` callbacks.

---

## Pivot Actions

Actions can run on BelongsToMany pivot rows instead of the primary model:

```php
RemoveTag::make()
    ->pivotAction()
    ->referToPivotAs('tag assignment')
    ->icon('tag')
```

---

## API Reference

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/resources/{resource}/actions` | List available actions (filter with `?context=index\|detail\|inline`) |
| `GET` | `/api/resources/{resource}/actions/{action}/fields` | Get action fields |
| `POST` | `/api/resources/{resource}/actions/{action}` | Execute action (bulk) |
| `POST` | `/api/resources/{resource}/{id}/actions/{action}` | Execute action (single record) |

### Execute request body

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

### Execute response

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

---

## Frontend Components

| Component | Description |
|-----------|-------------|
| `ActionDropdown` | Toolbar dropdown with group/submenu rendering and icon support |
| `ActionModal` | Confirmation dialog with field rendering, response handling, and dry-run support |
| Inline buttons | Per-row icon buttons in the DataTable (no checkbox required) |

All components respect the Martis theme system and work in both light and dark themes.
