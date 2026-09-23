# Override System

The override system is a core Martis differential. **Everything can be customized without forking** — React components, layouts, and server-side behaviors.

## Overview

Martis provides a 4-tier component resolution system that allows you to replace any field component, layout, or CRUD view at different granularity levels:

| Priority | Level | Scope | Method |
|----------|-------|-------|--------|
| 1 (highest) | Explicit key | Single field instance | `->component('key')` in PHP |
| 2 | Per-resource | All instances of a field in one resource | `registerResourceFieldDisplay()` |
| 3 | Global type | All fields of a given type | `registerFieldDisplay()` |
| 4 (lowest) | Built-in default | Fallback | Pre-registered by Martis |

The registry calls below run in your consumer extension (`resources/js/martis-extensions/`), which imports `componentRegistry` from `@martis/runtime` (v1.38.0+). It is the instance the SPA resolves from, also reachable as `window.Martis.componentRegistry`, which the auto-discovery entry uses to register the four buckets. On an extension scaffolded before v1.38.0, refresh the shim first: see [Refreshing the extension scaffold after an upgrade](installation-guide.md#refreshing-the-extension-scaffold-after-an-upgrade).

## 1. Field Component Overrides

### 1.1 Global Type Override

Replace the component for **all fields** of a given type across every resource.

```typescript
// resources/js/martis-extensions/index.ts
import { componentRegistry } from '@martis/runtime'
import { MyRatingDisplay, MyRatingInput } from './components/RatingField'

// All "number" fields now use MyRatingDisplay/MyRatingInput
componentRegistry.registerFieldDisplay('number', MyRatingDisplay)
componentRegistry.registerFieldInput('number', MyRatingInput)
```

### 1.2 Per-Resource Override

Replace the component only for a specific field in a specific resource.

```typescript
// resources/js/martis-extensions/index.ts
import { componentRegistry } from '@martis/runtime'
import { StatusBadgeDisplay } from './components/StatusBadge'

// Only the "status" field in the "posts" resource uses StatusBadgeDisplay
componentRegistry.registerResourceFieldDisplay('posts', 'status', StatusBadgeDisplay)
componentRegistry.registerResourceFieldInput('posts', 'status', MyStatusInput)
```

### 1.3 Explicit Key Override (via PHP)

The most precise method — the PHP field declaration specifies which component to use. The frontend registers a component with the same key.

**PHP (Resource):**
```php
// app/Martis/PostResource.php
public function fields(Request $request): array
{
    return [
        Text::make('title'),
        Text::make('status')->component('status-badge'),   // explicit key
        Number::make('rating')->component('star-rating'),
    ];
}
```

**TypeScript:**
```typescript
// resources/js/martis-extensions/index.ts
import { componentRegistry } from '@martis/runtime'
import { StatusBadge } from './components/StatusBadge'
import { StarRating } from './components/StarRating'

componentRegistry.register('status-badge', StatusBadge)
componentRegistry.register('star-rating', StarRating)
```

### 1.4 Per-Context Overrides

Override a field component differently for each view context (index, detail, create, update):

```php
Text::make('status')
    ->overrideIndex(new Override('status-badge'))      // badge on index
    ->overrideDetail(new Override('status-detail'))     // rich view on detail
    ->overrideCreate(new Override('status-select'))     // dropdown on create
    ->overrideUpdate(new Override('status-select'));    // dropdown on update
```

## 2. Layout Overrides

Each resource can render its pages in a layout of its own (v1.38.0+). The layout wraps every page of the resource (index, lens, create, detail and update) inside the shell, so the sidebar and topbar stay, and receives the page as `children`. A resource with no registered layout renders its pages as before. Before v1.38.0 `layoutRegistry.register()` had no effect: nothing in the SPA read the registry.

```typescript
// resources/js/martis-extensions/index.ts
import { layoutRegistry } from '@martis/runtime'
import { UserResourceLayout } from './layouts/UserResourceLayout'

// The "users" resource uses a custom layout
layoutRegistry.register('users', UserResourceLayout)
```

**Layout component** (outside the four auto-discovered buckets, since the call above registers it):
```tsx
// resources/js/martis-extensions/layouts/UserResourceLayout.tsx
import type { LayoutProps } from '@martis/runtime'

export function UserResourceLayout({ children }: LayoutProps) {
  return (
    <div className="user-admin-shell">
      <UserQuickStats />
      {children}
    </div>
  )
}
```

**Built-in layout presets** (configured in `config/martis.php`):

| Preset | Description |
|--------|-------------|
| `sidebar` | Left sidebar navigation + top bar (default) |
| `topnav` | Top navigation bar |
| `minimal` | Minimal header, no sidebar |

A resource layout lives inside the shell. To replace the shell itself, for every page, register a component under `layout:shell` in the component registry, or one piece under `layout:sidebar`, `layout:topbar` or `layout:footer`: see [Shell piece-by-piece overrides](#shell-piece-by-piece-overrides).

## 3. CRUD View Overrides (Drawers)

Override how create, update, and detail views are rendered. The built-in drawer components render forms/details inside a sliding panel instead of a full page.

### PHP Configuration

```php
// app/Martis/PostResource.php
public function overrides(): array
{
    return [
        'create' => new Override('martis:drawer-create'),
        'update' => new Override('martis:drawer-update'),
        'detail' => new Override('martis:drawer-detail'),
    ];
}
```

### Custom Drawer

Register a custom component to handle a CRUD action:

```typescript
// resources/js/martis-extensions/index.ts
import { componentRegistry } from '@martis/runtime'
import { MyPostCreator } from './components/MyPostCreator'

componentRegistry.register('custom-post-creator', MyPostCreator)
```

```php
// app/Martis/PostResource.php
public function overrides(): array
{
    return [
        'create' => new Override('custom-post-creator', ['wizardMode' => true]),
    ];
}
```

### Override constructor + `redirectAfter()`

The `Override` class accepts an optional second argument for arbitrary params and exposes a `redirectAfter()` chainable method that controls where the user lands after a successful CRUD operation:

```php
use Martis\Override;
use Martis\RedirectAfter;

public function overrides(): array
{
    return [
        'create' => (new Override('custom-post-creator', ['wizardMode' => true]))
            ->redirectAfter(RedirectAfter::DETAIL),    // open the new record
        'update' => (new Override('martis:drawer-update'))
            ->redirectAfter(RedirectAfter::INDEX),     // back to the list
    ];
}
```

`RedirectAfter` enum cases: `DETAIL` · `INDEX` · `EDIT` · `CREATE` · `DASHBOARD` · `STAY`. A literal string (`'detail'`, `'index'`, …) is accepted as a fallback for the same values. `STAY` keeps the drawer / page open after save — useful for "save and continue editing" workflows. With `confirmUnsavedChanges()` on, the update drawer then counts the values it saved as clean, and what was typed while the save ran as unsaved (v1.38.0+; before v1.38.0 closing it after the save asked to discard the changes just saved). The create drawer clears its form for the next record and mounts its fields again, so no input keeps the previous record's state, and counts the empty form as clean (v1.38.0+; before v1.38.0 an input that keeps state of its own could carry the previous record's over, and a drawer opened on a copy asked to discard the empty form).

### `DrawerSlot` enum (typed slot keys)

`Resource::overrides()` returns an associative array keyed by slot. The bundled controllers accept either string keys (`'create' | 'update' | 'detail' | 'quick'`) or the typed `DrawerSlot` enum — using the enum surfaces typos at compile time:

```php
use Martis\Enums\DrawerSlot;

public function overrides(): array
{
    return [
        DrawerSlot::Create->value => DrawerOverride::create(),
        DrawerSlot::Update->value => DrawerOverride::update(),
        DrawerSlot::Detail->value => DrawerOverride::detail(),
        DrawerSlot::Quick->value  => DrawerOverride::quick(),
    ];
}
```

Both forms are accepted in the same return array — adopt the enum incrementally.

### Override Props

All override components receive the `OverrideProps` interface:

```typescript
interface OverrideProps {
  schema: ResourceSchema       // Full resource schema with fields
  resource: string             // Resource URI key
  params: Record<string, unknown>  // Custom parameters from Override()
  record?: ResourceRecord | null   // Current record (detail/update) or null (create)
  recordId?: string | null     // Record ID
  fromResourceId?: string | number | null  // Create only: the record a Replicate copies (v1.38.0+)
  navigate: (to: string) => void   // Navigation function
  onClose: () => void          // Close the drawer/panel
  onCreated: (record: { id: string | number }) => void
  onUpdated: (record: { id: string | number }) => void
  onDeleted: () => void
  onEdit: (id?: string | number) => void
  onView: (id: string | number) => void
  addToast: (type: string, message: string) => void
}
```

On a create override, `fromResourceId` names the record the form replicates: the detail page opens its create override with it for the Replicate action (together with that `record`), and `/create?fromResourceId={id}` passes it too. A custom create override reads the copy's values from `GET /api/resources/{resource}/{id}/replicate`, which applies `authorizedToReplicate()`, leaves File fields out and hides the fields the user may not see for the record, and sends the id back as `fromResourceId` with the create, so the server answers with `replicatedMessage()`. The bundled `DrawerCreate` does exactly that (v1.38.0+): it mounts its fields once the copy has arrived, shows the endpoint's error instead of a form when the record cannot be replicated, and sends `fromResourceId` with the first create only. Before v1.38.0 it copied the record's detail payload into the form (File paths and fields the replicate endpoint leaves out included, with no `authorizedToReplicate()` check), and a create override opened by `/create?fromResourceId={id}` got no copy at all.

A host can hand a mounted override another `record` / `recordId`, or another resource's `schema` and `resource`, without remounting it: `ActionDrawer` does when Edit on another index row or an action response opens another record while its drawer is open, and the detail page does when its route moves to another record behind an open update drawer. An override that keeps form state has to seed it again for the record it now receives, or render its body with a `key` built from `resource` and `recordId`. Since v1.38.0 the bundled `DrawerUpdate` seeds its values, validation errors and dirty baseline again when the resource or the record changes; a fresh copy of the same record keeps the edits. The bundled `DrawerCreate` does the same since v1.38.0 when the resource or the record it replicates changes (an action response that opens another resource's create drawer while one is open, the detail page moving to another record behind an open Replicate drawer); before, it kept what was typed for the first target and created it in the second. The index page, for its part, closes its create drawer, the drawers opened from its rows or by an action, and its confirmations when its route moves to another resource's index (v1.38.0+); before, they stayed open, and the delete confirmation of a row then deleted the record with the same id in the new resource. The detail page keeps its drawers on the record in the URL but closes its delete, force-delete and restore confirmations and its action modal when that record changes, and a lens page starts over for each lens (v1.38.0+); before, confirming the delete opened for one record deleted the record the page had moved to.

### Built-in Drawer Components

| Component | Key | Description |
|-----------|-----|-------------|
| DrawerCreate | `martis:drawer-create` | Slide-in create form |
| DrawerUpdate | `martis:drawer-update` | Slide-in edit form |
| DrawerDetail | `martis:drawer-detail` | Slide-in detail view |
| DrawerQuick | `martis:drawer-quick` | ⭐ Lightweight read-only quick-look (narrower, no actions). Distinct from the BelongsTo / MorphTo hover **peek** popover, which surfaces *related-record* metadata; `DrawerQuick` is for the current row. |

`DrawerDetail`, `DrawerUpdate` and `DrawerQuick` tell the relationship panels inside them which record they belong to, since the page behind the drawer may not name it, and `DrawerCreate` tells them its record does not exist yet (see [relationships.md § Which record a panel belongs to](relationships.md#which-record-a-panel-belongs-to)). A custom override does the same through `@martis/runtime`: see [Naming the record of the relationship panels](#naming-the-record-of-the-relationship-panels-v1380).

### ⭐ Reading override props from nested components — `useOverrideProps()`

When a custom override has its own internal component tree (header, sidebar, form sections), prop-drilling `OverrideProps` through every level is noisy. The `useOverrideProps()` hook exposes the same payload via React context — wrap once at the top of your override, read anywhere underneath:

```tsx
import { useOverrideProps, OverridePropsProvider, type OverrideProps } from '@martis/runtime' // v1.38.0+

export function MyDrawerCreate(props: OverrideProps) {
  return (
    <OverridePropsProvider value={props}>
      <MyHeader />
      <MyForm />
    </OverridePropsProvider>
  )
}

function MyHeader() {
  // No props passed — pulls from context
  const { schema, onClose } = useOverrideProps()
  return (
    <header>
      <h2>{schema.singularLabel}</h2>
      <button onClick={onClose}>×</button>
    </header>
  )
}
```

The hook **throws** outside the provider so wiring bugs are loud. Use `useOverridePropsOptional()` (returns `null`) when an override component is shared between contexts where the provider may not exist.

The provider is opt-in — overrides that pass `props` manually keep working unchanged. It is the override's own: the package mounts none around the components it renders.

**Drawer features:**
- Slide-in animation from left/right
- Expand/collapse toggle
- Fullscreen mode
- Close on ESC or backdrop click
- Responsive grid layout with field column spans
- Browser back button closes the drawer (history integration)
- Optional unsaved-changes confirmation — see [Unsaved changes guard](#unsaved-changes-guard)

### Unsaved changes guard

Both drawer-based create/update and the full-page `ResourceCreate` /
`ResourceUpdate` routes can warn the user before discarding unsaved
edits. The guard intercepts:

- The drawer close button, backdrop click and ESC
- The browser back button
- Clicks on breadcrumb / in-app router links

Tab close and hard reload are not intercepted: the guard wires no
`beforeunload` prompt, which doubled up with its own dialog.

On the full-page routes the guard holds the browser back button only
while the form has unsaved changes (v1.38.0+). A clean form leaves the
history alone, so Back and Forward move between pages as on any other
page; once the user has typed something, Back asks first, and leaving
the page after that can drop the Forward history, as editing a page does
in any browser. Before v1.38.0 every visit to a create or edit page
added an entry to the history, which erased the pages the user could go
Forward to, and a clean form could make Back skip the previous page.

Opt in via `Resource::confirmUnsavedChanges()`:

```php
use Martis\Contracts\UnsavedChangesConfigContract;
use Martis\UnsavedChangesConfig;

// Enable with the package defaults (localised copy).
public static function confirmUnsavedChanges(): bool|UnsavedChangesConfigContract
{
    return true;
}

// OR fully customised copy + visuals.
public static function confirmUnsavedChanges(): bool|UnsavedChangesConfigContract
{
    return UnsavedChangesConfig::make()
        ->title(__('projects.unsaved_title'))
        ->body(__('projects.unsaved_body'))
        ->icon('briefcase')
        ->iconColor('info')        // success | warning | danger | info | muted | accent
        ->confirmLabel(__('projects.unsaved_discard'))
        ->confirmColor('danger')
        ->cancelLabel(__('projects.unsaved_keep'));
}
```

**Default:** the method returns `false` on the base Resource — resources
opt in explicitly. Return `true` to use the localised defaults shipped
with the package, or an `UnsavedChangesConfig` instance to customise
every piece of copy and colour. `icon(null)` hides the dialog icon.

### DrawerOverride PHP API

Use the `DrawerOverride` class for chainable PHP configuration of the built-in drawers. Three static factories spin up a pre-keyed override — pick the one that matches the slot:

| Factory | Slot key it emits | Drawer component |
|---------|-------------------|------------------|
| `DrawerOverride::create()` | `martis:drawer-create` | DrawerCreate |
| `DrawerOverride::update()` | `martis:drawer-update` | DrawerUpdate |
| `DrawerOverride::detail()` | `martis:drawer-detail` | DrawerDetail |

```php
use Martis\Enums\DrawerPosition;

public function overrides(): array
{
    return [
        'create' => DrawerOverride::create()
            ->width('600px')
            ->expandedWidth('900px')
            ->allowExpand()
            ->allowFullscreen()
            ->position(DrawerPosition::Right)
            ->showCloseButton()
            ->backdrop(false)
            ->subtitle('Fill in the details below')
            ->showIcon()
            ->iconColor('#6366f1'),

        'update' => DrawerOverride::update()
            ->width('720px')
            ->allowExpand()
            ->subtitle('Editing this record'),

        'detail' => DrawerOverride::detail()
            ->position(DrawerPosition::Left)
            ->backdrop(true),
    ];
}
```

All three factories return a `DrawerOverride` instance, so every chainable method below is shared.

**Available methods:**

| Method | Signature | Description |
|--------|-----------|-------------|
| `width()` | `width(string $width)` | Set the collapsed drawer width (CSS value, e.g. `'40rem'`). Default: `720px` (configurable via `config('martis.drawer.width')`). |
| `expandedWidth()` | `expandedWidth(string $width)` | Set the expanded drawer width. Default: `960px` (configurable via `config('martis.drawer.expanded_width')`). |
| `allowExpand()` | `allowExpand(bool $value = true)` | Show the expand/collapse toggle button. |
| `allowFullscreen()` | `allowFullscreen(bool $value = true)` | Show the fullscreen toggle button. |
| `showCloseButton()` | `showCloseButton(bool $value = true)` | Show the close (×) button in the header. |
| `position()` | `position(DrawerPosition $position)` | Slide in from `DrawerPosition::Right` (default) or `DrawerPosition::Left`. |
| `backdrop()` | `backdrop(bool $value = true)` | Show a dimmed backdrop behind the drawer. |
| `subtitle()` | `subtitle(string $subtitle)` | Display a subtitle below the drawer title. |
| `showIcon()` | `showIcon(bool\|string $value = true)` | Show an icon in the header. Pass a Phosphor icon name to use a specific icon. |
| `iconColor()` | `iconColor(string $color)` | Set the header icon color (any CSS color value). |


## 4. Server-Side Hooks

Lifecycle hooks execute custom logic before/after CRUD operations.

### Resource Hooks

```php
// app/Martis/PostResource.php
public function beforeSave(Model $model, Request $request, bool $creating): void
{
    $model->slug = Str::slug($model->title);
    parent::beforeSave($model, $request, $creating); // fires BeforeSave event
}

public function afterSave(Model $model, Request $request, bool $creating): void
{
    Cache::forget("post:{$model->id}");
    parent::afterSave($model, $request, $creating); // fires AfterSave event
}

public function beforeDelete(Model $model, Request $request): void
{
    if ($model->status === 'published') {
        throw new \RuntimeException('Cannot delete published posts.');
    }
    parent::beforeDelete($model, $request); // fires BeforeDelete event
}
```

### Event Listeners

Events are dispatched automatically when hooks call `parent::hookName()`. Use listeners for cross-cutting logic:

```php
// app/Providers/AppServiceProvider.php
use Martis\Events\AfterSave;
use Martis\Events\BeforeDelete;

Event::listen(AfterSave::class, function (AfterSave $event) {
    AuditLog::record('saved', $event->resourceClass, $event->model->id, $event->creating);
});

Event::listen(BeforeDelete::class, function (BeforeDelete $event) {
    if ($event->model->is_protected) {
        throw new \RuntimeException('Record is protected.');
    }
});
```

| Event | Trigger | Properties |
|-------|---------|------------|
| `BeforeSave` | Before `$model->save()` | `resourceClass`, `model`, `request`, `creating` |
| `AfterSave` | After `$model->save()` | `resourceClass`, `model`, `request`, `creating` |
| `BeforeDelete` | Before `$model->delete()` | `resourceClass`, `model`, `request` |
| `AfterDelete` | After `$model->delete()` | `resourceClass`, `model`, `request` |

## 5. Component Interface

### Display Component

Every display component receives `FieldDisplayProps`. Examples below use the [Tailwind preset](theming.md#-in-tsx-tailwind-preset) so the override stays in sync with the active theme (light/dark, accent override, density).

```tsx
import type { FieldDisplayProps } from '@martis/runtime'

export function StatusBadge({ field, value }: FieldDisplayProps) {
  const label = String(value ?? '')
  // Map domain status to a semantic Martis tone.
  const tone = label === 'published' ? 'success' : 'info'

  return (
    <span
      className={
        'inline-flex items-center rounded-martis-full px-2 py-0.5 text-martis-xs font-martis-medium ' +
        (tone === 'success'
          ? 'bg-martis-success-bg text-martis-success'
          : 'bg-martis-info-bg text-martis-info')
      }
    >
      {label}
    </span>
  )
}
```

### Input Component

Every input component receives `FieldInputProps`:

```tsx
import type { FieldInputProps } from '@martis/runtime'

export function StatusSelect({ field, value, onChange, error }: FieldInputProps) {
  return (
    <div className="flex flex-col gap-1">
      <select
        value={String(value ?? '')}
        onChange={(e) => onChange(e.target.value)}
        className={
          'rounded-martis-md bg-martis-input-bg text-martis-text px-2 py-1 ' +
          (error ? 'border border-martis-danger' : 'border border-martis-border')
        }
      >
        <option value="draft">Draft</option>
        <option value="published">Published</option>
      </select>
      {error && <small className="text-martis-danger">{error}</small>}
    </div>
  )
}
```

If you opted out of the Tailwind preset, the same effect works with inline styles (`style={{ color: 'var(--martis-danger)' }}`) or the bundled helper classes (`.martis-text`, `.martis-border`). Either way, **don't hard-code colours like `bg-red-500`** — they don't follow the active theme.

**An input whose value holds other values** (rows, items with fields of their own) also receives `nestedErrors`: the server errors of the values inside its value, keyed by their path below the field's attribute. A 422 on the `name` field of row 1 of a `lines` Repeater (`lines.1.fields.name`) reaches the `lines` input as `nestedErrors = { '1.fields.name': 'The Name field is required.' }`, while `error` keeps the field's own message. The bundled `Repeater` shows each under the matching row field. An input that renders other inputs passes each child its own error and the entries below `child.`, so a nested input gets its errors too. Every bundled form and `useMartisForm().fieldProps()` fill the prop (since v1.38.0); an input with a scalar value can ignore it.

**`value` can change after the input mounts.** The edit forms (the update page and the update drawer) mount the fields once the record has filled the form, so an input gets the stored value on its first render. When the form moves to another record (the update page follows the record in the URL, the update drawer the record its host hands it), the fields mount again with that record's values, and the create page does the same when it moves to another resource, parent or record to replicate, so no input state carries from one form to the next. "Create & add another" mounts the fields again as well, with the empty values of the next record (v1.38.0+; before v1.38.0 it cleared the values under the mounted inputs, and an input that could not tell the cleared value from its own last one carried the previous record's state over). The replicate form, too, mounts its fields once the copy has filled it (v1.38.0+; before v1.38.0 the copy arrived one render after the fields mounted). The value can still change under a mounted input: a nested create form fills its parent in once the parent has loaded, and a Tool form can replace its values with `setValues()`. Render from `value` where you can. An input that keeps its own state (rows, a selection, a preview) has to adopt a `value` it did not emit itself, and keep its state when the form hands back what it just emitted:

```typescript
import { useEffect, useRef, useState } from 'react'

const toChips = (v: unknown): string[] => (Array.isArray(v) ? v.map(String) : [])

export function ChipsInput({ value, onChange }: FieldInputProps) {
  const [chips, setChips] = useState(() => toChips(value))
  // The last value this input handed to `onChange`.
  const emitted = useRef<unknown>(value)

  useEffect(() => {
    if (value === emitted.current) return // the form handing back our own value
    emitted.current = value // a value from outside: the record, a cleared form
    setChips(toChips(value))
  }, [value])

  function emit(next: string[]) {
    emitted.current = next
    setChips(next)
    onChange(next)
  }

  // ... render `chips`, call `emit(...)` on every edit
}
```

The bundled `KeyValue`, `Tag`, `Avatar`, `BelongsTo`, `Repeater` and (v1.38.0+) `Sparkline` inputs follow this pattern, and `Slug` uses it to follow its source again once the form clears it. When the fields inside your input can emit while they mount (a slug generating itself from a default), compare during render instead of in an effect, as `Repeater` does: child effects run before the parent's.

The comparison only sees a value that changes. A form cleared back to the very value your input emitted last (`null` after its own Clear button) hands it nothing new, so keep no state that `value` does not carry, or that the rest of the form can tell you about: a slug the user emptied by hand is `null` before and after the form is cleared, and `Slug` follows its source again once the source is empty too (v1.38.0+).

Several of those fields can also emit before the form hands your value back (on an edit form, every stored row whose slug generates itself from its source), and each of their handlers still sees the `value` of your last render, so an update built on that `value` drops the updates before it. Build each update on the value you emitted last, until the form hands you a new one, as `Repeater` does since v1.38.0. Tell the two apart with a token for each `value` your input receives, not with the value itself: a form cleared back to `null` hands an input that started empty the very value its first emission started from, and the comparison would take the cleared form for one that has not handed anything back yet. Before v1.38.0 this snippet compared the value, and so did `Repeater`, which brought the previous record's rows back into the next one after "Create & add another":

```typescript
import { useMemo, useRef } from 'react'

type Row = Record<string, unknown>
const toRows = (v: unknown): Row[] => (Array.isArray(v) ? v.map((row) => ({ ...row })) : [])

// Next to `emitted`: a token for each `value` the form hands in, and the
// token of the value the last emission started from.
const valueToken = useMemo(() => ({ value }), [value])
const emittedFrom = useRef<object | null>(null)

function emit(next: Row[]) {
  emittedFrom.current = valueToken
  emitted.current = next
  onChange(next)
}

// A fresh copy of the rows the next update builds on.
const latestRows = () => toRows(valueToken === emittedFrom.current ? emitted.current : value)

function setRowField(index: number, attribute: string, fieldValue: unknown) {
  const next = latestRows()
  next[index] = { ...next[index], [attribute]: fieldValue }
  emit(next)
}
```

## 5.A Composing native field components (v1.14.0+)

The two sections above show how to **replace** a field renderer. The opposite direction — **composing** the canonical Martis field renderer from inside your own custom component (a custom Action component, a Tool, a Card) — is supported via the consumer-extension runtime.

Since v1.14.0, `@martis/runtime` exposes:

| Export | Shape |
|---|---|
| `FieldInput` | The form-mode renderer. Receives the same `FieldInputProps` documented in section 5. |
| `FieldDisplay` | The read-mode renderer. Receives `FieldDisplayProps`. |
| `FieldDefinition` (type) | Re-exported so you can type your `field` payload without reaching into internal paths. |
| `FieldDisplayProps`, `FieldInputProps` (types) | Re-exported for the same reason. |
| `DrawerShell` | Generic slide-over drawer shell. Host edit/add/detail forms (composed from `FieldInput`) in a native drawer; you control open/close from your own state, like a modal. |
| `DrawerShellProps` (type) | Props for `DrawerShell`: `title`, `subtitle?`, `icon?`, `onClose`, `children`, … |
| `Tooltip` | The PrimeReact `Tooltip` component, for React content in a tooltip (JSX `content`), since the extension build doesn't alias `primereact`. The global `[data-pr-tooltip]` provider renders plain text, or markup you write when the trigger sets `data-pr-tooltip-html="true"` (unsanitised): see [Tooltip Standard](components.md#tooltip-standard-primereact). |
| `Dropdown`, `MultiSelect` (v1.29.0) | The exact PrimeReact controls Martis's own filters use. Apply the `martis-filter-dropdown` class for the compact filter look. Lets a Tool render pixel-identical single/multi filters without bundling a second copy of PrimeReact. |
| `createPortal` (v1.29.0) | `react-dom`'s `createPortal`, for overlays: the host's, so the portal renders with the host's React DOM. Since v1.38.0 `import { createPortal } from 'react-dom'` reaches the same function: the extension build sends `react-dom` to a shim that carries it and nothing else of `react-dom`. |
| `DropdownProps`, `MultiSelectProps` (types) | Re-exported so you can type the controls above without reaching into `primereact/*`. |
| `NestedParentProvider` (v1.38.0) | Names the record whose related records the relationship panels inside list, when the page URL does not name it; `id: null` on a create form. See [Naming the record of the relationship panels](#naming-the-record-of-the-relationship-panels-v1380). |
| `NestedParent` (type) | The provider's `value`: `{ resource: string; id: string \| number \| null }`. |

Import each one by name from `@martis/runtime`, which your extension build resolves to `resources/js/martis-extensions/.shims/runtime.mjs`. `martis:install` publishes that file once and `composer update` does not refresh it, so on an extension scaffolded before the version that added a name the build fails with `"Dropdown" is not exported by "resources/js/martis-extensions/.shims/runtime.mjs"`: see [Refreshing the extension scaffold after an upgrade](installation-guide.md#refreshing-the-extension-scaffold-after-an-upgrade). Their types come with the scaffold (`.shims/runtime.d.mts`, v1.38.0): see [Type-checking your extensions](installation-guide.md#type-checking-your-extensions).

### Example — Select inside a custom Action component

```tsx
import { useState } from 'react'
import { FieldInput } from '@martis/runtime'

const docTypeField = {
    type: 'select',
    attribute: 'document_type',
    label: 'Document type',
    options: [
        { value: 'note', label: 'Note' },
        { value: 'transcript', label: 'Transcript' },
        { value: 'screenshot', label: 'Screenshot' },
    ],
}

export function CandidateReview() {
    const [docType, setDocType] = useState<string>('note')
    return (
        <div className="martis-action-form">
            <FieldInput
                field={docTypeField as any}
                value={docType}
                onChange={(v) => setDocType(v as string)}
            />
        </div>
    )
}
```

The component you receive is **the real Martis renderer** — same look, same behaviour, same i18n. No duplicate CSS, no rebuilt search, no drift over time. If a host project has registered an override for `select`, `FieldInput` will resolve to the override for free.

> **Need a whole form, not a single field?** For *form-level* behaviour inside a Tool — shared `values` state across fields, the `dependsOn` sync, per-field error clearing, and the `tab_group`/`section`/`panel` render loop — use the `useMartisForm` + `FieldsForm` harness (and optionally declare the fields in PHP with `Tool::fields()`). See [tool-fields.md](tool-fields.md). The two caveats below apply there too.

### Example — a Tool hosting fields in a native drawer

`DrawerShell` is the bare, resource-agnostic slide-over used by the built-in
`martis:drawer-*` wrappers. A Tool can mount it directly to host its own
edit/add/detail form composed from `FieldInput`, controlling open/close from
its own state:

```tsx
import { useState } from 'react'
import { DrawerShell, FieldInput } from '@martis/runtime'

export function ReviewTool() {
    const [open, setOpen] = useState(false)
    const [docType, setDocType] = useState('note')
    if (!open) return <button onClick={() => setOpen(true)}>Review</button>
    return (
        <DrawerShell title="Review candidate" onClose={() => setOpen(false)}>
            <div className="martis-action-form">
                <FieldInput
                    field={{ type: 'select', attribute: 'document_type', label: 'Document type', options: [] } as any}
                    value={docType}
                    onChange={(v) => setDocType(v as string)}
                />
            </div>
        </DrawerShell>
    )
}
```

### Example — a filter that matches the native look (v1.29.0)

A Tool that renders its own filter bar can reach for the same `Dropdown` /
`MultiSelect` Martis's built-in filters use, instead of hand-replicating
PrimeReact's DOM. Add the `martis-filter-dropdown` class for the compact look:

```tsx
import { useState } from 'react'
import { Dropdown } from '@martis/runtime'

export function StatusFilter() {
    const [status, setStatus] = useState<string | null>(null)
    return (
        <Dropdown
            className="martis-filter-dropdown"
            value={status}
            options={[
                { label: 'Active', value: 'active' },
                { label: 'Archived', value: 'archived' },
            ]}
            onChange={(e) => setStatus(e.value)}
            placeholder="Status"
            showClear
        />
    )
}
```

`createPortal` (exported by `@martis/runtime`, and since v1.38.0 by the extension's
`react-dom` shim, which carries nothing else of `react-dom`) is available for
overlays that must escape a clipped or `overflow: hidden` container.

### Naming the record of the relationship panels (v1.38.0+)

A relationship field rendered through `FieldDisplay` or `FieldInput` (a
`HasMany`, `MorphMany`, `BelongsToMany` or `MorphToMany` panel, a `HasOne` /
`MorphOne` card) lists the related records of the record it belongs to: the
nearest record named with `NestedParentProvider`, else the record in the page
URL (`/resources/{resource}/{id}`). The bundled drawers and cards name theirs
(see [relationships.md § Which record a panel belongs to](relationships.md#which-record-a-panel-belongs-to)).
A custom override that shows a record the URL may not name (a drawer an
action, a lens row or an index row opens, a card of another record) names it
the same way:

```tsx
import { FieldDisplay, NestedParentProvider } from '@martis/runtime'
import type { FieldDefinition, OverrideProps } from '@martis/runtime'

export function ProjectSummaryDrawer({ schema, resource, record, recordId }: OverrideProps) {
    const fields = (schema.fieldsForDetail ?? []) as FieldDefinition[]
    return (
        <NestedParentProvider value={{ resource, id: recordId ?? null }}>
            {fields.map((field) => (
                <FieldDisplay
                    key={field.attribute}
                    field={field}
                    value={record?.[field.attribute] ?? null}
                    resourceKey={resource}
                    context="detail"
                />
            ))}
        </NestedParentProvider>
    )
}
```

Without the provider the panels read the page's record: on
`/resources/team-members/2`, a drawer showing project 3 would ask
`/api/resources/team-members/2/has-many/tasks` for the project's tasks (404,
or team member 2's own rows when team members declare the same relationship).

A custom create override names no record, since it does not exist yet: pass
`id: null`. A `BelongsToMany` / `MorphToMany` panel with no record renders
nothing and asks nothing (the schema keeps both fields off create forms
anyway). Without the provider, a create override opened over a
record's page (a Replicate, an action response) would hand its panels that
record.

```tsx
<NestedParentProvider value={{ resource, id: null }}>
    {/* the create form */}
</NestedParentProvider>
```

The nearest provider wins, so a provider inside another one (a create modal
opened from a record drawer) names the record of its own subtree. Type the
`value` with the re-exported `NestedParent`.

An extension scaffolded before v1.38.0 has a `.shims/runtime.mjs` without the
`NestedParentProvider` export: see [Refreshing the extension scaffold after an
upgrade](installation-guide.md#refreshing-the-extension-scaffold-after-an-upgrade).

### Caveats

**1. Relation pickers take their scope from the props you pass.**

`BelongsTo`, `MorphTo` and `Tag` load their options from `/api/resources/{resource}/{id}/relatable/{attribute}`. `{resource}` is `resourceKey`, else the resource of the page. `{id}` is `recordId`; without one, `context="create"` sends `_` (the create forms), and any other input uses the page's record only when it is scoped to the page's own resource (`_` otherwise). In a custom Action component, pass `actionEndpoint` so the pickers read the Action's own declaration of the field:

```tsx
<FieldInput
    field={field}
    value={values[field.attribute] ?? null}
    onChange={(v) => setValue(field.attribute, v)}
    context="create"
    actionEndpoint={`/api/resources/${resource}/actions/${action.uriKey}`}
/>
```

A custom modal that renders a relationship's pivot fields passes `pivotEndpoint` the same way: `/api/resources/{resource}/{id}/{belongs-to-many|morph-to-many}/{relationship}/pivot-fields` to attach, plus `/{relatedId}` to edit the pivot row of an attached record (v1.38.0+). A custom container that renders the fields of a Repeater row passes `repeaterRow={{ repeater: '<Repeater attribute>', repeatable: '<row type>' }}` next to the form's own scope, so the pickers and the remote `Select` search name the row the server reads the field from (see [Repeater → Relation pickers and remote selects in rows](repeater.md#relation-pickers-and-remote-selects-in-rows)).

With no resource at all (a Tool page without `resourceKey`), the input falls back to the context-free `/api/resources/_/_/relatable/{attribute}?related_resource={uriKey}`, so the `FieldDefinition` must carry `relatedResource` (the target resource's `uriKey`) for the server to resolve the relatable query. For pure enum dropdowns prefer `select`: it has no async dependency and works anywhere.

**2. Consumer bundles hosted outside the Martis shell need the published stylesheet.**

The field components rely on the `martis-*` class namespace defined in the package's stylesheet, which the host SPA already loads. If your extension renders inside Martis pages (the normal case — registered via `componentRegistry`), you inherit the styles for free. If your bundle runs in a context outside the shell, ensure `vendor/martis/assets/app-*.css` is also loaded.

### Why this layer, not the individual `*FieldInput` components

`FieldInput` is a stable single entry point that resolves through `componentRegistry` — overrides, lazy-loading, per-context resolution all keep working. Exposing each of the 46 internal `*FieldInput` components would freeze them as part of the public contract and prevent refactor. Reach for `FieldInput` first; open an issue if the routing layer is the wrong shape for your use case.

## 6. Creating Custom Components (Artisan)

Use the `martis:component` artisan command to scaffold an override TSX (alias: `martis:override`, kept for back-compat). Each `--type` option writes a different starter — see the [stubs directory on GitHub](https://github.com/Real-Edge-FX/martis-package/tree/main/stubs) for the full source of every scaffold.

> **Auto-registration scope (v1.10.1+)**
>
> Every `--type` value is now zero-config. The bundle's auto-discovery loop covers two paths:
>
> **(a) Canonical layout / auth slots** — fixed filename → key map:
>
> | Filename | Registered key |
> |---|---|
> | `Shell.tsx` | `layout:shell` |
> | `Sidebar.tsx` | `layout:sidebar` |
> | `Topbar.tsx` | `layout:topbar` |
> | `Footer.tsx` | `layout:footer` |
> | `LoginPage.tsx` | `auth:login` |
> | `RegisterPage.tsx` | `auth:register` |
> | `ForgotPasswordPage.tsx` | `auth:forgot-password` |
> | `ResetPasswordPage.tsx` | `auth:reset-password` |
> | `EmailVerifyNoticePage.tsx` | `auth:email-verify-notice` |
> | *(no fixed-filename scaffold yet)* | `auth:invitation-accept` |
>
> The SPA router resolves these hard-coded strings, so the map cannot be filename-derived. `auth:invitation-accept` has no `martis:component --type=` scaffold yet — register a replacement under this key directly from your extension bundle (`componentRegistry.register('auth:invitation-accept', MyScreen)`); the resolution mechanism is identical to the scaffolded slots above, it just skips the auto-discovery step. See [invitations.md § Overriding the screen](invitations.md#overriding-the-screen).
>
> If you only need to change copy, not markup, every page above (including the invitation-accept screen) also supports the lighter-weight `config('martis.auth.copy.*')` override — for invitation-accept, `config.auth.copy.invitation_accept.title` / `.subtitle` — before reaching for a full component override. See [authentication.md § Customising the auth copy](authentication.md#customising-the-auth-copy).
>
> **(b) Generic / field-shape overrides** — filename derives the key:
>
> | Filename | Registered key(s) | Export shape |
> |---|---|---|
> | `StatusBadge.tsx` | `status-badge` | default export → single component |
> | `StatusBadge.tsx` (with named `Display` + `Input`) | `status-badge` + `status-badge-input` | field-shape pair |
> | `RichBio.tsx` | `rich-bio` | default export → single component |
>
> The PHP `Override('status-badge')` / `Override('status-badge-input')` strings match what `martis:component --type=field StatusBadge` produces. No manual `OVERRIDE_KEYS` extension required since v1.10.1.
>
> **Recommendation**: when you want a brand-new field type with matching PHP class, prefer `martis:field <Name>` — that generator writes the PHP Field subclass too and routes the override through the bundle's `fields/` bucket (key namespace `field:{kebab}` instead of the bare `{kebab}`). Use `martis:component --type=field` only when you want to override the visual of an existing field (Text, Select, etc.) without introducing a new PHP field.

| `--type` | Stub source |
|----------|-------------|
| `field` | [`stubs/component-field.tsx.stub`](https://github.com/Real-Edge-FX/martis-package/blob/main/stubs/component-field.tsx.stub) |
| `shell` | [`stubs/component-shell.tsx.stub`](https://github.com/Real-Edge-FX/martis-package/blob/main/stubs/component-shell.tsx.stub) |
| `sidebar` | [`stubs/component-sidebar.tsx.stub`](https://github.com/Real-Edge-FX/martis-package/blob/main/stubs/component-sidebar.tsx.stub) |
| `topbar` | [`stubs/component-topbar.tsx.stub`](https://github.com/Real-Edge-FX/martis-package/blob/main/stubs/component-topbar.tsx.stub) |
| `footer` | [`stubs/component-footer.tsx.stub`](https://github.com/Real-Edge-FX/martis-package/blob/main/stubs/component-footer.tsx.stub) |
| `generic` | [`stubs/component-generic.tsx.stub`](https://github.com/Real-Edge-FX/martis-package/blob/main/stubs/component-generic.tsx.stub) |
| `login-page` | [`stubs/component-login-page.tsx.stub`](https://github.com/Real-Edge-FX/martis-package/blob/main/stubs/component-login-page.tsx.stub) |
| `register-page` | [`stubs/component-register-page.tsx.stub`](https://github.com/Real-Edge-FX/martis-package/blob/main/stubs/component-register-page.tsx.stub) |
| `forgot-password-page` | [`stubs/component-forgot-password-page.tsx.stub`](https://github.com/Real-Edge-FX/martis-package/blob/main/stubs/component-forgot-password-page.tsx.stub) |
| `reset-password-page` | [`stubs/component-reset-password-page.tsx.stub`](https://github.com/Real-Edge-FX/martis-package/blob/main/stubs/component-reset-password-page.tsx.stub) |
| `email-verify-notice-page` | [`stubs/component-email-verify-notice-page.tsx.stub`](https://github.com/Real-Edge-FX/martis-package/blob/main/stubs/component-email-verify-notice-page.tsx.stub) |
| `card` | [`stubs/component-card.tsx.stub`](https://github.com/Real-Edge-FX/martis-package/blob/main/stubs/component-card.tsx.stub) |

```bash
php artisan martis:component StatusBadge --type=field
php artisan martis:component AcmeShell --type=shell
php artisan martis:component AcmeSidebar --type=sidebar
php artisan martis:component AcmeTopbar --type=topbar
php artisan martis:component AcmeFooter --type=footer
php artisan martis:component InfoPanel --type=generic

# Generate all four shell pieces at once (prefix defaults to "Custom"):
php artisan martis:component --type=complete-layout
# → CustomShell.tsx, CustomSidebar.tsx, CustomTopbar.tsx, CustomFooter.tsx

# Or pass a prefix to namespace them:
php artisan martis:component Acme --type=complete-layout
# → AcmeShell.tsx, AcmeSidebar.tsx, AcmeTopbar.tsx, AcmeFooter.tsx
```

The command:
1. Creates the React component file at `resources/js/martis-extensions/overrides/{ClassName}.tsx`.
2. For shell pieces and auth pages, the file is published with the canonical fixed filename (`Shell.tsx`, `Sidebar.tsx`, `LoginPage.tsx`, etc.) so the bundle's `OVERRIDE_KEYS` map registers it automatically. The user-supplied `name` argument is ignored for these fixed slots — they exist exactly once each.
3. For `--type=generic` and `--type=field` the user-supplied name becomes the filename; the bundle's auto-discovery loop derives `{kebab(name)}` (and `{kebab(name)}-input` for the field-shape pair) and registers each half automatically. v1.10.1+.
4. Shell stubs document the exact props the shell injects (collapsed state, mobile drawer callbacks, navigation payload from `/api/navigation`) so you can skip reading the source. The sidebar stub types that payload with `NavigationGroup` / `NavigationItem` from `@martis/runtime` and lists the items of a nested menu group (`type: 'group'`) under its label (v1.38.0; the v1.9.3 stub drew such a group as a link).

> **`martis:component --type=field` only scaffolds TSX.** To create a brand-new field type with matching PHP class + React display/input, use `php artisan martis:field <Name>` instead — that command writes both `app/Martis/Fields/<Name>Field.php` and `resources/js/martis/fields/<name>.tsx`. Use `martis:component --type=field` when you just want to override the *visual* of an existing field (Text, Badge, etc.) without introducing a new PHP field.

**Arguments:**

| Argument | Required | Description |
|----------|----------|-------------|
| `name` | Required for all types except `complete-layout` | PHP `StudlyCase` name. Becomes the React export name and — kebab-cased — the registry key for field/generic types. For `complete-layout` it becomes the prefix (e.g. `Acme` → `AcmeShell`, `AcmeSidebar`, …). Optional for `complete-layout`; defaults to `Custom`. |
| `--type` | Optional, defaults to `generic` | See table below. |
| `--force` | Optional | Overwrite an existing file. Without it, the command aborts when the destination already exists. |

**Component types (--type):**

| Type | What it generates | Registry key |
|------|-------------------|--------------|
| `field` | Display + Input pair for overriding a field visual | `{kebab-name}` + `{kebab-name}-input` |
| `shell` | Entire shell replacement (composes sidebar + topbar + content + footer) | `layout:shell` |
| `sidebar` | Left nav column only | `layout:sidebar` |
| `topbar` | Top bar only | `layout:topbar` |
| `footer` | Page footer only | `layout:footer` |
| `complete-layout` | All four shell pieces at once (shell + sidebar + topbar + footer), each under its default key | `layout:shell`, `layout:sidebar`, `layout:topbar`, `layout:footer` |
| `login-page` | Custom login page (replaces the bundled one) | `auth:login-page` |
| `register-page` | Custom registration page | `auth:register-page` |
| `forgot-password-page` | Custom "forgot password" page | `auth:forgot-password-page` |
| `reset-password-page` | Custom "reset password" page | `auth:reset-password-page` |
| `email-verify-notice-page` | Custom email verification notice page | `auth:email-verify-notice-page` |
| `generic` | Free-form component | `{kebab-name}` |

The five auth-page types follow the same wiring as the shell pieces — generate the TSX, build, and the bundled login / register / password-reset / email-verify pages are automatically replaced. See [authentication.md](authentication.md) for the broader auth customisation surface (backend handlers, blade templates, OAuth providers).

After creating a component, rebuild the consumer extension bundle:

```bash
npm run build:extensions
```

That runs `vite build --config vite.extensions.config.ts`, which is published into your app by `martis:install`. The bundle is emitted at `public/vendor/martis-user/extensions.js` and Martis loads it at runtime through the URL listed in `MARTIS_EXTENSIONS` (also set by `martis:install`). The auto-discovery entry walks the four buckets under `resources/js/martis-extensions/` and registers every `.tsx` against `window.Martis.componentRegistry` — no manual `boot.ts`, no `MARTIS_USER_DIR`, no symlink.

### Shell piece-by-piece overrides

Replace any of the three shell pieces (`Sidebar`, `Topbar`, `Footer`) without touching the rest. Two equivalent wiring options — pick the one that matches your workflow:

**Option A — JS boot file** (register directly under the default key):

```typescript
// resources/js/martis-extensions/index.ts
import { componentRegistry } from '@martis/runtime'
import { MyTopbar } from './components/MyTopbar'
import { MyFooter } from './components/MyFooter'

componentRegistry.register('layout:topbar', MyTopbar)
componentRegistry.register('layout:footer', MyFooter)
```

**Option B — PHP config + any registry key** (useful when multiple candidates are registered and PHP owns the selection, e.g. for feature-flagged deploys):

```typescript
// resources/js/martis-extensions/index.ts
import { componentRegistry } from '@martis/runtime'
import { MyTopbar } from './components/MyTopbar'
import { MyFooter } from './components/MyFooter'

componentRegistry.register('my-topbar', MyTopbar)
componentRegistry.register('my-footer', MyFooter)
```

```php
// config/martis.php
'layout' => [
    'preset' => 'sidebar',
    'components' => [
        'shell'   => null,         // whole shell; skips grid + drawer
        'sidebar' => null,
        'topbar'  => 'my-topbar',  // the auto-discovered key
        'footer'  => 'my-footer',
    ],
],
```

Resolution precedence for each piece: `config.layout.components.<piece>` → `layout:<piece>` → bundled component.

Your replacement receives the same props the bundled component does, so the shell's state (`sidebarCollapsed`, `onToggleCollapse`, `onToggleSidebar`, `mobileOpen`, `onMobileClose`) keeps flowing. Use piece-by-piece overrides when you want to change one piece's visual design but keep the overall shell mechanics (mobile drawer, grid, collapse animation) intact.

Use `layout:shell` (or `config.layout.components.shell`) when you want to rebuild the entire layout from scratch and don't need Martis's default mobile drawer / collapse behaviour.

## Component Registry API

```typescript
import { componentRegistry } from '@martis/runtime'

// ─── Registration ──────────────────────────────────────────
// Register by key (also used for explicit `field.component` keys from PHP)
componentRegistry.register(key, component)

// Register a global field display/input by field type
componentRegistry.registerFieldDisplay(type, component)
componentRegistry.registerFieldInput(type, component)

// Register a per-resource field display/input
componentRegistry.registerResourceFieldDisplay(resource, field, component)
componentRegistry.registerResourceFieldInput(resource, field, component)

// ─── Lookup ────────────────────────────────────────────────
// Resolve a display component with the full 4-tier priority chain
componentRegistry.resolveDisplay(type, fieldName, resourceKey, explicitKey, fallback)

// Resolve an input component with the full 4-tier priority chain
componentRegistry.resolveInput(type, fieldName, resourceKey, explicitKey, fallback)

// Resolve a single component by exact key (no fallback chain)
componentRegistry.resolve(key)

// Boolean check
componentRegistry.has(key)

// List every registered key — useful in devtools console for debugging
// "why didn't my override pick up?" cases.
componentRegistry.keys()
```

`resolveDisplay` and `resolveInput` walk Tiers 1 → 4 in order (explicit key → per-resource → global type → fallback). The single-arg `resolve(key)` is the low-level lookup used by drawer / shell overrides where the consumer already knows the exact registry key.

The same instance is `window.Martis.componentRegistry`, so `window.Martis.componentRegistry.keys()` in the browser console lists every registered key without a rebuild.

## Debugging — `martis:list-overrides`

When an override does not pick up, the most common cause is a key mismatch between the PHP layer (which declares "I want a component called `<key>`") and the consumer extension bundle (`resources/js/martis-extensions/`) (which registers the actual React component under that key). The `martis:list-overrides` artisan command prints the keys the PHP layer declares: the component key of each Tool and of each Action with a custom component, and each resource's URI key:

```bash
php artisan martis:list-overrides
php artisan martis:list-overrides --kind=tool       # only Tools
php artisan martis:list-overrides --kind=action     # only Actions with Action::component()
php artisan martis:list-overrides --kind=resource   # only Resources (uri keys)
php artisan martis:list-overrides --filter=order    # substring filter on the key
php artisan martis:list-overrides --frontend        # ⭐ cross-check vs `resources/js/martis-extensions/` and flag missing registrations
```

### ⭐ `--frontend` cross-check

The `--frontend` flag adds a **Frontend** column that shows whether your extension registers each key, read statically from `resources/js/martis-extensions/`: the key each file of the four buckets registers through the auto-discovery entry (`tools/Charts.tsx` is `tool:charts`, `overrides/Sidebar.tsx` is `layout:sidebar`), and the literal key of each `register()` call on the component registry in `index.ts` (v1.38.0). A resource row shows `n/a`: the SPA renders a resource without a component of its own.

```
+----------+-----------------+----------------------------+--------------+
| Kind     | Component key   | Source                     | Frontend     |
+----------+-----------------+----------------------------+--------------+
| resource | clients         | App\Martis\ClientResource  | n/a          |
| resource | invoices        | App\Martis\InvoiceResource | n/a          |
| tool     | tool:imports    | App\Martis\Tools\Imports   | ✓ registered |
| tool     | system-status   | App\Martis\Tools\…         | ✗ missing    |
+----------+-----------------+----------------------------+--------------+
```

Exit code `2` (INVALID) when a Tool or Action key is missing, so you can wire it into CI as `php artisan martis:list-overrides --frontend || exit 1`. Pass `--extensions-dir=path/to/dir` when the extension sources live outside `resources/js/martis-extensions/`.

Only string-literal keys are read, and only from `index.ts`: a computed key (`register('field:' + kind, ...)`, a template literal with `${...}`) or a `register()` call in another module shows as missing. Check those in the browser console (below).

Sample output:

```
+----------+--------------------------+------------------------------------------+
| Kind     | Component key            | Source                                   |
+----------+--------------------------+------------------------------------------+
| resource | clients                  | App\Martis\ClientResource                |
| resource | invoices                 | App\Martis\InvoiceResource               |
| tool     | system-status            | App\Martis\Tools\SystemStatus            |
| action   | order-bulk-publish       | App\Martis\OrderResource → PublishOrders |
+----------+--------------------------+------------------------------------------+
4 component key(s) declared. Verify each Tool and Action key is registered by your extension: a TSX file
under resources/js/martis-extensions/{tools,fields,cards,overrides}/ (v1.9+ filename → key auto-discovery)
or a register() call in its index.ts. A resource needs no component.
```

The command lists what is **expected**, not what is **registered**: the actual override registry lives in the browser and cannot be introspected from PHP. Check the matching list in your frontend by running this in the browser devtools console after the SPA boots:

```js
window.Martis.componentRegistry.keys()
```

Any Tool or Action key that appears in `martis:list-overrides` but not in `componentRegistry.keys()` is one your extension does not register (a missing TSX file under `resources/js/martis-extensions/`, or a missing `register()` call), the most common reason an override fails to resolve.

## ⭐ Component Inspector — `/dev/components`

A developer-only page mounted at `/martis/dev/components` (alongside the regular admin routes) that lets you preview any registered component in isolation, fed by an editable JSON payload. The intended workflow:

1. Scaffold an override (`php artisan martis:component StatusBadge --type=field`).
2. Build the bundle (`npm run build`).
3. Open `/martis/dev/components`, pick `status-badge` from the list on the left.
4. Tweak the JSON payload (`{ field: {...}, value: 'draft' }`) and watch the component re-render on the right.
5. Iterate until the design is right — *then* go test through a real Resource page.

Distinct from the BelongsTo / MorphTo **peek** popover. Peek shows real records of a related model to end-users. The Inspector renders synthetic payloads for **developers**.

The page reads the same `componentRegistry.keys()` you'd inspect via the browser devtools console, so it surfaces every registered key — bundled drawers (`martis:drawer-*`), built-in field renderers (`field:display:text`, `field:input:select`, …), and your own overrides.

Keys the Inspector does not recognise by prefix (custom action components such as the bundled `demo-custom-action`, tools, ad-hoc overrides) are seeded with an empty `{}` payload, so a component you intend to preview there should render sensible fallbacks from missing props (the bundled demo action defaults `componentProps`, `selectedIds` and its handlers for exactly this reason). A misshapen JSON payload renders a red error box instead of crashing the inspector. The page sits inside the same authenticated shell as every other Martis admin page; restrict it via your existing middleware if you don't want non-developers landing on it.

### Environment gate

The Inspector route is **only registered when `config('martis.dev.tools_enabled')` is true**. The default resolves to `true` on `local` / `testing` environments and `false` everywhere else, so production bundles do not expose `/dev/components` at all. Force either value via env:

```dotenv
# Force enable on a staging box for a specific debugging session.
MARTIS_DEV_TOOLS=true

# Force disable even in local (e.g. recording a clean walkthrough).
MARTIS_DEV_TOOLS=false
```

Setting this is a server-side config change, so re-publish or re-bundle after editing — the bridge runs through `window.MartisConfig.dev.toolsEnabled` and the React router conditionally registers the route from there.

## Page Title Hook

Custom pages (layouts, custom resource views, dashboards built outside the default router) should set the browser tab title so navigation inside the SPA stays consistent with the server-side title on hard reload.

```tsx
import { usePageTitle } from '@martis/runtime' // v1.38.0+

export function MyCustomPage({ resource }) {
  // Passing a segment → `"${segment} · ${brand}"`.
  usePageTitle(resource.label)

  // Passing null/undefined → the bundled "brand — Admin Control" default
  // in the active locale.
  // usePageTitle(null)

  return <div>…</div>
}
```

The hook restores the previous title on unmount so stacked modals/drawers don't leave stale segments in the tab bar. See [configuration.md](configuration.md#customising-the-page-title) for the server-side half (static config + closure API).
