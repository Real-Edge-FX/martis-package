import { createContext, useContext, useState, useEffect, useCallback, type ReactNode } from 'react'
import { api } from '@/lib/api'
import { config } from '@/lib/config'
import { useAuth } from '@/contexts/AuthContext'
import i18n, { loadLocale } from '@/lib/i18n'

export type ThemeMode = 'dark' | 'light' | 'system'
export type AccentColor = 'martis' | 'blue' | 'teal' | 'violet' | 'amber' | 'custom'
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

interface PreferencesContextValue {
  prefs: Preferences
  meta: PreferencesMeta | null
  update: (patch: Partial<Preferences>) => Promise<void>
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

function readInitialPrefs(): Preferences {
  // (1) SSR-injected payload (no flash)
  const injected = (window as unknown as { MartisConfig?: { preferences?: { initial?: Partial<Preferences> } } })
    .MartisConfig?.preferences?.initial
  if (injected && typeof injected === 'object') {
    return { ...DEFAULTS, ...injected } as Preferences
  }
  // (2) localStorage cache
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (raw) return { ...DEFAULTS, ...JSON.parse(raw) } as Preferences
  } catch {}
  // (3) config default (deep fallback)
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

  const enabled = config.preferences?.enabled !== false

  // Apply preferences to the DOM on every change (reactive).
  useEffect(() => {
    applyToDom(prefs)
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(prefs))
    } catch {}
  }, [prefs])

  // Refetch preferences whenever the auth state resolves to a user. This
  // covers the critical flow where the user logs in client-side (react-router
  // Navigate, no hard reload): the SSR payload injected during the login
  // page had the guest defaults, so without a refetch the saved theme/locale
  // from the authenticated user would never load. Keyed on user.id so it
  // also re-runs after account switching.
  useEffect(() => {
    if (!enabled) return
    let active = true
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
        /* offline, unauthenticated, or preferences disabled — keep local state */
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

  const update = useCallback(async (patch: Partial<Preferences>) => {
    // Optimistic: apply locally immediately, then reconcile with server.
    // We send the full merged state — not just the patch — so that creating
    // a new user_preferences row on the server never loses fields the user
    // already applied client-side (otherwise schema defaults would clobber
    // e.g. a `theme=light` the user set before ever saving a locale change).
    let nextState: Preferences | null = null
    setPrefs((prev) => {
      nextState = { ...prev, ...patch }
      return nextState
    })
    if (!enabled || nextState === null) return
    try {
      const resp = await api.put<ShowResponse>('/api/preferences', nextState)
      if (resp?.data) setPrefs((prev) => ({ ...prev, ...resp.data }))
      if (resp?.meta) setMeta(resp.meta)
    } catch {
      // Server write failed (guest? 2FA? offline?) — keep the optimistic local state.
    }
  }, [enabled])

  const reset = useCallback(async () => {
    if (!enabled) return
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
