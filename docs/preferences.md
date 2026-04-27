# User Preferences

> Task 07.1 — ⭐ D2 User preferences persisted + shareable presets.

Martis persists per-user UI preferences (theme, accent, density, locale, reduced-motion) so settings travel across devices and sessions. Exposed through a compact overlay in the topbar.

---

## What's persisted

| Preference | Values | Default |
|------------|--------|---------|
| `theme` | `dark` · `light` · `system` | `dark` |
| `accent` | `martis` · `blue` · `teal` · `violet` · `amber` · `custom` | `martis` |
| `brandColor` | `#RGB` · `#RRGGBB` · `#RRGGBBAA` · `null` | `null` (⭐ D1, off by default) |
| `density` | `comfortable` · `dense` | `comfortable` (⭐ D3) |
| `locale` | configured locale code | `en` |
| `reducedMotion` | `true` · `false` | `false` (⭐ D3) |

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

---

## API

Protected routes — 2FA-completed users only.

| Verb | Path | Body | Purpose |
|------|------|------|---------|
| GET | `/martis/api/preferences` | — | Read current effective preferences + meta. |
| PUT | `/martis/api/preferences` | `{ theme?, accent?, brandColor?, density?, locale?, reducedMotion? }` | Upsert user row. The SPA sends the **full merged state** to avoid schema defaults clobbering fields not in the patch. |
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
    "densities": ["comfortable", "dense"]
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

    // Locales shown in the language dropdown (must have lang files).
    'locales' => ['en', 'pt_PT', 'pt_BR'],

    // Human-readable labels. Missing locales fall back to their code.
    'locale_labels' => [
        'en' => 'English',
        'pt_PT' => 'Português (PT)',
        'pt_BR' => 'Português (BR)',
    ],

    // ⭐ D1 — per-user custom brand hex (off by default).
    // Turn on for multi-tenant apps where each tenant has its own colour.
    'allowBrandColor' => env('MARTIS_ALLOW_BRAND_COLOR', false),

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

## Differentials

### ⭐ D1 — Arbitrary brand colour

When `allowBrandColor` is `true`, the preferences panel exposes a hex input. Any valid `#RGB`, `#RRGGBB`, or `#RRGGBBAA` value overrides the accent (`data-accent="custom"` on `<html>`, `--martis-accent` inline style). Ideal for multi-tenant branding.

### ⭐ D2 — Persisted preferences + shareable presets

User rows in `martis_user_preferences` replace session-only state. URL presets (`?preset=exec-comfort`) compose over the user row — recipients see the shared layout without overwriting their own defaults.

### ⭐ D3 — Density per surface + reduced-motion enforcement

- `[data-density]` tokens (`--martis-row-h`, `--martis-nav-item-h`, …) propagate density through shell components. Any descendant can override via `data-density="dense"` on a local container.
- `[data-reduced-motion="true"]` + `@media (prefers-reduced-motion: reduce)` clamp all `--martis-dur-*` tokens to `1ms`. Transitions still resolve — just instantly — so accessibility tools work without breaking focus-state logic.

---

## Migration

```bash
php artisan vendor:publish --tag=martis-preferences-migration
php artisan migrate
```

`martis:install` publishes this automatically. The migration is idempotent — safe to re-publish.

---

## Related

- [i18n.md](i18n.md) — adding new locales.
- [theming.md](theming.md) — the 94-token design system that preferences drive.
- [differentials.md](differentials.md) — full differentials list.
