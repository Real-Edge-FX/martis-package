# User Preferences

> Per-user UI preferences (theme, accent, density, locale, reduced-motion) persisted server-side so they travel across devices and sessions. URL presets compose over the user row for shareable, link-driven layouts. Exposed through a compact overlay in the topbar.

Each admin user keeps their own interface preferences: theme, accent (or, when allowed, a brand colour), density, locale and reduced motion. The topbar overlay edits them, the server keeps them per user across devices, and a URL preset can override them for a shared link.

---

## What's persisted

| Preference | Values | Default |
|------------|--------|---------|
| `theme` | `dark` · `light` · `system` | `dark` |
| `accent` | `martis` · `blue` · `teal` · `violet` · `amber` · `custom` | `martis` |
| `brandColor` | `#RGB` · `#RGBA` · `#RRGGBB` · `#RRGGBBAA` · `null` | `null` (off by default — opt-in via `allowBrandColor`) |
| `density` | `comfortable` · `dense` | `comfortable` |
| `locale` | configured locale code | `en` |
| `reducedMotion` | `true` · `false` | `false` |

---

## How it works

### Resolution chain (server-side)

The `PreferencesResolver` resolves the effective payload on every protected request, in this order (highest priority first):

1. **URL preset** — `?preset=<name>` maps to `config('martis.preferences.presets.<name>')`.
2. **User row** in `martis_user_preferences`.
3. **Config defaults** (`martis.preferences.defaults`).

Unknown values, missing tables, and invalid enums degrade silently to defaults — the feature never throws during bootstrap.

### SSR + no-flash

The resolved payload is injected into `window.MartisConfig.preferences.initial` by the blade template **before** the SPA script tag. An inline bootstrap script then writes the `<html>` attributes (`.dark` class, `data-theme`, `data-accent`, `data-density`, `data-reduced-motion`) before first paint, so the user never sees a flash of the wrong theme/density.

### Auth awareness

When the user logs in client-side (no hard reload), `PreferencesProvider` refetches `/api/preferences` so the authenticated user's saved preferences replace the guest defaults. This is why `AuthProvider` wraps `PreferencesProvider` in [app.tsx](../resources/js/app.tsx).

The refetch and the optimistic PUT are BOTH skipped when `user` is null (v1.7.6). The endpoints live behind `martis.auth`, so a guest mount or guest theme/locale tweak would otherwise log a 401 in the browser console. localStorage and the SSR payload are already authoritative for guests; the server has nothing extra to add until the user signs in.

A pick made while signed out is carried to the account that signs in next only from the same tab session (v2.4.0): `update()` sets a `martis-preferences-guest-modified` marker in `sessionStorage` for a guest, the post-login effect consumes it with a single PUT, and a marker or cache a previous visitor of a shared browser left in `localStorage` is never promoted (see [Authentication → Guest persistence](authentication.md#guest-persistence-v176)). Sign-out clears the cache and the marker.

### Persistence guarantees

`PreferencesProvider` exposes `update(patch)` for partial writes. The patch can be a plain object **or** a function `(prev) => Partial<Preferences>` so callers can derive the next value from the LATEST committed state. The function form is required for chained updates that must compound (e.g. a theme cycle on rapid clicks) — the plain object form captures the current value in the render closure, which under React 18 batching can stay stale across multiple back-to-back invocations.

Internally, `update()` mirrors the latest committed prefs into a `useRef` and computes the merged snapshot from the ref **before** calling `setPrefs`. Without this, the local `nextState` would be subject to React's update timing and the localStorage write could be silently skipped.

```ts
// Plain object form — fine for one-shot patches
update({ theme: 'light' })

// Function form — required for cycles / chained updates
update((prev) => {
  const next = nextThemeInCycle(prev.theme)
  return { theme: next }
})
```

---

### Locale normalisation (v2.10.0)

The locale the resolver answers is always one the app offers (`preferences.locales`, from `MARTIS_UI_LOCALES`). A `MARTIS_DEFAULT_LOCALE` outside the list resolves to the first listed locale; a locale stored for a user (say `pt_BR` after the app dropped it) or named by a preset, when it is not listed, resolves to that default. The stored row is left alone, so the user gets their language back when the list offers it again. The `martis.locale` middleware applies the normalised value and the shell writes it in `<html lang>` (`en_GB` becomes `en-GB`). `PUT /api/preferences` refuses a locale outside the list with `422`.

## Choosing which preferences users may change (v2.12.0)

An app with its own brand theme may want users to keep the light/dark, density and reduced-motion choices without recolouring the panel. `preferences.editable` (`MARTIS_PREFERENCES_EDITABLE`) names the preferences users may change: comma-separated, any of `theme`, `accent`, `density`, `locale`, `reducedMotion`. The default is all five, which is the behaviour before v2.12.0.

```bash
# A branded install: users pick light/dark, density and reduced motion only.
MARTIS_PREFERENCES_EDITABLE=theme,density,reducedMotion
```

A preference left out of the list:

- is hidden from the preferences menu (its section is not rendered) and, for `theme` and `locale`, from the controls of the sign-in pages; with no preference left, the menu's topbar button is not rendered either;
- is refused by `PUT /api/preferences` with `422` (`This preference cannot be changed.` on that field) when the request changes it, and nothing is stored. A value equal to the one in effect is accepted and dropped, so a client that sends its full state keeps working;
- is ignored in a user's stored row (the row is left alone, so a choice the user made comes back if the app lists the preference again) and in a URL preset (`?preset=focus-amber` still applies its theme, not its accent). A row the PUT creates starts from the values in effect for what the request leaves out, not from the table's column defaults;
- always resolves to its value in `preferences.defaults`, from the first paint: the shell and the SPA ignore a value cached in the browser for it.

Two older switches feed the same list. `theme.allowToggle => false` removes `theme` from it (and, since v2.12.0, the server applies it too: a stored theme is ignored and a theme change is refused). The brand-colour hex follows the accent: a hex is accepted only when `accent` is listed **and** `allowBrandColor` is on (`brandColor` is then in the effective list). `brandColor: null` is part of picking an accent, so it is accepted, stored and applied whenever `accent` is editable: a swatch clears a hex, including a `defaults.brandColor`. A preset's `brandColor` is written by the app, so it follows `accent` alone.

An unknown name throws an `InvalidArgumentException` naming `MARTIS_PREFERENCES_EDITABLE` (`brandColor` gets a hint that it follows `accent`). Unset or empty means all five; `MARTIS_PREFERENCES_EDITABLE=none` locks every preference (the menu then has nothing to show); `MARTIS_PREFERENCES_ENABLED=false` removes the feature entirely. A config published before v2.12.0 has no `editable` key and keeps every preference editable; setting the variable with such a config throws, telling you to add the key (Laravel merges the package config one level deep, so the published `preferences` block hides the new key).

The effective list (with `theme.allowToggle` and `brandColor` already applied) reaches the SPA as `window.MartisConfig.preferences.editable` and the API as `meta.editable`, so a custom shell can follow it; `PreferencesContext.update()` drops a locked preference from every patch, whichever control sent it (the menu, the sign-in strip, `useTheme().toggle()`), and keeps it out of the browser cache; a custom control built on `useTheme()` or `useMartisNav().theme` should check `window.MartisConfig.preferences.editable`, since with `theme` locked its toggle does nothing and the current theme stays at the default. Each section of the menu carries `data-martis-preference="<name>"` (`theme`, `accent`, `density`, `locale`, `reducedMotion`) for a host stylesheet to target.

## API

Protected routes — 2FA-completed users only.

| Verb | Path | Body | Purpose |
|------|------|------|---------|
| GET | `/martis/api/preferences` | — | Read current effective preferences + meta. |
| PUT | `/martis/api/preferences` | `{ theme?, accent?, brandColor?, density?, locale?, reducedMotion? }` | Upsert user row. The SPA sends the **full merged state** of the preferences users may change, to avoid schema defaults clobbering fields not in the patch. Changing a preference outside `meta.editable` answers `422` (v2.12.0). |
| DELETE | `/martis/api/preferences` | — | Delete the row; resolver falls back to config defaults. |

Response shape (GET/PUT/DELETE — all use the standard JSON envelope):

```json
{
  "data": {
    "theme": "dark",
    "accent": "martis",
    "brandColor": null,
    "density": "comfortable",
    "locale": "en",
    "reducedMotion": false,
    "source": "user",
    "preset": null
  },
  "meta": {
    "source": "user",
    "preset": null,
    "presetsAvailable": ["exec-comfort", "ops-compact", "focus-amber"],
    "locales": ["en", "pt_PT", "pt_BR"],
    "accents": ["martis", "blue", "teal", "violet", "amber"],
    "themes": ["dark", "light", "system"],
    "densities": ["comfortable", "dense"],
    "editable": ["theme", "accent", "density", "locale", "reducedMotion"]
  }
}
```

---

## Disabling the preferences menu

Flip the `enabled` flag off and the topbar icon disappears, the blade stops injecting the initial payload, the API routes are not registered, and `PreferencesProvider` returns guest state without firing any network calls. Useful for embedded installs, read-only demos, or when a custom shell already ships its own settings panel.

```php
// config/martis.php
'preferences' => [
    'enabled' => env('MARTIS_PREFERENCES_ENABLED', true),
    // …
],
```

```bash
# or per-environment via .env
MARTIS_PREFERENCES_ENABLED=false
```

The migration can safely remain applied — the resolver silently ignores the table when the feature is off.

## Configuration

```php
// config/martis.php

'preferences' => [
    'enabled' => env('MARTIS_PREFERENCES_ENABLED', true),

    'defaults' => [
        'theme' => 'dark',
        'accent' => 'martis',
        'brandColor' => null,
        'density' => 'comfortable',
        'locale' => env('MARTIS_DEFAULT_LOCALE', 'en'),
        'reducedMotion' => false,
    ],

    // Locales shown in the language dropdown (v2.10.0: MARTIS_UI_LOCALES,
    // comma-separated, e.g. `en_GB,pt_PT`).
    'locales' => array_values(array_filter(array_map('trim', explode(',', (string) env('MARTIS_UI_LOCALES', 'en,pt_PT,pt_BR'))))),

    // Human-readable labels. Missing locales fall back to their code.
    'locale_labels' => [
        'en' => 'English',
        'en_GB' => 'English (UK)',
        'pt_PT' => 'Português (PT)',
        'pt_BR' => 'Português (BR)',
    ],

    // MARTIS_UI_LOCALE_LABELS="en_GB:English (UK),pt_PT:Português (Portugal)",
    // merged over `locale_labels` (the env entries win).
    'locale_labels_env' => env('MARTIS_UI_LOCALE_LABELS'),

    // Per-user custom brand hex (off by default).
    // Turn on for multi-tenant apps where each tenant has its own colour.
    'allowBrandColor' => env('MARTIS_ALLOW_BRAND_COLOR', false),

    // Preferences users may change (v2.12.0). MARTIS_PREFERENCES_EDITABLE,
    // comma-separated; default all five.
    'editable' => array_values(array_filter(array_map('trim', explode(',', (string) env('MARTIS_PREFERENCES_EDITABLE', 'theme,accent,density,locale,reducedMotion'))))),

    // Named presets applied via `?preset=<name>`. Shareable links.
    'presets' => [
        'exec-comfort' => [
            'accent' => 'violet',
            'density' => 'comfortable',
        ],
        'ops-compact' => [
            'accent' => 'teal',
            'density' => 'dense',
            'reducedMotion' => true,
        ],
        'focus-amber' => [
            'theme' => 'dark',
            'accent' => 'amber',
        ],
    ],
],
```

---

## Highlights

### Accent swatches follow the stylesheets

Each swatch in the accent picker is painted with the colour the cascade resolves for `html[data-accent="<key>"]` in the current mode (`lib/accentSwatches.ts` walks the same-origin stylesheets: the package CSS, then the consumer theme, then the inline `MARTIS_CUSTOM_ACCENTS` block), so a branded theme that redefines `--martis-accent` on its root blocks re-tints the "Martis" swatch, a theme that overrides a bundled or custom accent per mode (`html[data-theme="light"][data-accent="teal"] { … }`) shows that override, and a theme switch re-tints the swatches in the same render. The bundled hex table and the env hex of a custom accent are only fallbacks for a key no stylesheet declares. (v1.34.0)

### Arbitrary brand colour

When `allowBrandColor` is `true` and `accent` is editable, the preferences panel exposes a hex input. The server applies the switch too (v2.12.0): with it off, `PUT /api/preferences` refuses a hex (`422`) and a hex stored earlier is ignored (an accent stored as `custom` then resolves to the default accent); `brandColor: null` stays accepted. Any valid `#RGB`, `#RGBA`, `#RRGGBB`, or `#RRGGBBAA` value overrides the accent (`data-accent="custom"` on `<html>`, `--martis-accent` inline style). Ideal for multi-tenant branding.

A `brandColor` selection drives the full seven-token accent palette (`--martis-accent`, `--martis-accent-hover`, `--martis-accent-active`, `--martis-accent-bg-light`, `--martis-accent-bg`, `--martis-focus-ring`, `--martis-accent-contrast`). Five of the derived tokens come from the base hex via `color-mix(in srgb, ...)` and the contrast colour from its luminance (white while it holds the WCAG 3:1 floor, else a near-black navy; `lib/accentContrast.ts`) — the same chain used by `MARTIS_CUSTOM_ACCENTS`. Applied in two places so the FOUC stays clean: the pre-paint blade bootstrap and the React `PreferencesContext::applyToDom()`. Clearing `brandColor` removes all seven overrides and falls back to the active `data-accent` palette. (v1.8.12+)

### Persisted preferences + shareable presets

User rows in `martis_user_preferences` replace session-only state. URL presets (`?preset=exec-comfort`) compose over the user row — recipients see the shared layout without overwriting their own defaults.

### Density per surface + reduced-motion enforcement

- `[data-density]` tokens (`--martis-row-h`, `--martis-nav-item-h`, …) propagate density through shell components. Any descendant can override via `data-density="dense"` on a local container.
- `[data-reduced-motion="true"]` + `@media (prefers-reduced-motion: reduce)` clamp all `--martis-dur-*` tokens to `1ms`. Transitions still resolve — just instantly — so accessibility tools work without breaking focus-state logic.

---

### Fits a phone screen (v2.15.0)

On a screen up to 640px wide the panel spans the width, 8px from each edge (`.p-overlaypanel.martis-preferences-panel` in `martis.css`), instead of PrimeReact's 320px panel pushed against the left edge. On any screen its height is capped to the room under the top bar, measured each time it opens, and the content scrolls: a panel taller than that room used to flip above the trigger, where a short screen cut its bottom off.

## Migration

```bash
php artisan vendor:publish --tag=martis-preferences-migration
php artisan migrate
```

`martis:install` publishes this automatically. The migration is idempotent — safe to re-publish.

---

## Related

- [i18n.md](i18n.md) — adding new locales.
- [theming.md](theming.md) — the 163-token design system that preferences drive.
