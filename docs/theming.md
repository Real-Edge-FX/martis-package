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

### 2. Text & Borders (4 variables)

| Variable | Purpose |
|----------|---------|
| `--martis-text` | Primary text color |
| `--martis-text-muted` | Secondary, placeholder, label text |
| `--martis-text-faint` | Tertiary text (footers, timestamps, helper text) |
| `--martis-border` | Default border color (inputs, panels, table cells) |

### 3. Accent / Brand (7 variables)

The brand identity colors — buttons, links, focus states, selected items.

| Variable | Purpose |
|----------|---------|
| `--martis-accent` | Primary brand color |
| `--martis-accent-hover` | Hover state |
| `--martis-accent-active` | Active/pressed state |
| `--martis-accent-contrast` | Text/icon colour rendered **on top of** an accent fill (buttons, badges) |
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

### 7. Overlays & Shadows (5 variables)

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

### 10. Typography (17 variables)

#### Font families

| Variable | Default |
|----------|---------|
| `--martis-font-sans` | Inter + system stack |
| `--martis-font-mono` | JetBrains Mono + system stack |
| `--martis-font-heading` | Same as `--martis-font-sans` |

#### Font sizes (modular scale)

The scale ships with **two interchangeable names** — `--martis-text-*` (short, used pervasively in package CSS) and `--martis-font-size-*` (verbose, semantic). Both resolve to the same value. Prefer the short form in new code.

| Variable | Alias | Size | Pixels | Use |
|----------|-------|------|--------|-----|
| `--martis-text-xs` | `--martis-font-size-xs` | `0.75rem` | 12px | Tooltips, micro labels |
| `--martis-text-sm` | `--martis-font-size-sm` | `0.875rem` | 14px | Body, inputs, labels |
| `--martis-text-base` | `--martis-font-size-base` | `1rem` | 16px | Default |
| `--martis-text-lg` | `--martis-font-size-lg` | `1.125rem` | 18px | Section headers |
| `--martis-text-xl` | `--martis-font-size-xl` | `1.25rem` | 20px | Card titles |
| `--martis-text-2xl` | `--martis-font-size-2xl` | `1.5rem` | 24px | Page titles |
| `--martis-text-3xl` | `--martis-font-size-3xl` | `1.875rem` | 30px | Dashboard metrics |

#### Font weights

Same dual-naming convention as font sizes. The short form (`--martis-weight-*`) is what the bundled package CSS uses.

| Variable | Alias | Value |
|----------|-------|-------|
| `--martis-weight-regular` | `--martis-font-weight-normal` | `400` |
| `--martis-weight-medium` | `--martis-font-weight-medium` | `500` |
| `--martis-weight-semibold` | `--martis-font-weight-semibold` | `600` |
| `--martis-weight-bold` | `--martis-font-weight-bold` | `700` |

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

### 12. Avatar Palette (16 variables)

16 deterministic hues used by `AvatarField` and `UiAvatarField` when the backend doesn't supply an explicit colour. The `lib/avatarPalette.ts` helper picks one of `--martis-avatar-1..16` from a stable hash of the seed (name, email, slug), so two users with the same name always get the same colour.

```css
--martis-avatar-1 ... --martis-avatar-16
```

The hex values are intentionally identical across light and dark themes — a user's avatar colour cannot change when the theme toggles.

### 13. Brand Gradient (9 variables)

Tokens for hero / welcome / marquee surfaces (currently the dashboard `WelcomeCard`) and brand-bearing surfaces like the auth screen. Override these in your theme CSS to reskin the brand without touching React.

| Variable | Description |
|----------|-------------|
| `--martis-brand-gradient` | Base 135° gradient. Three stops; defaults to indigo / violet / purple. |
| `--martis-brand-aurora-cyan` | Cyan aurora blob colour (drifts top-left). |
| `--martis-brand-aurora-pink` | Pink aurora blob colour (drifts bottom-right). |
| `--martis-brand-pointer-glow` | Spot-glow that tracks the cursor. |
| `--martis-brand-grid-dot` | Dot-grid overlay opacity. |
| `--martis-brand-shadow` | Shadow pushed under the brand surface. |
| `--martis-brand-text` | Default text colour on top of the brand surface. |
| `--martis-brand-logo-height-auth` | Logo height (px) on the auth screen lockup. |
| `--martis-brand-logo-height-menu` | Logo height (px) in the user dropdown menu. |

Light and dark themes ship the same recipe with stops keyed for the canvas — hero surfaces stay dark by design (white type on a saturated gradient reads better than the inverse), so the difference between themes is mostly trimmed opacity on the auroras.

### 14. File Icon Colors (6 variables)

Semantic colors for file type icons in `FileField`.

| Variable | Default | File type |
|----------|---------|-----------|
| `--martis-file-icon-pdf` | `#ef4444` | PDF |
| `--martis-file-icon-doc` | `#3b82f6` | Word documents |
| `--martis-file-icon-xls` | `#22c55e` | Excel/CSV |
| `--martis-file-icon-ppt` | `#f97316` | PowerPoint |
| `--martis-file-icon-zip` | `#a855f7` | Archives |
| `--martis-file-icon-default` | `#6b7280` | Unknown |

### 15. Badge Variants (legacy — 12 variables)

Kept for backward compatibility with existing Badge field components. New code should use semantic variants (`--martis-success-bg`, etc.).

```css
--martis-badge-{type}-bg
--martis-badge-{type}-text
--martis-badge-{type}-border
```

Where `{type}` is one of: `info`, `success`, `warning`, `danger`.

---

## Attribute-Driven Theming

The scaffolded theme layers three orthogonal axes on top of dark/light mode, all driven by attributes on `<html>`. The Preferences panel writes these automatically; you can also toggle them via DevTools to preview a change.

### Accent variants — `[data-accent]`

Switch the brand colour without editing the file. Five built-in accents ship in the stub:

| Attribute | Accent |
|-----------|--------|
| `data-accent="martis"` (default) | Martis blue (`#4F7BF9`) |
| `data-accent="blue"` | `#3B82F6` |
| `data-accent="teal"` | `#14B8A6` |
| `data-accent="violet"` | `#8B5CF6` |
| `data-accent="amber"` | `#F59E0B` |

Each accent overrides six tokens: `--martis-accent`, `--martis-accent-hover`, `--martis-accent-active`, `--martis-accent-bg-light`, `--martis-accent-bg`, `--martis-focus-ring` — in both dark and light modes.

To add a sixth accent, append two selectors to your theme file and define those six tokens:

```css
html.dark[data-accent="crimson"],
html[data-theme="dark"][data-accent="crimson"] {
  --martis-accent: #DC143C;
  /* ... 5 more tokens */
}
html:not(.dark)[data-accent="crimson"],
html[data-theme="light"][data-accent="crimson"] {
  /* light-mode values */
}
```

### Density tokens — `[data-density]`

Control spacing globally or per-surface.

| Token | Comfortable | Dense |
|-------|-------------|-------|
| `--martis-row-h` | `44px` | `32px` |
| `--martis-nav-item-h` | `34px` | `28px` |
| `--martis-input-h` | `36px` | `30px` |
| `--martis-btn-h` | `34px` | `28px` |
| `--martis-pad-x` | `20px` | `14px` |
| `--martis-pad-y` | `18px` | `12px` |
| `--martis-gap` | `14px` | `10px` |

Override per-surface by adding `[data-density="dense"]` on any ancestor — a dense financial table inside an otherwise-comfortable app.

### Motion tokens — `--martis-dur-*`, `--martis-ease-*`

**Six** duration stops (`fast`, `sm`, `base`, `medium`, `slow`, `ultra` — 80ms → 480ms) and **five** easing curves (`linear`, `standard`, `accel`, `decel`, `spring`). Custom themes inherit them; override any value to slow down / speed up your whole app without touching component CSS.

Both `@media (prefers-reduced-motion: reduce)` and `html[data-reduced-motion="true"]` clamp every duration to `1ms` — transitions still resolve (focus rings keep working), just instantly.

### Dark / Light selector

The stub targets both the legacy class selector and the new attribute:

```css
:root, html.dark, html[data-theme="dark"]        { /* dark tokens */ }
html:not(.dark), html[data-theme="light"]        { /* light tokens */ }
```

This means any app that sets either `.dark` or `data-theme` on `<html>` gets the right palette without extra glue.

### ⭐ Per-resource accent override — `Resource::accentColor()`

Override the panel's accent colour while a specific resource is active without mutating the user's global preference.

```php
use Martis\Resource;

class PaymentResource extends Resource
{
    public static function accentColor(): ?string
    {
        return 'teal';   // built-in name OR a hex like '#DC143C'
    }
}
```

How the frontend handles the value:

- **Built-in name** (`'martis' | 'blue' | 'teal' | 'violet' | 'amber'`, or any custom one your theme registered) — written to `<html data-accent>` while the resource view is mounted and restored on unmount.
- **Hex string** (`'#DC143C'`) — written as an inline `--martis-accent` style on `<html>` (wins over the `[data-accent]` selector). Use when none of the built-ins match your brand.
- **`null`** (default) — keeps the user's global accent.

The hook lives in `lib/useResourceAccent.ts`; both `ResourceIndexPage` and `ResourceDetailPage` already wire it up. Sidebar and topbar share the same `<html>`, so they reflect the override immediately.

### ⭐ Print stylesheet — `@media print`

The bundled CSS ships a print mode that:

- Forces a white-paper / black-text palette (saves ink).
- Drops shadows, sidebar, topbar, command palette overlay.
- Inlines link targets (`<a>foo</a>` → `foo (https://…)`) for offline reading.
- Avoids splitting table rows across pages.

Five tokens drive the print palette — override them in your theme to keep the print mode on-brand:

| Variable | Default |
|----------|---------|
| `--martis-print-bg` | `#ffffff` |
| `--martis-print-text` | `#000000` |
| `--martis-print-border` | `#000000` |
| `--martis-print-link-color` | `#000000` |
| `--martis-print-muted` | `#444444` |

Hide additional surfaces by attribute: `<div data-print-hide="true">…` is not printed.

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

### In TSX (helper utility classes)

The bundled CSS ships a small set of pre-built utility classes that wrap the most common token references:

```tsx
<div className="martis-text martis-card-bg martis-border">
  Content
</div>
```

Available helper classes:
- `.martis-text`, `.martis-text-muted`
- `.martis-bg`, `.martis-surface`, `.martis-card-bg`, `.martis-sidebar-bg`, `.martis-topbar-bg`
- `.martis-border`, `.martis-input-bg`, `.martis-surface-alt`

### ⭐ In TSX (Tailwind preset)

For consumers using Tailwind, the package ships `tailwind.preset.js` that surfaces every `--martis-*` token as a real Tailwind utility — no need to ship your own `theme.extend.colors` block:

```js
// tailwind.config.js
module.exports = {
  presets: [require('martis/tailwind.preset')],
  content: [
    './resources/**/*.{tsx,ts,jsx,js,blade.php}',
    './vendor/martis/martis/resources/js/**/*.tsx',
  ],
}
```

After this, write component CSS the Tailwind way — utilities resolve at runtime via `var()` so the active theme (light/dark, accent override, density) is always honoured:

```tsx
<div className="bg-martis-surface text-martis-text rounded-martis-lg shadow-martis-md p-4">
  <button className="bg-martis-accent text-martis-accent-contrast hover:bg-martis-accent-hover">
    Save
  </button>
</div>
```

The preset is additive — your existing `colors`, `fontFamily`, etc. stay untouched.

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
| Text & borders | 4 |
| Accent variants | 7 |
| Semantic solid | 8 |
| Semantic backgrounds & text | 8 |
| Interactive states | 4 |
| Overlays & shadows | 5 |
| DataTable | 5 |
| Border radius | 5 |
| Typography (families/sizes/weights/heights) | 17 |
| Chart palette | 10 |
| Avatar palette | 16 |
| Brand gradient | 9 |
| File icons | 6 |
| Badge variants (legacy) | 12 |
| Density tokens | 7 |
| Motion tokens (durations + eases) | 11 |
| **Total** | **141** |

The `--martis-text-*` ↔ `--martis-font-size-*` and `--martis-weight-*` ↔ `--martis-font-weight-*` aliases are counted once each; the package ships both names but they always resolve to the same value.

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

## Theme upgrade workflow

When a new package version introduces additional `--martis-*` tokens, your custom theme keeps working (the package CSS provides defaults), but a brand-conscious team usually wants to redeclare the new tokens explicitly. Use the bundled diff command:

```bash
php artisan martis:theme:diff               # uses config('martis.theme.name')
php artisan martis:theme:diff mytheme       # explicit theme name
php artisan martis:theme:diff --show-match  # also list tokens both files declare
```

Output is split into three groups:

- **Missing in consumer** — tokens the package defines that your theme does not. Add a value for each one.
- **Unknown to package** — tokens your theme defines that no longer exist. Either a typo or a deprecated token from a previous package version.
- **Match** — tokens both files declare. Counted by default; pass `--show-match` to list them.

Exit codes: `0` (everything aligned), `2` (drift detected — useful for CI gates).

---

## Troubleshooting

### Theme not loading
1. Verify `config('martis.theme.name')` returns your theme name
2. Check `public/vendor/martis/themes/{name}.css` exists
3. Run `php artisan view:clear` and `php artisan config:clear`
4. Inspect HTML `<head>` — theme `<link>` must appear AFTER app CSS

### Some colors don't change
Since v0.6.0, Martis ships a **PrimeReact bridge**: `--primary-color`, `--surface-card`, `--surface-border`, `--text-color`, `--text-color-secondary`, `--highlight-bg`, `--highlight-text-color`, `--focus-ring`, `--border-radius`, and `--maskbg` are mapped to the matching `--martis-*` tokens. PrimeReact components therefore inherit your theme automatically. If a specific PrimeReact internal still uses its own colour, override it alongside the Martis tokens in your theme file.

### Chart colors don't update
Chart.js receives resolved color strings, not CSS variables. The theme system already resolves `--martis-chart-*` at runtime. If you provide `var(--my-custom-var)` directly to a chart prop without using the helper, it won't work.

### Typography variables not applied
Ensure variables are declared in **both** `:root` (dark) and `html:not(.dark)` (light) blocks. Typography variables are inherited from `body` — applied automatically to all elements unless overridden.
