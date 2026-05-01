import { createContext, useContext, useState, useEffect, useCallback, useRef, type ReactNode } from 'react'
import { api } from '@/lib/api'
import { config } from '@/lib/config'
import { useAuth } from '@/contexts/AuthContext'
import i18n, { loadLocale } from '@/lib/i18n'

export type ThemeMode = 'dark' | 'light' | 'system'
/** Bundled accent values shipped by Martis. Custom accents declared via
 *  `MARTIS_CUSTOM_ACCENTS` (v1.7.0) extend this set at runtime — they
 *  are valid AccentColor values too, but TypeScript cannot enumerate
 *  them statically because they come from env. The `string & {}` arm
 *  keeps autocomplete on the bundled values while accepting any extra
 *  custom name at compile time. */
export type AccentColor =
  | 'martis'
  | 'blue'
  | 'teal'
  | 'violet'
  | 'amber'
  | 'custom'
  | (string & {})
export type UiDensity = 'comfortable' | 'dense'

export interface Preferences {
  theme: ThemeMode
  accent: AccentColor
  brandColor: string | null
  density: UiDensity
  locale: string
  reducedMotion: boolean
}

export interface PreferencesMeta {
  source: 'default' | 'user' | 'preset'
  preset: string | null
  presetsAvailable: string[]
  locales: string[]
  accents: string[]
  themes: string[]
  densities: string[]
}

type PreferencePatch = Partial<Preferences> | ((prev: Preferences) => Partial<Preferences>)

interface PreferencesContextValue {
  prefs: Preferences
  meta: PreferencesMeta | null
  update: (patch: PreferencePatch) => Promise<void>
  reset: () => Promise<void>
  enabled: boolean
}

const DEFAULTS: Preferences = {
  theme: 'dark',
  accent: 'martis',
  brandColor: null,
  density: 'comfortable',
  locale: 'en',
  reducedMotion: false,
}

const PreferencesContext = createContext<PreferencesContextValue | null>(null)

const STORAGE_KEY = 'martis-preferences'
/**
 * Companion flag in `localStorage` that records whether the most recent
 * `update()` happened while the user was unauthenticated. Set true on
 * any guest pick (theme cycle, locale select, …) and consumed by the
 * post-login effect, which promotes the cached preferences to the
 * server with a single PUT and clears the flag.
 *
 * Without this signal, a guest who picks `theme=light` on the login
 * page would silently revert to whatever was server-saved before once
 * they authenticate — confusing because the change "disappeared" from
 * their POV. v1.8.5.
 */
const GUEST_MODIFIED_KEY = 'martis-preferences-guest-modified'

/** Map the `theme=system` preference into a concrete dark/light at runtime. */
function resolveTheme(theme: ThemeMode): 'dark' | 'light' {
  if (theme === 'system') {
    return window.matchMedia?.('(prefers-color-scheme: light)').matches ? 'light' : 'dark'
  }
  return theme
}

/** Write preferences to the root `<html>` element so CSS tokens pick them up. */
function applyToDom(prefs: Preferences): void {
  const root = document.documentElement
  const effectiveTheme = resolveTheme(prefs.theme)

  // Legacy `.dark` class (Martis convention) + `data-theme` attribute
  // (design-system convention). Both are written so either selector set
  // resolves to the same visual state.
  if (effectiveTheme === 'dark') root.classList.add('dark')
  else root.classList.remove('dark')
  root.setAttribute('data-theme', effectiveTheme)

  root.setAttribute('data-accent', prefs.accent)
  root.setAttribute('data-density', prefs.density)

  if (prefs.reducedMotion) root.setAttribute('data-reduced-motion', 'true')
  else root.removeAttribute('data-reduced-motion')

  if (prefs.brandColor) {
    root.style.setProperty('--martis-accent', prefs.brandColor)
  } else {
    root.style.removeProperty('--martis-accent')
  }
}

/**
 * Resolve the initial preference state on every page load.
 *
 * Priority (v1.7.3):
 *   1. SSR payload with `source` ∈ {user, preset} — describes what THIS
 *      authenticated user / preset actually persisted server-side.
 *      Wins over localStorage (which may hold stale values from a
 *      different account that previously logged in on this browser).
 *   2. localStorage cache — guest choices made on the login / register
 *      surfaces, OR the most recent state for an authed user when
 *      offline.
 *   3. SSR payload with `source = default` — config defaults from the
 *      server (no row found, no preset applied).
 *   4. Hard-coded fallback derived from `config.theme.default`.
 *
 * The earlier implementation collapsed (1) and (3) into a single
 * `injected wins always` branch, which made guest preferences
 * silently revert to the config default after every refresh — the
 * SSR payload came back as `source=default` and overwrote the
 * localStorage value the guest just picked.
 */
function readInitialPrefs(): Preferences {
  const injected = (window as unknown as {
    MartisConfig?: { preferences?: { initial?: Partial<Preferences> & { source?: string } } }
  }).MartisConfig?.preferences?.initial

  const isPersisted =
    injected !== undefined &&
    injected !== null &&
    typeof injected === 'object' &&
    (injected.source === 'user' || injected.source === 'preset')

  // (1) Authenticated / preset payload — server is the source of truth.
  if (isPersisted) {
    return { ...DEFAULTS, ...injected } as Preferences
  }

  // (2) localStorage cache — guest choices on login/register, or the
  // last-applied state for any user. Wins over the server "default"
  // payload because that payload is just config defaults, not
  // anything the user actually saved.
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (raw) {
      const cached = JSON.parse(raw)
      if (cached && typeof cached === 'object') {
        return { ...DEFAULTS, ...cached } as Preferences
      }
    }
  } catch {}

  // (3) Server-supplied defaults (source = 'default').
  if (injected && typeof injected === 'object') {
    return { ...DEFAULTS, ...injected } as Preferences
  }

  // (4) Hard-coded fallback derived from `config.theme.default`.
  return { ...DEFAULTS, theme: (config.theme?.default as ThemeMode) ?? 'dark' }
}

interface ShowResponse {
  data: Preferences & Partial<PreferencesMeta>
  meta: PreferencesMeta
}

export function PreferencesProvider({ children }: { children: ReactNode }) {
  const [prefs, setPrefs] = useState<Preferences>(readInitialPrefs)
  const [meta, setMeta] = useState<PreferencesMeta | null>(null)
  const { user } = useAuth()

  // Mirror `prefs` into a ref so `update()` can read the latest committed
  // state synchronously when computing the next merged snapshot. Relying on
  // the value React passes into `setPrefs(updater)` was unsafe under React 18
  // automatic batching — when the updater ran asynchronously, the local
  // `nextState` variable inside `update()` stayed `null` and the
  // localStorage write was silently skipped (v1.7.6 guest-page regression).
  const prefsRef = useRef<Preferences>(prefs)
  useEffect(() => { prefsRef.current = prefs }, [prefs])

  const enabled = config.preferences?.enabled !== false

  // Apply preferences to the DOM on every change (reactive). The
  // localStorage write that used to live here was moved into
  // `update()` (v1.7.5) so it captures EXPLICIT user choices only —
  // not the SSR-injected defaults that this effect runs against on
  // every mount. Persisting on mount silently locked every visitor
  // into whatever defaults shipped on their first visit, which made
  // a later `MARTIS_DEFAULT_THEME` / `MARTIS_DEFAULT_ACCENT` change
  // invisible to anyone who had loaded the page even once before.
  useEffect(() => {
    applyToDom(prefs)
  }, [prefs])

  // Refetch preferences whenever the auth state resolves to a user. This
  // covers the critical flow where the user logs in client-side (react-router
  // Navigate, no hard reload): the SSR payload injected during the login
  // page had the guest defaults, so without a refetch the saved theme/locale
  // from the authenticated user would never load. Keyed on user.id so it
  // also re-runs after account switching.
  useEffect(() => {
    if (!enabled) return
    // Skip the refetch for guests (v1.7.6). The /api/preferences
    // routes live inside the `martis.auth` middleware group — a
    // guest GET returns 401, the catch below swallows the failure,
    // but the browser console still logs the network error on every
    // login-page mount. The fetch is only meaningful AFTER the user
    // authenticates: that is when the SSR payload may diverge from
    // what the user had on the guest page (different language,
    // saved accent, etc) and the post-login refetch is needed to
    // reconcile. Guests have nothing extra on the server to load —
    // the SSR payload + localStorage are already authoritative.
    if (!user) return
    let active = true

    // v1.8.5 — If the user explicitly tweaked theme / locale / etc on
    // a guest auth surface immediately before logging in, promote
    // those picks to the server with a single PUT instead of pulling
    // the (potentially older) server row. The flag is cleared as soon
    // as the round-trip succeeds so a future hard refresh resumes the
    // normal GET-on-mount behaviour.
    let guestModified = false
    try {
      guestModified = localStorage.getItem(GUEST_MODIFIED_KEY) === '1'
    } catch { /* localStorage blocked — fall through to plain GET */ }

    if (guestModified) {
      api.put<ShowResponse>('/api/preferences', prefsRef.current)
        .then((resp) => {
          if (!active) return
          try { localStorage.removeItem(GUEST_MODIFIED_KEY) } catch {}
          if (resp?.data) setPrefs((prev) => ({ ...prev, ...resp.data }))
          if (resp?.meta) setMeta(resp.meta)
          if (resp?.data.locale && resp.data.locale !== i18n.language) {
            void loadLocale(resp.data.locale)
          }
        })
        .catch(() => {
          // PUT failed (validation, 5xx, offline). Drop the flag
          // anyway — keeping it would re-fire the same broken PUT on
          // every mount. The local state is still correct from the
          // optimistic update() that set the flag in the first place.
          try { localStorage.removeItem(GUEST_MODIFIED_KEY) } catch {}
        })
      return () => { active = false }
    }

    api.get<ShowResponse>('/api/preferences')
      .then((resp) => {
        if (!active || !resp?.data) return
        setPrefs((prev) => ({ ...prev, ...resp.data }))
        if (resp.meta) setMeta(resp.meta)
        // Keep i18next in sync with the authenticated user's locale. Without
        // this, the SPA boots with whatever locale the blade injected
        // (often the app default or the guest-page locale) and the user's
        // saved language only applies after they open the preferences panel
        // and pick it manually — which is how we ended up with mixed
        // PT/EN strings across the sidebar.
        if (resp.data.locale && resp.data.locale !== i18n.language) {
          void loadLocale(resp.data.locale)
        }
      })
      .catch(() => {
        /* offline or preferences disabled — keep local state */
      })
    return () => { active = false }
  }, [enabled, user?.id])

  // Re-apply when the OS theme changes, but only if user picked "system".
  useEffect(() => {
    if (prefs.theme !== 'system') return
    const mql = window.matchMedia('(prefers-color-scheme: light)')
    const handler = () => applyToDom(prefs)
    mql.addEventListener?.('change', handler)
    return () => mql.removeEventListener?.('change', handler)
  }, [prefs])

  const update = useCallback(async (patch: PreferencePatch) => {
    // Compute the merged snapshot from `prefsRef` (mirror of the latest
    // committed state) BEFORE calling setPrefs. Doing it inside the
    // setPrefs updater would leave `nextState` null whenever React 18
    // batched the updater asynchronously, which silently skipped the
    // localStorage write on the guest auth surfaces (login / register /
    // 2FA challenge). The ref-based snapshot is deterministic.
    //
    // `patch` accepts a function form so callers can derive the next
    // value from the LATEST committed state (read from the ref). The
    // theme cycle button needs this — three rapid clicks before React
    // re-renders all see the same `prefs.theme` from the captured
    // closure, so a static patch always advances by ONE step. The
    // function form re-evaluates the next theme against the live ref.
    const concretePatch = typeof patch === 'function' ? patch(prefsRef.current) : patch
    const nextState: Preferences = { ...prefsRef.current, ...concretePatch }
    // Write back to the ref synchronously BEFORE setPrefs so a second
    // rapid click (or chained update) reads the freshly merged state
    // instead of the stale value the post-render `useEffect` would only
    // sync after the next paint. Without this the toggle felt
    // "intermittent" — fast clicks kept colliding on the same `prev`.
    prefsRef.current = nextState
    setPrefs(nextState)
    if (!enabled) return
    // Persist the user's EXPLICIT choice (v1.7.5). This is the only
    // place that writes to localStorage — the SSR-injected defaults
    // never end up persisted, so a later `MARTIS_DEFAULT_*` env
    // change reaches every guest who has not yet picked anything.
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(nextState))
      // v1.8.5 — Mark the choice as a guest pick when the user is
      // unauthenticated. The post-login effect uses this to promote
      // the cached preferences to the server (single PUT) so a theme
      // / locale picked on /login carries into the authenticated
      // shell instead of getting silently overwritten by the
      // server-saved row.
      if (!user) {
        localStorage.setItem(GUEST_MODIFIED_KEY, '1')
      }
    } catch {}
    // Skip the server PUT for guests. The /api/preferences route lives
    // behind the `martis.auth` middleware group — every theme/locale
    // tweak on the login/register/2FA surfaces would otherwise log a
    // 401 in the user's console (symmetric to the GET refetch guard
    // shipped in v1.7.6). LocalStorage already captured the choice
    // above, so the post-login readInitialPrefs() picks it up.
    if (!user) return
    // Optimistic write — send the FULL merged state so creating a new
    // user_preferences row never loses fields the user already applied
    // client-side (otherwise schema defaults would clobber e.g. a
    // `theme=light` the user set before ever saving a locale change).
    try {
      const resp = await api.put<ShowResponse>('/api/preferences', nextState)
      if (resp?.data) setPrefs((prev) => ({ ...prev, ...resp.data }))
      if (resp?.meta) setMeta(resp.meta)
    } catch {
      // Server write failed (2FA mid-flow? offline?) — keep the optimistic local state.
    }
  }, [enabled, user])

  const reset = useCallback(async () => {
    if (!enabled) return
    // Reset clears the local override so the SSR / config defaults
    // win again on next mount. Symmetric with the v1.7.5 update()
    // change that only writes to localStorage on explicit picks.
    try {
      localStorage.removeItem(STORAGE_KEY)
    } catch {}
    try {
      const resp = await api.delete<ShowResponse>('/api/preferences')
      if (resp?.data) setPrefs({ ...DEFAULTS, ...resp.data })
      if (resp?.meta) setMeta(resp.meta)
    } catch {
      setPrefs(DEFAULTS)
    }
  }, [enabled])

  return (
    <PreferencesContext.Provider value={{ prefs, meta, update, reset, enabled }}>
      {children}
    </PreferencesContext.Provider>
  )
}

export function usePreferences(): PreferencesContextValue {
  const ctx = useContext(PreferencesContext)
  if (!ctx) throw new Error('usePreferences must be used within PreferencesProvider')
  return ctx
}

/**
 * Safe variant of {@see usePreferences} that returns `null` when the
 * provider is missing instead of throwing. Use this from components
 * (e.g. `AuthControls`) that may render in test surfaces or detached
 * trees that don't bootstrap the full app shell. Production code that
 * runs inside the shell should keep using `usePreferences()` to keep
 * the missing-provider contract loud.
 */
export function usePreferencesOptional(): PreferencesContextValue | null {
  return useContext(PreferencesContext)
}
