# Theming

> Complete guide to customizing the visual appearance of Martis.

Martis uses **CSS custom properties** (variables) for all styling. A theme is a CSS file that overrides these variables. Themes apply globally and support both **dark** and **light** modes.

---

## Quick Start

Generate a custom theme scaffold:

```bash
php artisan martis:theme MyTheme
```

This creates:
- `resources/css/martis/mytheme.css` — editable source
- `public/vendor/martis/themes/mytheme.css` — published copy
- Updates `config/martis.php` to activate the theme

Edit the CSS file (no rebuild needed) and refresh the browser. Changes are immediate.

---

## How It Works

### CSS Load Order

```
1. app.css                     (Martis defaults — defines all variables)
2. {theme-name}.css            (Your overrides — wins by CSS specificity)
```

Themes load **after** the package CSS, so any variable you redefine wins. You only need to declare variables you want to change — others fall back to defaults.

### Configuration

```php
// config/martis.php
'theme' => [
    'default' => 'dark',           // Initial mode: 'dark' or 'light'
    'allowToggle' => true,         // Show light/dark toggle in user menu
    'name' => 'mytheme',           // Theme CSS file name (null = default)
],
```

---

## Variable Reference

All variables are organized into **10 logical groups**. The default theme defines values for **all** variables in both dark mode (`:root`) and light mode (`html:not(.dark)`).

### 1. Background Layers (7 variables)

Surface and background colors used throughout the UI.

| Variable | Purpose |
|----------|---------|
| `--martis-bg` | Page background |
| `--martis-surface` | Cards, panels, modals — primary surface |
| `--martis-surface-alt` | Alternate surface (zebra rows, secondary panels, drawer footer) |
| `--martis-sidebar` | Sidebar background |
| `--martis-topbar` | Top navigation bar |
| `--martis-card` | Card components |
| `--martis-input-bg` | Form input backgrounds |

### 2. Text & Borders (3 variables)

| Variable | Purpose |
|----------|---------|
| `--martis-text` | Primary text color |
| `--martis-text-muted` | Secondary, placeholder, label text |
| `--martis-border` | Default border color (inputs, panels, table cells) |

### 3. Accent / Brand (6 variables)

The brand identity colors — buttons, links, focus states, selected items.

| Variable | Purpose |
|----------|---------|
| `--martis-accent` | Primary brand color |
| `--martis-accent-hover` | Hover state |
| `--martis-accent-active` | Active/pressed state |
| `--martis-accent-bg-light` | Subtle background tint (e.g. selected row) |
| `--martis-accent-bg` | Stronger background tint |
| `--martis-focus-ring` | Focus ring color (with alpha for box-shadow) |

### 4. Semantic Colors — Solid (8 variables)

Solid colors used in modals, action buttons, alerts.

| Variable | Default Dark | Default Light | Purpose |
|----------|--------------|---------------|---------|
| `--martis-success` | `#22c55e` | `#16a34a` | Success state |
| `--martis-success-hover` | `#16a34a` | `#15803d` | Hover |
| `--martis-warning` | `#f59e0b` | `#d97706` | Warning state (e.g. archive) |
| `--martis-warning-hover` | `#d97706` | `#b45309` | Hover |
| `--martis-danger` | `#ef4444` | `#dc2626` | Danger state (e.g. delete) |
| `--martis-danger-hover` | `#dc2626` | `#b91c1c` | Hover |
| `--martis-info` | `#3b82f6` | `#2563eb` | Info state |
| `--martis-info-hover` | `#2563eb` | `#1d4ed8` | Hover |

### 5. Semantic Backgrounds & Text (8 variables)

Used for badges, alerts, status indicators (alpha tints in dark, solid pastels in light).

| Variable | Purpose |
|----------|---------|
| `--martis-success-bg` | Success badge/alert background |
| `--martis-success-text` | Success badge/alert text |
| `--martis-warning-bg` | Warning badge/alert background |
| `--martis-warning-text` | Warning badge/alert text |
| `--martis-danger-bg` | Danger badge/alert background |
| `--martis-danger-text` | Danger badge/alert text |
| `--martis-info-bg` | Info badge/alert background |
| `--martis-info-text` | Info badge/alert text |

### 6. Interactive States (4 variables)

| Variable | Purpose |
|----------|---------|
| `--martis-hover` | Generic hover background |
| `--martis-active` | Generic active/pressed background |
| `--martis-search-bg` | Search input overlay |
| `--martis-search-border` | Search input border |

### 7. Overlays & Shadows (4 variables)

| Variable | Purpose |
|----------|---------|
| `--martis-overlay` | Modal backdrop |
| `--martis-shadow-sm` | Small shadow (1px) |
| `--martis-shadow-md` | Medium shadow (cards, peeks) |
| `--martis-shadow-lg` | Large shadow (modals) |
| `--martis-peek-shadow` | Hover preview popover shadow |

### 8. DataTable (5 variables)

| Variable | Purpose |
|----------|---------|
| `--martis-row-even` | Striped even row |
| `--martis-row-hover` | Row hover background |
| `--martis-table-header-bg` | Header row background |
| `--martis-table-header-text` | Header text |
| `--martis-table-header-border` | Header border |

### 9. Border Radius (5 variables)

| Variable | Default | Use |
|----------|---------|-----|
| `--martis-radius-sm` | `0.25rem` | Tight elements (badges, chips) |
| `--martis-radius-md` | `0.375rem` | Inputs, small buttons |
| `--martis-radius-lg` | `0.5rem` | Buttons, cards |
| `--martis-radius-xl` | `0.75rem` | Containers, large cards |
| `--martis-radius-full` | `9999px` | Pills, avatars |

### 10. Typography (15 variables)

#### Font families

| Variable | Default |
|----------|---------|
| `--martis-font-sans` | Inter + system stack |
| `--martis-font-mono` | JetBrains Mono + system stack |
| `--martis-font-heading` | Same as `--martis-font-sans` |

#### Font sizes (modular scale)

| Variable | Size | Pixels | Use |
|----------|------|--------|-----|
| `--martis-font-size-xs` | `0.75rem` | 12px | Tooltips, micro labels |
| `--martis-font-size-sm` | `0.875rem` | 14px | Body, inputs, labels |
| `--martis-font-size-base` | `1rem` | 16px | Default |
| `--martis-font-size-lg` | `1.125rem` | 18px | Section headers |
| `--martis-font-size-xl` | `1.25rem` | 20px | Card titles |
| `--martis-font-size-2xl` | `1.5rem` | 24px | Page titles |
| `--martis-font-size-3xl` | `1.875rem` | 30px | Dashboard metrics |

#### Font weights

| Variable | Value |
|----------|-------|
| `--martis-font-weight-normal` | `400` |
| `--martis-font-weight-medium` | `500` |
| `--martis-font-weight-semibold` | `600` |
| `--martis-font-weight-bold` | `700` |

#### Line heights

| Variable | Value | Use |
|----------|-------|-----|
| `--martis-line-height-tight` | `1.25` | Titles |
| `--martis-line-height-normal` | `1.5` | Body |
| `--martis-line-height-relaxed` | `1.75` | Long-form content |

### 11. Chart Palette (10 variables)

10 distinct colors used by Partition and Trend metrics. Customize for branded dashboards.

```css
--martis-chart-1 ... --martis-chart-10
```

Used automatically by `PartitionCard` (donut/pie) when no custom colors provided. Resolved at runtime via JavaScript (Chart.js can't read CSS vars natively).

### 12. File Icon Colors (6 variables)

Semantic colors for file type icons in `FileField`.

| Variable | Default | File type |
|----------|---------|-----------|
| `--martis-file-icon-pdf` | `#ef4444` | PDF |
| `--martis-file-icon-doc` | `#3b82f6` | Word documents |
| `--martis-file-icon-xls` | `#22c55e` | Excel/CSV |
| `--martis-file-icon-ppt` | `#f97316` | PowerPoint |
| `--martis-file-icon-zip` | `#a855f7` | Archives |
| `--martis-file-icon-default` | `#6b7280` | Unknown |

### 13. Badge Variants (legacy — 12 variables)

Kept for backward compatibility with existing Badge field components. New code should use semantic variants (`--martis-success-bg`, etc.).

```css
--martis-badge-{type}-bg
--martis-badge-{type}-text
--martis-badge-{type}-border
```

Where `{type}` is one of: `info`, `success`, `warning`, `danger`.

---

## Using Variables in Custom Components

### In TSX (inline style)

```tsx
<div style={{
  backgroundColor: 'var(--martis-surface)',
  color: 'var(--martis-text)',
  borderRadius: 'var(--martis-radius-lg)',
  fontSize: 'var(--martis-font-size-sm)',
  boxShadow: 'var(--martis-shadow-md)',
}}>
  Themed content
</div>
```

### In TSX (Tailwind utilities)

Most variables are exposed as Tailwind classes:

```tsx
<div className="martis-text martis-card-bg martis-border">
  Content
</div>
```

Available helper classes:
- `.martis-text`, `.martis-text-muted`
- `.martis-bg`, `.martis-surface`, `.martis-card-bg`, `.martis-sidebar-bg`, `.martis-topbar-bg`
- `.martis-border`, `.martis-input-bg`, `.martis-surface-alt`

### In TSX (canvas/Chart.js — runtime resolution)

CSS variables can't be read by canvas APIs. Use the helper:

```tsx
import { cssVar, accentColor, mutedTextColor, chartPalette, resolveColor } from '@/lib/themeColors'

const accent = accentColor()                           // 'rgb(...)' resolved
const muted = mutedTextColor()
const palette = chartPalette()                         // ['#6366f1', '#22c55e', ...]
const custom = cssVar('--my-var', '#fallback')         // generic
const resolved = resolveColor('var(--martis-success)') // works on var() OR literal
```

### In PHP (chart colors, badges)

```php
Badge::make('plan')->map([
    'free' => 'info',
    'pro' => 'success',
    'enterprise' => 'warning',
]);

// PartitionMetric — accepts ANY CSS color value
ProjectsByStatusMetric::make()->colors([
    'Active' => 'var(--martis-success)',  // theme variable
    'Paused' => '#f59e0b',                // hex
    'Done' => 'rgb(59, 130, 246)',        // rgb
    'Archived' => 'rgba(0,0,0,0.5)',      // rgba
]);

// TrendMetric / ProgressMetric — single color
RevenueMetric::make()->color('var(--martis-success)');
```

---

## Complete Variable Count

| Category | Count |
|----------|-------|
| Background layers | 7 |
| Text & borders | 3 |
| Accent variants | 6 |
| Semantic solid | 8 |
| Semantic backgrounds & text | 8 |
| Interactive states | 4 |
| Overlays & shadows | 5 |
| DataTable | 5 |
| Border radius | 5 |
| Typography (families/sizes/weights/heights) | 15 |
| Chart palette | 10 |
| File icons | 6 |
| Badge variants (legacy) | 12 |
| **Total** | **94** |

---

## Theme Examples

### Brand Color Override (minimal)

```css
:root {
  --martis-accent: #ec4899;        /* pink */
  --martis-accent-hover: #db2777;
}

html:not(.dark) {
  --martis-accent: #db2777;
  --martis-accent-hover: #be185d;
}
```

That's it — buttons, links, focus rings, selected items all turn pink instantly.

### Custom Typography

```css
:root {
  --martis-font-sans: 'Roboto', sans-serif;
  --martis-font-heading: 'Playfair Display', serif;
  --martis-font-size-base: 0.9375rem;  /* 15px instead of 16px */
}
```

### Branded Chart Palette

```css
:root {
  --martis-chart-1: #ff6b6b;
  --martis-chart-2: #4ecdc4;
  --martis-chart-3: #45b7d1;
  --martis-chart-4: #ffe66d;
  /* ... */
}
```

### Reduced-Motion / Compact UI

```css
:root {
  --martis-radius-sm: 2px;
  --martis-radius-md: 3px;
  --martis-radius-lg: 4px;
  --martis-radius-xl: 6px;
}
```

---

## Troubleshooting

### Theme not loading
1. Verify `config('martis.theme.name')` returns your theme name
2. Check `public/vendor/martis/themes/{name}.css` exists
3. Run `php artisan view:clear` and `php artisan config:clear`
4. Inspect HTML `<head>` — theme `<link>` must appear AFTER app CSS

### Some colors don't change
PrimeReact has its own `:root` variables (`--primary-color`, `--surface-*`, etc.) that are NOT controlled by Martis variables. Some PrimeReact internals may still use their own colors. Override them in your theme if needed.

### Chart colors don't update
Chart.js receives resolved color strings, not CSS variables. The theme system already resolves `--martis-chart-*` at runtime. If you provide `var(--my-custom-var)` directly to a chart prop without using the helper, it won't work.

### Typography variables not applied
Ensure variables are declared in **both** `:root` (dark) and `html:not(.dark)` (light) blocks. Typography variables are inherited from `body` — applied automatically to all elements unless overridden.
