import { beforeEach, describe, expect, it } from 'vitest'
import {
  GUEST_PICK_KEY,
  PREFERENCES_CACHE_KEY,
  clearGuestPick,
  discardGuestPreferences,
  hasGuestPick,
  markGuestPick,
} from './preferencesStorage'

// F095: a guest's picks (theme, language, accent) are carried to the account
// that signs in next only when they were made in the tab session of the person
// signing in. The marker lives in sessionStorage; the flag the earlier versions
// wrote to localStorage binds to no one and is never honoured.

beforeEach(() => {
  sessionStorage.clear()
  localStorage.clear()
})

describe('the guest-pick marker', () => {
  it('is set and read in the tab session', () => {
    expect(hasGuestPick()).toBe(false)

    markGuestPick()

    expect(hasGuestPick()).toBe(true)
    expect(sessionStorage.getItem(GUEST_PICK_KEY)).toBe('1')
    expect(localStorage.getItem(GUEST_PICK_KEY)).toBeNull()
  })

  it('is not set by a flag a previous visitor left in localStorage, which is dropped', () => {
    localStorage.setItem(GUEST_PICK_KEY, '1')

    expect(hasGuestPick()).toBe(false)
    expect(localStorage.getItem(GUEST_PICK_KEY)).toBeNull()
  })

  it('is cleared once the picks were carried over or discarded', () => {
    markGuestPick()

    clearGuestPick()

    expect(hasGuestPick()).toBe(false)
  })

  it('survives sessionStorage being blocked', () => {
    const original = Object.getOwnPropertyDescriptor(window, 'sessionStorage')!
    Object.defineProperty(window, 'sessionStorage', {
      configurable: true,
      get: () => { throw new DOMException('blocked', 'SecurityError') },
    })
    try {
      expect(() => markGuestPick()).not.toThrow()
      expect(hasGuestPick()).toBe(false)
      expect(() => clearGuestPick()).not.toThrow()
    } finally {
      Object.defineProperty(window, 'sessionStorage', original)
    }
  })
})

describe('discardGuestPreferences', () => {
  it('drops the cached preferences, the marker and the legacy flag', () => {
    localStorage.setItem(PREFERENCES_CACHE_KEY, JSON.stringify({ theme: 'light' }))
    localStorage.setItem(GUEST_PICK_KEY, '1')
    markGuestPick()

    discardGuestPreferences()

    expect(localStorage.getItem(PREFERENCES_CACHE_KEY)).toBeNull()
    expect(localStorage.getItem(GUEST_PICK_KEY)).toBeNull()
    expect(hasGuestPick()).toBe(false)
  })

  it('leaves what is not the SPA\'s alone', () => {
    localStorage.setItem('unrelated', 'kept')

    discardGuestPreferences()

    expect(localStorage.getItem('unrelated')).toBe('kept')
  })
})
