/**
 * Consumer-extension bundle entry — auto-discovery edition (v1.9.0+).
 *
 * Built into `public/vendor/martis-user/extensions.js`. Martis loads
 * this bundle at runtime when `MARTIS_EXTENSIONS` lists the URL.
 *
 * Convention: every `.tsx` under one of the four buckets gets imported
 * eagerly via `import.meta.glob` and registered on
 * `window.Martis.componentRegistry` with a derived key:
 *
 *   tools/Charts.tsx        → "tool:charts"
 *   tools/SystemHealth.tsx  → "tool:system-health"
 *   cards/RevenueGauge.tsx  → "card:revenue-gauge"
 *   fields/PriceTag.tsx     → "field:price-tag"   (display + input via named exports)
 *   overrides/Sidebar.tsx   → "layout:sidebar"    (mapped via OVERRIDE_KEYS below)
 *   overrides/LoginPage.tsx → "auth:login"
 *
 * The PHP side binds Tools/Fields/Cards to these keys via
 * `withComponent('tool:charts')` etc., so filename and key stay in
 * lock-step. No manual `componentRegistry.register(...)` calls — the
 * generator drops the `.tsx` in the right bucket and the loop below
 * picks it up at the next `npm run build:extensions`.
 */

declare global {
  interface Window {
    Martis?: {
      componentRegistry?: {
        register: (key: string, component: unknown, secondary?: unknown) => void
      }
    }
  }
}

interface FieldModule {
  default?: unknown
  Display?: unknown
  Input?: unknown
}

/**
 * Fixed mapping for layout/auth overrides. The filename selects the
 * registry key — all keys live under stable namespaces the Martis SPA
 * looks up by hard-coded string, so we cannot derive them from the
 * filename alone.
 */
const OVERRIDE_KEYS: Record<string, string> = {
  Shell: 'layout:shell',
  Sidebar: 'layout:sidebar',
  Topbar: 'layout:topbar',
  Footer: 'layout:footer',
  LoginPage: 'auth:login',
  RegisterPage: 'auth:register',
  ForgotPasswordPage: 'auth:forgot-password',
  ResetPasswordPage: 'auth:reset-password',
  EmailVerifyNoticePage: 'auth:email-verify-notice',
}

function pascalToKebab(name: string): string {
  return name
    .replace(/([a-z0-9])([A-Z])/g, '$1-$2')
    .replace(/([A-Z]+)([A-Z][a-z])/g, '$1-$2')
    .toLowerCase()
}

function basename(path: string): string {
  const segments = path.split('/')
  return (segments[segments.length - 1] ?? '').replace(/\.tsx$/, '')
}

const registry = window.Martis?.componentRegistry

if (registry === undefined) {
  // eslint-disable-next-line no-console
  console.error('[martis-extensions] window.Martis.componentRegistry not available — Martis app.tsx must run before this bundle.')
} else {
  // Tools — `tools/Charts.tsx` → "tool:charts"
  const tools = import.meta.glob<{default: unknown}>('./tools/*.tsx', {eager: true})
  for (const [path, mod] of Object.entries(tools)) {
    const name = basename(path)
    const key = `tool:${pascalToKebab(name)}`
    if (mod.default !== undefined) registry.register(key, mod.default)
  }

  // Cards — `cards/RevenueGauge.tsx` → "card:revenue-gauge"
  const cards = import.meta.glob<{default: unknown}>('./cards/*.tsx', {eager: true})
  for (const [path, mod] of Object.entries(cards)) {
    const name = basename(path)
    const key = `card:${pascalToKebab(name)}`
    if (mod.default !== undefined) registry.register(key, mod.default)
  }

  // Fields — `fields/PriceTag.tsx` → "field:price-tag" with optional Display/Input named exports.
  const fields = import.meta.glob<FieldModule>('./fields/*.tsx', {eager: true})
  for (const [path, mod] of Object.entries(fields)) {
    const name = basename(path)
    const key = `field:${pascalToKebab(name)}`
    const display = mod.Display ?? mod.default
    const input = mod.Input ?? mod.default
    if (display !== undefined) registry.register(key, display, input)
  }

  // Overrides — two paths:
  //
  //   (a) Layout / auth slots: filename matches a fixed entry in
  //       OVERRIDE_KEYS above and registers under the canonical key
  //       the SPA router looks up by hard-coded string.
  //
  //   (b) Generic / field-shape overrides: filename is the consumer's
  //       choice. The registry key derives from the filename
  //       (`StatusBadge.tsx` → `status-badge`). When the module ships
  //       both a `Display` and an `Input` named export, both halves
  //       are registered (`status-badge` + `status-badge-input`) so a
  //       PHP `Override('status-badge')` / `Override('status-badge-input')`
  //       pair resolves end-to-end. Otherwise the default export is
  //       registered alone under the derived key.
  //
  // No manual OVERRIDE_KEYS extension or hand-rolled register
  // calls in this file. v1.10.1+ makes `--type=field` and
  // `--type=generic` zero-config too.
  const overrides = import.meta.glob<FieldModule>('./overrides/*.tsx', {eager: true})
  for (const [path, mod] of Object.entries(overrides)) {
    const name = basename(path)
    const fixedKey = OVERRIDE_KEYS[name]

    if (fixedKey !== undefined) {
      // (a) Layout / auth slot — registry key is fixed.
      if (mod.default !== undefined) registry.register(fixedKey, mod.default)
      continue
    }

    // (b) Generic / field-shape override — derive key from filename.
    const derivedKey = pascalToKebab(name)
    if (mod.Display !== undefined || mod.Input !== undefined) {
      // Field-shape: `Display` + `Input` named exports.
      if (mod.Display !== undefined) registry.register(derivedKey, mod.Display)
      if (mod.Input !== undefined) registry.register(`${derivedKey}-input`, mod.Input)
    } else if (mod.default !== undefined) {
      // Single-component override.
      registry.register(derivedKey, mod.default)
    }
  }
}

export {}
