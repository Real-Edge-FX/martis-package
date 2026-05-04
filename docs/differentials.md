# Highlights

A tour of the surfaces Martis ships beyond the bare-essential admin-panel
contract — features that exist as first-class APIs in the package, with
their own configuration, generators, or escape hatches. Each entry below
links into the dedicated reference for the full surface.

This page is descriptive: it tells you **what is in the box**. It does
not benchmark Martis against any other package — for ecosystem
comparisons see the project README.

---

## Override System

### Four-Tier Component Resolution

Replace any React component at the right granularity level without
forking the package.

Resolution order (highest priority first):

1. **Explicit key** — `->component('my-custom-field')` on a specific field instance.
2. **Per-resource** — `componentRegistry.registerResourceFieldDisplay('users', 'status', MyStatusBadge)`.
3. **Global type** — `componentRegistry.registerFieldDisplay('select', MyCustomSelect)`.
4. **Built-in default** — the package's default component.

```php
// PHP: set an explicit component key on a field
Text::make('bio')->component('rich-bio-display')
```

```typescript
// TypeScript: register a custom component under `resources/js/martis-extensions/`
import { componentRegistry } from '@/lib/componentRegistry'
import { RichBioDisplay } from './components/RichBioDisplay'

componentRegistry.register('rich-bio-display', RichBioDisplay)
```

Full guide: [Override System](overrides.md).

### Drawer CRUD (slide-in panels)

Render Create, Update, and Detail in a slide-out drawer instead of a
full page. Reduces context switching and keeps the index visible.

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

Slide animation, expand/collapse, fullscreen toggle, ESC to close,
backdrop click, configurable width and position.

### Unsaved Changes Guard

Uniform protection against discarding unsaved edits across **every**
surface — drawers and full-page create/update routes. Intercepts the
close button, ESC, backdrop click, the browser back button, and
in-app router links.

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

Return `false` (the default) to disable, `true` for the localised
package defaults, or an `UnsavedChangesConfig` to override title, body,
icon, colours and button labels. The same config applies whether the
resource uses a drawer override or the page-based create/update flow.

**Implementation**

- **Browser back/forward** — handled via a history *sentinel* pushed on
  mount plus a **capture-phase popstate listener** that calls
  `stopImmediatePropagation()`. This prevents React Router from ever
  seeing the pop, avoiding the v6 URL-flicker bug. The sentinel is
  re-armed the moment the dialog opens, so repeated back presses while
  the dialog is visible stay trapped. On confirm the guard pops the
  re-armed sentinel (drawer: one `back()`; page: `go(-2)` for sentinel
  + real entry).
- **In-app navigation** — `useBlocker` (React Router v6.4 data-router
  API) intercepts `<Link>` clicks and imperative `navigate()` calls.
  Popstate-originated navigations are deliberately ignored
  (`historyAction === 'POP'`) because the browser-level listener
  already owns that path.
- **Modals on top of a guarded surface** — coordinated via
  `resources/js/lib/historyLock.ts`. See *Modal History Locks* below.
- **`beforeunload` is intentionally NOT wired up.** The browser's
  native "Are you sure?" prompt produced double prompts alongside the
  custom dialog and fired erratically when the previous history entry
  lived outside the SPA origin. Tab-close protection is an explicit
  trade-off Martis chose against for UX cleanliness.

### Modal History Locks

Two hooks in `resources/js/lib/historyLock.ts` coordinate modal
back-button behaviour with `DrawerShell` and the page-level unsaved
changes guard.

- **`useModalHistoryLock(open: boolean)` — hard lock.** Absorbs browser
  back indefinitely; the user must dismiss via UI (confirm, cancel, X,
  Esc, backdrop). Use for destructive confirms and modals holding
  unsaved input.
- **`useModalHistoryBackToClose(open, onClose)` — soft lock.** First
  browser back closes the modal through `onClose`; a second back
  navigates normally. Use for non-destructive previews where "back"
  should mean "close the overlay".

Modal surface inventory:

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
| `minimal` | Minimal layout with no chrome (no topbar, no sidebar) |

```php
// config/martis.php
'layout' => [
    'preset' => 'sidebar', // or 'topnav', 'minimal'
],
```

The `minimal` preset is intended for embedded uses — a Martis surface
mounted inside an outer SaaS shell that already provides its own
chrome.

### Per-Context Field Overrides

Different components for different contexts (index vs. detail vs.
create vs. update):

```php
Text::make('status')
    ->overrideIndex('status-badge')      // Badge on index
    ->overrideDetail('status-detail')    // Rich view on detail
    ->overrideCreate('status-select')    // Dropdown on create
```

Per-context overrides compose on top of the global tier-resolution
order described above.

---

## Action System

### Dry-Run Preview

Preview what an action would do before executing it:

```php
Action::make('Bulk Update Prices')
    ->withDryRun()
```

The action runs in a "dry" mode that returns a structured preview
(rows that would be touched, fields that would change, errors) without
committing. The confirm dialog renders the preview before the user
hits the actual run button.

### Post-Processing with `then()`

Execute a callback after action completion:

```php
Action::make('Import Data')
    ->then(fn () => cache()->forget('dashboard-stats'))
```

Useful for cache busting, queue dispatching, side-effects that should
not block the action's main success response.

### Closure-first Authorization

Inline `canSee()` / `canRun()` on the Action class still honour the
standard policy callbacks as a fallback:

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
`Policy::update` (or `delete`). Teams that prefer a pure-policy story
can omit the closures entirely.

### `updatePivot{Model}` policy ability

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

Relation field payloads (BelongsTo, HasMany, BelongsToMany, MorphTo,
MorphToMany, MorphMany, MorphOne, HasOne) include `authorizedToCreate`
/ `authorizedToViewAny` computed from the **target resource's**
policy. The inline "Create Related" button is suppressed when the user
cannot create the related model — without the developer needing to
toggle `showCreateRelationButton` by hand.

---

## Lens System

### Sticky summary row

A `summary()` hook renders aggregates as a sticky row under the lens
table — no separate Metric required to show totals alongside a lens.

```php
public function summary(Request $request, Builder $query): array
{
    return [
        'revenue' => ['label' => 'Total', 'value' => $query->sum('revenue')],
        'count'   => ['label' => 'Rows',  'value' => $query->count()],
    ];
}
```

### Declarative query cache

Per-lens cache keeps heavy aggregations warm across requests.

```php
(new MostValuableClients())->cacheFor(60);                         // seconds
(new MostValuableClients())->cacheFor(new DateInterval('PT5M'));   // interval
```

The cache key mixes lens uriKey, filters, search, sort and page so
distinct views never collide.

### Default filters pre-applied

A lens declares defaults that the URL auto-hydrates on first load.

```php
(new OverdueInvoices())->withDefaultFilters(['status' => 'overdue']);
```

### URL state sync

Filters, search, sort, direction and page round-trip through the query
string. Every lens view is deeplinkable.

---

## Filter System

### Built-in `DateRangeFilter`

Native date range filter with `from` and `to` inputs.

```php
DateRangeFilter::make('Created Between')->column('created_at')
```

### Lens-exempt filters

Some filters belong to the resource index but make no sense inside
lenses (custom date scopes, internal-only toggles). Mark them with
`lensExempt()` so they stay out of the lens schema.

```php
StatusFilter::make('Status')->lensExempt()
```

### Per-resource SoftDeletes filter control

Resources expose `softDeletesFilter(bool)` (and the inverse
`hideSoftDeletesFilter()`) so individual resources can opt out of the
standard "Trashed" toggle even when the underlying model uses
`SoftDeletes`. Useful for internal tables where the trashed view would
leak information that other resources should keep visible.

---

## Field Extensions

### Tooltip on the field label

`->help()` ships plain-text guidance under the input. `->tooltip(...)`
attaches a `(?)` icon to the label and renders **raw HTML** on hover —
multi-line, bold, lists, inline links — without growing the form's
vertical footprint.

```php
Text::make('name', 'Full name')
    ->help('Must be unique')
    ->tooltip(
        '<strong>Full legal name</strong>.<br />Examples:<br />'
        .'• John Smith<br />• Ana Pereira<br /><br />'
        .'<em>Avoid abbreviations.</em>'
    );
```

`tooltip()` costs zero pixels until the user hovers the icon. Applies
uniformly to every field via the base class — Panel, Section, TabGroup,
ResourceCreate, ResourceUpdate, and detail labels rendered inside
Sections/TabGroups.

Only field tooltips render as HTML. Every other `data-pr-tooltip`
trigger keeps the default plain-text escape via an explicit
`data-pr-tooltip-html="true"` opt-in set only by the label renderer.
Authors are responsible for producing safe markup, the same way they
are for `help()`.

A `Tooltip` field class was deliberately rejected — a `Field`
represents a value, not a decoration. See
[Fields → Tooltips](fields.md#tooltips) for the full rationale and the
`tooltip()` vs `help()` decision matrix.

### Icon — Phosphor picker

The `Icon` field has three modes:

- **Display-only** — render a named Phosphor icon on detail/index. Useful for status cues.
- **Stored with visual picker** — opens a categorised + searchable picker. Saves the icon *name* to the DB (portable, framework-agnostic).
- **Computed from another attribute** — derive the icon from a model field via a closure; the picker is hidden.

Supports palette whitelisting (restrict to a configured subset),
`colorFrom()` to pull the hex colour from another attribute,
configurable sizes and tooltips. Full API in
[Fields Reference](fields.md#icon).

### BooleanGroup — grouped sections + live counter

`BooleanGroup` ships extras beyond a flat list:

- **`grouped([sectionTitle => keys])`** — renders each section as a collapsible panel with its own legend. Keeps 20+ permission flags manageable.
- **`minChecked(int)` / `maxChecked(int)`** — enforced server-side AND surfaced as a live counter pill that turns amber when the user hasn't met the minimum or hits the ceiling.
- **`requireAny()` / `requireAll()`** — convenience presets.

```php
BooleanGroup::make('permissions')
    ->options(['clients.view' => 'View clients', /* … */])
    ->grouped(['Clients' => ['clients.view']])
    ->requireAny();
```

### Avatar + UiAvatar — identity pictures with zero plumbing

Both fields share the same palette + initials logic through the
[`ResolvesInitialsPayload`](../src/Fields/Concerns/ResolvesInitialsPayload.php)
trait, keeping the topbar pill, login view, profile page, `Avatar`
empty state and `UiAvatar` all visually consistent.

**`Avatar` — upload field with a zero-config empty state:**

- Out of the box, `Avatar::make('photo')` renders an `<img>` when the user uploaded one, and **coloured initials inline** when they haven't. No fallback closure required. No external service hit.
- `fallback($url | Closure)` is available as an opt-in override (per-row Closure-aware).
- `colorFrom('brand_color')` + `initialsFrom('display_name')` + `initials(Closure)` customise the defaults.
- Typed `AvatarShape::Circle | Rounded | Squared` enum.

**`UiAvatar` — always initials, never uploads:**

- Display-only (`hideFromForms()` locked), computed from the model — no DB column.
- Same deterministic 16-slot palette hash. Shipped client-side with no external service call.
- Same `colorFrom()` / `initials(Closure)` / `from('other_attr')` knobs as `Avatar`.

```php
// Most common case — one line, inline initials for members without a photo.
Avatar::make('avatar_path')->circle()->colorFrom('brand_color');

// Read-only resource without a photo column — pure initials pill.
UiAvatar::make('avatar_initials')->from('name')->colorFrom('brand_color');
```

### Audio — canvas waveform, zero server deps

The `Audio` field extends `File` with a **client-side waveform**: the
audio is fetched, decoded via `AudioContext`, peaks are sampled and
painted onto a `<canvas>`. No server-side image rendering, no ffmpeg,
no external service. `downloadable(bool)` toggles the download button.

### Stack + Line — index-capable composite display

`Stack::make(...)` renders on both index and detail — ideal for
compressing identity columns (name + email + company) into a single
table cell without writing a custom component.
`Line::subtitleFrom('attribute'|Closure)` emits a second muted line
below the first without declaring an extra `Line`, and
`Stack::divider()` inserts a thin separator between entries.

```php
Stack::make('identity', __('fields.identity'), [
    Line::make('name')->asHeading()->subtitleFrom('email'),
    Line::make('company')->asMuted(),
])->divider();
```

Line variants — `asHeading()`, `asBase()`, `asSmall()`, `asMuted()`,
`asCode()` — map to `.martis-line-*` classes so custom themes restyle
every Line in the package through a handful of CSS tokens instead of
per-field inline styles. See [Fields Reference](fields.md#stack--line).

### Badge closures — schema-time and per-row

`Badge::map()`, `->labels()`, `->types()` and `->icons()` accept one of:

- An array — a static map.
- **Zero-arg Closure** — resolved once when the schema is serialised. Perfect for enum-backed palettes: `->map(fn () => StatusEnum::badgeMap())`.
- **One-arg Closure** — resolved per row. Receives the raw value (and optionally the model) and returns the single string for that value. Ideal for convention-driven i18n: `->labels(fn (string $v) => __("statuses.$v"))`.

For full control,
`->resolveBadgeUsing(fn ($value, $model) => ['type', 'label', 'icon'])`
runs per row and returns the resolved payload. Missing keys fall back
to the static/closure maps. All per-row results ship to the frontend
wrapped in a `__martisBadge` object the Badge component detects
transparently.

### Country, Currency, Slug, Timezone

- **`Country`** — `withFlags()` toggles the emoji flag renderer per call site.
- **`Currency`** — `displayMode` switches between text / badge / badge_text. `badgeColor`, `showBadge`, `showText` knobs allow per-row variants.
- **`Slug`** — URL-safe auto-generated identifier with a live collision check against the target column.
- **`Timezone`** — IANA dropdown with a live clock pill next to the option, so the operator immediately sees what time it is in that zone.

### Relationship toolbar hide flags

Every HasMany / MorphMany / BelongsToMany / MorphToMany /
HasManyThrough field exposes nine fluent flags to hide affordances
inside the relationship card without forking the component.

```php
HasMany::make('Invoices')
    ->hideCreateButton()
    ->hideSearch()
    ->hideTrashedFilter()
    ->hidePagination();
```

Full set: `hideCreateButton`, `hideSearch`, `hidePerPage`,
`hideTrashedFilter`, `hidePagination`, `hideEditAction`,
`hideDeleteAction`, `hideDetachAction`, `hideRestoreAction`. Each one
degrades cleanly: hidden controls are stripped from the schema and
ignored on the backend.

### Grid layout system (12-column)

Responsive multi-column form layouts with `Section::columns()` and
`Field::span()`:

```php
Section::make('Details', [
    Text::make('first_name')->span(6),
    Text::make('last_name')->span(6),
    Email::make('email')->span(12),
])->columns(12)
```

Supports responsive breakpoints: `colSpan()`, `colSpanMd()`,
`colSpanLg()`.

---

## Repeater

The Martis Repeater ships five surfaces beyond a basic repeater API.
Full documentation in [repeater.md](repeater.md).

**`dependsOn([parent attributes])`**

Every field inside every row receives the parent record's attributes
in `formValues`, so conditional logic can react to the record state
without leaving the row.

**Cardinality + collapse + reorder**

`minRows()`, `maxRows()`, `collapsible()`, `collapsedByDefault()`,
`reorderable()` with an auto-managed `position` column in HasMany /
Polymorphic mode. Native HTML5 drag-and-drop — zero extra dependency.

**Row header affordances**

`Repeatable::icon()`, `->color()`, `->title('{field} · {field}')`
(template resolved per row) and `->badgeCount()`. Surfaces real row
context instead of just the class basename.

**`rowTemplates()`, duplicate row, bulk paste**

Pre-filled row templates group beneath the Add button, each row header
gets a one-click duplicate, and a "Colar linhas" footer button parses
TSV / CSV / JSON clipboard content into rows (header auto-detection
when the first line matches field attribute names).

**`asPolymorphic()`**

Single child table shared by every row type, discriminated by a `type`
column and a JSON `payload`. Ideal for page-builder-style layouts
without one table per Repeatable type.

```php
Repeater::make('blocks')
    ->asPolymorphic('type', 'payload')
    ->uniqueField('uuid')
    ->reorderable()
    ->repeatables([HeroBlock::make(), TextBlock::make(), GalleryBlock::make()]);
```

---

## UI Primitives

### Standardised button classes

Every confirm/cancel/destructive/secondary button in the admin shares
the same chip geometry, shadow and hover choreography. Custom override
components can drop in the same classes and inherit the full styling
for free.

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

Where the package uses them already: `DrawerShell` footer,
`DrawerCreate`/`DrawerUpdate`, full-page `ResourceCreate`/`ResourceUpdate`,
`ActionModal`, `DeleteModal`, `UnsavedChangesDialog`.

### `<ClearButton />`

A reusable clear-button component used by every input-like field
(Text, Email, URL, Password, Number, Currency, Date, DateTime, Select,
Country, MultiSelect, BelongsTo, MorphTo, Tag):

- Always red (`var(--martis-danger)`).
- Always has a `Clear` tooltip (translated).
- Only renders when `field.nullable === true && hasValue && !field.readonly`.
- Hover: darker red, no background fill.

For consumer apps building custom fields:

```tsx
import { ClearButton } from '@/components/ClearButton'

<ClearButton
  visible={field.nullable && hasValue && !field.readonly}
  onClick={() => onChange(null)}
  style={{ position: 'absolute', right: '0.5rem', top: '50%', transform: 'translateY(-50%)' }}
/>
```

### MartisTooltip engine

Custom `MartisTooltip` component instead of PrimeReact `<Tooltip>`.
Uses event delegation on the document, which solves several PrimeReact
Tooltip limitations:

- Works reliably with dynamically rendered elements (conditional buttons, pills, drawers).
- No tooltip "skip" when hovering quickly between adjacent items (e.g. sidebar menu).
- Instant switch between targets without delay when moving between tooltip elements.
- First hover uses 300ms delay; subsequent hovers between targets are immediate.

All elements use `data-pr-tooltip` and `data-pr-position` attributes:

```tsx
<button data-pr-tooltip="Clear search" data-pr-position="top">
  <XIcon size={14} />
</button>
```

---

## Metrics & dashboards

### Responsive 12-column metric grid

Arbitrary 12-column widths plus responsive breakpoints:

```php
TotalUsers::make('Total Users')
    ->width(12)       // mobile: full width
    ->widthMd(6)      // tablet: half
    ->widthLg(4)      // desktop: one-third
```

Fraction strings (`'1/2'`, `'1/3'`, `'2/3'`, …) are auto-converted for
convenience.

### Filter grid layout span

```php
StatusFilter::make('Status')->span(4)          // 1/3 width
DateRangeFilter::make('Period')->span(8)       // 2/3 width
```

Filters layout in the same 12-column grid as the metric cards.

### `ActivityFeedMetric`

Stream of recent events as a card. Each entry is rendered with a
coloured Phosphor avatar tile + actor / verb / target line + mono
timestamp.

```php
class RecentDeploys extends ActivityFeedMetric
{
    public function calculate(Request $request): ActivityFeedResult
    {
        return $this->result()->add(
            actor: 'Ana Pereira', verb: 'deployed', time: '2m ago',
            target: 'api-core@v2.4.1', icon: 'rocket-launch',
        );
    }
}
```

Generator: `php artisan martis:activity-feed`. Full reference in
[metrics.md](metrics.md#activity-feed-metric).

### `EndpointTableMetric`

Compact HTTP route table card with method chips (GET / POST / PATCH /
PUT / DELETE), mono numeric columns and a thin share-of-traffic bar.
Drops cleanly into `card-span-3`.

```php
class TopEndpoints extends EndpointTableMetric
{
    public function calculate(Request $request): EndpointTableResult
    {
        return $this->result()
            ->errorWarnThreshold(0.2)
            ->add(method: 'GET', path: '/v1/resources', rpm: 482, latencyMs: 42, errorRate: 0.02, share: 28);
    }
}
```

Generator: `php artisan martis:endpoint-table`. Full reference in
[metrics.md](metrics.md#endpoint-table-metric).

### Trend sparkline mode

`TrendResult::sparkline()` swaps the full Chart.js panel for an inline
SVG sparkline + delta pill. Pairs with `.martis-dash-kpis` rows so
four trend metrics fit across the top of a dashboard.

```php
return $this->countByDays($request, Order::class)
    ->sparkline()
    ->showLatestValue()
    ->prefix('€');
```

The `<Sparkline>` component is also exported (`@/components/metrics`)
for custom framed cards.

### Per-metric color override

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

Accepts any CSS color value — hex, rgb, rgba, hsl, named colors, or
`var(--martis-*)`.

### Theme-aware chart colors

Chart.js cannot read CSS variables natively. Martis ships a runtime
resolver:

```tsx
import { chartPalette, accentColor, mutedTextColor, resolveColor } from '@/lib/themeColors'

const colors = chartPalette()              // ['#6366f1', '#22c55e', ...] resolved from --martis-chart-*
const accent = accentColor()                // resolved --martis-accent
const muted = mutedTextColor()              // resolved --martis-text-muted
const safe = resolveColor('var(--my-var)')  // works on var() OR literal hex
```

Charts re-render with the correct theme colors automatically when the
theme changes.

### Dashboard layout helpers

`.martis-dash-kpis` (4-col KPI row, collapses to 2) and
`.martis-dash-grid` (3-col body grid, collapses to 1) ship as public
utility classes so consumer dashboards render with the same scaffold
as the built-in one. `.span-2` / `.span-3` cell helpers reset on the
same breakpoint.

### Custom dashboard cards (with scaffolding)

A single command scaffolds the PHP class, the React component, and
the boot-file registration:

```bash
php artisan martis:card RevenueChart
```

Creates:

1. `app/Martis/Cards/RevenueChart.php` — with `componentKey()` pre-configured.
2. `resources/js/martis-extensions/overrides/RevenueChart.tsx` — starter React component.
3. Auto-registers via `resources/js/martis-extensions/cards/{Name}.tsx` filename auto-discovery.

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

### Card width & framing

```php
Card::make('Revenue')
    ->componentKey('revenue-card')
    ->width(6)        // grid-column span (1-12), defaults to 4
    ->framed();       // wrap custom component in the default MetricCard chrome
```

- `width(int)` — the Dashboard grid wraps the custom component in a
  `div` with `grid-column: span {width}`, so the component body never
  touches `gridColumn` itself.
- `framed(bool = true)` — when `true`, the component renders inside
  the standard `MetricCard` container (title, icon, border). Defaults
  to `false` so hero-style cards can render full-bleed.

---

## SSO subsystem

Pluggable single sign-on with Azure AD, Google, GitHub, or any custom
provider — config-driven, role-mapping built-in,
`spatie/laravel-permission` auto-detected, generator scaffolds the
entire flow with one command.

Three orthogonal axes that compose freely:

| Axis | Values | What it controls |
|---|---|---|
| `role_source` | `groups` / `app_role_assignments` / `callable` | Where external role names come from |
| `role_strategy` | `column` / `config` / `callable` | How external names map to local roles |
| `permission_adapter` | `auto` / `spatie` / `native` / `callable` | How resolved roles get written onto the user |

### Generator command

```bash
php artisan martis:sso azure --with-spatie --with-migration
```

Scaffolds the provider end-to-end: config block, env stubs, optional
`azure_group_name` migration on the `roles` table, and printed next
steps (composer require, Azure portal config). After the user fills in
`AZURE_CLIENT_ID` / `AZURE_CLIENT_SECRET`, the **Continue with
Microsoft** button auto-renders on the login page. Zero code changes.

### Five host-app hooks

```php
use Martis\Sso\Facades\MartisSso;

MartisSso::resolveUserUsing(fn ($identity, $provider) => User::firstOrCreate(...));
MartisSso::resolveRolesUsing(fn ($externalRoles, $user, $provider) => Role::whereIn(...)->get());
MartisSso::syncRolesUsing(fn ($user, $roles) => $user->syncRoles($roles));
MartisSso::afterLogin(fn ($user, $identity, $provider) => AuditLog::record(...));
MartisSso::onNoRoleMatchUsing(fn ($identity, $provider) => redirect(...)->withErrors(...));
```

### Spatie integration is automatic

`permission_adapter = 'auto'` (default) detects
`spatie/laravel-permission` via `class_exists()` and routes role sync
through `$user->syncRoles($collection)`. Apps without Spatie use the
`NativeAdapter` against the standard `model_has_roles` schema. Apps
with bespoke schemas use `permission_adapter = 'callable'` +
`MartisSso::syncRolesUsing(...)`.

### Per-environment role mapping via env

Roles change between QA / Staging / Production. Map once in config,
override per env:

```php
'role_map' => [
    'admin' => env('AZURE_GROUP_ROLE_ADMIN'),
    'sales_rep' => env('AZURE_GROUP_ROLE_SALES_REP'),
],
```

`.env.qa` and `.env.production` carry different group identifiers. The
mapper resolves through env, no code change to ship a new environment.

Full reference: [sso.md](sso.md).

---

## Frontend utilities

### Event bus

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

Built-in events: `martis:record-created`, `martis:record-updated`,
`martis:record-deleted`, `martis:record-restored`,
`martis:action-executed`, `martis:refresh-index`.

### Configurable loader

Customizable loading indicator:

```php
'loader' => [
    'logo' => '/images/my-logo.png',
    'spinnerColor' => '#6366f1',
    'overlayOpacity' => 0.6,
    'disableOn' => ['table' => false, 'search' => true],
],
```

### User preferences

Persisted per-user theme/accent/density/locale, server-backed by
`martis_user_preferences` (one row per user). Preferences survive
across devices and sessions.

- **Arbitrary brand colour per tenant.** Optional hex input (`#RGB` / `#RGBA` / `#RRGGBB` / `#RRGGBBAA`) overrides the preset accent. Off by default (`allowBrandColor = false`) — flip on for multi-tenant apps where each tenant has its own colour.
- **Persisted preferences + shareable URL presets.** Named presets (`?preset=exec-comfort`) compose over the user row so shared links don't overwrite recipient defaults. Resolution chain: URL preset > user row > config defaults.
- **Density per surface + reduced-motion enforced.** `[data-density="dense"]` tokens cut row/button/input heights (including dashboard cards — StatCard, MetricCard, resource shortcuts, welcome hero). `[data-reduced-motion="true"]` clamps every `--martis-dur-*` to `1ms` AND applies a universal `*, *::before, *::after { transition-duration: 1ms; animation-duration: 1ms }` override so inline styles, third-party widgets, and raw Tailwind utilities also honour the preference. The same rules apply under the OS-level `prefers-reduced-motion: reduce` media query.

The preferences panel is a compact topbar overlay — theme / accent /
density / language / accessibility. See [preferences.md](preferences.md)
for the resolver, API, and SSR no-flash mechanics.

### 94-token theme system

A single theme file controls the entire admin panel:

- **Background layers** (7 vars) — page bg, surfaces, sidebar, topbar, cards, inputs.
- **Text & borders** (3 vars).
- **Accent / brand** (6 vars) — primary, hover, active, alpha tints, focus ring.
- **Semantic colors solid** (8 vars) — success/warning/danger/info + hover variants.
- **Semantic backgrounds** (8 vars) — for badges, alerts, status.
- **Interactive states** (4 vars) — hover, active, search overlay.
- **Overlays & shadows** (5 vars) — modal backdrop, sm/md/lg shadows, peek.
- **DataTable** (5 vars) — header, rows, borders.
- **Border radius** (5 vars) — from sm to full pill.
- **Typography** (15 vars) — font families (sans/mono/heading), 7-step size scale, 4 weights, 3 line heights.
- **Chart palette** (10 vars) — for partition/trend metrics.
- **File icons** (6 vars) — semantic per file type.
- **Badge variants** (12 vars) — legacy compatibility.

```bash
# Generate a theme scaffold with all variables
php artisan martis:theme MyTheme
```

The generated stub includes all 94 variables in both dark mode
(`:root`) and light mode (`html:not(.dark)`), with comments and
grouping. Edit any value, refresh the browser — no rebuild needed.

See [Theming Guide](theming.md) for the complete variable reference.

### Component scaffolding

```bash
# Custom dashboard card — creates PHP class + React component (filename
# auto-discovery binds card:welcome-card to RevenueGauge.tsx).
php artisan martis:card WelcomeCard

# Visual override — creates TSX component. Every --type value
# auto-registers since v1.10.1: shell pieces and auth pages bind to
# fixed registry keys via the OVERRIDE_KEYS map; --type=field and
# --type=generic derive {kebab(filename)} (and -input for the
# field-shape pair) automatically.
php artisan martis:override StatusBadge --type=field
```

`martis:card` writes both the PHP class (`app/Martis/Cards/`) and the React component (`resources/js/martis-extensions/cards/{Name}.tsx`); the bundle's filename → key auto-discovery (`{Name}.tsx` → `card:{kebab-name}`) registers it on the next `npm run build:extensions`. `martis:override` writes a TSX-only file under `resources/js/martis-extensions/overrides/` and the bundle auto-registers it on the next build. See [Override System: Auto-registration scope](overrides.md#6-creating-custom-components-artisan) for the full filename → key table.

---

## Artisan command suite

| Command | Description |
|---------|-------------|
| `martis:install` | Full installation with optional `--with-profile` and `--with-2fa` |
| `martis:resource` | Generate a resource class |
| `martis:field` | Generate a custom field (PHP + React TSX) |
| `martis:action` | Generate an action (`--destructive` for destructive variant) |
| `martis:filter` | Generate a filter (`--boolean`, `--date` variants) |
| `martis:card` | Generate a custom dashboard card (PHP + React TSX; auto-discovers via filename → `card:{kebab}`) |
| `martis:override` | Scaffold a React override component (TSX only; auto-registers for every `--type` value via filename → key derivation since v1.10.1) |
| `martis:policy` | Generate a resource policy |
| `martis:theme` | Scaffold a custom theme with all CSS variables (dark + light) |
| `martis:value` | Generate a value metric |
| `martis:trend` | Generate a trend metric |
| `martis:partition` | Generate a partition metric |
| `martis:progress` | Generate a progress metric |
| `martis:dashboard` | Generate a dashboard class |
| `martis:user` | Create an admin user |
| `martis:list-env-vars` | Markdown / JSON table of every `MARTIS_*` env var (used to refresh the env-var index in [configuration.md](configuration.md)) |
