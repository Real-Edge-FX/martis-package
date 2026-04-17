# Martis Differentials

> Features unique to Martis that do **not** exist in Laravel Nova 5.
> These are not compatibility layers — they are product advantages that go beyond Nova's capabilities.

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

Nova v5 requires developers to wire up view/edit/delete buttons manually via inline actions. Martis ships it as the default experience, with three layers of customization:

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

## Filter System Extensions

### DateRangeFilter (Built-in)

Native date range filter with `from` and `to` inputs. Nova requires third-party packages.

```php
DateRangeFilter::make('Created Between')->column('created_at')
```

### Filter Authorization (canSee)

Per-filter visibility control. Nova does not support filter-level authorization.

```php
SalaryFilter::make('Salary Range')
    ->canSee(fn ($request) => $request->user()->isAdmin())
```

Hidden filters are excluded from the schema AND ignored on the backend.

### Action Authorization — closure-first, policy-second

Nova relies on a policy ability named after the action
(`Policy::publish`, `Policy::archive`, …). Martis lets you declare
authorization inline on the Action while still honouring the policy
callbacks Nova developers expect:

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
`Policy::update` (or `delete`). This keeps the standard Nova policy
story for teams that want pure-policy flows.

### updatePivot{Model} policy ability

BelongsToMany and MorphToMany pivot edits consult a dedicated
`updatePivot{Model}` policy ability, falling back to `update` on the
parent. Nova has no equivalent — pivot writes are gated by `update`
only.

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

Nova shows only a generic count inside the dropdown.

### Searchable Select Filters

Built-in search within large filter option lists:

```php
CountryFilter::make('Country')->searchable()
```

---

## Field Extensions

### 16 Extended Field Types (Built-in)

All included without additional packages:

| Field | Description |
|-------|-------------|
| `Badge` | Colored status badge with configurable colors |
| `Status` | Status indicator with color mapping |
| `Code` | Code editor with syntax highlighting |
| `Color` | Color picker with preview |
| `Country` | Country selector dropdown |
| `Currency` | Formatted currency display with symbol |
| `Gravatar` | Gravatar avatar from email |
| `KeyValue` | Editable key-value pair table |
| `Markdown` | Markdown editor with preview |
| `MultiSelect` | Multi-select dropdown |
| `Sparkline` | Inline mini chart |
| `Tag` | Tag input with autocomplete |
| `Trix` | Rich text editor |
| `Url` | URL field with validation |
| `Heading` | Section divider with optional content |
| `Hidden` | Hidden field for form data |

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

## Metrics & Dashboard Extensions

### Dashboard Filters

> Nova 5 does not support dashboard-level filters.

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

> Nova 5 uses fixed widths: `'1/3'`, `'1/2'`, `'2/3'`, `'full'`.

Martis uses a 12-column grid with responsive breakpoints:

```php
TotalUsers::make('Total Users')
    ->width(12)       // mobile: full width
    ->widthMd(6)      // tablet: half
    ->widthLg(4)      // desktop: one-third
```

Nova-style strings are auto-converted for compatibility.

### Metric Polling (Auto-Refresh)

> Nova 5 only has a manual refresh button.

Martis supports automatic polling with a visual "LIVE" indicator:

```php
ActiveUsersNow::make('Active Users')->refreshEvery(30)
```

Cards with polling auto-refresh without user interaction. Minimum interval: 5 seconds.

### Card Icons

> Nova 5 does not support icons on metric cards.

```php
TotalUsers::make('Total Users')->icon('users')
```

Icons use the built-in Phosphor Icons library (1,512 icons).

### Card Styles

> Nova 5 does not support visual card styling.

```php
use Martis\Enums\CardStyle;

Revenue::make('Revenue')->style(CardStyle::Success)  // green accent
```

Available: `Default`, `Success`, `Warning`, `Danger`, `Info`.

### Card Height Control

> Nova 5 does not support card height control.

```php
UsersByRole::make('By Role')->height(300)
```

### Filter Grid Layout (span)

> Nova 5 does not support filter layout control.

```php
StatusFilter::make('Status')->span(4)          // 1/3 width
DateRangeFilter::make('Period')->span(8)       // 2/3 width
```

### Global Cache Configuration

> Nova 5 only supports per-metric caching. Martis adds global defaults.

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

### Comprehensive Theme System

> Nova 5 has limited theming. Martis exposes 94 CSS variables across 13 categories.

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

> Nova 5 has inconsistent clear behavior across field types.

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

> Nova 5 charts use fixed Indigo/Cyan palette.

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

> Nova 5 metrics have a fixed color (cardStyle accent).

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

> Nova 5 cards require manual PHP class creation and separate frontend registration.

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

> Nova 5 cards require the developer to manage grid spans and chrome inside the custom component.

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

> Nova 5 does not support descriptions/subtitles on Panels or Sections.

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

> Nova 5 supports plain text help only.

Martis `->help()` supports inline HTML for rich help text with bold, links, and code:

```php
Text::make('password')
    ->help('Minimum 8 characters. Use a <strong>strong password</strong>.')

Email::make('email')
    ->help('See our <a href="/privacy">privacy policy</a> for details.')
```

### Field Layout Methods

> Nova 5 has `fullWidth()` and `stacked()` but Martis combines these with the Section grid system for more control.

```php
Text::make('bio')->fullWidth()     // Spans the full form width
Text::make('status')->stacked(false) // Label inline beside the input
```

### Form Field Declaration Order Preserved

> Nova 5 renders Panels and scalar fields in declaration order. Martis does the same.

Martis respects the exact order fields and layout containers are declared in `fieldsForCreate()` / `fieldsForUpdate()`. Scalar fields, Panels, Sections, and TabGroups are rendered in the position the developer defined — no automatic reordering.
