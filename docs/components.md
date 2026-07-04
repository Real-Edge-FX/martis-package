# Built-in Components Reference

Martis ships with a set of React components that make up the admin panel UI. All components are designed to be replaceable through the [Override System](overrides.md).

## Page Components

### ResourceIndex

The main listing page for a resource. Displays records in a paginated, sortable, searchable table.

**Route:** `/resources/:resource`

**Features:**
- Paginated data table with configurable per-page options
- Column sorting (click headers to toggle asc/desc)
- Global search with 300ms debounce
- Soft delete support (archive/restore)
- Custom create override detection (drawer or page)
- Row click navigation to detail page

**Configurable via Resource PHP:**
- `perPageOptions()` — Available page sizes
- `defaultPerPage()` — Initial page size
- `defaultSort()` / `defaultSortDirection()` — Initial sort
- `tableStriped()` / `tableShowGridlines()` / `tableSize()` / `tableRowHover()` — Table appearance

### ResourceDetail

Displays a single record with all its field values in read-only mode.

**Route:** `/resources/:resource/:id`

**Features:**
- Parallel fetch of schema and record data
- Scalar fields displayed in a definition list layout
- HasMany relationships displayed as inline DataTables with full CRUD
- Edit and Delete action buttons
- Soft delete detection with Restore button
- Custom detail override support (drawer mode)

### ResourceCreate

Form for creating a new record.

**Route:** `/resources/:resource/create`

**Features:**
- Schema-driven form with all `fieldsForCreate`
- File upload support via FormData (automatic detection)
- Inline validation errors per field
- Via-relationship support (create child from parent's HasMany)
- Custom create override support (drawer mode)
- Responsive grid layout with `colSpan` support

### ResourceUpdate

Form for editing an existing record.

**Route:** `/resources/:resource/:id/edit`

**Features:**
- Parallel schema and record fetch
- Pre-populated form with current values
- Smart value filtering (skips unchanged file/BelongsTo objects)
- File upload support
- Via-relationship support for inline editing

### Dashboard

Landing page after login. Configurable via `config/martis.php`:

```php
'dashboard' => [
    'showGreeting' => true,
    'showWelcome' => true,
    'showMetrics' => true,
    'showResourceCards' => true,
],
```

### Login / Register / 2FA challenge / Error screens

All six unauthenticated surfaces (Login, Register, 2FA challenge, 404, 403, 500) share the `components/auth/AuthFrame.tsx` shell — dot-grid background, brand-only logo row, centered Shell footer, and a guest-mode theme + language picker in the top-right via `AuthControls.tsx`.

- **Login** — email + password + `Keep me signed in` toggle. SSO, Google, Forgot password, and "Create an account" render only when the matching `config.auth.*.enabled` flag is set.
- **Register** — `/register` route gated by `config.auth.registration.enabled`. Posts to `/{martis-path}/api/auth/register` (consumer-provided endpoint).
- **2FA challenge** — 6-cell OTP row with auto-advance + paste-to-fill, a 30 s visual countdown, and a backup-code toggle that swaps the OTP grid for a plain recovery-code input.
- **Error pages** — `ErrorScreen.tsx` drives 404 / 403 / 500 with a faded watermark code, accent icon, optional `incidentId` chip with copy button, and primary + secondary CTAs.

See [Authentication](authentication.md) for the full config surface (`config.auth`), backend recipes for SSO / Google, and the registration contract.

## Layout Components

### Layout (Shell)

The main application shell that wraps all pages. Resolves layout preset from config and checks authentication.

**Presets:**

| Preset | Component | Description |
|--------|-----------|-------------|
| `sidebar` | SidebarLayout | Left sidebar + top bar + main content (default) |
| `topnav` | TopnavLayout | Top navigation bar + main content |
| `minimal` | MinimalLayout | Minimal header + main content |

> `SidebarLayout` is an inner function inside `resources/js/components/Layout.tsx`, not a standalone file under `components/layouts/`. `TopnavLayout` and `MinimalLayout` each live in their own file there. To override the sidebar preset wholesale, register a custom layout via `layoutRegistry.register('sidebar', MyLayout)`.

Override the layout for a specific resource using `layoutRegistry`:

```typescript
import { layoutRegistry } from '@/lib/layoutRegistry'
layoutRegistry.register('users', CustomUserLayout)
```

### Sidebar

Collapsible left navigation panel.

**Features:**
- Declarative menu sections and items from `/api/navigation`
- Automatic resource sections generated from `group()` when no custom main menu is defined
- Expandable group sections
- Dashboard quick link
- Phosphor icons per item/resource
- Collapse state persisted to localStorage
- Responsive: shows icons only when collapsed

### Topbar

Top navigation bar with:
- Brand logo/text
- Global search input (configurable: bar, icon, or disabled)
- User menu with theme toggle, profile, and logout
- Mobile-responsive search mode

### Command palette (`GlobalSearch.tsx`)

Global overlay that replaces the old "search sheet". Triggered by **⌘K** (macOS) / **Ctrl+K** (Windows / Linux) anywhere in the app, or by `/` when focus is outside an input. Mirrors the design-system Catalog spec via the `.martis-cmdk-*` CSS family.

Four ordered sections:

| Section | Source | When it shows |
|---------|--------|---------------|
| Resources | `/api/command-palette` → `resources` | Always (filtered by query). Each row is the resource label + its navigation group as a hint. |
| Actions | `/api/command-palette` → `actions` | When any registered resource exposes a standalone action (`Action::standalone()`). The hint column shows the owning resource. |
| Recent | `/api/command-palette` → `recent` | Only when the query is empty. Pulls the authenticated user's latest 8 `martis_action_events` rows. Clicking jumps to the affected record when `model_id` is set. |
| Records | `/api/search?q=…` | When the query has 2+ characters. Uses the existing unified record-search endpoint. |

Keyboard:
- `↑` / `↓` — navigate.
- `↵` — run the active item.
- `esc` — close.
- `⌘K` / `Ctrl+K` — toggle open / closed even while typing in a text field.

Backend route: `GET /api/command-palette` (`CommandPaletteController@index`), behind the standard Martis auth + 2FA + locale middleware stack. The aggregate is short-cached client-side for 30 s; the record search is debounced 300 ms and re-queries for every distinct query string.

To wire a consumer-specific command into the palette, register a standalone action on any resource — the palette picks it up automatically.

### Footer

Configurable page footer. Enable/disable and set custom text via config:

```php
'footer' => [
    'enabled' => true,
    'text' => 'Powered by Martis',
],
```

Override with a custom component:

```typescript
componentRegistry.register('layout:footer', MyCustomFooter)
```

## Data Display Components

### Table

The data table used on index pages and HasMany relationship views.

**Features:**
- Renders field values via `FieldDisplay` components
- Sortable column headers with visual indicators
- Row selection with checkboxes (prepared for bulk actions)
- Configurable striping, gridlines, size, and hover effects
- Overridable via registry

**Configuration (from Resource):**

```php
public function tableStriped(): bool       { return true; }
public function tableShowGridlines(): bool  { return false; }
public function tableSize(): string         { return 'normal'; }  // small, normal, large
public function tableRowHover(): bool       { return true; }
```

### Pagination

Page navigation controls showing current page, total records, and per-page selector.

### DeleteModal

Confirmation dialog for delete/archive operations.

**Behavior:**
- Soft delete models: Shows orange "Archive" button
- Hard delete models: Shows red "Delete Permanently" button
- Portal renders to `document.body`
- Close on ESC or backdrop click

### Modal shell (`.martis-modal-*`)

Every dialog in Martis renders through the same CSS shell so consumer-built overrides line up with the shipped modals. The classes live in `resources/css/martis.css` and mirror the design-system Catalog spec:

| Class | Role |
|------|------|
| `.martis-modal-scrim` | Fixed-position overlay, centers a single surface. `onClick` is the backdrop-close handler. |
| `.martis-modal-surface` | 480 px default. Size variants: `.is-lg` (640), `.is-xl` (800), `.is-2xl` (960). Always `flex-column` with `max-height: 85vh` and body-scroll. |
| `.martis-modal-head` | Title row with a bare icon (no circular disc) + the close button on the far right. |
| `.martis-modal-head-title` | `<h3>` token — 16 / 600 / -0.01em. |
| `.martis-modal-body` | 18/20 px padding, muted text, `overflow-y: auto`, grows to fill the surface. |
| `.martis-modal-foot` | Footer row on `--martis-surface-alt` with a 1 px divider on top. Right-aligned buttons. |
| `.martis-modal-close` | 28 × 28 square close button (X icon). |

Consumer recipe for a custom confirmation dialog:

```tsx
import { createPortal } from 'react-dom'
import { XIcon, WarningIcon } from '@phosphor-icons/react'
import { useModalHistoryLock } from '@/lib/historyLock'

export function DangerConfirm({ open, onCancel, onConfirm, title, body }: Props) {
  useModalHistoryLock(open)
  if (!open) return null

  return createPortal(
    <div className="martis-modal-scrim" onClick={onCancel}>
      <div role="dialog" aria-modal="true" className="martis-modal-surface" onClick={(e) => e.stopPropagation()}>
        <div className="martis-modal-head">
          <div className="flex items-center gap-3">
            <WarningIcon size={18} weight="fill" style={{ color: 'var(--martis-danger)' }} />
            <h3 className="martis-modal-head-title">{title}</h3>
          </div>
          <button type="button" className="martis-modal-close" onClick={onCancel}><XIcon size={16} /></button>
        </div>
        <div className="martis-modal-body">{body}</div>
        <div className="martis-modal-foot">
          <button type="button" className="martis-btn-secondary" onClick={onCancel}>Cancel</button>
          <button type="button" className="martis-btn-danger" onClick={onConfirm}>Delete</button>
        </div>
      </div>
    </div>,
    document.body,
  )
}
```

The `useModalHistoryLock(open)` hook intercepts the browser back button while the dialog is visible and cooperates with the DrawerShell so closing the dialog does not also close the drawer underneath. Required whenever a modal nests inside a drawer or the unsaved-changes guard.

### Index toolbar (`.martis-index-toolbar`)

The resource index and lens pages share a single card surface (`.martis-index-surface`) that holds the filter row, the search/per-page/trashed row, the DataTable, and the paginator as one continuous visual block. Toolbar rows render inside `.martis-index-toolbar` — pad, gap, and bottom border adjust automatically under `[data-density="dense"]`. Override the paginator or table chrome via the usual override hooks; override the toolbar by writing directly to the same classes in a consumer stylesheet.

### Breadcrumbs

Navigation breadcrumbs showing the current path (Dashboard > Resource > Record).

### ResourceIcon

Renders Phosphor icons by name. Backed by `iconRegistry`, a three-tier resolver that gives consumers access to all 1500+ Phosphor icons without the up-front bundle cost.

```php
// In Resource
public function icon(): string
{
    return 'users';        // kebab-case
    // return 'Users';     // PascalCase also works
}
```

Accepts kebab-case (`shopping-cart`), PascalCase (`ShoppingCart`), snake_case (`shopping_cart`), or any of those suffixed with `Icon`.

#### Resolution strategy

`iconRegistry.resolve(name)` walks three tiers, fastest first:

1. **Custom registry** — consumer-registered icons (synchronous).
2. **Curated built-ins** — 130 most-used Phosphor icons bundled eagerly with the main app chunk (synchronous, no network roundtrip).
3. **Dynamic CSR import** — every other Phosphor icon (1380+) loads on demand from `@phosphor-icons/react/dist/csr/<Name>.es.js`. Each becomes its own ~1-3 KB chunk wrapped in `<Suspense fallback={null}>`. Only icons actually rendered ship to the browser.

Names that don't match any Phosphor export fall back silently to `DatabaseIcon` so the page never crashes on a typo.

#### Registering custom icons

For icons outside Phosphor (custom SVGs) or to skip the dynamic-import roundtrip on a hot path, register synchronously from a consumer boot file:

```ts
// resources/js/martis-extensions/index.ts
import { iconRegistry } from '@/lib/iconRegistry'
import { CrownIcon } from '@phosphor-icons/react'

iconRegistry.register('crown', CrownIcon)
```

Custom registrations win over the built-in curated set (use this to override a built-in too).

#### Bundle impact

Pre-v0.8 the package imported the entire `@phosphor-icons/react` namespace inside `ResourceIcon`, which defeated tree-shaking and produced a ~5 MB minified `phosphor-icons-*.js` chunk on every build (1 MB gzip).

After v0.8 the curated set bundles eagerly with the main app chunk and the remaining 1380+ icons each become a ~1-3 KB lazy chunk fetched on first render. Total saving on first paint: ~3 MB raw per page load while preserving access to all 1500+ Phosphor icons.

### LoadingSkeleton

Skeleton loading placeholders with pulse animation. Displayed while data is being fetched.

### Sparkline (`components/metrics/Sparkline.tsx`)

Tiny SVG area sparkline used by `TrendCard` when the backend opts into sparkline mode (`TrendResult::sparkline()`). Exported from `@/components/metrics` so custom framed cards can reuse it.

```tsx
import { Sparkline } from '@/components/metrics'

<Sparkline values={[32, 38, 41, 44, 52, 60, 70]} variant="inline" color="var(--martis-chart-2)" />
```

| Prop | Default | Description |
|------|---------|-------------|
| `values` | required | Numeric series. Renders nothing when fewer than 2 points. |
| `color` | `var(--martis-accent)` | Stroke + gradient colour. |
| `variant` | `block` | `block` (36px, fills width) or `inline` (96×28, fits next to a KPI value). |
| `label` | — | Optional `aria-label` for the SVG. |

### ErrorBoundary

Catches and displays React rendering errors gracefully instead of crashing the entire application.

### GlobalSearch

Cross-resource search from the top bar. Configurable via:

```php
'search' => [
    'enabled' => true,
    'mode' => 'bar',       // 'bar', 'icon', or 'disabled'
    'mobileMode' => 'icon', // mobile-specific mode
    'placeholder' => 'Search...',
],
```

### Toast

Notification popup system. Supports 4 types: success, error, warning, info.

**Configuration:**
```php
'toast' => [
    'position' => 'bottom-right', // top-right, top-left, bottom-right, bottom-left, top-center, bottom-center
],
```

**Usage in components:**
```typescript
const { addToast } = useToast()
addToast('success', 'Record saved successfully')
addToast('error', 'Failed to save record')
```

## Drawer Components

Slide-in panels for inline CRUD operations. See [Override System](overrides.md) for configuration details.

### DrawerShell

Reusable container for all drawer overrides.

**Features:**
- Slide-in animation (configurable left/right)
- Expand/collapse button (toggle width)
- Fullscreen button (100vw)
- Close button + ESC key
- Backdrop click to close
- Portal renders to `document.body`
- Configurable width (default: 520px)

### DrawerCreate

Wraps the create form inside a `DrawerShell`. Fields are rendered in a responsive grid layout respecting `colSpan` settings.

### DrawerUpdate

Pre-populated edit form inside a `DrawerShell`.

### DrawerDetail

Read-only detail display inside a `DrawerShell`.

## Contexts (Global State)

### AuthContext

Manages authentication state.

```typescript
const { user, isLoading, login, logout } = useAuth()
```

- `user` — Current logged-in user or null
- `isLoading` — Whether the session check is in progress
- `login(email, password)` — Authenticate
- `logout()` — End session and reload

### ThemeContext

Manages dark/light theme state.

```typescript
const { theme, toggle, setTheme } = useTheme()
```

- `theme` — `'light'` or `'dark'`
- `toggle()` — Switch between themes
- `setTheme(t)` — Set theme explicitly
- Persists to localStorage (`martis-theme`)
- Toggles `.dark` class on `<html>`

### PreferencesContext

Single source of truth for user-tunable preferences (theme, accent, density, locale, reduced motion). Drives the Preferences menu, the per-resource accent override, and the density / reduced-motion CSS hooks (`data-density`, `data-reduced-motion`).

```typescript
import { usePreferences, usePreferencesOptional } from '@/contexts/PreferencesContext'

const { prefs, meta, update, reset, enabled } = usePreferences()

prefs.theme           // 'dark' | 'light' | 'system'
prefs.accent          // accent token name (e.g. 'martis', 'teal')
prefs.density         // 'comfortable' | 'dense'
prefs.locale          // 'en' | 'pt_PT' | 'pt_BR' | …
prefs.reducedMotion   // boolean

await update({ theme: 'light' })       // patch one or many keys
await update((prev) => ({ accent: prev.accent === 'teal' ? 'martis' : 'teal' }))
await reset()                           // back to package defaults
```

| Property | Type | Description |
|---|---|---|
| `prefs` | `Preferences` | Currently-applied preference set. |
| `meta` | `PreferencesMeta \| null` | Allowed values per axis (`themes`, `accents`, `densities`, `locales`, `presetsAvailable`) plus `source` (`default`, `user`, or `preset`). `null` while loading. |
| `update(patch)` | `(Partial<Preferences> \| (prev) => Partial<Preferences>) => Promise<void>` | Optimistic patch; persists to the server when authenticated and to `localStorage` when not. |
| `reset()` | `() => Promise<void>` | Clears the user override and falls back to the active preset (or package defaults). |
| `enabled` | `boolean` | `false` when the preferences subsystem is disabled via config; UI surfaces should hide their controls. |

`usePreferencesOptional()` is the safe variant that returns `null` outside the provider — use it from surfaces that may render before the shell mounts.

### ToastContext

Centralized notification system.

```typescript
const { addToast } = useToast()
addToast('success', 'Changes saved')
```

## Utilities

### api.ts

Unified API client with CSRF handling, JSON and multipart support.

```typescript
import { api } from '@/lib/api'

const data = await api.get<Post[]>('/api/posts')
await api.post('/api/posts', { title: 'New Post' })
await api.upload('POST', '/api/posts', formValues) // handles file uploads
```

**Features:**
- Automatic CSRF token injection (cookie or meta tag)
- Same-origin credentials
- File detection and FormData conversion
- Laravel validation error normalization
- `ApiError` class with `errorsByField()` for inline display

### config.ts

Reads configuration from `window.MartisConfig` (set by Laravel's Blade template).

```typescript
import { config, API_BASE_URL, BASE_PATH } from '@/lib/config'

config.theme?.default      // 'dark' or 'light'
config.layout?.preset      // 'sidebar', 'topnav', 'minimal'
config.search?.enabled     // boolean
config.footer?.text        // string
API_BASE_URL               // e.g. 'http://app.test/martis'
BASE_PATH                  // e.g. '/martis'
```

### usePrefersReducedMotion (`lib/usePrefersReducedMotion.ts`)

Reactive React hook that returns `true` when motion should be paused. Combines the OS-level signal (`@media (prefers-reduced-motion: reduce)`) with the per-user Martis preference (`html[data-reduced-motion="true"]` written by `PreferencesContext`).

```tsx
import { usePrefersReducedMotion } from '@/lib/usePrefersReducedMotion'

const reducedMotion = usePrefersReducedMotion()

useEffect(() => {
  if (reducedMotion) return                   // skip JS-driven motion
  el.addEventListener('mousemove', onTilt)
  return () => el.removeEventListener('mousemove', onTilt)
}, [reducedMotion])                            // re-runs when the user toggles
```

Use it whenever you write a `mousemove`-driven parallax, a `requestAnimationFrame` loop, or any other motion that CSS cannot clamp. CSS-driven animations (Tailwind `animate-*`, the `martis-` keyframes) already get clamped to 1ms via the global `*` selector under both `[data-reduced-motion="true"]` and `prefers-reduced-motion: reduce`, so simple CSS animations don't need the hook.

The hook listens to both signals and re-renders on any change, so toggling the Preferences panel switch pauses motion immediately without remount.

### i18n.ts

Internationalization setup using react-i18next.

```typescript
import { useTranslation } from 'react-i18next'

const { t } = useTranslation('messages')
t('record_deleted')     // Translated string
t('delete_confirm')     // Fallback to key if not found
```

**Namespaces:** `resources`, `messages`, `actions`, `navigation`

Translations are fetched dynamically from `/api/translations/{locale}` and cached by react-i18next.

### resolveRedirect.ts

Resolves post-CRUD navigation targets:

| Value | Destination |
|-------|-------------|
| `'detail'` | `/resources/{resource}/{id}` |
| `'index'` | `/resources/{resource}` |
| `'edit'` | `/resources/{resource}/{id}/edit` |
| `'create'` | `/resources/{resource}/create` |
| `'dashboard'` | `/` |
| `'stay'` | No navigation |
| Custom URL | Replace `{id}` and `{resource}` placeholders |

## Event Bus

The Martis Event Bus enables decoupled communication between components without prop drilling. It is available via the `useEventBus` hook.

```tsx
import { useEventBus } from '@/lib/useEventBus'

const { on, emit } = useEventBus()

// Subscribe — auto-cleaned up on unmount:
useEffect(() => {
  return on('martis:record-created', ({ resourceKey, id }) => {
    console.log('New record', id, 'in', resourceKey)
  })
}, [on])

// Emit:
emit('martis:record-created', { resourceKey: 'posts', id: 1 })
```

**Built-in events:**

| Event | Payload | Fired when |
|-------|---------|------------|
| `martis:record-created` | `{ resourceKey, id }` | After a record is created |
| `martis:record-updated` | `{ resourceKey, id }` | After a record is updated |
| `martis:record-deleted` | `{ resourceKey, id }` | After a record is deleted |
| `martis:record-restored` | `{ resourceKey, id }` | After a soft-deleted record is restored |
| `martis:action-executed` | `{ actionKey, resourceKey }` | After an action completes |
| `martis:refresh-index` | `{ resourceKey? }` | Request a Resource index to revalidate. Any transport (a consumer's own ws-gateway, SSE, or an Echo listener) can emit this to tell a `ResourceIndexPage` "another session mutated this resource — refetch." The index invalidates its `['resources', <uriKey>]` query when the payload's `resourceKey` matches its own resource, or on every index when `resourceKey` is omitted. |
| `martis:notification-received` | `{ id?, title?, message? }` | Pluggable real-time feed for the notification bell — emit this from any transport (a consumer's own ws-gateway, SSE, or an Echo listener) to push a notification into the bell instantly. See `docs/notifications.md` "Real-time delivery". |
| `martis:notifications-changed` | `{}` (no payload) | Ask the notification bell to re-fetch its unread count + open list (reconcile after a read / read-all / delete happened elsewhere — the down-direction mirror of `martis:notification-received`). See `docs/notifications.md` "Reconciling reads across sessions". |

Custom events can use any string key. Martis prefixes built-in events with `martis:`.

The event bus is also exposed on the `@martis/runtime` barrel as `martisEventBus` (the singleton instance, not the hook) so consumer-extension bundles can emit into native Martis UI without importing `@/lib/eventBus` directly:

```ts
import { martisEventBus } from '@martis/runtime'

martisEventBus.emit('martis:notification-received', { id: 42, title: 'New order' })
```

---

## useRevalidateOnFocus Hook

Resources and Tools built on `useQuery` already get `refetchOnWindowFocus` from the react-query default: the data revalidates automatically when the operator returns to a backgrounded Martis tab. Custom Tools that fetch data manually (no react-query) don't get this for free — `useRevalidateOnFocus` closes that gap.

```tsx
import { useRevalidateOnFocus } from '@/hooks/useRevalidateOnFocus'
// or, from a consumer-extension bundle:
import { useRevalidateOnFocus } from '@martis/runtime'

function MyManualFetchTool() {
  const [data, setData] = useState(null)

  const refetch = useCallback(() => {
    fetch('/api/my-tool-data').then(res => res.json()).then(setData)
  }, [])

  useEffect(() => { refetch() }, [refetch])

  // Re-fetch whenever the tab becomes visible again or the window regains focus.
  useRevalidateOnFocus(refetch)

  return <div>{/* ... */}</div>
}
```

The hook takes a single `onRevalidate: () => void` callback, subscribes to `document`'s `visibilitychange` (firing only when the page becomes visible) and `window`'s `focus`, and cleans both listeners up on unmount. It has no dependencies of its own and does not touch react-query — it is purely a DOM-event seam for manual-fetch Tools.

---

## useUnsavedChangesGuard Hook

Wraps a form with the package-wide unsaved-changes guard. Reads the resource's `confirmUnsavedChanges` flag from the schema, snapshots initial values, and intercepts navigation when the form is dirty.

```tsx
import { useUnsavedChangesGuard } from '@/lib/useUnsavedChangesGuard'

function MyForm({ schema, initialValues }) {
  const [values, setValues] = useState(initialValues)
  const initialSnapshot = useMemo(() => JSON.stringify(initialValues), [initialValues])

  const { dialog, markSaved } = useUnsavedChangesGuard({
    values,
    initialSnapshot,
    schema,
  })

  const onSubmit = async () => {
    await save(values)
    markSaved()        // suppress the guard for the post-save redirect
  }

  return (
    <>
      {dialog}          {/* render the confirm dialog inside the tree */}
      <form onSubmit={onSubmit}>...</form>
    </>
  )
}
```

| Option | Type | Effect |
|---|---|---|
| `values` | `Record<string, unknown>` | Current form values; the hook re-snapshots on every render. |
| `initialSnapshot` | `string \| null` | JSON snapshot of the loaded values. Pass `null` to disable the guard while the record loads. |
| `schema` | `ResourceSchema \| undefined` | Resource schema. The hook reads `schema.confirmUnsavedChanges` to decide whether to engage. |
| `bypass` | `boolean` | When `true`, suppresses the guard for the next navigation (used after a successful submit). |

The hook integrates with `react-router-dom`'s `useBlocker`, so navigation via `<Link>` or `useNavigate()` triggers the dialog. It also integrates with the modal-history lock primitives in `resources/js/lib/historyLock.ts` so back-button navigation respects the guard.

## useError Hook

Centralised error state management for forms and page components.

```tsx
import { useError } from '@/lib/useError'

const { errors, setError, clearErrors, hasErrors } = useError()

try {
  await api.post('/api/posts', data)
} catch (err) {
  setError(err) // Handles ApiError, Error, or string
}

// Render errors:
{errors.message && <p className="text-destructive">{errors.message}</p>}
{errors.fieldErrors?.title && <p className="text-destructive">{errors.fieldErrors.title}</p>}
```

| Property / Method | Type | Description |
|-------------------|------|-------------|
| `errors` | `{ message?: string; fieldErrors: Record<string, string> }` | Current error state |
| `setError(err)` | `(ApiError \| Error \| string) => void` | Parse and set errors from a caught exception |
| `clearErrors()` | `() => void` | Reset all error state |
| `hasErrors` | `boolean` | Whether any error is currently set |

---

## useOverrideProps Hook

React context primitive that carries the live `OverrideProps` payload (the `schema`, `record`, `recordId`, `params`, navigation callbacks, etc.) every drawer or page override receives. Wrap children with the provider and any deeply-nested component reads the same payload without prop-drilling.

```tsx
import { OverridePropsProvider, useOverrideProps, useOverridePropsOptional } from '@/hooks/useOverrideProps'

export function MyDrawerCreate(props: OverrideProps) {
  return (
    <OverridePropsProvider value={props}>
      <MyHeader />
      <MyForm />
    </OverridePropsProvider>
  )
}

function MyHeader() {
  const { schema, onClose } = useOverrideProps()       // throws outside provider
  return <h2>{schema.singularLabel}</h2>
}

function MyOptionalConsumer() {
  const ctx = useOverridePropsOptional()               // returns null outside provider
  if (!ctx) return null
  return <span>{ctx.recordId}</span>
}
```

Opt-in. Overrides that prefer manual prop passing don't need to wrap.

## usePageTitle Hook

Sets `document.title` for the currently-mounted page and restores the previous title on unmount, so stacked drawers and modals do not leave stale segments after they close.

```tsx
import { usePageTitle } from '@/hooks/usePageTitle'

function MyCustomPage({ resource }) {
  usePageTitle(resource.label)            // → "Clients · Brand"
  // usePageTitle(null)                    // → translated default ("Brand — Admin Control")
  return <div>…</div>
}
```

| Argument | Effect |
|---|---|
| `string` | Renders `"{segment} · {brand}"`. |
| `null` / `undefined` / `""` | Falls back to the localized default (`navigation.page_title_default`). |

Brand resolves from `config.brand`; the translation namespace is `navigation`.

## useIsMobile Hook

Reactive viewport-width hook. Returns `true` when `window.innerWidth <= breakpoint` (default `768`) and re-renders on every resize. Used by the topbar to switch the search input between bar and icon modes; consumers can reuse it from any override that needs a JS-side mobile gate without re-implementing the matchMedia listener.

```tsx
import { useIsMobile } from '@/hooks/useIsMobile'

const isMobile = useIsMobile()           // default 768px
const isNarrow = useIsMobile(540)        // custom breakpoint

return isMobile ? <CompactToolbar /> : <FullToolbar />
```

Prefer CSS media queries when the layout swap is purely visual; reach for this hook when the difference is structural (different React tree, different data fetch, etc.).

---

## Tooltip Standard (PrimeReact)

All tooltips in Martis **must** use [`primereact/tooltip`](https://primereact.org/tooltip/). Native HTML `title=` attributes and custom tooltip implementations are prohibited.

A global Tooltip provider is registered in the layout targeting `[data-pr-tooltip]`, so any element with `data-pr-tooltip` automatically gets a tooltip.

### Simple tooltip (recommended)

```tsx
<button
  data-pr-tooltip="Delete this record"
  data-pr-position="top"
>
  <Trash size={16} />
</button>
```

### Ref-based tooltip (for complex / HTML content)

```tsx
import { Tooltip } from 'primereact/tooltip'
import { useRef } from 'react'

const btnRef = useRef(null)

<button ref={btnRef}>Save</button>
<Tooltip target={btnRef} content="Save record" position="top" />
```

> **Rich / HTML content:** the global `[data-pr-tooltip]` provider registers with
> the default `escape` (HTML is escaped), so `data-pr-tooltip="<b>…</b>"` renders
> the literal markup. Rich content therefore **must** use the ref-based component
> with `escape={false}`:
>
> ```tsx
> <Tooltip target={ref} position="top" content={<div><b>Re-index</b><br/>…</div>} escape={false} />
> ```

### From a consumer Tool / extension

Extension bundles can't `import { Tooltip } from 'primereact/tooltip'` — the
extension build doesn't alias `primereact`. Reach the same component off the
runtime surface (`window.Martis.runtime`, since v1.19.0), exactly like
`FieldInput` / `DrawerShell`:

```tsx
import { Tooltip } from '@martis/runtime' // shim → window.Martis.runtime.Tooltip

<button ref={ref}>Re-index</button>
<Tooltip target={ref} position="top" escape={false}
  content={<div className="martis-…"><b>Re-index</b><br/>Rebuilds the index.</div>} />
```

### Rules

| Rule | Detail |
|------|--------|
| ❌ Never use `title=` | Native browser tooltips are inconsistent across themes |
| ❌ Never build custom tooltip divs | Breaks dark/light mode consistency |
| ✅ Always use `data-pr-tooltip` for simple text | Covered by global provider |
| ✅ Use ref-based `<Tooltip>` for complex/HTML tooltips | Full PrimeReact API available |
| ✅ Use `data-pr-position` to control placement | `"top"` \| `"bottom"` \| `"left"` \| `"right"` |



## Theming

Martis uses CSS custom properties for theming. Override these variables to customize colors:

```css
:root {
  --martis-bg: #1b2332;          /* Page background */
  --martis-surface: #1e293b;     /* Card/panel background */
  --martis-sidebar: #111827;     /* Sidebar background */
  --martis-border: #334155;      /* Border color */
  --martis-text: #e2e8f0;        /* Primary text */
  --martis-text-muted: #94a3b8;  /* Secondary text */
  --martis-accent: #6366f1;      /* Primary action color (indigo) */
  --martis-accent-hover: #4f46e5; /* Hover state */
  --martis-card: #1e293b;        /* Card background */
  --martis-input-bg: #111827;    /* Form input background */
}
```

Light theme variables are set via `html:not(.dark)` selector and automatically applied when theme is toggled.

## Loading Indicator

### MartisLoader

The built-in loading indicator used across all resource pages, the profile page, and action drawers.

**Props:**

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `loading` | `boolean` | `true` | Controls visibility |
| `message` | `string \| null` | config or i18n | Text shown next to spinner |
| `overlay` | `boolean` | `false` | Covers child content with a semi-transparent overlay |
| `disabled` | `boolean` | `false` | Hides this instance regardless of state |
| `size` | `'sm' \| 'md' \| 'lg'` | `'md'` | Spinner and text size |
| `children` | `ReactNode` | — | Content to wrap in overlay mode |

**Usage:**

```tsx
import { MartisLoader } from '@/components/Loader'

// Simple spinner
<MartisLoader loading={isLoading} />

// Overlay mode — wraps content with a semi-transparent loading state
<MartisLoader loading={isFetching} overlay>
  <DataTable rows={rows} />
</MartisLoader>
```

**Configuration:** All visual options (message, icon, logo, colors, opacity) are controlled via `config/martis.php` under the `loader` key. See the [Loader documentation](loader.md) for the full configuration reference and customization guide.

**Custom loader component:** Replace the built-in loader entirely via the component registry:

```typescript
import { componentRegistry } from '@/lib/componentRegistry'
componentRegistry.register('loader', MyCustomLoader)
```

## CSS Utility Classes

Every component inside Martis is themed via design tokens (see [theming.md](theming.md)), but a small set of standalone utility classes is also exposed so consumer overrides, custom actions and bespoke pages can drop in chips, badges and avatars without reinventing them.

### Buttons

Compose a variant with an optional size and the `martis-btn-icon` helper for icon-only chips.

| Class | Effect |
|-------|--------|
| `martis-btn-primary` | Accent-filled primary CTA. |
| `martis-btn-secondary` | Neutral chip with a 1px border. |
| `martis-btn-danger` / `martis-btn-success` / `martis-btn-warning` | Semantic filled variants. |
| `martis-btn-ghost` | Transparent chip that washes on hover — ideal for toolbars and row actions. |
| `martis-btn-sm` / `martis-btn-lg` | Height + typography tokens. Default (no size class) = 36px. |
| `martis-btn-icon` | Square icon-only shape. Compose with any variant and any size. |

```html
<button class="martis-btn-ghost martis-btn-icon" aria-label="Open"><svg/></button>
<button class="martis-btn-primary martis-btn-lg">Save changes</button>
```

### Badges

```html
<span class="martis-badge martis-badge-success">Active</span>
<span class="martis-badge martis-badge-danger martis-badge-dot">Failed</span>
<span class="martis-badge martis-badge-neutral">Draft</span>
```

| Class | Effect |
|-------|--------|
| `martis-badge` | Base chip shape (pill, 22px, 11px font, weight 500, tabular numerals). Always required. |
| `martis-badge-info` / `success` / `warning` / `danger` | Semantic colour tokens (background + text + border). |
| `martis-badge-neutral` | Muted chip for "draft" / "unassigned" states. |
| `martis-badge-dot` | Prepends a small dot for "live" / connection states. |

### Avatars

Size tokens compose with any shape class (`martis-avatar-circle`, `-rounded`, `-squared`). Default avatar size stays at 36px so existing call sites are unaffected.

| Class | Size |
|-------|------|
| `martis-avatar-xs` | 20px |
| `martis-avatar-sm` | 24px |
| `martis-avatar-md` | 28px |
| *(no class)* | 36px |
| `martis-avatar-lg` | 40px |
| `martis-avatar-xl` | 56px |

Use `.martis-avatar-stack` on a wrapper to overlap several avatars with a subtle ring:

```html
<div class="martis-avatar-stack">
  <span class="martis-avatar martis-avatar-sm martis-avatar-circle">JA</span>
  <span class="martis-avatar martis-avatar-sm martis-avatar-circle">MW</span>
  <span class="martis-avatar martis-avatar-sm martis-avatar-circle">+3</span>
</div>
```

`.martis-avatar-fallback` paints a muted user glyph slot for records with no image and no initials seed, keeping row layouts aligned.

The `lib/avatarPalette.ts` helper returns a deterministic colour for any seed string, picking one of the 16 `--martis-avatar-1..16` token hues. Two users with the same name always get the same colour, and the colour stays stable across light/dark themes:

```ts
import { avatarColorForSeed } from '@/lib/avatarPalette'

<span
  className="martis-avatar martis-avatar-md martis-avatar-circle"
  style={{ backgroundColor: avatarColorForSeed(user.name) }}
>
  {user.initials}
</span>
```

### KPI typography

KPI cards (Value, Trend, Progress, framed custom cards) share three typography classes:

| Class | Effect |
|-------|--------|
| `martis-kpi-label` | 12px uppercase muted text with a 0.04em tracking. Wraps an icon (`martis-kpi-label-icon`, 14px) and the label text (`martis-kpi-label-text`). |
| `martis-kpi-value` | 28px semibold, tabular numerals. Collapses to 24px under `[data-density="dense"]`. |
| `martis-kpi-delta` | Inline pill rendered next to the value; `is-up` / `is-down` colour variants. `.martis-kpi-delta-sub` styles the "vs previous" suffix. |

```html
<h3 class="martis-kpi-label">
  <span class="martis-kpi-label-icon">{icon}</span>
  <span class="martis-kpi-label-text">Total revenue</span>
</h3>
<p class="martis-kpi-value">€389,785</p>
<span class="martis-kpi-delta is-up">↗ +12.4%
  <span class="martis-kpi-delta-sub">vs €346,521</span>
</span>
```

### Status dot (Live indicator)

The shell's pulsating green dot is exposed as a public utility so any surface that signals "this value auto-refreshes" uses the same visual:

```html
<span class="martis-status-dot">
  <span class="martis-status-pulse"></span>
  Live
</span>
```

The pulse halo respects the user's reduced-motion preference (both `[data-reduced-motion="true"]` and `prefers-reduced-motion`).

### Notification dot

A reusable danger-coloured dot for unread / notification indicators on icon buttons:

```html
<button class="martis-tb-icon-btn" aria-label="Notifications">
  <svg/>
  <span class="martis-notif-dot"></span>
</button>
```

The selector `.martis-tb-icon-btn .martis-notif-dot` keeps the topbar's exact spec geometry (6×6 + 2px topbar border). Using `.martis-notif-dot` outside the topbar produces the same hue without the border.

### Detail panel rows

Stacked label/value rows used by the resource detail page right rail and the drawer detail surface:

```html
<dl class="martis-detail-panel">
  <div class="martis-detail-row">
    <dt class="martis-detail-label">Status</dt>
    <dd class="martis-detail-value">Active</dd>
  </div>
</dl>
```

Add `.martis-detail-panel.is-drawer` on the wrapper for drawer-tighter spacing. `.martis-detail-kicker` is the eyebrow text rendered above the panel title.

### Form density helpers

Wrap form bodies in these classes so the create / update pages and drawer forms react to the active density token:

| Class | Effect |
|-------|--------|
| `martis-form-body` | Padded form container. Tightens on `[data-density="dense"]`. |
| `martis-form-stack` | Vertical flex stack of fields with token-driven gap. |
| `martis-form-grid` | 12-column form grid container; pair with `martis-input-wrap` per field. |

### Tabs / Segmented / Skeleton

Generalised primitives previously living inline only inside specific surfaces:

| Class | Effect |
|-------|--------|
| `martis-tabs` + `martis-tab` | Underline-active tab strip (2px `--martis-accent` border-bottom). |
| `martis-segmented` | Equal-width segmented control with focus-visible ring. |
| `martis-skeleton` | 1.6s linear shimmer gradient. Replaces ad-hoc `animate-pulse` usage. |

### Dashboard layout helpers

Two grid classes mirror the Dashboard.html spec so a custom dashboard renders the same as the built-in one:

| Class | Effect |
|-------|--------|
| `martis-dash-kpis` | 4-column row of KPI cards (collapses to 2 cols below 1100px). |
| `martis-dash-grid` | 3-column body grid; supports `.span-2` / `.span-3` cell helpers. Collapses to 1 col below 1100px. |

### Filter chip

Public 24px chip used by `<FilterPanel>` for active-filter pills. Drop the class on any `<span>` carrying a label / value plus a dismiss button to inherit the look.

```html
<span class="martis-filter-chip">
  <span class="martis-filter-chip-label">Status:</span>
  <span class="martis-filter-chip-value">Active</span>
  <button class="martis-filter-chip-x" aria-label="Clear">×</button>
</span>
```

| Class | Effect |
|-------|--------|
| `martis-filter-chip` | 24px height, `--martis-hover` bg, 1px `--martis-border`, 12px font, `--martis-radius-sm`. |
| `martis-filter-chip-label` | Muted text for the field name prefix. |
| `martis-filter-chip-value` | Default text for the value. |
| `martis-filter-chip-x` | Round dismiss button with hover state. |

### Dropzone

Public file-upload surface used by `FileField`, `ImageField`, and any consumer that wants the canonical Martis upload look.

```html
<div class="martis-dropzone is-drag-over">
  <!-- file row content -->
</div>

<!-- big-zone variant: centred icon + CTA, dashed border -->
<div class="martis-dropzone is-zone">
  <span class="martis-dropzone-icon"><svg/></span>
  <span class="martis-dropzone-title">No file attached</span>
  <span class="martis-dropzone-hint">Drop a file or click to browse.</span>
  <span class="martis-dropzone-cta">Choose file</span>
</div>
```

| Class | Effect |
|-------|--------|
| `martis-dropzone` | Compact inline row. `--martis-input-bg` + 1px `--martis-border`. |
| `martis-dropzone.is-zone` | Dashed border, centred icon + CTA. Use for empty states. |
| `martis-dropzone.is-drag-over` | Accent border + tinted bg while a file is hovering. |
| `martis-dropzone.has-error` | Danger border. |
| `martis-dropzone-icon` / `-title` / `-hint` / `-cta` | Children of the `is-zone` variant. |

### Card chrome

Public wrapper used by `martis:card` scaffolds and by any custom dashboard panel that wants the canonical Martis card look without reaching for the internal `.martis-metric-card` selectors:

```html
<article class="martis-card">
  <header class="martis-card-head">
    <h3 class="martis-kpi-label">
      <span class="martis-kpi-label-text">My custom card</span>
    </h3>
  </header>
  <div class="martis-card-body">
    <p>Card content…</p>
  </div>
</article>
```

| Class | Effect |
|-------|--------|
| `martis-card` | Surface + border + 16/18 padding + `--martis-radius-lg`. |
| `martis-card-head` | Flex row, space-between alignment, baseline gap. Pair with `.martis-kpi-label` for the title. |
| `martis-card-body` | Vertical stack with token-driven gap. |
