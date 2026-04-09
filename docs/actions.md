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

Every action execution is logged to the `action_events` table automatically. Martis ships with a built-in **ActionEvent model**, a publishable **migration**, an **Actionable trait** for querying history, and a **built-in Resource** for browsing the audit log in the admin panel.

### Setup

Publish and run the migration to create the `action_events` table:

```bash
php artisan vendor:publish --tag=martis-migrations
php artisan migrate
```

### Table Schema

| Column | Type | Description |
|--------|------|-------------|
| `id` | `bigint` | Auto-increment primary key |
| `batch_id` | `uuid` | Groups records from a single bulk execution |
| `user_id` | `int\|null` | ID of the user who triggered the action |
| `name` | `string` | Action display name (e.g. "Publish Posts") |
| `actionable_type` | `string\|null` | Polymorphic model class (e.g. `App\Models\Post`) |
| `actionable_id` | `int\|null` | ID of the target model |
| `target_type` | `string\|null` | Target model class (for pivot actions) |
| `target_id` | `int\|null` | Target model ID |
| `model_type` | `string\|null` | Source model class |
| `model_id` | `int\|null` | Source model ID |
| `fields` | `json` | Submitted action field values |
| `status` | `string` | `completed`, `failed`, or `queued` |
| `exception` | `text` | Error message on failure (empty string on success) |
| `original` | `json` | Snapshot of model state before action |
| `changes` | `json` | Snapshot of model state after action |
| `created_at` | `timestamp` | When the action was executed |
| `updated_at` | `timestamp` | Last update time |

### ActionEvent Model

The `Martis\Models\ActionEvent` Eloquent model provides typed access to the audit log:

```php
use Martis\Models\ActionEvent;

// Query all events
$events = ActionEvent::query()->latest()->paginate(25);

// Filter by action name
$publishes = ActionEvent::query()->forAction(Publish Posts)->get();

// Filter by user
$myActions = ActionEvent::query()->forUser(auth()->id())->get();

// Filter by batch (all records from a single execution)
$batch = ActionEvent::query()->forBatch($batchId)->get();

// Access relationships
$event = ActionEvent::find(1);
$event->user;          // The User model who triggered it
$event->actionable;    // The model the action ran on (polymorphic)
$event->target;        // The target model (polymorphic)
```

#### Available Scopes

| Scope | Usage | Description |
|-------|-------|-------------|
| `forBatch(string $batchId)` | `->forBatch($uuid)` | Filter by batch UUID |
| `forAction(string $name)` | `->forAction(Publish Posts)` | Filter by action name |
| `forUser(int\|string $userId)` | `->forUser(1)` | Filter by user ID |

### Actionable Trait

Add the `Actionable` trait to any Eloquent model to query its action history:

```php
use Illuminate\Database\Eloquent\Model;
use Martis\Concerns\Actionable;

class Post extends Model
{
    use Actionable;
}

// Query action history for a specific post
$post = Post::find(1);

// All actions run on this post (newest first)
$post->actions()->latest()->get();

// Count publish actions
$post->actions()->where(name, Publish Posts)->count();

// Get failed actions
$post->actions()->where(status, failed)->get();
```

### Built-in ActionEvent Resource

Martis automatically registers an `ActionEventResource` in the admin panel, providing a read-only interface for browsing the audit log. This resource:

- Appears in the sidebar as **"Action Events"** with a clipboard icon
- Is **read-only** (no create, update, or delete)
- Sorts by `created_at DESC` by default
- Shows: Action name, User ID, Model type, Status, Executed At

#### Hide from Navigation

To hide the ActionEvent resource from the sidebar, set the config option:

```php
// config/martis.php
action_events => [
    enabled => true,
    resource => false,    // Hide from sidebar
],
```

Or set the environment variable:

```env
MARTIS_ACTION_EVENTS_RESOURCE=false
```

### Disabling Action Logging

#### Globally

Disable all action event logging via configuration:

```php
// config/martis.php
action_events => [
    enabled => false,     // No events recorded at all
    resource => true,
],
```

Or via environment variable:

```env
MARTIS_ACTION_EVENTS_ENABLED=false
```

When disabled globally, no `action_events` rows are created regardless of per-action settings.

#### Per Action

Disable logging for a specific action using `withoutActionEvents()`:

```php
PublishPosts::make()->withoutActionEvents()
```

This is useful for high-frequency or low-value actions that would clutter the audit log.

#### Summary

| Method | Scope | Effect |
|--------|-------|--------|
| `config(martis.action_events.enabled, true)` | Global | Disables all action logging |
| `->withoutActionEvents()` | Per action | Disables logging for one action |
| `config(martis.action_events.resource, true)` | Global | Hides ActionEvent from sidebar |

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
