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

`RedirectAfter` enum cases: `DETAIL` · `INDEX` · `EDIT` · `CREATE` · `DASHBOARD` · `STAY`. A literal string (`'detail'`, `'index'`, …) is accepted as a fallback for the same values. `STAY` keeps the drawer / page open after save — useful for "save and continue editing" workflows.

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
| DrawerQuick | `martis:drawer-quick` | ⭐ Lightweight read-only quick-look (narrower, no actions). Distinct from the BelongsTo / MorphTo hover **peek** popover, which surfaces *related-record* metadata; `DrawerQuick` is for the current row. |

### ⭐ Reading override props from nested components — `useOverrideProps()`

When a custom override has its own internal component tree (header, sidebar, form sections), prop-drilling `OverrideProps` through every level is noisy. The `useOverrideProps()` hook exposes the same payload via React context — wrap once at the top of your override, read anywhere underneath:

```tsx
import { useOverrideProps, OverridePropsProvider } from '@martis/martis/hooks/useOverrideProps'

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

The provider is opt-in — overrides that pass `props` manually keep working unchanged.

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

```typescript
import type { FieldDisplayProps } from '@martis/martis/components/fields/types'

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

```typescript
import type { FieldInputProps } from '@martis/martis/components/fields/types'

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

## 6. Creating Custom Components (Artisan)

Use the `martis:component` artisan command to scaffold and auto-register a component (alias: `martis:override`, kept for back-compat). Each `--type` option writes a different starter — see the [stubs directory on GitHub](https://github.com/Real-Edge-FX/martis-package/tree/main/stubs) for the full source of every scaffold:

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
1. Creates the React component file(s) at `resources/js/martis/components/`.
2. Generates or updates `resources/js/martis/boot.ts` with the import and registration.
3. For `field`, registers both display and input components.
4. For shell types, uses stubs that document the exact props the shell injects (collapsed state, mobile drawer callbacks, navigation payload from `/api/navigation`) so you can skip reading the source.

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

After creating a component, rebuild assets:
```bash
# v0.8+ — auto-discovery picks up `resources/martis-extensions/`
# automatically when the package lives in `vendor/martis/martis/`:
npm run build

# Override the discovered directory (or set it explicitly when working
# in dev mode where the package is symlinked outside vendor/):
MARTIS_USER_DIR=$(pwd)/resources/martis-extensions npm run build
```

The `@user` Vite alias resolves in this order:

1. **`MARTIS_USER_DIR` env var** — explicit override always wins.
2. **Auto-discovery** — walks up from the package directory looking for a Laravel app root (`artisan` file) that ships a `resources/martis-extensions/` folder.
3. **Fallback** — the package's own empty `resources/js/user/` so the package can build standalone.

### Shell piece-by-piece overrides

Replace any of the three shell pieces (`Sidebar`, `Topbar`, `Footer`) without touching the rest. Two equivalent wiring options — pick the one that matches your workflow:

**Option A — JS boot file** (register directly under the default key):

```typescript
// resources/js/martis/boot.ts
import { componentRegistry } from '@/lib/componentRegistry'
import { MyTopbar } from './components/MyTopbar'
import { MyFooter } from './components/MyFooter'

componentRegistry.register('layout:topbar', MyTopbar)
componentRegistry.register('layout:footer', MyFooter)
```

**Option B — PHP config + any registry key** (useful when multiple candidates are registered and PHP owns the selection, e.g. for feature-flagged deploys):

```typescript
// resources/js/martis/boot.ts
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
        'topbar'  => 'my-topbar',  // the key from boot.ts
        'footer'  => 'my-footer',
    ],
],
```

Resolution precedence for each piece: `config.layout.components.<piece>` → `layout:<piece>` → bundled component.

Your replacement receives the same props the bundled component does, so the shell's state (`sidebarCollapsed`, `onToggleCollapse`, `onToggleSidebar`, `mobileOpen`, `onMobileClose`) keeps flowing. Use piece-by-piece overrides when you want to change one piece's visual design but keep the overall shell mechanics (mobile drawer, grid, collapse animation) intact.

Use `layout:shell` (or `config.layout.components.shell`) when you want to rebuild the entire layout from scratch and don't need Martis's default mobile drawer / collapse behaviour.

## Component Registry API

```typescript
import { componentRegistry } from '@martis/martis/lib/componentRegistry'

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

## Debugging — `martis:list-overrides`

When an override does not pick up, the most common cause is a key mismatch between the PHP layer (which declares "I want a component called `<key>`") and the frontend `boot.ts` (which registers the actual React component under that key). The `martis:list-overrides` artisan command prints every component key the PHP layer expects:

```bash
php artisan martis:list-overrides
php artisan martis:list-overrides --kind=tool       # only Tools
php artisan martis:list-overrides --kind=action     # only Actions with Action::component()
php artisan martis:list-overrides --kind=resource   # only Resources (uri keys)
php artisan martis:list-overrides --filter=order    # substring filter on the key
php artisan martis:list-overrides --frontend        # ⭐ cross-check vs boot.ts and flag missing registrations
```

### ⭐ `--frontend` cross-check

The `--frontend` flag adds a **Frontend** column to the table that statically parses your `resources/js/martis/boot.ts` for `componentRegistry.register/registerFieldDisplay/registerFieldInput/registerResourceFieldDisplay/registerResourceFieldInput` calls and shows whether each PHP-declared key is registered:

```
+----------+--------------------------+------------------------+----------------+
| Kind     | Component key            | Source                 | Frontend       |
+----------+--------------------------+------------------------+----------------+
| resource | clients                  | App\Martis\ClientResource | ✓ registered |
| resource | invoices                 | App\Martis\InvoiceResource| ✓ registered |
| tool     | system-status            | App\Martis\Tools\…        | ✗ missing     |
+----------+--------------------------+------------------------+----------------+
```

Exit code `2` (INVALID) when any key is missing, so you can wire it into CI as `php artisan martis:list-overrides --frontend || exit 1`. Pass `--boot=path/to/file.ts` to point at a non-default boot file.

The parser handles string-literal keys; computed keys (`register(\`field:${kind}\`, ...)`) are not resolved — list those manually.

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
4 component key(s) declared. Verify each one is registered in your frontend
boot file (e.g. resources/js/boot.ts) via componentRegistry.register('<key>', Component).
```

The command lists what is **expected**, not what is **registered** — the actual override registry lives in the browser and cannot be introspected from PHP. Check the matching list in your frontend by running this in the browser devtools console after the SPA boots:

```js
window.componentRegistry.keys()
```

Any key that appears in `martis:list-overrides` but not in `componentRegistry.keys()` is a missing registration in your `boot.ts` — the most common reason an override fails to resolve.

## ⭐ Component Inspector — `/dev/components`

A developer-only page mounted at `/martis/dev/components` (alongside the regular admin routes) that lets you preview any registered component in isolation, fed by an editable JSON payload. The intended workflow:

1. Scaffold an override (`php artisan martis:component StatusBadge --type=field`).
2. Build the bundle (`npm run build`).
3. Open `/martis/dev/components`, pick `status-badge` from the list on the left.
4. Tweak the JSON payload (`{ field: {...}, value: 'draft' }`) and watch the component re-render on the right.
5. Iterate until the design is right — *then* go test through a real Resource page.

Distinct from the BelongsTo / MorphTo **peek** popover. Peek shows real records of a related model to end-users. The Inspector renders synthetic payloads for **developers**.

The page reads the same `componentRegistry.keys()` you'd inspect via the browser devtools console, so it surfaces every registered key — bundled drawers (`martis:drawer-*`), built-in field renderers (`field:display:text`, `field:input:select`, …), and your own overrides.

A misshapen JSON payload renders a red error box instead of crashing the inspector. The page sits inside the same authenticated shell as every other Martis admin page; restrict it via your existing middleware if you don't want non-developers landing on it.

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
