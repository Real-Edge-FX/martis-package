# Martis Differentials

> Features unique to Martis. These are product advantages that go beyond
> baseline admin-panel capabilities.

## Override System

### Four-Tier Component Resolution

Replace any React component at the right granularity level without forking the package.

Resolution order (highest priority first):
1. **Explicit key** — `->component('my-custom-field')` on a specific field instance
2. **Per-resource** — `componentRegistry.registerResourceFieldDisplay('users', 'status', MyStatusBadge)`
3. **Global type** — `componentRegistry.registerFieldDisplay('select', MyCustomSelect)`
4. **Built-in default** — The package's default component

```php
// PHP: set an explicit component key on a field
Text::make('bio')->component('rich-bio-display')
```

```typescript
// TypeScript: register a custom component in boot.ts
import { componentRegistry } from '@/lib/componentRegistry'
import { RichBioDisplay } from './components/RichBioDisplay'

componentRegistry.register('rich-bio-display', RichBioDisplay)
```

See [Override System](overrides.md) for the full guide.

### Drawer CRUD (Slide-in Panels)

Render Create, Update, and Detail views in slide-out drawers instead of full pages. Reduces context switching and keeps the index visible.

```php
public function overrides(): array
{
    return [
        'create' => DrawerOverride::make()
            ->width('520px')
            ->expandedWidth('800px')
            ->allowExpand()
            ->allowFullscreen()
            ->position('right'),
    ];
}
```

Features: slide animation, expand/collapse, fullscreen toggle, ESC close, backdrop click, configurable width and position.

### Unsaved Changes Guard

Uniform protection against discarding unsaved edits across **every** surface — drawers and full-page create/update routes. Intercepts the close button, ESC, backdrop click, the browser back button, and in-app router links.

```php
use Martis\UnsavedChangesConfig;
use Martis\Contracts\UnsavedChangesConfigContract;

public static function confirmUnsavedChanges(): bool|UnsavedChangesConfigContract
{
    return UnsavedChangesConfig::make()
        ->title(__('projects.unsaved_title'))
        ->body(__('projects.unsaved_body'))
        ->icon('briefcase')
        ->iconColor('info')
        ->confirmLabel(__('projects.unsaved_discard'))
        ->confirmColor('danger')
        ->cancelLabel(__('projects.unsaved_keep'));
}
```

Return `false` (the default) to disable, `true` for the localised package defaults, or an `UnsavedChangesConfig` to override title, body, icon, colours and button labels. The same config applies whether the resource uses a drawer override or the page-based create/update flow.

**Implementation architecture**

- **Browser back/forward** — handled via a history *sentinel* pushed on mount plus a **capture-phase popstate listener** that calls `stopImmediatePropagation()`. This prevents React Router from ever seeing the pop, avoiding the v6 URL-flicker bug. The sentinel is re-armed the moment the dialog opens, so repeated back presses while the dialog is visible stay trapped. On confirm the guard pops the re-armed sentinel (drawer: one `back()`; page: `go(-2)` for sentinel + real entry).
- **In-app navigation** — `useBlocker` (React Router v6.4 data-router API) intercepts `<Link>` clicks and imperative `navigate()` calls. Popstate-originated navigations are deliberately ignored (`historyAction === 'POP'`) because the browser-level listener already owns that path.
- **Modals on top of a guarded surface** — coordinated via `resources/js/lib/historyLock.ts`. See [Modal history locks](#modal-history-locks) below for the two hooks and the full modal surface inventory.
- **`beforeunload` is intentionally NOT wired up.** The browser's native "Are you sure?" prompt was producing double prompts alongside the custom dialog and firing erratically when the previous history entry lived outside the SPA origin. Tab-close protection is an explicit trade-off Martis chose against for UX cleanliness.

### Modal History Locks

Two hooks in `resources/js/lib/historyLock.ts` coordinate modal back-button behaviour with `DrawerShell` and the page-level unsaved-changes guard.

- **`useModalHistoryLock(open: boolean)` — hard lock.** Absorbs browser back indefinitely; the user must dismiss via UI (confirm, cancel, X, Esc, backdrop). Use for destructive confirms and modals holding unsaved input. Consumers today: `DeleteModal`, `ActionModal`, `UnsavedChangesDialog`, `InlineCreateModal`, and every attach/detach/pivot modal inside `BelongsToManyField` and `MorphToManyField`.
- **`useModalHistoryBackToClose(open: boolean, onClose: () => void)` — soft lock.** First browser back closes the modal through `onClose`; a second back navigates normally. Use for non-destructive previews where "back" should mean "close the overlay". Consumer today: `TrixField`'s `ImageModal` (read-only image preview).

#### Modal surface inventory

| Modal | Lock | Reason |
|---|---|---|
| `DeleteModal` | hard | destructive confirm |
| `ActionModal` | hard | mutates data, may have form inputs |
| `UnsavedChangesDialog` | hard | user has unsaved input |
| `InlineCreateModal` | hard | form data |
| `AttachModal` (BelongsToMany / MorphToMany) | hard | multi-select + pivot form |
| `DetachConfirmModal` (BelongsToMany / MorphToMany) | hard | destructive confirm |
| `EditPivotModal` (BelongsToMany) | hard | form data |
| `PivotActionModal` (BelongsToMany / MorphToMany) | hard | mutates pivot rows |
| `TwoFactorWizard` (Profile → Security) | hard | multi-step form with secret material |
| `SecuritySection` disable-2FA dialog | hard | destructive confirm (requires current password) |
| `SecuritySection` recovery-codes dialog | hard | surfaces one-time codes |
| `TrixField` `ImageModal` | soft | read-only preview |
| `MartisTooltip` / peek previews | none | hover-only |

### Layout Presets

Three configurable layout presets with runtime hot-swap:

| Preset | Description |
|--------|-------------|
| `sidebar` | Traditional sidebar navigation (default) |
| `topnav` | Horizontal top navigation |
| `minimal` | Minimal layout with no chrome |

```php
// config/martis.php
'layout' => [
    'preset' => 'sidebar', // or 'topnav', 'minimal'
],
```

### Per-Context Field Overrides

Different components for different contexts (index vs. detail vs. create vs. update):

```php
Text::make('status')
    ->overrideIndex('status-badge')      // Badge on index
    ->overrideDetail('status-detail')    // Rich view on detail
    ->overrideCreate('status-select')    // Dropdown on create
```

---

## Action System Extensions

### Default Row Actions (View, Edit, Delete)

Every resource index automatically ships with a trailing column of built-in row actions (view, edit, delete) — no registration required. Each icon is automatically disabled when the row's authorization payload denies the operation, so unauthorized users still see the action exists but cannot trigger it. Custom inline actions always render **after** the defaults (never replace them unless opted out).

Martis ships view/edit/delete row actions as the default experience, with three layers of customization:

**1. Global config** (`config/martis.php`):

```php
'index' => [
    'default_row_actions' => [
        'enabled' => env('MARTIS_DEFAULT_ROW_ACTIONS', true),
        'view'    => true,
        'edit'    => true,
        'delete'  => true,
    ],
],
```

**2. Per-resource override** — use a method, not a property, so the decision can depend on the request:

```php
class ClientResource extends Resource
{
    public function defaultRowActions(Request $request): bool|array
    {
        // return false;              // hide the column entirely
        // return ['view'];           // show only view
        return ['view', 'edit'];      // pick a subset
    }
}
```

**3. Composition with custom inline actions** — default actions always appear first; any `showInline()` action you define renders to their right.

```
[ 👁  ✏  🗑 ]  [ custom action 1 ]  [ custom action 2 ]  [ ⋮ grouped ]
   defaults             your inline actions follow
```

See [Default Row Actions](default_row_actions.md) for the full guide.

### Action Icons

Every action can display a Phosphor icon from the 1,512 built-in icons:

```php
Action::make('Export PDF')
    ->icon('file-pdf')
    ->iconColor('#dc2626')
```

### Action Groups (Submenus)

Organize actions into hierarchical dropdown menus with dot-notation:

```php
Action::make('Export as CSV')->group('Export.CSV')
Action::make('Export as PDF')->group('Export.PDF')
Action::make('Archive')->group('Transform.Archive')
```

Renders as nested submenus: `Export →` with `CSV` and `PDF` as children.

### Disabled Action State

When `canRun()` returns false for a record, the action appears greyed out with a tooltip instead of being hidden:

```php
Action::make('Publish')
    ->canRun(fn ($request, $model) => $model->status === 'draft')
```

Users see what actions exist but understand why they're unavailable.

### Dry-Run Preview

Preview what an action would do before executing it:

```php
Action::make('Bulk Update Prices')
    ->withDryRun()
```

### Custom Action Components

Render custom React components inside action confirmation modals:

```php
Action::make('Configure Settings')
    ->component('settings-wizard', ['step' => 1])
```

### Post-Processing with then()

Execute a callback after action completion:

```php
Action::make('Import Data')
    ->then(fn () => cache()->forget('dashboard-stats'))
```

---

## Lens System Extensions

### D1 — Sticky Summary Row

> Martis ships a `summary()` hook that renders aggregates as a sticky row under the lens table — no separate Metric required to show totals alongside a lens.

```php
public function summary(Request $request, Builder $query): array
{
    return [
        'revenue' => ['label' => 'Total', 'value' => $query->sum('revenue')],
        'count'   => ['label' => 'Rows',  'value' => $query->count()],
    ];
}
```

### D2 — Declarative Query Cache

> Per-lens cache keeps heavy aggregations warm across requests.

```php
(new MostValuableClients())->cacheFor(60);                         // seconds
(new MostValuableClients())->cacheFor(new DateInterval('PT5M'));   // interval
```

Cache key mixes lens uriKey, filters, search, sort and page so distinct views never collide.

### D3 — Default Filters Pre-Applied

> Martis lets the lens declare defaults that the URL auto-hydrates on first load.

```php
(new OverdueInvoices())->withDefaultFilters(['status' => 'overdue']);
```

### D4 — URL State Sync

> Martis round-trips filters, search, sort, direction and page through the query string, making every lens view deeplinkable.

---

## Filter System Extensions

### DateRangeFilter (Built-in)

Native date range filter with `from` and `to` inputs.

```php
DateRangeFilter::make('Created Between')->column('created_at')
```

### Filter Authorization (canSee)

Per-filter visibility control with filter-level authorization.

```php
SalaryFilter::make('Salary Range')
    ->canSee(fn ($request) => $request->user()->isAdmin())
```

Hidden filters are excluded from the schema AND ignored on the backend.

### Action Authorization — closure-first, policy-second

Martis lets you declare authorization inline on the Action while
still honouring standard policy callbacks:

```php
class Publish extends Action
{
    public function canSee(Request $request): bool
    {
        return $request->user()->hasRole('editor');
    }

    public function canRun(Request $request, Model $model): bool
    {
        return $model->state === 'draft';
    }
}
```

Fallback order when executing an action: `canRun()` closure →
`Policy::runAction` (or `runDestructiveAction` for destructive ones) →
`Policy::update` (or `delete`). This keeps a standard policy
story for teams that want pure-policy flows.

### updatePivot{Model} policy ability

BelongsToMany and MorphToMany pivot edits consult a dedicated
`updatePivot{Model}` policy ability, falling back to `update` on the
parent.

```php
public function updatePivotTag(User $user, Post $post, Tag $tag): bool
{
    return $user->is_editor;
}
```

### Relation fields inherit target policy authorization

Relation field payloads (BelongsTo, HasMany, BelongsToMany,
MorphTo, MorphToMany, MorphMany, MorphOne, HasOne) include
`authorizedToCreate` / `authorizedToViewAny` computed from the **target
resource's** policy. The inline "Create Related" button is suppressed
when the user cannot create the related model, without the developer
needing to toggle `showCreateRelationButton` by hand.

### Active Filter Pills

Visual pill tags showing active filters with name and value, visible even when the filter panel is closed. Each pill has an individual clear button (X).

Every active filter renders as a removable pill, visible even when the dropdown is closed.

### Searchable Select Filters

Built-in search within large filter option lists:

```php
CountryFilter::make('Country')->searchable()
```

---

## Field Extensions

### 26 Extended Field Types (Built-in)

All included without additional packages:

| Field | Description |
|-------|-------------|
| `Audio` ⭐ | Audio upload + **client-side canvas waveform** (zero server deps) + `downloadable(bool)` |
| `Avatar` ⭐ | Image subclass with per-row `fallback($url\|Closure)` + typed `shape(AvatarShape::*)` |
| `Badge` | Colored status badge — closure-backed map/labels and per-row `resolveBadgeUsing()` (⭐) |
| `BooleanGroup` ⭐ | Named boolean flags with `grouped([section => keys])` + `minChecked/maxChecked` live counter + `requireAny/All` |
| `Status` | Status indicator with color mapping |
| `Code` | Code editor with syntax highlighting |
| `Color` | Color picker with preview |
| `Country` | Country selector dropdown |
| `Currency` | Formatted currency display with symbol |
| `Gravatar` | Gravatar avatar from email |
| `Icon` ⭐ | Phosphor icon picker (Martis differential) |
| `KeyValue` | Editable key-value pair table |
| `Markdown` | Markdown editor with preview |
| `MultiSelect` | Multi-select dropdown |
| `PasswordConfirmation` | Companion confirmation field that pairs with `Password::new()` |
| `Slug` | URL-safe auto-generated identifier with live collision check (⭐) |
| `UiAvatar` ⭐ | Deterministic 16-slot palette from seed hash, `colorFrom('attribute')`, custom `initials(Closure)` |
| `Sparkline` | Inline mini chart |
| `Stack` + `Line` ⭐ | Composite display — renders on index and detail |
| `Tag` | Tag input with autocomplete |
| `Timezone` | IANA timezone dropdown with live clock (⭐) |
| `Trix` | Rich text editor with attachment uploads |
| `Url` | URL field with validation and auto-scheme normalisation |
| `Heading` | Section divider with optional content |
| `Hidden` | Hidden field for form data |

### Label Tooltips on Any Field (⭐ Martis 100% differential)

Martis exposes `help()` for plain-text guidance under the input, plus
a **second channel** for contextual guidance that is opt-in by hover:
`->tooltip('<strong>...</strong>')` attaches a `(?)` icon to the field label
and renders **raw HTML** on hover, so authors can pack multi-line, rich hints
(line breaks, bold, lists, inline links) into a single call without bloating
the form.

```php
Text::make('name', 'Full name')
    ->help('Must be unique')
    ->tooltip(
        '<strong>Full legal name</strong>.<br>Examples:<br>'
        .'• John Smith<br>• Ana Pereira<br><br>'
        .'<em>Avoid abbreviations.</em>'
    );
```

**Why it matters**

- `help()` costs permanent vertical space; `tooltip()` costs **zero pixels**
  until the user asks for it by hovering the icon.
- HTML support means rich, localised, multi-line guidance in one string —
  no custom field classes, no side panels.
- Applies **uniformly** to every existing and future field (base-class
  modifier). Renders on Panel, Section, TabGroup, ResourceCreate,
  ResourceUpdate, and detail labels rendered inside Sections/TabGroups.

**Security model**

Only field tooltips render as HTML — every other `data-pr-tooltip` trigger in
the app keeps the default plain-text escape via an explicit
`data-pr-tooltip-html="true"` opt-in set only by the label renderer. Authors
are responsible for producing safe markup, the same way they are for `help()`.

**Why NOT a `Tooltip` field class**

Discussed and deliberately rejected — a `Field` represents a value, not a
decoration; tooltips are a presentation modifier that applies *to* fields, not
a field type of their own. See [Fields → Tooltips](fields.md#tooltips-martis-differential)
for the full rationale and the `tooltip()` vs `help()` decision matrix.

### Icon Field — Phosphor Picker (⭐ Martis 100% differential)

Martis provides three complementary modes for the Icon field:

- **Display-only** — render a named Phosphor icon on the detail/index view. Useful for status cues.
- **Stored with visual picker** — opens a categorised + searchable picker. Saves the icon *name* to the DB (portable, framework-agnostic).
- **Computed from another attribute** — derive the icon from a model field via a closure; the picker is hidden.

Supports palette whitelisting (restrict to a configured subset), `colorFrom()` to pull the hex colour from another attribute, configurable sizes and tooltips. Full API lives in [Fields Reference](fields.md#icon).

### BooleanGroup — Grouped Sections + Live Counter (⭐)

Martis ships `BooleanGroup` with extras beyond a flat list:

- **`grouped([sectionTitle => keys])`** — renders each section as a collapsible panel with its own legend. Keeps 20+ permission flags manageable.
- **`minChecked(int)` / `maxChecked(int)`** — enforced server-side AND surfaced as a live counter pill that turns amber when the user hasn't met the minimum or hits the ceiling.
- **`requireAny()` / `requireAll()`** — convenience presets.

```php
BooleanGroup::make('permissions')
    ->options(['clients.view' => 'View clients', /* … */])
    ->grouped(['Clients' => ['clients.view']])
    ->requireAny();
```

### Avatar + UiAvatar — Identity Pictures with Zero Plumbing (⭐)

Both fields share the same palette + initials logic through the [`ResolvesInitialsPayload`](../src/Fields/Concerns/ResolvesInitialsPayload.php) trait, keeping the topbar pill, login view, profile page, `Avatar` empty state and `UiAvatar` all visually consistent.

**`Avatar` — upload field with a zero-config empty state:**

- **Out of the box**, `Avatar::make('photo')` renders an `<img>` when the user uploaded one, and **coloured initials inline** when they haven't. No fallback closure required. No external service hit.
- `fallback($url | Closure)` is available as an **opt-in** override (per-row Closure-aware).
- `colorFrom('brand_color')` + `initialsFrom('display_name')` + `initials(Closure)` customise the defaults.
- Typed `AvatarShape::Circle | Rounded | Squared` enum.

**`UiAvatar` — always initials, never uploads:**

- Display-only (`hideFromForms()` locked), computed from the model — no DB column.
- Same deterministic 16-slot palette hash. **Martis ships it client-side with no external service call.**
- Same `colorFrom()` / `initials(Closure)` / `from('other_attr')` knobs as `Avatar`.

```php
// Most common case — one line, inline initials for members without a photo.
Avatar::make('avatar_path')->circle()->colorFrom('brand_color');

// Read-only resource without a photo column — pure initials pill.
UiAvatar::make('avatar_initials')->from('name')->colorFrom('brand_color');
```

### Audio — Canvas Waveform, Zero Server Deps (⭐)

The `Audio` field extends `File` with a **client-side waveform**: the audio is fetched, decoded via `AudioContext`, peaks are sampled and painted onto a `<canvas>`. No server-side image rendering, no ffmpeg, no external service. `downloadable(bool)` toggles the download button.

### Stack + Line — Index-capable Composite Display (⭐)

Martis ships `Stack::make(...)` that renders on both index and detail — ideal for compressing identity columns (name + email + company) into a single table cell without writing a custom component. `Line::subtitleFrom('attribute'|Closure)` emits a second muted line below the first without declaring an extra `Line`, and `Stack::divider()` inserts a thin separator between entries.

```php
Stack::make('identity', __('fields.identity'), [
    Line::make('name')->asHeading()->subtitleFrom('email'),
    Line::make('company')->asMuted(),
])->divider();
```

Line variants — `asHeading()`, `asBase()`, `asSmall()`, `asMuted()`, `asCode()` — map to `.martis-line-*` classes so custom themes restyle every Line in the package through a handful of CSS tokens instead of per-field inline styles. See [Fields Reference](fields.md#stack--line).

### Badge Closures — Schema-time and Per-row (⭐)

`Badge::map()`, `->labels()`, `->types()` and `->icons()` accept one of:

- An array — a static map.
- **Zero-arg Closure** — resolved once when the schema is serialised. Perfect for enum-backed palettes: `->map(fn () => StatusEnum::badgeMap())`.
- **One-arg Closure** ⭐ — resolved per row. Receives the raw value (and optionally the model) and returns the single string for that value. Ideal for convention-driven i18n: `->labels(fn (string $v) => __("statuses.$v"))`.

For full control, `->resolveBadgeUsing(fn ($value, $model) => ['type', 'label', 'icon'])` runs per row and returns the resolved payload. Missing keys fall back to the static/closure maps. All per-row results ship to the frontend wrapped in a `__martisBadge` object the Badge component detects transparently.

### Grid Layout System

Responsive multi-column form layouts with `Section::columns()` and `Field::span()`:

```php
Section::make('Details', [
    Text::make('first_name')->span(6),
    Text::make('last_name')->span(6),
    Email::make('email')->span(12),
])->columns(12)
```

Supports responsive breakpoints: `colSpan()`, `colSpanMd()`, `colSpanLg()`.

---

### Repeater — Polymorphic storage, templates, duplicate, bulk paste

The Martis Repeater ships five differentials beyond a basic repeater
API. Full documentation in [repeater.md](repeater.md).

**D1 — `dependsOn([parent attributes])`**
Every field inside every row receives the parent record's attributes in
`formValues`, so conditional logic can react to the record state without
leaving the row.

**D2 — Cardinality + collapse + reorder**
`minRows()`, `maxRows()`, `collapsible()`, `collapsedByDefault()`,
`reorderable()` with an auto-managed `position` column in HasMany /
Polymorphic mode. Native HTML5 drag-and-drop — zero extra dependency.

**D3 — Row header affordances**
`Repeatable::icon()`, `->color()`, `->title('{field} · {field}')`
(template resolved per row) and `->badgeCount()`. Martis surfaces real
row context instead of just the class basename.

**D4 — `rowTemplates()`, duplicate row, bulk paste**
Pre-filled row templates group beneath the Add button, each row header
gets a one-click duplicate, and a "Colar linhas" footer button parses
TSV / CSV / JSON clipboard content into rows (header auto-detection when
the first line matches field attribute names).

**D5 — `asPolymorphic()`**
Single child table shared by every row type, discriminated by a `type`
column and a JSON `payload`. Ideal for page-builder-style layouts without
one table per Repeatable type.

```php
Repeater::make('blocks')
    ->asPolymorphic('type', 'payload')
    ->uniqueField('uuid')
    ->reorderable()
    ->repeatables([HeroBlock::make(), TextBlock::make(), GalleryBlock::make()]);
```

---

## UI Primitives

### Standardised Button Classes

Every confirm/cancel/destructive/secondary button in the admin shares the same chip geometry, shadow and hover choreography. Custom override components can drop in the same classes and inherit the full styling for free.

| Class | Purpose | Background | Hover |
|-------|---------|------------|-------|
| `.martis-btn-primary` | Default confirm (save, create, run) | `--martis-accent` | `--martis-accent-hover` |
| `.martis-btn-secondary` | Cancel / tertiary | `--martis-card` + subtle border | Overlay + lift |
| `.martis-btn-danger` | Destructive confirm (delete) | `--martis-danger` | `--martis-danger-hover` |
| `.martis-btn-warning` | Archive / reversible destructive | `--martis-warning` | `--martis-warning-hover` |
| `.martis-btn-filled` | Dynamic `backgroundColor` via inline style (e.g. `UnsavedChangesDialog` honouring a user-provided `confirmColor`) | inline | `filter: brightness(0.92)` |

Shared across every variant:

- **Resting**: 1px border, `box-shadow: 0 1px 2px rgba(15,23,42,0.08)`, 6px radius, `500` weight, 0.875rem font, `0.5rem 1rem` padding.
- **Hover** (not `:disabled`): deeper shadow (`0 3px 6px rgba(15,23,42,0.14)`) plus `translateY(-1px)` lift.
- **Active**: shadow collapses, `translateY(0)`.
- **Disabled**: no shadow, opacity `0.6`, cursor `not-allowed`.
- **Focus-visible**: 2px `--martis-accent` outline with 2px offset.

```tsx
// Inside a custom action or override component
<button className="martis-btn-primary">Save</button>
<button className="martis-btn-secondary">Cancel</button>
<button className="martis-btn-danger">Delete</button>
```

Where the package uses them already: `DrawerShell` footer, `DrawerCreate`/`DrawerUpdate`, full-page `ResourceCreate`/`ResourceUpdate`, `ActionModal`, `DeleteModal`, `UnsavedChangesDialog`.

---

## Metrics & Dashboard Extensions

### Dashboard Filters

> Martis supports dashboard-level filters that affect every card.

Martis allows declarative filters on dashboards that affect all cards. This reuses the same Filter system from resources:

```php
class SalesDashboard extends Dashboard
{
    public function filters(Request $request): array
    {
        return [
            DateRangeFilter::make('Period')->column('created_at'),
            RegionFilter::make('Region'),
        ];
    }
}
```

### Responsive 12-Column Metric Grid

> Martis supports arbitrary 12-column widths plus responsive breakpoints.

Martis uses a 12-column grid with responsive breakpoints:

```php
TotalUsers::make('Total Users')
    ->width(12)       // mobile: full width
    ->widthMd(6)      // tablet: half
    ->widthLg(4)      // desktop: one-third
```

Fraction strings are auto-converted for convenience.

### Metric Polling (Auto-Refresh)

> Martis supports automatic polling in addition to manual refresh.

Martis supports automatic polling with a visual "LIVE" indicator:

```php
ActiveUsersNow::make('Active Users')->refreshEvery(30)
```

Cards with polling auto-refresh without user interaction. Minimum interval: 5 seconds.

### Card Icons

> Martis supports icons on metric cards.

```php
TotalUsers::make('Total Users')->icon('users')
```

Icons use the built-in Phosphor Icons library (1,512 icons).

### Card Styles

> Martis supports visual card styling.

```php
use Martis\Enums\CardStyle;

Revenue::make('Revenue')->style(CardStyle::Success)  // green accent
```

Available: `Default`, `Success`, `Warning`, `Danger`, `Info`.

### Card Height Control

> Martis supports explicit card height control.

```php
UsersByRole::make('By Role')->height(300)
```

### Filter Grid Layout (span)

> Martis supports filter layout control via spans.

```php
StatusFilter::make('Status')->span(4)          // 1/3 width
DateRangeFilter::make('Period')->span(8)       // 2/3 width
```

### Global Cache Configuration

> Martis adds global cache defaults on top of per-metric caching.

```php
// config/martis.php
'cache' => [
    'metrics' => 5,        // 5 minutes default
    'dashboards' => null,  // no cache
    'navigation' => 1,     // 1 minute
],
```

---

## Authentication & Profile

### User Profile Page

Built-in self-service profile page with configurable sections:

| Section | Features |
|---------|----------|
| Account | Name, email editing |
| Password | Current + new password change |
| Avatar | Upload, preview, remove |
| Security | 2FA setup, recovery codes |

```php
// config/martis.php
'profile' => [
    'enabled' => true,
    'sections' => ['account', 'password', 'avatar', 'security'],
],
```

### Two-Factor Authentication (TOTP)

Enterprise-grade 2FA with TOTP:
- QR code setup wizard
- App verification step
- 8 recovery codes (configurable)
- Login challenge middleware
- Enable/disable from profile

### Avatar Upload

Configurable avatar storage with thumbnail preview:

```php
'avatar' => [
    'enabled' => true,
    'disk' => 'public',
    'path' => 'avatars',
    'max_size_kb' => 2048,
    'column' => 'profile_picture',
],
```

---

## Frontend Utilities

### Event Bus

Decoupled pub/sub for cross-component communication:

```typescript
import { useEventBus } from '@/lib/useEventBus'

const { emit, on } = useEventBus()

// Emit an event
emit('martis:record-created', { resource: 'users', id: 42 })

// Listen for events
on('martis:record-created', (payload) => {
    console.log('New record:', payload)
})
```

Built-in events: `martis:record-created`, `martis:record-updated`, `martis:record-deleted`, `martis:record-restored`, `martis:action-executed`, `martis:refresh-index`.

### Configurable Loader

Customizable loading indicator:

```php
'loader' => [
    'logo' => '/images/my-logo.png',
    'spinnerColor' => '#6366f1',
    'overlayOpacity' => 0.6,
    'disableOn' => ['table' => false, 'search' => true],
],
```

### User Preferences (Task 07.1)

> Martis persists per-user theme/accent/density/locale.

Martis ships a full preferences system backed by `martis_user_preferences` (one row per user). Preferences survive across devices and sessions.

**⭐ D1 — Arbitrary brand colour per tenant.** Optional hex input (`#RGB`/`#RRGGBB`/`#RRGGBBAA`) overrides the preset accent. Off by default (`allowBrandColor = false`) — flip on for multi-tenant apps where each tenant has its own colour.

**⭐ D2 — Persisted preferences + shareable URL presets.** Named presets (`?preset=exec-comfort`) compose over the user row so shared links don't overwrite recipient defaults. Resolution chain: URL preset > user row > config defaults.

**⭐ D3 — Density per surface + reduced-motion enforced.** `[data-density="dense"]` tokens cut row/button/input heights (including dashboard cards — StatCard, MetricCard, resource shortcuts, welcome hero). `[data-reduced-motion="true"]` clamps every `--martis-dur-*` to `1ms` AND applies a universal `*, *::before, *::after { transition-duration: 1ms; animation-duration: 1ms }` override so inline styles, third-party widgets, and raw Tailwind utilities also honour the preference. The same rules apply under the OS-level `prefers-reduced-motion: reduce` media query.

The preferences panel is a compact topbar overlay — theme / accent / density / language / accessibility. See [preferences.md](preferences.md) for the resolver, API, and SSR no-flash mechanics.

### Comprehensive Theme System

> Martis exposes 94 CSS variables across 13 categories.

A single theme file controls the entire admin panel:

- **Background layers** (7 vars) — page bg, surfaces, sidebar, topbar, cards, inputs
- **Text & borders** (3 vars)
- **Accent / brand** (6 vars) — primary, hover, active, alpha tints, focus ring
- **Semantic colors solid** (8 vars) — success/warning/danger/info + hover variants
- **Semantic backgrounds** (8 vars) — for badges, alerts, status
- **Interactive states** (4 vars) — hover, active, search overlay
- **Overlays & shadows** (5 vars) — modal backdrop, sm/md/lg shadows, peek
- **DataTable** (5 vars) — header, rows, borders
- **Border radius** (5 vars) — from sm to full pill
- **Typography** (15 vars) — font families (sans/mono/heading), 7-step size scale, 4 weights, 3 line heights
- **Chart palette** (10 vars) — for partition/trend metrics
- **File icons** (6 vars) — semantic per file type
- **Badge variants** (12 vars) — legacy compatibility

```bash
# Generate a theme scaffold with all variables
php artisan martis:theme MyTheme
```

The generated stub includes all 94 variables in both dark mode (`:root`) and light mode (`html:not(.dark)`), with comments and grouping. Edit any value, refresh the browser — no rebuild needed.

See [Theming Guide](theming.md) for the complete variable reference.

### Standardized Clear Button (`<ClearButton />`)

> Martis provides consistent clear behaviour across field types.

Martis ships a reusable `<ClearButton />` component. All input-like fields (Text, Email, URL, Password, Number, Currency, Date, DateTime, Select, Country, MultiSelect, BelongsTo, MorphTo, Tag) use it consistently:

- Always red (`var(--martis-danger)`)
- Always has `Clear` tooltip (translated)
- Only renders when `field.nullable === true && hasValue && !field.readonly`
- Hover: darker red, no background fill

For consumer apps building custom fields:

```tsx
import { ClearButton } from '@/components/ClearButton'

<ClearButton
  visible={field.nullable && hasValue && !field.readonly}
  onClick={() => onChange(null)}
  style={{ position: 'absolute', right: '0.5rem', top: '50%', transform: 'translateY(-50%)' }}
/>
```

### Theme-Aware Chart Colors

> Martis chart palettes are themeable via CSS variables.

Chart.js can't read CSS variables natively. Martis provides a runtime resolver:

```tsx
import { chartPalette, accentColor, mutedTextColor, resolveColor } from '@/lib/themeColors'

const colors = chartPalette()              // ['#6366f1', '#22c55e', ...] resolved from --martis-chart-*
const accent = accentColor()                // resolved --martis-accent
const muted = mutedTextColor()              // resolved --martis-text-muted
const safe = resolveColor('var(--my-var)')  // works on var() OR literal hex
```

Charts re-render with the correct theme colors automatically when the theme changes.

### Per-Metric Color Override

> Martis metrics support per-metric color overrides.

Any metric can specify a custom chart color via `->color()`:

```php
// TrendMetric: line color
RevenueMetric::make()->color('var(--martis-success)');

// ProgressMetric: bar fill (overrides semantic)
TaskCompletion::make()->color('var(--martis-warning)');

// PartitionMetric: per-label color map
ProjectsByStatus::make()->colors([
    'Active' => 'var(--martis-success)',
    'Paused' => '#f59e0b',
    'Done' => 'rgb(59, 130, 246)',
]);
```

Accepts any CSS color value — hex, rgb, rgba, hsl, named colors, or `var(--martis-*)`.

### Component Scaffolding

Generate custom dashboard cards, field overrides, and visual components:

```bash
# Custom dashboard card — creates PHP class + React component + auto-registers
php artisan martis:card WelcomeCard

# Visual override — creates TSX component + auto-registers (no PHP)
php artisan martis:override StatusBadge --type=field
```

The `martis:card` command creates both the PHP class (`app/Martis/Cards/`) and the React component, auto-registering it in the boot file. The developer only needs to add the card to a Dashboard's `cards()` method.

The `martis:override` command creates a TSX-only component for overriding how existing fields, layouts, or footers render — without needing a PHP class.

### Custom Dashboard Cards

> Martis cards avoid manual PHP class creation and separate frontend registration.

Martis provides a single command that scaffolds everything:

```bash
php artisan martis:card RevenueChart
```

This creates:
1. `app/Martis/Cards/RevenueChart.php` — with `componentKey()` pre-configured
2. `resources/martis-extensions/martis/components/RevenueChart.tsx` — starter React component
3. Auto-registers in `boot.ts`

Usage in a Dashboard:

```php
public function cards(Request $request): array
{
    return [
        (new RevenueChart())->withMeta(['currency' => 'EUR']),
    ];
}
```

The React component receives all `meta` data as props.

### Card Width and Framing

> Martis cards ship with grid spans and chrome handled by the framework — the developer only writes the inner body.

Martis Cards expose two declarative methods on the backend:

```php
Card::make('Revenue')
    ->componentKey('revenue-card')
    ->width(6)        // grid-column span (1-12), defaults to 4
    ->framed();       // wrap custom component in the default MetricCard chrome
```

- `width(int)` — the Dashboard grid wraps the custom component in a `div` with `grid-column: span {width}`, so the component body never needs to touch `gridColumn` itself.
- `framed(bool = true)` — when `true`, the component renders inside the standard `MetricCard` container (title, icon, border). Defaults to `false` so hero-style cards can render full-bleed.

---

## Developer Experience

### Artisan Command Suite

| Command | Description |
|---------|-------------|
| `martis:install` | Full installation with optional `--with-profile` and `--with-2fa` |
| `martis:resource` | Generate a resource class |
| `martis:field` | Generate a custom field (PHP + React TSX) |
| `martis:action` | Generate an action (`--destructive` for destructive variant) |
| `martis:filter` | Generate a filter (`--boolean`, `--date` variants) |
| `martis:card` | Generate a custom dashboard card (PHP + React TSX + auto-register) |
| `martis:override` | Scaffold a React override component (TSX only, auto-register) |
| `martis:policy` | Generate a resource policy |
| `martis:theme` | Scaffold a custom theme with all CSS variables (dark + light) |
| `martis:value` | Generate a value metric |
| `martis:trend` | Generate a trend metric |
| `martis:partition` | Generate a partition metric |
| `martis:progress` | Generate a progress metric |
| `martis:dashboard` | Generate a dashboard class |
| `martis:user` | Create an admin user |

### Action Event Logging

Automatic audit log for all action executions:

```php
// config/martis.php
'action_events' => [
    'enabled' => true,
    'resource' => true, // Show audit log in sidebar
],
```

### MartisTooltip (Custom Tooltip Engine)

Martis uses a custom `MartisTooltip` component instead of the PrimeReact `<Tooltip>`. It uses event delegation on the document level, which solves several PrimeReact Tooltip limitations:

- Works reliably with dynamically rendered elements (conditional buttons, pills, drawers)
- No tooltip "skip" when hovering quickly between adjacent items (e.g. sidebar menu)
- Instant switch between targets without delay when moving between tooltip elements
- First hover uses 300ms delay; subsequent hovers between targets are immediate

All elements use `data-pr-tooltip` and `data-pr-position` attributes:

```tsx
<button data-pr-tooltip="Clear search" data-pr-position="top">
  <XIcon size={14} />
</button>
```

### Panel and Section Descriptions

> Martis supports descriptions/subtitles on Panels and Sections.

Add contextual descriptions below Panel and Section titles to guide users through complex forms:

```php
Panel::make('Security Settings', [
    Text::make('password'),
])->description('Authentication and access control settings.')

Section::make('Timeline', [
    Date::make('start_date')->span(6),
    Date::make('end_date')->span(6),
])
    ->columns(12)
    ->description('Project timeline dates in a 2-column grid.')
```

### Help Text with Inline HTML

> Martis supports inline HTML in field help text.

Martis `->help()` supports inline HTML for rich help text with bold, links, and code:

```php
Text::make('password')
    ->help('Minimum 8 characters. Use a <strong>strong password</strong>.')

Email::make('email')
    ->help('See our <a href="/privacy">privacy policy</a> for details.')
```

### Field Layout Methods

> Martis combines `fullWidth()` and `stacked()` with the Section grid system for fine-grained layout control.

```php
Text::make('bio')->fullWidth()     // Spans the full form width
Text::make('status')->stacked(false) // Label inline beside the input
```

### Form Field Declaration Order Preserved

> Martis renders Panels and scalar fields in declaration order.

Martis respects the exact order fields and layout containers are declared in `fieldsForCreate()` / `fieldsForUpdate()`. Scalar fields, Panels, Sections, and TabGroups are rendered in the position the developer defined — no automatic reordering.
