# Override System

The override system is Martis's competitive advantage over Nova. **Everything can be customized without forking** — React components, layouts, and server-side behaviors.

## Overview

Martis provides a 4-tier component resolution system that allows you to replace any field component, layout, or CRUD view at different granularity levels:

| Priority | Level | Scope | Method |
|----------|-------|-------|--------|
| 1 (highest) | Explicit key | Single field instance | `->component('key')` in PHP |
| 2 | Per-resource | All instances of a field in one resource | `registerResourceFieldDisplay()` |
| 3 | Global type | All fields of a given type | `registerFieldDisplay()` |
| 4 (lowest) | Built-in default | Fallback | Pre-registered by Martis |

## 1. Field Component Overrides

### 1.1 Global Type Override

Replace the component for **all fields** of a given type across every resource.

```typescript
// resources/js/martis/boot.ts
import { componentRegistry } from '@martis/martis/lib/componentRegistry'
import { MyRatingDisplay, MyRatingInput } from './components/RatingField'

// All "number" fields now use MyRatingDisplay/MyRatingInput
componentRegistry.registerFieldDisplay('number', MyRatingDisplay)
componentRegistry.registerFieldInput('number', MyRatingInput)
```

### 1.2 Per-Resource Override

Replace the component only for a specific field in a specific resource.

```typescript
// resources/js/martis/boot.ts
import { componentRegistry } from '@martis/martis/lib/componentRegistry'
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
// resources/js/martis/boot.ts
import { componentRegistry } from '@martis/martis/lib/componentRegistry'
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

Each resource can use a custom page layout shell.

```typescript
// resources/js/martis/boot.ts
import { layoutRegistry } from '@martis/martis/lib/layoutRegistry'
import { UserResourceLayout } from './layouts/UserResourceLayout'

// The "users" resource uses a custom layout
layoutRegistry.register('users', UserResourceLayout)
```

**Layout component:**
```tsx
// resources/js/martis/layouts/UserResourceLayout.tsx
import type { ReactNode } from 'react'

export function UserResourceLayout({ children }: { children: ReactNode }) {
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
// resources/js/martis/boot.ts
import { componentRegistry } from '@martis/martis/lib/componentRegistry'
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

### Override Props

All override components receive the `OverrideProps` interface:

```typescript
interface OverrideProps {
  schema: ResourceSchema       // Full resource schema with fields
  resource: string             // Resource URI key
  params: Record<string, unknown>  // Custom parameters from Override()
  record?: ResourceRecord | null   // Current record (detail/update) or null (create)
  recordId?: string | null     // Record ID
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

### Built-in Drawer Components

| Component | Key | Description |
|-----------|-----|-------------|
| DrawerCreate | `martis:drawer-create` | Slide-in create form |
| DrawerUpdate | `martis:drawer-update` | Slide-in edit form |
| DrawerDetail | `martis:drawer-detail` | Slide-in detail view |

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
- Tab close / hard reload (via `beforeunload`)

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

Use the `DrawerOverride` class for chainable PHP configuration of built-in drawers:

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
    ];
}
```

**Available methods:**

| Method | Signature | Description |
|--------|-----------|-------------|
| `width()` | `width(string $width)` | Set the collapsed drawer width (CSS value, e.g. `'40rem'`). |
| `expandedWidth()` | `expandedWidth(string $width)` | Set the expanded drawer width. |
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

Every display component receives `FieldDisplayProps`:

```typescript
import type { FieldDisplayProps } from '@martis/martis/components/fields/types'

export function StatusBadge({ field, value }: FieldDisplayProps) {
  const label = String(value ?? '')
  const color = label === 'published' ? 'green' : 'gray'

  return (
    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-${color}-100 text-${color}-800`}>
      {label}
    </span>
  )
}
```

### Input Component

Every input component receives `FieldInputProps`:

```typescript
import type { FieldInputProps } from '@martis/martis/components/fields/types'

export function StatusSelect({ field, value, onChange, error }: FieldInputProps) {
  return (
    <div className="flex flex-col gap-1">
      <select
        value={String(value ?? '')}
        onChange={(e) => onChange(e.target.value)}
        className={error ? 'border-red-500' : 'border-gray-300'}
      >
        <option value="draft">Draft</option>
        <option value="published">Published</option>
      </select>
      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}
```

## 6. Creating Custom Components (Artisan)

Use the `martis:component` artisan command to scaffold and auto-register a component:

```bash
php artisan martis:component StatusBadge --type=field
php artisan martis:component DashboardLayout --type=layout
php artisan martis:component CustomFooter --type=footer
php artisan martis:component InfoPanel --type=generic
```

This command:
1. Creates the React component file at `resources/js/martis/components/`
2. Generates or updates `resources/js/martis/boot.ts` with the import and registration
3. For field types, registers both display and input components

**Component types:**

| Type | Description | Registry Key |
|------|-------------|-------------|
| `field` | Display + Input pair | `{kebab-name}` + `{kebab-name}-input` |
| `layout` | Page layout shell | `layout:shell` |
| `footer` | Page footer | `layout:footer` |
| `generic` | General purpose | `{kebab-name}` |

After creating a component, rebuild assets with `make build`.

## Component Registry API

```typescript
import { componentRegistry } from '@martis/martis/lib/componentRegistry'

// Register by key
componentRegistry.register(key, component)

// Register field display/input by type
componentRegistry.registerFieldDisplay(type, component)
componentRegistry.registerFieldInput(type, component)

// Register per-resource field display/input
componentRegistry.registerResourceFieldDisplay(resource, field, component)
componentRegistry.registerResourceFieldInput(resource, field, component)

// Check if a key is registered
componentRegistry.has(key)

// List all registered keys
componentRegistry.keys()

// Resolve a component (follows 4-tier priority)
componentRegistry.resolve(type, field, resource, explicitKey, fallback)
```

## Page Title Hook

Custom pages (layouts, custom resource views, dashboards built outside the default router) should set the browser tab title so navigation inside the SPA stays consistent with the server-side title on hard reload.

```tsx
import { usePageTitle } from '@/hooks/usePageTitle'

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
