/**
 * @vitest-environment jsdom
 */
import { describe, expect, it, beforeEach, afterEach } from 'vitest'

import { readInitialPrefs } from '@/contexts/PreferencesContext'

const STORAGE_KEY = 'martis-preferences'
const GUEST_MODIFIED_KEY = 'martis-preferences-guest-modified'

beforeEach(() => {
  localStorage.clear()
  sessionStorage.clear()
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  ;(window as any).MartisConfig = undefined
  delete document.documentElement.dataset.theme
  document.documentElement.classList.remove('dark')
})

afterEach(() => {
  localStorage.clear()
  sessionStorage.clear()
})

/*
 * The priority chain of `readInitialPrefs()`. The spec locks in the v1.8.8
 * fix: the guest-pick marker makes the cached preferences win over a
 * `source=user` SSR payload, because the guest just picked something and the
 * post-login PUT will sync it. Since v2.4.0 (F095) the marker is a
 * sessionStorage value (this tab session), not a localStorage one: a flag
 * left by a previous visitor of a shared browser is not honoured.
 */

describe('readInitialPrefs priority chain', () => {
  it('honours the guest-pick marker over source=user SSR (v1.8.8 regression)', () => {
    // Returning user has dark/en saved on the server. SSR injects them.
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    ;(window as any).MartisConfig = {
      preferences: { initial: { theme: 'dark', locale: 'en', source: 'user' } },
    }
    // But on /login the guest picked light/pt_PT.
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ theme: 'light', locale: 'pt_PT' }))
    sessionStorage.setItem(GUEST_MODIFIED_KEY, '1')

    const prefs = readInitialPrefs()
    expect(prefs.theme).toBe('light')
    expect(prefs.locale).toBe('pt_PT')
  })

  it('ignores a guest-modified flag left in localStorage (a previous visitor of a shared browser, F095)', () => {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    ;(window as any).MartisConfig = {
      preferences: { initial: { theme: 'dark', locale: 'en', source: 'user' } },
    }
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ theme: 'light', locale: 'pt_PT' }))
    localStorage.setItem(GUEST_MODIFIED_KEY, '1')

    const prefs = readInitialPrefs()
    expect(prefs.theme).toBe('dark')
    expect(prefs.locale).toBe('en')
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
    sessionStorage.setItem(GUEST_MODIFIED_KEY, '1') // flag set but no cache

    const prefs = readInitialPrefs()
    // Falls through to SSR source=user.
    expect(prefs.theme).toBe('dark')
  })
})
