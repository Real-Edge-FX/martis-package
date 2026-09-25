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
- Before it replaces or removes a file it cannot write back, the publish copies it to `storage/app/martis/theme-backups/<date>-<time>/`, under its path in the app (for example `public/vendor/martis/themes/brand.css`), and prints a warning with the backup's path: a copy edited in place, a theme without a source, or any other file in `public/vendor/martis/themes/`, hidden files included. When a backup cannot be written, the publish stops before deleting anything. A file already backed up with the same content is not copied again. When a run makes a new backup, it deletes the old runs beyond 10 and lists each one it deletes: it keeps the oldest run, which holds what the first publish after the upgrade took away (your 1.x copies), and the 9 newest. A run it cannot delete is a warning. Delete a run once you have looked at it.
- A copy the publish wrote itself, or one identical to its source, is replaced without a backup. The publish records the copies it writes in `public/vendor/martis/themes/.published.json`, next to them, as sha1 hashes only (it names no theme), so it needs nothing writable outside the published directory.
- `.gitkeep`, `.gitignore` and `.keep` in `public/vendor/martis/themes/` are not themes: the publish never reports, backs up or removes them, and a full publish puts them back after its wipe.
- A symlink in `public/vendor/martis/themes/` is never followed: the publish replaces a link to a theme with a copy of its source, and removes any other link without touching its target. Neither needs a backup.
- When `public/vendor/martis/` or `public/vendor/martis/themes/` is itself a symlink (a shared directory of Deployer or Envoyer, for example), the publish and `martis:theme` never write into its target. They check and back up the theme files the link shows like any other, warn, and replace the link with a real directory holding a copy of the target's files (symlinks stay symlinks), before any backup or removal. The wipe and the copy then work on the new directory, and the target is left as it was, so drop the directory from the deploy tool's shared list. When a link cannot be replaced (its parent directory is not writable), the run stops with exit code 1 before anything is deleted or written.
- The publish stops, with exit code 1 and nothing changed, when it would take away the theme `martis.theme.name` names (that theme's source is skipped or cannot be read, or it has no source and the run would remove its published copy, a file or a symlink), when it would remove the published copy of a source it cannot read (a broken symlink, a file that does not open: the copy may be the only readable version left), and when `resources/css/martis/` cannot be listed. A source it cannot read that nothing depends on is skipped with a warning.
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
| A theme source that cannot be read | Never read | Skipped with a warning; the publish stops and changes nothing when it is the source of `martis.theme.name` or the run would remove its published copy |
| `public/vendor/martis/themes/` as a symlink (deploy tool shared directory) | The wipe removed the link itself and left the target alone, so the panel lost the theme; `martis:theme` wrote its copy through the link, into the target | Checked and backed up, then replaced by a real directory holding a copy of the target's files; the target is never written |
| `public/vendor/martis/` itself as a symlink | Since v1.8.8 the wipe emptied the target through the link (any other file there was deleted) and the assets were copied into it | Replaced the same way before anything is written; the target is never written |
| `martis:theme:diff` | Compares the published copy | Compares the source, and warns when the published copy differs |

Before you upgrade:

1. Find the file that holds your edits: `diff resources/css/martis/<name>.css public/vendor/martis/themes/<name>.css`. If the published copy has them, copy it over the source: `mkdir -p resources/css/martis && cp public/vendor/martis/themes/<name>.css resources/css/martis/<name>.css`. If a publish already deleted it, restore your edits into the source from version control or a backup.
2. Commit `resources/css/martis/<name>.css`. `public/vendor/martis/` can stay out of version control: every publish writes it again.
3. Upgrade as usual (`composer update martis/martis`, then `php artisan martis:publish-assets` or `php artisan martis:install --force`). The publish writes your theme to `public/vendor/martis/themes/<name>.css`. If you skipped step 1, it backs up the edited copy to `storage/app/martis/theme-backups/` and says so: move your edits from the backup into the source, then publish again. If the theme `martis.theme.name` names only exists as its published copy, or the publish would remove the copy of a theme source it cannot read, the publish (and `martis:install` with it) stops with exit code 1 and changes nothing: do step 1, or fix the file, then run it again. If your deploy tool shares `public/vendor/martis/themes/` between releases (a workaround for the old wipe), remove it from the shared list: the publish replaces the link with a real directory.
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

A theme can define **162 CSS variables** in **19 groups**. The bundled `martis.css` gives 160 of them a value, once on `:root` (the dark theme, and every variable that does not depend on the mode) and again on `html:not(.dark)` for 102 of them; the scaffolded theme (`stubs/theme.css.stub`, written by `martis:theme`) declares the same 160 on `:root` and the same 102 on `html:not(.dark)`, with the same values (the test in `tests/Unit/ThemeTokenDriftTest.php` compares them, accents, density and motion included). The other two, the brand logo heights, come from the config (`MARTIS_BRAND_LOGO_HEIGHT_MENU` / `MARTIS_BRAND_LOGO_HEIGHT_AUTH`, written on `:root` by the panel's layout); the stub carries them commented out, to uncomment only to override those knobs. The **Light** column shows "same" when light mode keeps the dark value.

### 1. Background Layers (7 variables)

Surface and background colors used throughout the UI.

| Variable | Dark | Light | Purpose |
|----------|------|-------|---------|
| `--martis-bg` | `#0B0D10` | `#F7F8FA` | Page background |
| `--martis-surface` | `#12151A` | `#FFFFFF` | Cards, panels, modals: primary surface |
| `--martis-surface-alt` | `#161A20` | `#F2F4F7` | Alternate surface (zebra rows, secondary panels, drawer footer) |
| `--martis-sidebar` | `#0E1115` | `#FFFFFF` | Sidebar background |
| `--martis-topbar` | `#0E1115` | `#FFFFFF` | Top navigation bar |
| `--martis-card` | `#12151A` | `#FFFFFF` | Card components |
| `--martis-input-bg` | `#0E1115` | `#FFFFFF` | Form input backgrounds |

### 2. Text & Borders (3 variables)

| Variable | Dark | Light | Purpose |
|----------|------|-------|---------|
| `--martis-text` | `#E6E8EC` | `#0F172A` | Primary text color |
| `--martis-text-muted` | `#8A93A1` | `#64748B` | Secondary, placeholder, label, and dimmed/tertiary text |
| `--martis-border` | `#232830` | `#E4E8EE` | Default border color (inputs, panels, table cells) |

### 3. Accent / Brand (7 variables)

The brand identity colors: buttons, links, focus states, selected items. The `[data-accent]` variants below override six of them (all but `--martis-accent-contrast`).

| Variable | Dark | Light | Purpose |
|----------|------|-------|---------|
| `--martis-accent` | `#4F7BF9` | `#3B6AF0` | Primary brand color |
| `--martis-accent-hover` | `#3B6AF0` | `#2C57D8` | Hover state |
| `--martis-accent-active` | `#2C57D8` | `#1E44BE` | Active/pressed state |
| `--martis-accent-contrast` | `#FFFFFF` | same | Text/icon colour rendered **on top of** an accent fill. Every accent fill reads it (primary buttons, paginator and datepicker highlights, calendar trigger, checkbox ticks, Trix dialog buttons, the SPA's accent-filled buttons and the PrimeReact `--primary-color-text` bridge), with `#ffffff` as the fallback. A theme with a bright accent sets a dark value here (`#071726`) instead of darkening the accent; for `MARTIS_CUSTOM_ACCENTS` and a per-user `brandColor` Martis derives it from the accent's luminance |
| `--martis-accent-bg-light` | `rgba(79, 123, 249, 0.14)` | `#ECF1FE` | Subtle background tint (e.g. selected row) |
| `--martis-accent-bg` | `rgba(79, 123, 249, 0.24)` | `#D7E1FD` | Stronger background tint |
| `--martis-focus-ring` | `rgba(79, 123, 249, 0.45)` | `rgba(59, 106, 240, 0.35)` | Focus ring color (with alpha for box-shadow) |

### 4. Semantic Colors, Solid (8 variables)

Solid colors used in modals, action buttons, alerts.

| Variable | Dark | Light | Purpose |
|----------|------|-------|---------|
| `--martis-success` | `#22C55E` | `#16A34A` | Success state |
| `--martis-success-hover` | `#16A34A` | `#15803D` | Hover |
| `--martis-warning` | `#F59E0B` | `#D97706` | Warning state (e.g. archive) |
| `--martis-warning-hover` | `#D97706` | `#B45309` | Hover |
| `--martis-danger` | `#EF4444` | `#DC2626` | Danger state (e.g. delete) |
| `--martis-danger-hover` | `#DC2626` | `#B91C1C` | Hover |
| `--martis-info` | `#38BDF8` | `#0EA5E9` | Info state |
| `--martis-info-hover` | `#0EA5E9` | `#0284C7` | Hover |

### 5. Semantic Backgrounds & Text (8 variables)

Used for badges, alerts, status indicators (alpha tints in dark, solid pastels in light).

| Variable | Dark | Light | Purpose |
|----------|------|-------|---------|
| `--martis-success-bg` | `rgba(34, 197, 94, 0.12)` | `#E7F7EC` | Success badge/alert background |
| `--martis-success-text` | `#4ADE80` | `#15803D` | Success badge/alert text |
| `--martis-warning-bg` | `rgba(245, 158, 11, 0.14)` | `#FEF3DB` | Warning badge/alert background |
| `--martis-warning-text` | `#FBBF24` | `#B45309` | Warning badge/alert text |
| `--martis-danger-bg` | `rgba(239, 68, 68, 0.14)` | `#FDE5E5` | Danger badge/alert background |
| `--martis-danger-text` | `#F87171` | `#B91C1C` | Danger badge/alert text |
| `--martis-info-bg` | `rgba(56, 189, 248, 0.14)` | `#E0F2FE` | Info badge/alert background |
| `--martis-info-text` | `#7DD3FC` | `#0369A1` | Info badge/alert text |

### 6. Interactive States (4 variables)

| Variable | Dark | Light | Purpose |
|----------|------|-------|---------|
| `--martis-hover` | `rgba(255, 255, 255, 0.035)` | `rgba(15, 23, 42, 0.035)` | Generic hover background |
| `--martis-active` | `rgba(255, 255, 255, 0.06)` | `rgba(15, 23, 42, 0.06)` | Generic active/pressed background |
| `--martis-search-bg` | `#0B0D10` | `#FFFFFF` | Search input overlay |
| `--martis-search-border` | `#2A303A` | `#D6DBE3` | Search input border |

### 7. Overlays & Shadows (5 variables)

| Variable | Dark | Light | Purpose |
|----------|------|-------|---------|
| `--martis-overlay` | `rgba(5, 7, 10, 0.72)` | `rgba(15, 23, 42, 0.4)` | Modal backdrop |
| `--martis-shadow-sm` | `0 1px 0 rgba(0, 0, 0, 0.25)` | `0 1px 0 rgba(15, 23, 42, 0.04)` | Small shadow (1px) |
| `--martis-shadow-md` | `0 4px 16px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(255, 255, 255, 0.03)` | `0 4px 12px rgba(15, 23, 42, 0.06), 0 0 0 1px rgba(15, 23, 42, 0.04)` | Medium shadow (cards, peeks) |
| `--martis-shadow-lg` | `0 24px 48px rgba(0, 0, 0, 0.55), 0 0 0 1px rgba(255, 255, 255, 0.04)` | `0 18px 36px rgba(15, 23, 42, 0.12), 0 0 0 1px rgba(15, 23, 42, 0.06)` | Large shadow (modals) |
| `--martis-peek-shadow` | `0 8px 24px rgba(0, 0, 0, 0.45)` | `0 6px 16px rgba(15, 23, 42, 0.08)` | Hover preview popover shadow |

### 8. DataTable (5 variables)

| Variable | Dark | Light | Purpose |
|----------|------|-------|---------|
| `--martis-row-even` | `#10131A` | `#F9FAFB` | Striped even row |
| `--martis-row-hover` | `#171B22` | `#F2F4F7` | Row hover background |
| `--martis-table-header-bg` | `#0E1115` | `#F7F8FA` | Header row background |
| `--martis-table-header-text` | `#8A93A1` | `#64748B` | Header text |
| `--martis-table-header-border` | `#232830` | `#E4E8EE` | Header border |

### 9. Border Radius (5 variables)

| Variable | Dark | Light | Purpose |
|----------|------|-------|---------|
| `--martis-radius-sm` | `0.25rem` | same | Tight elements (badges, chips) |
| `--martis-radius-md` | `0.375rem` | same | Inputs, small buttons |
| `--martis-radius-lg` | `0.5rem` | same | Buttons, cards |
| `--martis-radius-xl` | `0.75rem` | same | Containers, large cards |
| `--martis-radius-full` | `9999px` | same | Pills, avatars |

### 10. Typography (31 variables)

Font families, the modular size scale, weights and line heights. Sizes, weights and line heights ship with **two names** each: the short form (`--martis-text-*`, `--martis-weight-*`, `--martis-leading-*`), used pervasively in package CSS, and the verbose alias (`--martis-font-size-*`, `--martis-font-weight-*`, `--martis-line-height-*`), defined as `var()` of the short one. **A theme sets the short name**: the package CSS reads it (only `body` and the password checklist read an alias), so a theme that sets only `--martis-font-size-sm` changes almost nothing. Both names are counted below, since both are defined.

| Variable | Dark | Light | Purpose |
|----------|------|-------|---------|
| `--martis-font-sans` | `'Inter var', 'Inter', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif` | same | Body font stack |
| `--martis-font-mono` | `'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, monospace` | same | Code font stack |
| `--martis-font-heading` | `'Inter var', 'Inter', ui-sans-serif, system-ui, sans-serif` | same | Heading font stack |
| `--martis-text-xs` | `12px` | same | Tooltips, micro labels |
| `--martis-text-sm` | `14px` | same | Body, inputs, labels |
| `--martis-text-base` | `16px` | same | Default |
| `--martis-text-lg` | `18px` | same | Section headers |
| `--martis-text-xl` | `20px` | same | Card titles |
| `--martis-text-2xl` | `24px` | same | Page titles |
| `--martis-text-3xl` | `30px` | same | Dashboard metrics |
| `--martis-font-size-xs` | `var(--martis-text-xs)` | same | Alias of `--martis-text-xs` |
| `--martis-font-size-sm` | `var(--martis-text-sm)` | same | Alias of `--martis-text-sm` |
| `--martis-font-size-base` | `var(--martis-text-base)` | same | Alias of `--martis-text-base` |
| `--martis-font-size-lg` | `var(--martis-text-lg)` | same | Alias of `--martis-text-lg` |
| `--martis-font-size-xl` | `var(--martis-text-xl)` | same | Alias of `--martis-text-xl` |
| `--martis-font-size-2xl` | `var(--martis-text-2xl)` | same | Alias of `--martis-text-2xl` |
| `--martis-font-size-3xl` | `var(--martis-text-3xl)` | same | Alias of `--martis-text-3xl` |
| `--martis-weight-regular` | `400` | same | Font weight |
| `--martis-weight-medium` | `500` | same | Font weight |
| `--martis-weight-semibold` | `600` | same | Font weight |
| `--martis-weight-bold` | `700` | same | Font weight |
| `--martis-font-weight-normal` | `var(--martis-weight-regular)` | same | Alias of `--martis-weight-regular` |
| `--martis-font-weight-medium` | `var(--martis-weight-medium)` | same | Alias of `--martis-weight-medium` |
| `--martis-font-weight-semibold` | `var(--martis-weight-semibold)` | same | Alias of `--martis-weight-semibold` |
| `--martis-font-weight-bold` | `var(--martis-weight-bold)` | same | Alias of `--martis-weight-bold` |
| `--martis-leading-tight` | `1.25` | same | Titles |
| `--martis-leading-normal` | `1.5` | same | Body |
| `--martis-leading-relaxed` | `1.75` | same | Long-form content |
| `--martis-line-height-tight` | `var(--martis-leading-tight)` | same | Alias of `--martis-leading-tight` |
| `--martis-line-height-normal` | `var(--martis-leading-normal)` | same | Alias of `--martis-leading-normal` |
| `--martis-line-height-relaxed` | `var(--martis-leading-relaxed)` | same | Alias of `--martis-leading-relaxed` |

### 11. Chart Palette (10 variables)

10 distinct colors used by Partition and Trend metrics. `PartitionCard` (donut/pie) uses them when no custom colors are provided; they are resolved at runtime via JavaScript (Chart.js cannot read CSS variables natively).

| Variable | Dark | Light | Purpose |
|----------|------|-------|---------|
| `--martis-chart-1` | `#60A5FA` | `#2563EB` | Series 1 |
| `--martis-chart-2` | `#34D399` | `#059669` | Series 2 |
| `--martis-chart-3` | `#F472B6` | `#DB2777` | Series 3 |
| `--martis-chart-4` | `#FBBF24` | `#D97706` | Series 4 |
| `--martis-chart-5` | `#A78BFA` | `#7C3AED` | Series 5 |
| `--martis-chart-6` | `#22D3EE` | `#0891B2` | Series 6 |
| `--martis-chart-7` | `#FB923C` | `#EA580C` | Series 7 |
| `--martis-chart-8` | `#F87171` | `#DC2626` | Series 8 |
| `--martis-chart-9` | `#4ADE80` | `#16A34A` | Series 9 |
| `--martis-chart-10` | `#C084FC` | `#9333EA` | Series 10 |

### 12. Avatar Palette (16 variables)

16 deterministic hues for every initials avatar: the `Avatar` and `UiAvatar` fields, the Topbar and the profile page. The server (`Martis\Support\Initials`) picks one of `--martis-avatar-1..16` from a stable hash of the seed (the user's name, or e-mail when the name is blank; a field's seed attribute) and the frontend paints `var(--martis-avatar-N)`, so redefining these tokens in a theme recolours every avatar. `martis.css` and the scaffolded theme declare them twice, on `:root` and on `html:not(.dark)`, so set both blocks: a value set on `:root` alone recolours the dark mode only. Two people with the same name always get the same colour. A field with `colorFrom()` paints that attribute's colour instead. For a custom component, `avatarColorForSeed()` on `@martis/runtime` uses the same hash. The hex values are intentionally identical across light and dark themes: a user's avatar colour cannot change when the theme toggles.

| Variable | Dark | Light | Purpose |
|----------|------|-------|---------|
| `--martis-avatar-1` | `#2563EB` | same | Avatar hue 1 |
| `--martis-avatar-2` | `#059669` | same | Avatar hue 2 |
| `--martis-avatar-3` | `#DB2777` | same | Avatar hue 3 |
| `--martis-avatar-4` | `#D97706` | same | Avatar hue 4 |
| `--martis-avatar-5` | `#7C3AED` | same | Avatar hue 5 |
| `--martis-avatar-6` | `#0891B2` | same | Avatar hue 6 |
| `--martis-avatar-7` | `#EA580C` | same | Avatar hue 7 |
| `--martis-avatar-8` | `#DC2626` | same | Avatar hue 8 |
| `--martis-avatar-9` | `#16A34A` | same | Avatar hue 9 |
| `--martis-avatar-10` | `#9333EA` | same | Avatar hue 10 |
| `--martis-avatar-11` | `#4F46E5` | same | Avatar hue 11 |
| `--martis-avatar-12` | `#0D9488` | same | Avatar hue 12 |
| `--martis-avatar-13` | `#C026D3` | same | Avatar hue 13 |
| `--martis-avatar-14` | `#65A30D` | same | Avatar hue 14 |
| `--martis-avatar-15` | `#BE185D` | same | Avatar hue 15 |
| `--martis-avatar-16` | `#475569` | same | Avatar hue 16 |

### 13. Brand Gradient (12 variables)

Tokens for hero / welcome / marquee surfaces (currently the dashboard `WelcomeCard`) and brand-bearing surfaces like the auth screen. Override these in your theme CSS to reskin the brand without touching React. Hero surfaces stay dark by design in both themes (white type on a saturated gradient reads better than the inverse), so the difference between themes is mostly trimmed opacity on the auroras. The two logo heights come from the config, not from `martis.css`: the panel's layout writes `MARTIS_BRAND_LOGO_HEIGHT_MENU` (default `40`, clamped to 20–56) and `MARTIS_BRAND_LOGO_HEIGHT_AUTH` (default `48`, clamped to 24–80) on `:root`, and the **Dark** / **Light** columns show those defaults. A theme that defines them overrides the `.env` knobs, so the scaffolded theme carries them commented out.

| Variable | Dark | Light | Purpose |
|----------|------|-------|---------|
| `--martis-brand-gradient` | `linear-gradient(135deg, #1A1F4B 0%, #2A1F66 45%, #3B1F7A 100%)` | `linear-gradient(135deg, #1F2566 0%, #2E2173 45%, #4324A0 100%)` | Base 135° gradient. Three stops; defaults to indigo / violet / purple. |
| `--martis-brand-aurora-cyan` | `rgba(56, 189, 248, 0.55)` | `rgba(56, 189, 248, 0.45)` | Cyan aurora blob colour (drifts top-left). |
| `--martis-brand-aurora-pink` | `rgba(236, 72, 153, 0.45)` | `rgba(236, 72, 153, 0.38)` | Pink aurora blob colour (drifts bottom-right). |
| `--martis-brand-pointer-glow` | `rgba(124, 140, 255, 0.35)` | `rgba(124, 140, 255, 0.30)` | Spot-glow that tracks the cursor. |
| `--martis-brand-grid-dot` | `rgba(255, 255, 255, 0.09)` | `rgba(255, 255, 255, 0.10)` | Dot-grid overlay opacity. |
| `--martis-brand-shadow` | `0 20px 50px -20px rgba(76, 56, 200, 0.55)` | `0 20px 50px -20px rgba(76, 56, 200, 0.40)` | Shadow pushed under the brand surface. |
| `--martis-brand-text` | `#FFFFFF` | same | Default text colour on top of the brand surface. |
| `--martis-brand-badge-bg` | `linear-gradient(110deg, rgba(255, 255, 255, 0.12) 0%, rgba(255, 255, 255, 0.06) 100%)` | same | Glass fill of the version badge on the hero (default: a 12 % → 6 % white gradient). |
| `--martis-brand-badge-border` | `rgba(255, 255, 255, 0.22)` | same | Border of the version badge (default `rgba(255, 255, 255, 0.22)`). |
| `--martis-brand-shimmer` | `rgba(255, 255, 255, 0.28)` | same | Colour of the bright band that sweeps across the version badge every 3.5 s (default `rgba(255, 255, 255, 0.28)`). On a bright gradient the band can push the badge text below WCAG AA for a slice of every cycle: lower the alpha, or set `transparent` to remove the sweep. The band also stops under `prefers-reduced-motion` and `html[data-reduced-motion="true"]` (the aurora blobs keep drifting; they sit below the vestibular threshold, a bright highlight moving over text does not). |
| `--martis-brand-logo-height-auth` | `48px` | same | Logo height on the auth screen lockup (`MARTIS_BRAND_LOGO_HEIGHT_AUTH`). |
| `--martis-brand-logo-height-menu` | `40px` | same | Logo height of the brand in the sidebar and the top navigation, in logo-only mode (`MARTIS_BRAND_LOGO_HEIGHT_MENU`). |

### 14. File Icon Colors (6 variables)

Semantic colors for file type icons in `FileField`.

| Variable | Dark | Light | Purpose |
|----------|------|-------|---------|
| `--martis-file-icon-pdf` | `#EF4444` | `#DC2626` | PDF |
| `--martis-file-icon-doc` | `#3B82F6` | `#2563EB` | Word documents |
| `--martis-file-icon-xls` | `#22C55E` | `#16A34A` | Excel/CSV |
| `--martis-file-icon-ppt` | `#F97316` | `#EA580C` | PowerPoint |
| `--martis-file-icon-zip` | `#A78BFA` | `#7C3AED` | Archives |
| `--martis-file-icon-default` | `#8A93A1` | `#64748B` | Unknown |

### 15. Badge Variants (legacy) (12 variables)

Kept for backward compatibility with existing Badge field components. New code should use the semantic variants (`--martis-success-bg`, etc.).

| Variable | Dark | Light | Purpose |
|----------|------|-------|---------|
| `--martis-badge-info-bg` | `rgba(56, 189, 248, 0.14)` | `#E0F2FE` | Legacy info badge background |
| `--martis-badge-info-text` | `#7DD3FC` | `#0369A1` | Legacy info badge text |
| `--martis-badge-info-border` | `rgba(56, 189, 248, 0.30)` | `#BAE6FD` | Legacy info badge border |
| `--martis-badge-success-bg` | `rgba(34, 197, 94, 0.12)` | `#E7F7EC` | Legacy success badge background |
| `--martis-badge-success-text` | `#4ADE80` | `#15803D` | Legacy success badge text |
| `--martis-badge-success-border` | `rgba(34, 197, 94, 0.30)` | `#BBF7D0` | Legacy success badge border |
| `--martis-badge-warning-bg` | `rgba(245, 158, 11, 0.14)` | `#FEF3DB` | Legacy warning badge background |
| `--martis-badge-warning-text` | `#FBBF24` | `#B45309` | Legacy warning badge text |
| `--martis-badge-warning-border` | `rgba(245, 158, 11, 0.30)` | `#FDE68A` | Legacy warning badge border |
| `--martis-badge-danger-bg` | `rgba(239, 68, 68, 0.14)` | `#FDE5E5` | Legacy danger badge background |
| `--martis-badge-danger-text` | `#F87171` | `#B91C1C` | Legacy danger badge text |
| `--martis-badge-danger-border` | `rgba(239, 68, 68, 0.30)` | `#FECACA` | Legacy danger badge border |

### 16. Density (7 variables)

The comfortable values; `[data-density="dense"]` swaps them (see [Density tokens](#density-tokens--data-density)).

| Variable | Dark | Light | Purpose |
|----------|------|-------|---------|
| `--martis-row-h` | `44px` | same | Table row height |
| `--martis-nav-item-h` | `34px` | same | Sidebar item height |
| `--martis-input-h` | `36px` | same | Input height |
| `--martis-btn-h` | `34px` | same | Button height |
| `--martis-pad-x` | `20px` | same | Horizontal surface padding |
| `--martis-pad-y` | `18px` | same | Vertical surface padding |
| `--martis-gap` | `14px` | same | Gap between stacked elements |

### 17. Motion (10 variables)

Durations and easing curves (see [Motion tokens](#motion-tokens----martis-dur----martis-ease-)). Reduced motion clamps every duration to `1ms`.

| Variable | Dark | Light | Purpose |
|----------|------|-------|---------|
| `--martis-dur-ultra` | `80ms` | same | Micro feedback |
| `--martis-dur-fast` | `120ms` | same | Hover, color and background changes |
| `--martis-dur-base` | `180ms` | same | Default transition |
| `--martis-dur-medium` | `240ms` | same | Panels, drawers |
| `--martis-dur-slow` | `320ms` | same | Large surfaces |
| `--martis-ease-standard` | `cubic-bezier(0.2, 0.8, 0.2, 1)` | same | Default easing |
| `--martis-ease-accel` | `cubic-bezier(0.4, 0, 1, 1)` | same | Leaving the screen |
| `--martis-ease-decel` | `cubic-bezier(0, 0, 0.2, 1)` | same | Entering the screen |
| `--martis-ease-linear` | `linear` | same | Progress, constant motion |
| `--martis-ease-spring` | `cubic-bezier(0.34, 1.56, 0.64, 1)` | same | Playful overshoot |

### 18. Print (5 variables)

The palette of the print stylesheet (see [Print stylesheet](#-print-stylesheet--media-print)); `@media print` pins them for both themes.

| Variable | Dark | Light | Purpose |
|----------|------|-------|---------|
| `--martis-print-bg` | `#ffffff` | same | Paper background |
| `--martis-print-text` | `#000000` | same | Body text |
| `--martis-print-border` | `#000000` | same | Table and card borders |
| `--martis-print-link-color` | `#000000` | same | Link text (targets are inlined) |
| `--martis-print-muted` | `#444444` | same | Secondary text |

### 19. Rich Text Editor (1 variable)

| Variable | Dark | Light | Purpose |
|----------|------|-------|---------|
| `--martis-trix-icon-filter` | `invert(0.85)` | `none` | CSS `filter` applied to the Trix toolbar icons (inverted on the dark theme) |

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

**Five** duration stops (`ultra`, `fast`, `base`, `medium`, `slow`: 80ms → 320ms) and **five** easing curves (`linear`, `standard`, `accel`, `decel`, `spring`). Custom themes inherit them; override any value to slow down / speed up your whole app without touching component CSS.

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
  fontSize: 'var(--martis-text-sm)',
  boxShadow: 'var(--martis-shadow-md)',
}}>
  Themed content
</div>
```

### In TSX (helper utility classes)

The bundled CSS ships a small set of pre-built utility classes that wrap the most common token references:

```tsx
<div className="martis-text martis-card-bg martis-border border border-solid">
  Content
</div>
```

Available helper classes:
- `.martis-text`, `.martis-text-muted`
- `.martis-bg`, `.martis-surface`, `.martis-card-bg`, `.martis-sidebar-bg`, `.martis-topbar-bg`
- `.martis-border`, `.martis-input-bg`, `.martis-surface-alt`

`.martis-border` sets the border colour only: the width and the style come from `border border-solid` (see [Borders](#borders)).

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

### Borders

The bundled CSS is built with Tailwind's preflight turned off (`corePlugins.preflight: false` in `tailwind.config.ts`). Preflight is an unlayered reset, and its `border-width: 0` on every element beat the PrimeReact component borders, which the theme declares inside `@layer primereact`. Preflight is also what gives every element `border-style: solid`, so in the panel a border utility needs a style utility next to it:

| Border | Classes |
|--------|---------|
| Every side | `border border-solid` (`border-2 border-solid` for 2px) |
| One side | `border-0 border-b border-solid` |
| Between the children of a list | `divide-y divide-x-0 divide-solid` |

- A width alone (`border`, `border-b`, `divide-y`) draws nothing: the style stays `none`.
- A style draws the browser's default `medium` width (3px) on every side no utility gives a width, so a one-sided border zeroes the others with `border-0` first. Tailwind emits `border-0` before the one-sided widths, so `border-b` still sets the bottom. `divide-x-0` does the same for the sides of a divided list's children.
- The colour defaults to the text colour (`currentColor`). Give it a theme token: `border-martis-border` with the [Tailwind preset](#-in-tsx-tailwind-preset), the `.martis-border` helper class, or `borderColor: 'var(--martis-border)'` inline.
- An inline shorthand names the style too: `border: '1px solid var(--martis-border)'`. `border: '1px var(--martis-border)'` resets the style to `none`, and nothing is drawn.
- Native `<input>` and `<textarea>` elements keep the browser's own border (inset on a text input) until a class replaces it: a width utility alone keeps the browser's inset style, and `border-0` removes the border.

`resources/js/themeTokenReads.test.ts` checks the components and the React stubs against these rules (utilities, class constants and inline styles), and `tests/Unit/BorderStyleCssTest.php` checks the border shorthands of `martis.css`.

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
| Background Layers | 7 |
| Text & Borders | 3 |
| Accent / Brand | 7 |
| Semantic Colors, Solid | 8 |
| Semantic Backgrounds & Text | 8 |
| Interactive States | 4 |
| Overlays & Shadows | 5 |
| DataTable | 5 |
| Border Radius | 5 |
| Typography | 31 |
| Chart Palette | 10 |
| Avatar Palette | 16 |
| Brand Gradient | 12 |
| File Icon Colors | 6 |
| Badge Variants (legacy) | 12 |
| Density | 7 |
| Motion | 10 |
| Print | 5 |
| Rich Text Editor | 1 |
| **Total** | **162** |

Each alias pair (`--martis-text-*` / `--martis-font-size-*`, `--martis-weight-*` / `--martis-font-weight-*`, `--martis-leading-*` / `--martis-line-height-*`) is counted as two variables, since both names are defined. The package CSS reads the short name, though: only `body` and the password checklist read an alias, so **set the short name in a theme** (`--martis-text-sm`, not `--martis-font-size-sm`).

Not counted: the per-element layout variables the React components set inline (`--martis-field-span`, `--martis-field-span-md`, `--martis-field-span-lg`, `--martis-field-columns`, `--martis-card-span`, `--martis-card-span-md`, `--martis-card-span-lg`, `--martis-filter-span`), which are not theme tokens, and two optional hooks no stylesheet defines: `--martis-tooltip-bg` and `--martis-tooltip-text` color the tooltips when a theme sets them (they fall back to `--martis-text` on `--martis-bg`, inverted). `tests/Unit/ThemeTokenDriftTest.php` fails when these lists drift: a variable `martis.css` defines that the stub or this reference does not list, a value or a count that does not match, a stale total elsewhere in the docs, or a `var(--martis-*)` read in the package CSS or components that nothing defines and this paragraph does not name.

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
  --martis-text-base: 0.9375rem;  /* 15px instead of 16px */
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
