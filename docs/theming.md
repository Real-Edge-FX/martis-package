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
- `resources/css/martis/mytheme.css`: the theme source, the file you edit and commit
- `public/vendor/martis/themes/mytheme.css`: the published copy the browser loads
- Updates `config/martis.php` to activate the theme

Edit the source, publish it and refresh the browser. No Vite rebuild is needed:

```bash
php artisan martis:publish-assets --themes-only
```

`--themes-only` publishes the themes alone; a full `php artisan martis:publish-assets` publishes them too, after the package assets. Never edit the published copy: every publish replaces it with the source (see [Theme files](#theme-files)).

---

## How It Works

### CSS Load Order

```
1. app.css                     (Martis defaults — defines all variables)
2. {theme-name}.css            (Your overrides — wins by CSS specificity)
```

Themes load **after** the package CSS, so any variable you redefine wins. You only need to declare variables you want to change — others fall back to defaults.

### Theme files

`resources/css/martis/<name>.css` is the theme: commit it with your app. The browser loads a copy, `public/vendor/martis/themes/<name>.css`, and that copy is generated. `php artisan martis:publish-assets` wipes `public/vendor/martis/`, copies the package assets, then publishes every `.css` file directly inside `resources/css/martis/` to `public/vendor/martis/themes/`. `martis:vendor-publish --assets` and `martis:install` run the same command, and `php artisan martis:publish-assets --themes-only` runs the theme step alone, without the wipe and the package assets: use it after editing a theme. So:

- The published themes match their sources. A publish replaces a copy with its source, and removes a copy whose source you deleted.
- Before it replaces or removes a file it cannot write back, the publish copies it to `storage/app/martis/theme-backups/<date>-<time>/`, under its path in the app (for example `public/vendor/martis/themes/brand.css`), and prints a warning with the backup's path: a copy edited in place, a theme without a source, or any other file in `public/vendor/martis/themes/`, hidden files included. When a backup cannot be written, the publish stops before deleting anything. A file already backed up with the same content is not copied again, and the 10 newest backup runs are kept: delete a run once you have looked at it.
- A copy the publish wrote itself, or one identical to its source, is replaced without a backup. The publish records the copies it writes, by sha1, in `storage/app/martis/published-themes.json`, out of the web root.
- A symlink in `public/vendor/martis/themes/` is never followed: the publish replaces a link to a theme with a copy of its source, and removes any other link without touching its target. Neither needs a backup.
- The publish stops, with exit code 1 and nothing changed, when a theme source cannot be read (a broken symlink, a file that does not open), and when it would take away the theme `martis.theme.name` names: that theme's source is skipped, or it has no source and the run would remove its published copy.
- `--no-wipe` still publishes the sources over the copies, backing up an edited one first; a file without a source stays, like any other stale file. With `--themes-only`, `--no-wipe` keeps those files as well.
- A source the panel could not load is skipped with a warning: a name not made of letters, digits, dashes and underscores (the panel only loads a `theme.name` of that form), or an extension other than a lowercase `.css`.
- The publish warns when `martis.theme.name` names a theme without a source that it does not remove, a name the panel ignores, or a name that differs from its source only in case (the theme loads on macOS and Windows, not on a Linux server).
- Keep the fonts and images a theme uses outside `public/vendor/martis/`, for example in `public/fonts/`, and reference them with absolute URLs: the publish only copies the `.css` sources.

#### Upgrading from 1.x

Up to v1.39.1 the `martis:theme` hint told you to edit the published copy, and asset publishes deleted it without writing it again: `martis:publish-assets` and `martis:vendor-publish --assets` since v1.8.8, `martis:install` since v1.29.1. The theme stylesheet then returned 404 and the panel fell back to the default tokens, with no error. `martis:theme` has always written both files, so a 1.x theme usually has its edits in the published copy and the untouched scaffold in the source. What changes:

| | 1.x | Now |
|---|---|---|
| File you edit | `public/vendor/martis/themes/<name>.css` (as the `martis:theme` hint said) | `resources/css/martis/<name>.css` |
| After an edit | Refresh the browser | Run `php artisan martis:publish-assets --themes-only`, then refresh |
| A copy edited in place, next to its source | Deleted by the publish | Backed up, then replaced with the source |
| A copy without a source | Deleted by the publish | Backed up and removed; when it is the theme `martis.theme.name` names, the publish stops instead and changes nothing |
| `--no-wipe` and a copy edited in place | Left alone | Backed up, then replaced with the source |
| A theme source that cannot be read | Never read | The publish stops and changes nothing |
| `martis:theme:diff` | Compares the published copy | Compares the source, and warns when the published copy differs |

Before you upgrade:

1. Find the file that holds your edits: `diff resources/css/martis/<name>.css public/vendor/martis/themes/<name>.css`. If the published copy has them, copy it over the source: `mkdir -p resources/css/martis && cp public/vendor/martis/themes/<name>.css resources/css/martis/<name>.css`. If a publish already deleted it, restore your edits into the source from version control or a backup.
2. Commit `resources/css/martis/<name>.css`. `public/vendor/martis/` can stay out of version control: every publish writes it again.
3. Upgrade as usual (`composer update martis/martis`, then `php artisan martis:publish-assets` or `php artisan martis:install --force`). The publish writes your theme to `public/vendor/martis/themes/<name>.css`. If you skipped step 1, it backs up the edited copy to `storage/app/martis/theme-backups/` and says so: move your edits from the backup into the source, then publish again. If the theme `martis.theme.name` names only exists as its published copy, or a theme source cannot be read, the publish (and `martis:install` with it) stops with exit code 1 and changes nothing: do step 1, or fix the file, then run it again.
4. Point any script that edits or copies the published copy at the source, and run a `martis:theme:diff` CI gate against the source.

### PrimeReact components (v1.39.0)

Martis renders its controls (inputs, dropdowns, tables, calendars, tooltips, toasts) with PrimeReact 10. The PrimeReact theme ships inside the package CSS, compiled from the lara theme's SASS source with every colour variable pointed at a `--martis-*` token. Every PrimeReact component therefore follows the active palette: light and dark mode, the accent presets and your theme. That includes components Martis never renders itself, such as a `Slider` or a `TabView` in a Tool page or an extension bundle.

- Severity fills (`severity="success"` buttons, for example) use the matching token (`--martis-success`) with white text, like the Martis buttons.
- `<Badge severity="…">` matches the `.martis-badge-*` pills (`--martis-badge-*` tokens). The default badge is a solid `--martis-accent` counter.
- The PrimeReact CSS variables (`--primary-color`, `--surface-ground`, `--surface-card`, `--surface-0` to `--surface-900`, `--text-color`, `--highlight-bg`, `--maskbg`, …) point at the same tokens, for code that reads them.

A theme only sets the Martis tokens; it never needs to restyle PrimeReact selectors to change colours.

### Configuration

```php
// config/martis.php
'theme' => [
    'default' => 'dark',           // Initial mode: 'dark' or 'light'
    'allowToggle' => true,         // Show light/dark toggle in user menu
    'name' => 'mytheme',           // resources/css/martis/mytheme.css (null = default)
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
| `--martis-text-muted` | Secondary, placeholder, label, and dimmed/tertiary text |
| `--martis-border` | Default border color (inputs, panels, table cells) |

### 3. Accent / Brand (7 variables)

The brand identity colors — buttons, links, focus states, selected items.

| Variable | Purpose |
|----------|---------|
| `--martis-accent` | Primary brand color |
| `--martis-accent-hover` | Hover state |
| `--martis-accent-active` | Active/pressed state |
| `--martis-accent-contrast` | Text/icon colour rendered **on top of** an accent fill. Every accent fill reads it (primary buttons, paginator and datepicker highlights, calendar trigger, checkbox ticks, Trix dialog buttons, the SPA's accent-filled buttons and the PrimeReact `--primary-color-text` bridge), with `#ffffff` as the fallback. A theme with a bright accent sets a dark value here (`#071726`) instead of darkening the accent; for `MARTIS_CUSTOM_ACCENTS` and a per-user `brandColor` Martis derives it from the accent's luminance |
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

16 deterministic hues for every initials avatar: the `Avatar` and `UiAvatar` fields, the Topbar and the profile page. The server (`Martis\Support\Initials`) picks one of `--martis-avatar-1..16` from a stable hash of the seed (the user's name, or e-mail when the name is blank; a field's seed attribute) and the frontend paints `var(--martis-avatar-N)`, so redefining these tokens in a theme recolours every avatar. Two people with the same name always get the same colour. A field with `colorFrom()` paints that attribute's colour instead. For a custom component, `avatarColorForSeed()` on `@martis/runtime` uses the same hash.

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
| `--martis-brand-badge-bg` | Glass fill of the version badge on the hero (default: a 12 % → 6 % white gradient). |
| `--martis-brand-badge-border` | Border of the version badge (default `rgba(255, 255, 255, 0.22)`). |
| `--martis-brand-shimmer` | Colour of the bright band that sweeps across the version badge every 3.5 s (default `rgba(255, 255, 255, 0.28)`). On a bright gradient the band can push the badge text below WCAG AA for a slice of every cycle: lower the alpha, or set `transparent` to remove the sweep. The band also stops under `prefers-reduced-motion` and `html[data-reduced-motion="true"]` (the aurora blobs keep drifting; they sit below the vestibular threshold, a bright highlight moving over text does not). |
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

CSS variables can't be read by canvas APIs. Use the helpers on `@martis/runtime` (v1.38.0+):

```tsx
import { cssVar, accentColor, mutedTextColor, chartPalette, resolveColor } from '@martis/runtime'

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

### Placeholder text in custom controls (v1.37.1)

A bare `<input placeholder="…">`, `<textarea>` or `<select>` rendered by a Tool page or an extension bundle gets a theme-aware placeholder without any Martis class: the package CSS declares a global `::placeholder { color: var(--martis-text-muted); opacity: 1 }`, loaded after the PrimeReact theme and outside its cascade layer. Up to v1.38 that theme painted every placeholder white at 60 %, invisible on the light theme; since v1.39.0 it reads the same token, and the global rule keeps bare controls on it whatever else they match. The rule follows the active mode through `--martis-text-muted`, and a theme that redefines that token re-colours bare placeholders too.

Martis controls (`.p-inputtext`, `.martis-input`, `.martis-search-input`, the ⌘K search, the resource search) keep their own `::placeholder` rules, which win by specificity, so their placeholders are unchanged. A custom control that wants a different placeholder colour declares its own class-scoped rule the same way:

```css
.my-tool-search::placeholder { color: var(--martis-text); opacity: .5; }
```

Before v1.37.1 the workaround was to add `className="martis-input"` (or `p-inputtext`) to the control just to reach a class-scoped placeholder rule; that is no longer needed.

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

The command compares the theme source, `resources/css/martis/<name>.css`. When only the published copy exists, it fails and tells you to move the copy there: a publish removes a copy without a source, after backing it up (`--no-wipe` keeps it). It rejects a name the panel does not load, fails on a source it cannot read, and warns when the published copy is missing or differs from the source, since the panel loads the copy; the exit code only reflects the tokens.

Output is split into three groups:

- **Missing in consumer** — tokens the package defines that your theme does not. Add a value for each one.
- **Unknown to package** — tokens your theme defines that no longer exist. Either a typo or a deprecated token from a previous package version.
- **Match** — tokens both files declare. Counted by default; pass `--show-match` to list them.

Exit codes: `0` (everything aligned), `2` (drift detected — useful for CI gates).

---

## Troubleshooting

### Theme not loading
1. Verify `config('martis.theme.name')` returns your theme name
2. Check `resources/css/martis/{name}.css` exists, then run `php artisan martis:publish-assets --themes-only`: it publishes the file to `public/vendor/martis/themes/{name}.css`, the stylesheet the browser loads, and warns when `martis.theme.name` has no source or a source is skipped. It stops with exit code 1, changing nothing, when a theme source cannot be read or when it would remove the published copy of the theme `martis.theme.name` names: the error names the file
3. Run `php artisan view:clear` and `php artisan config:clear`
4. Inspect HTML `<head>`: the theme `<link>` must appear AFTER app CSS and return 200

On martis/martis up to v1.39.1, asset publishes deleted the published copy without writing it again: `martis:publish-assets` and `martis:vendor-publish --assets` since v1.8.8, `martis:install` since v1.29.1. Upgrade (see [Upgrading from 1.x](#upgrading-from-1x)), or copy the source over the published copy after each publish.

### Some colors don't change
Every PrimeReact component reads the `--martis-*` tokens (see [PrimeReact components](#primereact-components-v1390)). When a colour does not follow your theme:

1. Check that the variable is a Martis token: `php artisan martis:theme:diff` lists the ones your theme declares that the package does not know.
2. Declare it for both modes (`:root` and `html:not(.dark)`), as the defaults do.
3. Look for a literal colour in your own CSS or in an extension bundle: it wins over the token. Use `var(--martis-…)` there too.

Setting a PrimeReact variable such as `--primary-color` restyles nothing: the components read the Martis tokens, and the PrimeReact variables only mirror them. To give one PrimeReact component a colour of its own, override its selector in your theme file:

```css
.p-slider .p-slider-range {
  background: var(--martis-success);
}
```

On martis/martis < 1.39.0 the bundled `lara-dark-indigo` theme painted with literal colours, and only the components Martis restyled in `martis.css` followed the tokens (a `Slider` in an extension stayed indigo, and dark in light mode). Upgrade, or override those selectors as above.

### Chart colors don't update
Chart.js receives resolved color strings, not CSS variables. The theme system already resolves `--martis-chart-*` at runtime. If you provide `var(--my-custom-var)` directly to a chart prop without using the helper, it won't work.

### Typography variables not applied
Ensure variables are declared in **both** `:root` (dark) and `html:not(.dark)` (light) blocks. Typography variables are inherited from `body` — applied automatically to all elements unless overridden.

### Placeholder text invisible on the light theme
On martis/martis < 1.37.1 an input without a Martis class inherited the bundled dark theme's white placeholder (`rgba(255,255,255,.6)`), invisible on a light surface. Upgrade, or on an older version give the control `className="martis-input"` (or `p-inputtext`) to reach a class-scoped placeholder rule. From 1.37.1 the global `::placeholder` default covers every control; see [Placeholder text in custom controls](#placeholder-text-in-custom-controls-v1371).
