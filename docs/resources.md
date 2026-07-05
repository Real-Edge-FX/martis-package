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

See the [Fields Reference](fields.md) for all 50 available field types.

### detailSidebar() (⭐ Martis differential)

Returns fields rendered in the **sticky right rail** of the detail page. When non-empty, the page lays out as a 1fr / 320px grid: the main column scrolls while the sidebar stays pinned beside it. Use it for at-a-glance metadata (status, owner, dates, IDs) so it doesn't compete with the long-form fields below.

```php
public function detailSidebar(Request $request): array
{
    return [
        Status::make('status'),
        BelongsTo::make('owner', 'Owner', UserResource::class),
        DateTime::make('created_at'),
        DateTime::make('updated_at'),
    ];
}
```

Fields rendered in the sidebar are automatically removed from the main `fieldsForDetail()` body so they only appear once. Below 1100px the layout collapses to a single column and the sidebar pushes to the bottom. Default: empty (single-column layout).

### Context-specific field overrides

Override any of `fieldsForIndex`, `fieldsForDetail`, `fieldsForCreate`, `fieldsForUpdate`, `fieldsForInlineCreate`, or `fieldsForPreview` to return a different field set per context. Each defaults to `fields($request)` so the override is opt-in.

```php
public function fieldsForIndex(Request $request): array
{
    return [
        Id::make('id'),
        Text::make('title')->sortable(),
        // No body / cover_image columns on the listing.
    ];
}

public function fieldsForDetail(Request $request): array
{
    return $this->fields($request); // include everything
}
```

Useful when the listing wants only a few summary columns while the detail page renders the full editor.

### filters() / lenses() / cards() / dashboards()

Resources opt into the four extension subsystems by overriding these methods on the Resource class. Each returns a list of objects of the matching type.

```php
public function filters(Request $request): array  { return [new StatusFilter()]; }
public function lenses(Request $request): array   { return [new HighValueClients()]; }
public function cards(Request $request): array    { return [new RevenueTrend()]; }
public static function dashboards(): array        { return [SalesDashboard::class]; }
```

See [Filters](filters.md), [Lenses](lenses.md), [Metrics](metrics.md), and [Dashboards](dashboards.md) for the per-class authoring guide.

### Eager-load relations declaratively — `static $with`

Add a `protected static array $with = [...]` to eager-load relations on every index, detail, and `relatableQuery` build. Saves the boilerplate `indexQuery` override that previously had to wrap `$query->with([...])`.

```php
class PostResource extends Resource
{
    /** @var list<string> */
    protected static array $with = ['author', 'tags', 'comments.user'];
}
```

The relations are loaded both on the listing query (`indexQuery`) and on the relationship-picker query (`relatableQuery`). For surgical control on a specific surface, override `indexQuery()` directly — the explicit override wins.

Source: `src/Resource.php::applyWith()`.

### Per-record title — `title()`

`titleAttribute()` declares which column to read; `title()` is the per-instance override that builds the actual label string. Default reads `static::titleAttribute()` from the model and falls back to `"{Singular} #{id}"` when no attribute is set.

```php
public function title(): string
{
    if ($this->model === null) return parent::title();

    return $this->model->name.' ('.$this->model->code.')';
}
```

Used in breadcrumbs, the relationship picker, and the global search palette. Override when a single attribute is not enough.

### Search bar configuration

| Method | Default | Effect |
|---|---|---|
| `indexSearchable(): bool` | `true` | Whether the search input renders on the resource index. Return `false` to hide it (search-by-URL still works for resources that need it). |
| `searchPlaceholder(): ?string` | `null` | Placeholder text shown inside the search input. Returns `null` to fall back to `martis::messages.search_placeholder`. |

```php
public static function indexSearchable(): bool       { return false; } // hide the bar
public static function searchPlaceholder(): ?string  { return 'Search by SKU or barcode…'; }
```

### Sidebar count badges — `showMenuCount()` / `menuCount()`

Render a small numeric badge next to the resource name in the sidebar. Useful for queues, inboxes, "needs review" lists.

```php
public static function showMenuCount(): bool { return true; }

public static function menuCount(Request $request): ?int
{
    return static::newModel()->newQuery()->where('status', 'pending')->count();
}
```

Returning `null` from `menuCount()` hides the badge for that user. Counts are formatted by the frontend with the `formatItemCount()` helper (compact `K` / `M` notation above the configured threshold).

### Place a resource under "System" — `belongsToSystemSection()`

Returns `true` to dock the resource under the sidebar's "System" group rather than the user-defined sections. Used internally by `ActionEventResource`. Override on consumer-side resources that should sit alongside the package's system tools.

```php
public function belongsToSystemSection(): bool { return true; }
```

### Custom search ordering — `searchOrderBy()`

The global search applies `LIKE %term%` (or Scout) and returns the first N matches. Override `searchOrderBy()` to apply a custom `ORDER BY` to the search hits — for example, surface recently-active records first.

```php
public function searchOrderBy(Builder $query, string $term): Builder
{
    return $query->orderByDesc('updated_at');
}
```

### `$stickyView` (⭐ Martis differential)

Controls whether this resource participates in the [Sticky Views](sticky_views.md) feature — per-user view state persistence on the index page (filters, sort, pagination, per-page, search). Default: `true`.

```php
class AuditEventResource extends Resource
{
    // Audit logs always want to show "today first" — opt out so the
    // index never restores a stale page / filter from the previous
    // session.
    protected static bool $stickyView = false;
}
```

When the global `config('martis.sticky_views.enabled')` is `false`, the per-resource flag is ignored and the feature is off everywhere.

### Auto-refresh polling — `$polling`, `$pollingInterval`, `$showPollingToggle`

Auto-refreshes the resource index payload at a fixed cadence. Useful for resources whose rows reflect external state (queue jobs, deployments, scraper feeds, real-time order books) where a stale view actively misleads the operator.

```php
class DeploymentResource extends Resource
{
    public static bool $polling = true;
    public static int $pollingInterval = 10;        // seconds
    public static bool $showPollingToggle = true;   // pause/resume control
}
```

Defaults: `$polling = false`, `$pollingInterval = 15`, `$showPollingToggle = true`.

The interval is silently clamped to a 5-second floor (`Resource::resolvedPollingInterval()`) — anything lower would risk hammering the backend on a long-running tab. When `$showPollingToggle` is `false` the user has no way to pause polling; when `true` the toolbar exposes a control that flips the live state for the current session.

The values surface in the index payload as:

```jsonc
{
  "schema": {
    "polling": true,
    "pollingInterval": 10,
    "showPollingToggle": true
  }
}
```

Lenses use the same shape (see [lenses.md](lenses.md)) — they inherit nothing from the resource, so each Lens declares its own polling cadence if it wants one.

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

When no custom main menu is defined, Martis uses `group()` to build the default menu sections automatically.

### displayInNavigation()

Controls whether the resource appears in the navigation menu. Override to `false` to hide a resource from the sidebar while keeping it accessible via direct URL.

```php
public static function displayInNavigation(): bool
{
    return false; // Hidden from sidebar, still accessible via URL
}
```

`displayInNavigation()` is still respected even when the main menu is customized through the menu builder.

### routable()

Controls whether the resource has a routable **human page surface**. Default `true`. Override to `false` to make the resource a headless data source: it stays registered and fully usable as a relation target, but its index/detail/create/edit pages disappear.

```php
public static function routable(): bool
{
    return false; // Headless: not a page, still a valid relation target
}
```

When `routable()` returns `false`:

- The index, show, store, update, destroy, restore, schema, and replicate-fields endpoints all return 404 — the resource has no human-facing page surface.
- The resource is excluded from the sidebar navigation, badge counts, global search, and the command palette (in addition to whatever `displayInNavigation()` already controls).
- The resource **remains fully usable as a relation target and data source**: the relatable endpoint (`BelongsTo`/`BelongsToMany`/etc. option lists), the Slug field's slug-check endpoint, `dependsOn` field sync, inline-create, and peek all keep working, still gated by the resource's own `authorizedTo*` methods.

Use this for the "a Tool owns the domain; the Resource is a headless data source" pattern: a custom Tool provides the human-facing UI and workflow for a model, while the underlying Resource definition is kept around purely so other resources can relate to it, validate slugs against it, or inline-create records of it, without exposing a redundant (and potentially confusing) standalone CRUD page for it.

Unlike `displayInNavigation()`, which only hides the resource from menus while leaving its endpoints reachable by direct URL, `routable(false)` fully removes the human page surface (404) while deliberately preserving the internal/relation surface.

### menuItem()

Customize the navigation item generated for a resource.

```php
use Illuminate\Http\Request;
use Martis\Menu\MenuItem;

public function menuItem(Request $request): MenuItem
{
    return MenuItem::resource(static::class)
        ->label('Help Desk')
        ->icon('lifebuoy')
        ->path('/support/help-desk');
}
```

Use this when the resource should remain a resource-backed navigation item, but needs a custom label, icon, or path.

Use this for internal resources (e.g., `ActionEventResource`) that should not clutter the main navigation.

### perPageOptions()

Configures the "per page" dropdown on the index page.

```php
public function perPageOptions(): array
{
    return [10, 25, 50, 100]; // default: [10, 25, 50, 100]
}
```

### perPage()

Sets the default number of records shown per page. Falls back to `config('martis.pagination.default_per_page')` (25 out of the box).

```php
public static function perPage(): int
{
    return 25;
}
```

### Effective per-page

`perPageOptions()` is the source of truth for the page-size dropdown. When the value returned by `perPage()` is not in that list, Martis silently clamps it to `perPageOptions()[0]`. This Option A behaviour keeps the dropdown and the actual filter in sync — no more "I set 7 in `perPage()` but the dropdown only shows 10/25/50 and returns 25".

Three rules govern it:

1. `perPageOptions()` is the canonical list. The dropdown renders exactly what this returns.
2. If `perPage()` is not in `perPageOptions()`, Martis clamps to `perPageOptions()[0]`. The clamp is silent (no warning, no exception).
3. Relation fields (`HasMany`, `MorphMany`, `BelongsToMany`, `MorphToMany`) inherit the related resource's `perPageOptions()` when the developer did not call `->perPageOptions([...])` on the field itself. See [relationships.md](relationships.md).

Two public accessors expose the clamped value:

| Method | Return | Notes |
|---|---|---|
| `Resource::resolvedPerPage(): int` | Clamped value used everywhere the backend or API needs a single "effective" per-page. | Use this in custom controllers instead of `perPage()`. |
| `Lens::resolvedPerPage(): int` | Same rule for lenses. | See [lenses.md — Effective per-page](lenses.md#effective-per-page). |

```php
public static function perPageOptions(): array { return [10, 25, 50, 100]; }
public static function perPage(): int          { return 7; } // not in options

// Resource::resolvedPerPage() === 10 — clamped to first option.
```

Source: `src/Resource.php::resolvedPerPage()`.

### defaultSort() / defaultSortDirection()

Sets the initial sort column and direction on the index page. Returns `null` (default) for no default sorting.

```php
public static function defaultSort(): ?string
{
    return 'created_at';
}

public static function defaultSortDirection(): SortDirection
{
    return SortDirection::Desc; // SortDirection::Asc or SortDirection::Desc
}
```

## Query Hooks

Static query hooks wrap every Eloquent query built by Martis for this resource. They run server-side before pagination and coexist with authorization policies — policies decide *who* can query, query hooks decide *which rows* are visible.

### indexQuery()

Constrains every index listing query. The canonical place for multi-tenancy, ownership scoping, or any other structural filter that must apply to every listing, export, and lens built on top of this resource.

```php
public static function indexQuery(Request $request, Builder $query): Builder
{
    return $query->where('tenant_id', $request->user()?->tenant_id);
}
```

Override when: you need a global constraint on the index that is *not* a user-configurable filter (filters are opt-in; `indexQuery()` is always applied).

Source: `src/Resource.php::indexQuery()`.

### relatableQuery()

Constrains the query used to list candidate records in relationship autocompletes (BelongsTo dropdowns, Tag pickers, MorphTo search). Generic fallback — for per-relationship customisation, define `relatable{PluralModelName}()` instead.

```php
public static function relatableQuery(Request $request, Builder $query): Builder
{
    return $query->where('active', true); // never offer inactive records
}
```

Override when: a BelongsTo/MorphTo selector should hide records that are otherwise valid on the index.

Source: `src/Resource.php::relatableQuery()`.

## Table Configuration

### tableStriped() / tableShowGridlines() / tableSize() / tableRowHover()

Configure the appearance of the index table.

```php
public function tableStriped(): bool      { return true; }      // Alternating row colors
public function tableShowGridlines(): bool { return false; }     // Cell borders
public static function tableSize(): TableSize { return TableSize::Normal; } // Small, Normal, Large
public function tableRowHover(): bool      { return true; }      // Highlight on hover
```

### tableLayout()

Controls how the index `<table>` distributes column widths.

```php
use Martis\Enums\TableLayout;

public static function tableLayout(): TableLayout
{
    return TableLayout::Auto; // default
}
```

- `Auto` (default) — the browser sizes each column by content. Martis ships sensible per-type defaults (Id → 80px, Email/Url → 280px max + ellipsis, Date → 140px, Boolean/Status → 120px; the `titleAttribute` column gets `minWidth: 220px`). Override per field with `->width()`, `->minWidth()`, `->maxWidth()`, `->truncate()`.
- `Fixed` — applies CSS `table-layout: fixed`, locking every column to its declared `->width()` (or the type default). Only pick this when you need pixel-perfect alignment across pages and can afford to width every visible column.

Per-field examples:

```php
Url::make('homepage')->maxWidth('200px')->truncate();
Id::make();                      // auto: 80px from the type default
Text::make('name');              // auto: minWidth 220px when name = titleAttribute
Badge::make('state')->width('96px');
```

#### Opting out of the type-default heuristics

If you prefer the pre-v0.7.0 behaviour where every column auto-sizes and nothing truncates, flip the global switch:

```php
// config/martis.php
'index' => [
    'column_defaults' => env('MARTIS_INDEX_COLUMN_DEFAULTS', true),
],
```

Set to `false` (or `MARTIS_INDEX_COLUMN_DEFAULTS=false` in `.env`) and Martis:

- skips every per-type default (`Id`, `Email`/`Url`, `Boolean`/`Status`/`Badge`, `Date`/`DateTime`);
- no longer injects the `minWidth: 220px` on the `titleAttribute` column.

Explicit per-field calls (`->width()`, `->minWidth()`, `->maxWidth()`, `->truncate()`) still apply regardless of the flag, so you can opt out globally and then whitelist individual columns as needed.

### actionsColumnLabel() / actionsMenuLabel() / bulkActionsMenuLabel()

Per-resource overrides for the text shown on the row-actions column and its two menus. Return `null` to fall back to the i18n default (`martis::actions.actions`).

| Method | Default | Controls |
|---|---|---|
| `actionsColumnLabel(): ?string` | `"Actions"` / `"Ações"` (i18n) | Header text of the row-actions column on the index. |
| `actionsMenuLabel(): ?string` | `null` (falls back to i18n) | Label of the per-row "Actions" dropdown. |
| `bulkActionsMenuLabel(): ?string` | `null` (falls back to i18n) | Label of the toolbar "Bulk Actions" dropdown shown when rows are selected. |

```php
public static function actionsColumnLabel(): ?string     { return 'Tools'; }
public static function actionsMenuLabel(): ?string        { return 'Row tools'; }
public static function bulkActionsMenuLabel(): ?string    { return 'Batch tools'; }
```

### Row-click behaviour

Whether clicking a row in the index opens the detail page is controlled by `rowClickOpensDetail(Request $request): ?bool` (per-resource override) and resolved to a concrete boolean by `resolveRowClickOpensDetail(Request $request): bool`. When the per-resource method returns `null`, the resolver falls back to `config('martis.index.row_click_opens_detail')`.

See [Default Row Actions — Row-click redundancy](default_row_actions.md#row-click-redundancy) for the full decision matrix.

### confirmUnsavedChanges()

Martis differential. Opts the create/update surfaces — both drawer overrides and full-page create/update routes — into the **UnsavedChangesDialog**. When the user tries to discard changes (close the drawer, navigate away, click Cancel), the dialog asks for confirmation.

```php
// Enable with package defaults (generic copy).
public static function confirmUnsavedChanges(): bool
{
    return true;
}

// Or enable and customise copy, icon, colours, button labels.
public static function confirmUnsavedChanges(): \Martis\Contracts\UnsavedChangesConfigContract
{
    return \Martis\UnsavedChangesConfig::make()
        ->title('Discard post changes?')
        ->body('Your edits to this post will be lost.')
        ->confirmLabel('Discard')
        ->cancelLabel('Keep editing');
}
```

Three return shapes:

- `false` (default) — dialog disabled; forms close silently.
- `true` — dialog enabled with package defaults.
- `UnsavedChangesConfigContract` — dialog enabled with custom title/body/icon/colours/button labels.

The resource-level `archiveConfirmMessage()`, `deleteConfirmMessage()`, and `forceDeleteConfirmMessage()` methods (see [Custom Messages](#custom-messages)) win over the generic per-variant defaults of this dialog.

Source: `src/Resource.php::confirmUnsavedChanges()`.

## Validation

Validation in Martis is **field-level** — each field declares its own rules using `rules()`, `required()`, `nullable()`, and `unique()`. The `ResourceController` automatically collects and validates all field rules on create and update.

```php
// Validation is set on each field directly:
Text::make('title')->required()->rules(['string', 'max:255']),
Email::make('email')->required()->unique(['users', 'email'], 'This email is already in use.'),
Password::make('password')->required()->rules(['min:8']),
```

See the [Fields Reference](fields.md) for all 50 field types and validation methods.

### errorDisplay()

Controls how validation errors are shown in the frontend.

```php
public static function errorDisplay(): ErrorDisplayMode
{
    return ErrorDisplayMode::Inline; // Inline, Toast, or Both
}
```

## Form save variants

Both the create and update forms ship with three submit buttons each, covering the common power-user shortcuts. The default button preserves the historical behaviour; the two extra buttons stay on or jump out of the form depending on the operator's intent.

| Page | Button | Post-save destination |
|---|---|---|
| Create | `Create {Resource}` (primary) | Detail page of the new record |
| Create | `Create & add another` | Same `/create` page with a freshly cleared form |
| Create | `Create & view list` | Resource index |
| Update | `Save changes` (primary) | Detail page of the record |
| Update | `Save & continue editing` | Same `/edit` page (baseline refreshed so the unsaved-changes guard does NOT re-trigger) |
| Update | `Save & view list` | Resource index |

The two extra buttons are **hidden when the form is launched from a nested relation** (i.e. `?viaResource=…&viaResourceId=…`). Nested flows already manage their own post-save redirect to the parent surface, and a "view list" jump from inside a relation panel would be confusing.

Translations live in `actions.php`:

| Key | English |
|---|---|
| `create_and_add_another` | Create & add another |
| `create_and_view_list` | Create & view list |
| `save_and_continue_editing` | Save & continue editing |
| `save_and_view_list` | Save & view list |
| `reset_filters` | Reset filters |

### Custom post-save destination — `redirectAfterCreate()` / `redirectAfterUpdate()`

When the operator clicks the **primary** submit button (`Create {Resource}` or `Save changes`), the SPA navigates to the new record's detail page by default. Override these hooks to send the user somewhere else for that primary path:

```php
public function redirectAfterCreate(Model $model, Request $request): ?string
{
    // Land on the column the new card joined.
    return "/resources/boards/{$model->board_id}";
}

public function redirectAfterUpdate(Model $model, Request $request): ?string
{
    // Stay on the parent resource when this row was edited from a panel.
    if ($request->input('viaResource')) {
        $via = $request->input('viaResource');
        $viaId = $request->input('viaResourceId');
        return "/resources/{$via}/{$viaId}";
    }
    return null; // fall through to default
}
```

Return `null` (the default) to keep the standard behaviour. The two extra save variants — "Create & add another" / "Create & view list" / "Save & continue editing" / "Save & view list" — always win because they encode an explicit user intent. The hook is only consulted for the primary submit button.

The string travels back to the SPA in the create/update response under `meta.redirectTo`.

## Index toolbar resets

The index toolbar surfaces two reset affordances:

- **Reset filters** — clears only the active filters and resets to page 1. Sort, search, pagination size, and the trashed-toggle are preserved. Visible whenever any filter is active.
- **Reset view** — the bigger hammer that clears filters + search + sort + per-page + trashed-toggle and forgets the [sticky view](sticky_views.md) for this resource. Visible whenever any of those drift away from the resource's defaults.

Both buttons coexist on the toolbar — they target different aggressiveness levels so users can pick the right one for the moment.

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

// In a service provider's boot() — typically MartisServiceProvider for app-side concerns.
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

Override the default notification messages and confirm dialogs shown after CRUD operations. Every method returns a string; the default implementation pulls from `martis::messages.*` so translations flow through Laravel's `__()` helper.

| Method | When shown | Default i18n key |
|---|---|---|
| `createdMessage()` | Toast after `POST /resources/:key` succeeds. | `martis::messages.record_created` |
| `updatedMessage()` | Toast after `PUT /resources/:key/:id` succeeds. | `martis::messages.record_updated` |
| `deletedMessage()` | Toast after `DELETE /resources/:key/:id` succeeds. | `martis::messages.record_deleted` |
| `restoredMessage()` | Toast after a soft-deleted record is restored. | `martis::messages.record_restored` |
| `forceDeletedMessage()` | Toast after a soft-deleted record is permanently deleted. | `martis::messages.record_force_deleted` |
| `replicatedMessage()` | Toast after a record is duplicated via the Replicate action. | `martis::messages.record_replicated` |
| `deleteConfirmMessage()` | Body of the destructive-delete confirm dialog. | `martis::messages.delete_confirm` |
| `archiveConfirmMessage()` | Body of the soft-delete (archive) confirm dialog. | `martis::messages.archive_confirm` |
| `forceDeleteConfirmMessage()` | Body of the force-delete confirm dialog (trashed records only). | `martis::messages.force_delete_confirm` |
| `validationMessage()` | Toast shown when validation fails and `errorDisplay()` includes toast mode. | `martis::messages.validation_failed` |

Example overrides:

```php
public static function createdMessage(): string            { return 'Post published!'; }
public static function updatedMessage(): string             { return 'Post updated.'; }
public static function deletedMessage(): string             { return 'Post removed.'; }
public static function restoredMessage(): string            { return 'Post restored.'; }
public static function forceDeletedMessage(): string        { return 'Post permanently deleted.'; }
public static function replicatedMessage(): string          { return 'Post duplicated.'; }
public static function archiveConfirmMessage(): string      { return 'Archive this post? You can restore it later.'; }
public static function deleteConfirmMessage(): string       { return 'Delete this post permanently?'; }
public static function forceDeleteConfirmMessage(): string  { return 'This post will be gone forever. Continue?'; }
public static function validationMessage(): string          { return 'Please fix the errors in the form.'; }
```

> The three `*ConfirmMessage()` methods on the Resource win over the dialog's per-variant localized defaults. That is, if you override `archiveConfirmMessage()`, the UnsavedChangesDialog surfaces that string instead of its own generic archive copy.

## Soft Deletes

If your Eloquent model uses the `SoftDeletes` trait, Martis automatically:

- Shows an "Archive" button instead of "Delete" on the detail page
- Displays a badge on archived records
- Adds a "Restore" button to restore archived records
- Supports `?trashed=only` and `?trashed=with` query parameters on the index API

No additional configuration is needed — Martis detects `SoftDeletes` automatically.

### Restricting Trashed Visibility by Role

By default, all users see the trashed filter dropdown when a resource uses soft deletes. Override `canViewTrashed()` to restrict this by role:

```php
public static function canViewTrashed(): bool
{
    return auth()->user()?->isAdmin() ?? false;
}
```

When `canViewTrashed()` returns false:

- The trashed filter dropdown is hidden on the index page
- The backend ignores `?trashed=` query parameters
- The schema reports `softDeletes: false` for that user

Note: `findModel()` and `serializeModel()` remain ungated so that users with `restore` or `forceDelete` permissions can still act on trashed records via direct URL.

`canViewTrashed()` gives per-resource role-based control over the SoftDeletes filter visibility.

## Search

Resources support global search when fields are marked as `searchable()`:

```php
Text::make('title')->searchable(),
Email::make('email')->searchable(),
```

The search bar on the index page queries all searchable fields using a `LIKE %term%` query. For more advanced search, Martis supports Laravel Scout integration via the `SearchResolver`.

### globallySearchable()

Controls whether the resource participates in the Cmd+K global search modal. Defaults to `true` — opt out to hide internal or noisy resources from the palette.

```php
public static function globallySearchable(): bool
{
    return false; // exclude from Cmd+K
}
```

Source: `src/Resource.php::globallySearchable()`.

### searchSubtitle()

Returns the per-record subtitle shown under the title in global-search results. Receives the current model so you can pull from any attribute.

```php
public function searchSubtitle(Model $model): ?string
{
    return $model->email; // e.g. "john@acme.com" under "John Doe"
}
```

Return `null` (default) to show no subtitle. Source: `src/Resource.php::searchSubtitle()`.

### Laravel Scout integration

When the underlying Eloquent model uses the `Laravel\Scout\Searchable` trait, Martis automatically uses Scout for searches instead of `LIKE %term%`. Two hooks customise the integration:

| Method | Purpose |
|---|---|
| `usesScout(): bool` | `true` when the model is `Searchable`; override to `false` to force database search even when the trait is present. |
| `scoutQuery(Request $request, mixed $query): mixed` | Customise the Scout builder (add `where`, filters, callbacks). Only called when `usesScout()` is `true`. |

```php
public static function usesScout(): bool
{
    return auth()->user()?->prefers_scout === true;
}

public static function scoutQuery(Request $request, mixed $query): mixed
{
    return $query->where('tenant_id', $request->user()?->tenant_id);
}
```

The `public static ?int $scoutSearchResults = null;` static property caps the number of hits Scout returns. Source: `src/Resource.php` (Scout integration section).

## Authorization

Martis provides dedicated resource policies, auto-discovery, and comprehensive ability checking.

### Policy Resolution Chain

When checking authorization, Martis resolves the policy in this order:

1. **Explicit $policy property** on the Resource class
2. **Auto-discovery** by convention: `{policy_namespace}\{ResourceBaseName}Policy`
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

The matching hooks on the Resource base class are:

| Method | Policy method checked | Fallback when policy does not define it |
|---|---|---|
| `authorizedToReplicate(Request $request): bool` | `replicate` | Must pass **both** `authorizedToCreate()` AND `authorizedToUpdate()`. |
| `authorizedToRunAction(Request $request): bool` | `runAction` | Delegates to `authorizedToUpdate()`. |
| `authorizedToRunDestructiveAction(Request $request): bool` | `runDestructiveAction` | Delegates to `authorizedToDelete()`. |

Override these directly on a Resource to hardcode behaviour without writing a Policy method — see [Disabling Specific Action Buttons](#disabling-specific-action-buttons) below.

### Relationship Abilities

| Ability | Controls | Default (no method) |
|---------|----------|-------------------|
| `add{Model}` | Inline create related record | allowed |
| `attach{Model}` | Attach specific related record | allowed |
| `attachAny{Model}` | Show attach button | allowed |
| `detach{Model}` | Detach related record | allowed |

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

Use php artisan martis:policy PolicyName --model=ModelName to generate a complete policy stub. The historical `martis:make-policy` name is kept as a hidden alias for backwards compatibility.

### Disabling Specific Action Buttons

To hide a specific button (Edit, Delete, Replicate, etc.) from the UI and enforce it on the backend, override the corresponding `authorizedTo*()` method directly in your Resource class:

```php
class PostResource extends Resource
{
    // Remove the Replicate button entirely
    public function authorizedToReplicate(Request $request): bool
    {
        return false;
    }

    // Remove Force Delete (even for soft-deleted records)
    public function authorizedToForceDelete(Request $request): bool
    {
        return false;
    }

    // Remove the Edit button
    public function authorizedToUpdate(Request $request): bool
    {
        return false;
    }

    // Remove the Delete button
    public function authorizedToDelete(Request $request): bool
    {
        return false;
    }

    // Remove the Create button from the index page
    public function authorizedToCreate(Request $request): bool
    {
        return false;
    }
}
```

This approach is **enforced on both frontend AND backend** — the button is hidden and the API rejects the request with 403.

For conditional logic (e.g., only admins can delete), use a Policy instead of hardcoding false.

Available methods: `authorizedToView`, `authorizedToCreate`, `authorizedToUpdate`, `authorizedToDelete`, `authorizedToReplicate`, `authorizedToRestore`, `authorizedToForceDelete`, `authorizedToRunAction`, `authorizedToRunDestructiveAction`.

### Attachment Uploads (Trix / Markdown)

The `AttachmentController` handles file uploads from Trix and Markdown rich text editors. Allowed MIME types, storage disks, and max size are configurable via `config/martis.php`:

```php
// config/martis.php
'attachments' => [
    'allowed_mimes' => explode(',' , env('MARTIS_ATTACHMENT_MIMES', 'jpg,jpeg,png,gif,webp,svg,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip,mp4,mp3')),
    'allowed_disks' => ['public', 'local'],
    'max_size' => (int) env('MARTIS_ATTACHMENT_MAX_SIZE', 10240), // KB
],
```

To allow additional file types (e.g., `.ai`, `.psd`), either:
1. Set the `MARTIS_ATTACHMENT_MIMES` env variable with a comma-separated list
2. Or publish and edit `config/martis.php` directly

Uploads are stored under `martis-attachments/` on the selected disk.

For field-specific MIME restrictions (on File/Image fields in forms), use the `acceptedTypes()` method on the field:

```php
File::make('document')
    ->acceptedTypes(['pdf', 'doc', 'docx'])
    ->maxSize(5120);
```

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

## Exception Handling

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

// Per-field validation errors (e.g. in an Action):
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

All exceptions extend `MartisException` which itself extends `\RuntimeException`. Laravel's exception handler will catch them automatically. Unhandled `MartisException` subclasses return a 500 JSON response in production.



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
    public static function perPageOptions(): array { return [10, 25, 50]; }
    public static function defaultSort(): ?string { return 'created_at'; }
    public static function defaultSortDirection(): SortDirection { return SortDirection::Desc; }

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
