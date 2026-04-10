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
    'showMetrics' => true,
    'showResourceCards' => true,
],
```

### Login

Authentication page with email/password form. Uses Sanctum session-based auth.

## Layout Components

### Layout (Shell)

The main application shell that wraps all pages. Resolves layout preset from config and checks authentication.

**Presets:**

| Preset | Component | Description |
|--------|-----------|-------------|
| `sidebar` | SidebarLayout | Left sidebar + top bar + main content (default) |
| `topnav` | TopnavLayout | Top navigation bar + main content |
| `minimal` | MinimalLayout | Minimal header + main content |

Override the layout for a specific resource using `layoutRegistry`:

```typescript
import { layoutRegistry } from '@martis/martis/lib/layoutRegistry'
layoutRegistry.register('users', CustomUserLayout)
```

### Sidebar

Collapsible left navigation panel.

**Features:**
- Resource grouping by `group()` method
- Expandable group sections
- Dashboard quick link
- Phosphor icons per resource
- Collapse state persisted to localStorage
- Responsive: shows icons only when collapsed

### Topbar

Top navigation bar with:
- Brand logo/text
- Global search input (configurable: bar, icon, or disabled)
- User menu with theme toggle, profile, and logout
- Mobile-responsive search mode

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

### Breadcrumbs

Navigation breadcrumbs showing the current path (Dashboard > Resource > Record).

### ResourceIcon

Renders Phosphor icons by name. Accepts kebab-case or PascalCase icon names from the [Phosphor Icons](https://phosphoricons.com/) library (1,512 icons available).

```php
// In Resource
public function icon(): string
{
    return 'users';        // kebab-case
    // return 'Users';     // PascalCase also works
}
```

### LoadingSkeleton

Skeleton loading placeholders with pulse animation. Displayed while data is being fetched.

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
import { api } from '@martis/martis/lib/api'

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
import { config, API_BASE_URL, BASE_PATH } from '@martis/martis/lib/config'

config.theme?.default      // 'dark' or 'light'
config.layout?.preset      // 'sidebar', 'topnav', 'minimal'
config.search?.enabled     // boolean
config.footer?.text        // string
API_BASE_URL               // e.g. 'http://app.test/martis'
BASE_PATH                  // e.g. '/martis'
```

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
import { MartisLoader } from '@martis/martis/components/Loader'

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
import { componentRegistry } from '@martis/martis/lib/componentRegistry'
componentRegistry.register('loader', MyCustomLoader)
```
