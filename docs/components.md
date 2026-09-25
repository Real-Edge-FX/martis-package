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
- Three explicit states for the records query (v1.32.4): while the first fetch is pending only the loader shows; a failed fetch renders the inline [`QueryErrorState`](#queryerrorstate) (status, server message, Retry) in place of the table body and fires an error toast; "No records found." renders only for an authoritative empty result. Before v1.32.4 a failed fetch (any 5xx) was rendered as the empty state.

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
- Responsive field grids (Section, Panel, Tab) with `colSpan` / `colSpanMd` / `colSpanLg` support

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

> `SidebarLayout` is an inner function inside `resources/js/components/Layout.tsx`, not a standalone file under `components/layouts/`. `TopnavLayout` and `MinimalLayout` each live in their own file there. To replace the whole shell, register a component under `layout:shell` (`componentRegistry.register('layout:shell', MyShell)`); to replace one piece, under `layout:sidebar`, `layout:topbar` or `layout:footer`. See [Overrides → Shell piece-by-piece overrides](overrides.md#shell-piece-by-piece-overrides).

Give the pages of one resource a layout of their own with `layoutRegistry` (v1.38.0+):

```typescript
import { layoutRegistry } from '@martis/runtime'
layoutRegistry.register('users', CustomUserLayout)
```

The layout wraps every page of that resource (index, lens, create, detail, update) inside the shell and receives the page as `children`. See [Overrides → Layout Overrides](overrides.md#2-layout-overrides).

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
- `emptyMessage` prop (v1.32.4): `undefined` renders the localised "No records found."; `null` renders a blank spacer (the rows are not authoritative yet: first fetch pending or failed); any node renders as custom copy. The index and lens pages pass `null` until the records query succeeds so the empty state can never describe a failed request.

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
import type { ReactNode } from 'react'
import { XIcon, WarningIcon } from '@phosphor-icons/react'
import { createPortal, useModalHistoryLock } from '@martis/runtime'

interface Props {
  open: boolean
  onCancel: () => void
  onConfirm: () => void
  title: ReactNode
  body: ReactNode
}

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

The `useModalHistoryLock(open)` hook intercepts the browser back button while the dialog is visible and cooperates with the DrawerShell so closing the dialog, by a button, the back button or Escape (v2.0.0), does not also close the drawer underneath: while a locked dialog is open the drawer ignores Escape, so your dialog handles its own. Its Escape handler also calls `e.preventDefault()`, which tells any later listener (a drawer's, the app's) that the key was taken. Required whenever a modal nests inside a drawer or the unsaved-changes guard. It is on `@martis/runtime` since v1.38.0 and has to come from there: it shares a lock count with the drawers, which a copy of the hook would not see. `createPortal` comes from the runtime too: it is the host's, so the dialog renders with the host's React DOM. Since v1.38.0 `import { createPortal } from 'react-dom'` reaches the same function, since the extension build sends `react-dom` to a shim that carries it and `flushSync` (v1.38.2), nothing else of `react-dom`; on a scaffold published earlier, `react-dom` resolves to the React shim, which exports React core only (see [Refreshing the extension scaffold](installation-guide.md#refreshing-the-extension-scaffold-after-an-upgrade)).

### Escape closes the top layer only (v2.0.0)

In a drawer, Escape closes whatever is open on top of it first: a modal, a menu (the actions menu, a row's inline action menu, the lens menu, a Repeater's add menu), a picker (`BelongsTo`, `MorphTo`, `Tag`, the multi-select, the icon picker) or a PrimeReact overlay (`Dropdown`, `MultiSelect`, `Calendar`, `AutoComplete`, `SplitButton`, `ColorPicker`, `OverlayPanel`). The drawer and the form in it stay; the next Escape closes the drawer, through its unsaved-changes prompt when the form is dirty. Before v2.0, an Escape meant for a menu or a picker in a Create or Update drawer also closed the drawer and lost the form.

The DrawerShell closes on an Escape nothing else took. It decides in the capture phase, before the layers' own listeners: a key press runs the microtasks between listeners, where React applies a layer's close, so a later check would find nothing open. It leaves the Escape alone when it arrives already handled (`defaultPrevented`, as every Martis modal and popup marks the Escape it takes), while a modal holds its history lock (`useModalHistoryLock()`), while a Martis popup is open, or while a PrimeReact overlay is mounted. A dialog of your own calls `e.preventDefault()` on the Escape it takes; a popup of your own (one that closes on an outside click) joins the same rule with `useEscapeLayer(open, close)` from `@martis/runtime`: Escape calls `close` when it is the top layer, and the drawer underneath stays open.

```tsx
import { useState } from 'react'
import { useEscapeLayer } from '@martis/runtime'

export function StatusMenu() {
  const [open, setOpen] = useState(false)
  useEscapeLayer(open, () => setOpen(false))

  return (
    <div>
      <button type="button" onClick={() => setOpen(true)}>Status</button>
      {open && <div role="menu">…</div>}
    </div>
  )
}
```

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

For icons outside Phosphor (custom SVGs) or to skip the dynamic-import roundtrip on a hot path, register synchronously from the extension entry (`iconRegistry` is on `@martis/runtime` since v1.38.0):

```ts
// resources/js/martis-extensions/index.ts
import { iconRegistry } from '@martis/runtime'
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

Tiny SVG area sparkline used by `TrendCard` when the backend opts into sparkline mode (`TrendResult::sparkline()`). A custom framed card reuses it from `@martis/runtime` (v1.38.0+).

```tsx
import { Sparkline } from '@martis/runtime'

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

### QueryErrorState

Inline error state for a failed **listing** fetch, rendered in place of the table body on the resource index, lens pages and relationship panels (v1.32.4). Sibling of the full-page `ResourceErrorPage`, but scoped to the table so the toolbar (search, filters, per-page, trashed) stays usable: the schema loaded fine and the user may just need to fix a filter or retry.

- Triage mirrors the error page: network / transport failure, 403, 404, 5xx, other 4xx, each with its own copy (`messages.query_error_*` keys, translated in `en` / `pt_PT` / `pt_BR`).
- A detail line shows `HTTP {status} · {server message}` (production Laravel returns the generic "Server Error"; the stack trace belongs in the logs). The status is what lets support tell a failure from an empty list on a screenshot.
- **Retry** calls the query's `refetch()`; the button is disabled while the retry is in flight and the page loader overlay covers the state.
- The index and lens pages also fire an error toast on the transition to the error state (again on each failed retry), so the failure is visible when the user is scrolled away from the table. Relationship panels render the `compact` variant without a toast.
- A failed *refetch* on top of data already held (polling, focus revalidation) keeps the last good rows on screen; the toast is the signal.

How the package's pages render it:

```tsx
// Package-internal: resources/js/components/QueryErrorState.tsx, not on @martis/runtime.
import { QueryErrorState } from '@/components/QueryErrorState'

<QueryErrorState error={query.error} onRetry={() => void query.refetch()} retrying={query.isFetching} />
<QueryErrorState compact error={query.error} onRetry={() => void query.refetch()} />
```

CSS hooks: `.martis-query-error` (+ `-compact`), `.martis-query-error-icon`, `-body`, `-title`, `-desc`, `-detail`, `-retry`. The root carries `role="alert"` and `data-status` (`500`, `403`, `network`, ...).

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

Wraps the create form inside a `DrawerShell`. Sections, Panels and Tabs lay their fields out on the responsive field grid (`colSpan` / `colSpanMd` / `colSpanLg`, see [Grid Layout](grid-layout.md#responsiveness)); a field outside any layout container is a full-width row.

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

`useTheme()` is package-internal (not on `@martis/runtime`). An extension reads and sets the theme through [`usePreferences()`](#preferencescontext): `prefs.theme` and `update({ theme })`.

### PreferencesContext

Single source of truth for user-tunable preferences (theme, accent, density, locale, reduced motion). Drives the Preferences menu, the per-resource accent override, and the density / reduced-motion CSS hooks (`data-density`, `data-reduced-motion`).

```typescript
import { usePreferences, usePreferencesOptional } from '@martis/runtime' // v1.38.0+

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
import { api } from '@martis/runtime'

const data = await api.get<Post[]>('/api/posts')
await api.post('/api/posts', { title: 'New Post' })
await api.upload('POST', '/api/posts', formValues) // handles file uploads
```

**Features:**
- Automatic CSRF token injection (cookie or meta tag)
- Same-origin credentials
- File detection and FormData conversion (`hasFileValues()` / `buildFormData()`): on the multipart path booleans travel as `1` / `0` and arrays or plain objects as JSON strings, which the resource controllers decode back for structured fields, so a Repeater, MultiSelect, BooleanGroup, KeyValue, Tag, MorphTo or Sparkline value saved alongside a file keeps its shape (see [Fields → Structured values and file uploads](fields.md#structured-values-and-file-uploads))
- Laravel validation error normalization
- `ApiError` class with `errorsByField()` for inline display

### config.ts

Reads configuration from `window.MartisConfig` (set by Laravel's Blade template).

```typescript
import { config } from '@martis/runtime'

config.theme?.default      // 'dark' or 'light'
config.layout?.preset      // 'sidebar', 'topnav', 'minimal'
config.search?.enabled     // boolean
config.footer?.text        // string
config.basePath            // e.g. '/martis'
```

The package's own modules also import two constants from `@/lib/config`, which are not on the runtime: `BASE_PATH` (`config.basePath ?? '/martis'`) and `API_BASE_URL` (`window.location.origin` followed by `BASE_PATH`, e.g. `'http://app.test/martis'`). An extension rarely needs them: `api` prefixes every request path with `API_BASE_URL`.

### usePrefersReducedMotion (`lib/usePrefersReducedMotion.ts`)

Reactive React hook that returns `true` when motion should be paused. Combines the OS-level signal (`@media (prefers-reduced-motion: reduce)`) with the per-user Martis preference (`html[data-reduced-motion="true"]` written by `PreferencesContext`).

```tsx
import { useEffect } from 'react'
import { usePrefersReducedMotion } from '@martis/runtime' // v1.38.0+

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

The Martis Event Bus enables decoupled communication between components without prop drilling. An extension reaches it through the `martisEventBus` singleton on `@martis/runtime`:

```tsx
import { useEffect } from 'react'
import { martisEventBus, type EventBusEvents } from '@martis/runtime'

// Subscribe, and unsubscribe on unmount:
useEffect(() => {
  const onCreated = ({ resourceKey, id }: EventBusEvents['martis:record-created']) => console.log('New record', id, 'in', resourceKey)
  martisEventBus.on('martis:record-created', onCreated)
  return () => martisEventBus.off('martis:record-created', onCreated)
}, [])

// Emit:
martisEventBus.emit('martis:record-created', { resourceKey: 'posts', id: 1 })
```

A built-in event types its payload in `on`, `once`, `off` and `emit` (`EventBusEvents`, v1.38.0): a handler written inline, `martisEventBus.on('martis:record-created', ({ resourceKey, id }) => …)`, gets both typed.

The package's own components use the `useEventBus()` hook, which wraps the same singleton and drops every handler it registered when the component unmounts:

```tsx
// Package-internal: resources/js/lib/useEventBus.ts, not on @martis/runtime.
import { useEventBus } from '@/lib/useEventBus'

const { on, emit } = useEventBus()

useEffect(() => {
  return on('martis:record-created', ({ resourceKey, id }) => {
    console.log('New record', id, 'in', resourceKey)
  })
}, [on])
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

An extension emits into native Martis UI the same way, for example a notification pushed into the bell:

```ts
import { martisEventBus } from '@martis/runtime'

martisEventBus.emit('martis:notification-received', { id: 42, title: 'New order' })
```

---

## useRevalidateOnFocus Hook

Resources and Tools built on `useQuery` already get `refetchOnWindowFocus` from the react-query default: the data revalidates automatically when the operator returns to a backgrounded Martis tab. Custom Tools that fetch data manually (no react-query) don't get this for free — `useRevalidateOnFocus` closes that gap.

```tsx
import { useCallback, useEffect, useState } from 'react'
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

### Data freshness across sessions — the mental model

Reason about staleness explicitly rather than discovering it via a duplicate action:

- **On focus (built-in).** Resource lists/details and any Tool query built on `useQuery` revalidate when the tab regains focus or the network reconnects — the react-query defaults are on. This is **gated by `staleTime` (30 s)**: within 30 s of the last fetch the cached data is treated as fresh and no refetch fires; after that, returning to the tab refetches. So a second tab converges on its own, but only after the data has gone stale — not instantly.
- **Push (opt-in, no broadcaster).** For immediate cross-session convergence, bridge your own transport (ws-gateway, SSE, or an Echo listener you write) to `martisEventBus`: emit `martis:refresh-index` (`{ resourceKey }`) when another session mutates a resource, and use `useRevalidateOnFocus` for manual-fetch Tools. Martis ships **no broadcaster** — it exposes the seams, you feed them (the same pattern as the notification bell's `martis:notification-received` / `martis:notifications-changed`).
- **No polling for lists.** Unlike the notification bell (which polls), Resource/Tool data is not polled — freshness comes from focus-revalidation + whatever push you wire. If you need tighter guarantees on a specific mutation, pair a backend conflict guard (e.g. a 409 on a stale write) with the push seam above.

---

## useUnsavedChangesGuard Hook

Wraps a form with the package-wide unsaved-changes guard. Reads the resource's `confirmUnsavedChanges` flag from the schema, snapshots initial values, and intercepts navigation when the form is dirty. The full-page create and update forms use it; a custom create or update override takes it from `@martis/runtime` (v1.38.0+).

```tsx
import { useMemo, useState } from 'react'
import { useUnsavedChangesGuard } from '@martis/runtime'

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

The hook integrates with React Router's `useBlocker`, so navigation via `<Link>` or `useNavigate()` triggers the dialog. It holds the browser back button only while the form is dirty (v1.38.0+): the first render where `values` differ from `initialSnapshot` pushes a copy of the page's history entry, and the Back press that removes it opens the dialog. A clean form leaves the history alone, so Back and Forward work as on any other page. It also integrates with the modal-history lock primitives in `resources/js/lib/historyLock.ts`: a modal open over the form keeps the back button, and a form that turns dirty while a modal holds the top history entry is guarded once the modal closes. Before v1.38.0 the hook pushed its history entry on mount, which erased the Forward history and could make Back skip a page.

If the form stays on the page after a save, pass the values the save sent as the new `initialSnapshot`, not the values the form holds when the request returns: the inputs stay editable while it runs, and what the user typed meanwhile is still unsaved.

## useError Hook

Centralised error state management for the forms and pages of an extension (on `@martis/runtime` since v1.38.0).

```tsx
import { api, useError } from '@martis/runtime'

const { errors, setError, clearErrors, clearFieldError, hasErrors } = useError()

try {
  await api.post('/api/posts', data)
} catch (err) {
  setError(err) // Handles ApiError, Error, or string
}

// Render errors:
{errors.message && <p className="text-destructive">{errors.message}</p>}
{errors.fieldErrors.title && <p className="text-destructive">{errors.fieldErrors.title}</p>}
```

| Property / Method | Type | Description |
|-------------------|------|-------------|
| `errors` | `{ message: string \| null; fieldErrors: Record<string, string>; apiError: ApiError \| null }` | Current error state. `fieldErrors` keeps the first message of each field; `apiError` is the caught `ApiError`, if any. |
| `setError(err)` | `(err: unknown) => void` | Parse and set errors from a caught exception: an `ApiError` fills `message`, `fieldErrors` and `apiError`, another `Error` or a string fills `message`, anything else sets a generic message. |
| `clearErrors()` | `() => void` | Reset all error state |
| `clearFieldError(field)` | `(field: string) => void` | Drop one field's error, e.g. when the user edits that field |
| `hasErrors` | `boolean` | Whether a message or any field error is set |

---

## useOverrideProps Hook

React context primitive that carries the live `OverrideProps` payload (the `schema`, `record`, `recordId`, `params`, navigation callbacks, etc.) every drawer or page override receives. Wrap children with the provider and any deeply-nested component reads the same payload without prop-drilling.

```tsx
import { OverridePropsProvider, useOverrideProps, useOverridePropsOptional, type OverrideProps } from '@martis/runtime' // v1.38.0+

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

Opt-in. Overrides that prefer manual prop passing don't need to wrap. The provider is the override's own: the package mounts none around it.

## usePageTitle Hook

Sets `document.title` for the currently-mounted page and restores the previous title on unmount, so stacked drawers and modals do not leave stale segments after they close. The package's pages call it; a custom page or override takes it from `@martis/runtime` (v1.38.0+).

```tsx
import { usePageTitle } from '@martis/runtime'

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
import { useIsMobile } from '@martis/runtime'

const isMobile = useIsMobile()           // default 768px
const isNarrow = useIsMobile(540)        // custom breakpoint

return isMobile ? <CompactToolbar /> : <FullToolbar />
```

Prefer CSS media queries when the layout swap is purely visual; reach for this hook when the difference is structural (different React tree, different data fetch, etc.).

---

## Tooltip Standard (PrimeReact)

All tooltips in Martis **must** go through the global `data-pr-tooltip` pattern (the PrimeReact attribute convention), rendered by the bundled `MartisTooltip` provider, or through the ref-based `Tooltip` export for JSX content. Native HTML `title=` attributes and ad-hoc tooltip implementations are prohibited. Extensions cannot import `primereact/tooltip` directly (the extension build does not alias `primereact`): use `data-pr-tooltip`, or `Tooltip` from `@martis/runtime`.

A global tooltip provider (`MartisTooltip`) is registered in the layout targeting `[data-pr-tooltip]`, so any element with `data-pr-tooltip` automatically gets a tooltip.

### Simple tooltip (recommended)

```tsx
<button
  data-pr-tooltip="Delete this record"
  data-pr-position="top"
>
  <Trash size={16} />
</button>
```

### Rich content: markup or React

The global provider renders `data-pr-tooltip` as plain text, so markup in it
shows literally. Rich content takes one of two routes, depending on what it
is made of:

- **Markup you write: add `data-pr-tooltip-html="true"`.** The global provider
  then renders the attribute as HTML (line breaks, bold, lists) in the same
  bubble, with the same placement and delay as a plain tooltip and a roomier
  layout for paragraphs. The field label tooltips (`->tooltip()`), the metric
  help and the cache page use it. The markup goes into the page as is, without
  sanitising: use it for markup you control (your own strings, translations),
  never for text that comes from users or records.

  ```tsx
  <span
    data-pr-tooltip="<strong>Re-index</strong><br/>Rebuilds the search index."
    data-pr-tooltip-html="true"
    data-pr-position="top"
  >
    <InfoIcon size={14} />
  </span>
  ```

- **React content: the ref-based `Tooltip` with JSX `content`.** For content
  made of components (an icon, a formatted value, a small table), content
  built from data (JSX escapes it), or PrimeReact options such as
  `autoHide={false}` for a tooltip the pointer can move into. PrimeReact's
  `Tooltip` has no `escape` prop, and a string `content` is plain text:

  ```tsx
  // Package-internal: an extension imports Tooltip from '@martis/runtime' (see below).
  import { Tooltip } from 'primereact/tooltip'
  import { useRef } from 'react'

  const btnRef = useRef(null)

  <button ref={btnRef}>Re-index</button>
  <Tooltip target={btnRef} position="top" content={<div><b>Re-index</b><br/>Rebuilds the index.</div>} />
  ```

### From a consumer Tool / extension

Extension bundles can't `import { Tooltip } from 'primereact/tooltip'` — the
extension build doesn't alias `primereact`. Reach the same component off the
runtime surface (`window.Martis.runtime`, since v1.19.0), exactly like
`FieldInput` / `DrawerShell`:

```tsx
import { Tooltip } from '@martis/runtime' // shim → window.Martis.runtime.Tooltip

<button ref={ref}>Re-index</button>
<Tooltip target={ref} position="top"
  content={<div className="martis-…"><b>Re-index</b><br/>Rebuilds the index.</div>} />
```

### Long text and viewport edges

The global `[data-pr-tooltip]` provider (`MartisTooltip`) lays the bubble out
before placing it, so any trigger can carry a sentence (v1.38.0+):

- The text wraps inside a shrink-to-fit bubble of at most 360 px (or the
  viewport width minus 16 px on a narrow screen); a one-word label keeps its
  single-line pill, and a long unbroken token breaks inside the bubble.
- The bubble stays inside the viewport with an 8 px margin. A trigger near an
  edge keeps the full-width bubble, shifted inward, with the arrow still on
  the trigger; when the requested side (`data-pr-position`) has no room the
  bubble flips to the opposite side, and a `left` / `right` bubble with room
  on neither side goes above or below the trigger.
- While open it follows its trigger through page and container scrolls and
  closes when the trigger scrolls out of view.

No escaping workaround is needed for a long plain-text tooltip: keep it on
`data-pr-tooltip` and reserve `data-pr-tooltip-html="true"` for real markup.

### Rules

| Rule | Detail |
|------|--------|
| ❌ Never use `title=` | Native browser tooltips are inconsistent across themes |
| ❌ Never build custom tooltip divs | Breaks dark/light mode consistency |
| ✅ Always use `data-pr-tooltip` for simple text | Covered by global provider |
| ✅ Add `data-pr-tooltip-html="true"` for markup you write | Same bubble and placement; not sanitised, so never for user or record data |
| ✅ Use the ref-based `<Tooltip>` for React content | JSX `content` (escaped), full PrimeReact API |
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

**Usage** (from an extension, on `@martis/runtime` since v1.38.0; it renders the loader registered under `loader` when there is one):

```tsx
import { MartisLoader } from '@martis/runtime'

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
import { componentRegistry } from '@martis/runtime'
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

The `avatarColorForSeed` helper (on `@martis/runtime` since v1.38.0) returns a deterministic colour for any seed string, picking one of the 16 `--martis-avatar-1..16` token hues. Two users with the same name always get the same colour, and the colour stays stable across light/dark themes. The server picks the avatar slots with the same hash, so for a user with a name it is the colour of their Topbar avatar:

```ts
import { avatarColorForSeed } from '@martis/runtime'

<span
  className="martis-avatar martis-avatar-md martis-avatar-circle"
  style={{ backgroundColor: avatarColorForSeed(user.name) }}
>
  {user.avatar_initials}
</span>
```

The signed-in user (`useAuth().user`) already carries both: `avatar_initials`, and `avatar_palette`, the slot to paint as `var(--martis-avatar-${user.avatar_palette})`.

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
| `martis-form-grid` | Grid gap of a form or detail field grid (16px, 10px dense); pair with `martis-input-wrap` per field. |
| `martis-field-grid` | Responsive field grid: tracks from `--martis-field-columns` (12 by default), each child placed from its `--martis-field-span` / `-md` / `-lg` custom properties, full row below 768px. See [Grid Layout](grid-layout.md#responsiveness). |

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
| `martis-dropzone.is-readonly` | 0.6 opacity, `not-allowed` cursor, no accent on hover. `FileField` / `ImageField` set it on a readonly field, which ignores dropped files. |
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
