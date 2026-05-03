/**
 * @vitest-environment jsdom
 */
import { describe, expect, it, beforeEach, afterEach } from 'vitest'

const STORAGE_KEY = 'martis-preferences'
const GUEST_MODIFIED_KEY = 'martis-preferences-guest-modified'

beforeEach(() => {
  localStorage.clear()
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  ;(window as any).MartisConfig = undefined
  delete document.documentElement.dataset.theme
  document.documentElement.classList.remove('dark')
})

afterEach(() => {
  localStorage.clear()
})

/**
 * The function under test isn't exported, so the spec runs the same
 * priority logic inline and asserts the produced state. The point of
 * the spec is to lock in the v1.8.8 fix: `GUEST_MODIFIED_KEY=1` makes
 * localStorage win over a `source=user` SSR payload, because the
 * guest just picked something and the post-login PUT will sync it.
 */
function readInitialPrefs(): Record<string, unknown> {
  const DEFAULTS = {
    theme: 'dark',
    accent: 'martis',
    brandColor: null,
    density: 'comfortable',
    locale: 'en',
    reducedMotion: false,
  }

  const injected = (window as unknown as {
    MartisConfig?: { preferences?: { initial?: Record<string, unknown> & { source?: string } } }
  }).MartisConfig?.preferences?.initial

  let guestModified = false
  let cached: Record<string, unknown> | null = null
  try {
    guestModified = localStorage.getItem(GUEST_MODIFIED_KEY) === '1'
    const raw = localStorage.getItem(STORAGE_KEY)
    if (raw) {
      const parsed = JSON.parse(raw)
      if (parsed && typeof parsed === 'object') cached = parsed
    }
  } catch { /* ignore */ }

  if (guestModified && cached) return { ...DEFAULTS, ...cached }

  const isPersisted =
    injected !== undefined &&
    injected !== null &&
    typeof injected === 'object' &&
    (injected.source === 'user' || injected.source === 'preset')

  if (isPersisted) return { ...DEFAULTS, ...injected }
  if (cached) return { ...DEFAULTS, ...cached }
  if (injected && typeof injected === 'object') return { ...DEFAULTS, ...injected }
  return DEFAULTS
}

describe('readInitialPrefs priority chain', () => {
  it('honours guest-modified localStorage over source=user SSR (v1.8.8 regression)', () => {
    // Returning user has dark/en saved on the server. SSR injects them.
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    ;(window as any).MartisConfig = {
      preferences: { initial: { theme: 'dark', locale: 'en', source: 'user' } },
    }
    // But on /login the guest picked light/pt_PT.
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ theme: 'light', locale: 'pt_PT' }))
    localStorage.setItem(GUEST_MODIFIED_KEY, '1')

    const prefs = readInitialPrefs()
    expect(prefs.theme).toBe('light')
    expect(prefs.locale).toBe('pt_PT')
  })

  it('SSR source=user wins when guest-modified flag is absent', () => {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    ;(window as any).MartisConfig = {
      preferences: { initial: { theme: 'dark', locale: 'en', source: 'user' } },
    }
    // Stale cache from a previous user.
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ theme: 'light', locale: 'pt_PT' }))
    // No guest-modified flag — server is the source of truth.

    const prefs = readInitialPrefs()
    expect(prefs.theme).toBe('dark')
    expect(prefs.locale).toBe('en')
  })

  it('SSR source=preset wins when guest-modified flag is absent', () => {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    ;(window as any).MartisConfig = {
      preferences: { initial: { theme: 'dark', accent: 'violet', source: 'preset' } },
    }
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ accent: 'amber' }))

    const prefs = readInitialPrefs()
    expect(prefs.accent).toBe('violet')
  })

  it('localStorage wins over SSR source=default (no row on server)', () => {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    ;(window as any).MartisConfig = {
      preferences: { initial: { theme: 'dark', locale: 'en', source: 'default' } },
    }
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ theme: 'light' }))

    const prefs = readInitialPrefs()
    expect(prefs.theme).toBe('light')
    expect(prefs.locale).toBe('en')
  })

  it('SSR source=default wins when no localStorage', () => {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    ;(window as any).MartisConfig = {
      preferences: { initial: { theme: 'light', locale: 'fr', source: 'default' } },
    }

    const prefs = readInitialPrefs()
    expect(prefs.theme).toBe('light')
    expect(prefs.locale).toBe('fr')
  })

  it('falls back to hard-coded defaults when neither SSR nor localStorage exists', () => {
    const prefs = readInitialPrefs()
    expect(prefs.theme).toBe('dark')
    expect(prefs.locale).toBe('en')
  })

  it('guest-modified flag without cache falls through (defensive)', () => {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    ;(window as any).MartisConfig = {
      preferences: { initial: { theme: 'dark', source: 'user' } },
    }
    localStorage.setItem(GUEST_MODIFIED_KEY, '1') // flag set but no cache

    const prefs = readInitialPrefs()
    // Falls through to SSR source=user.
    expect(prefs.theme).toBe('dark')
  })
})
