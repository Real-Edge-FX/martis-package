import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, render, waitFor } from '@testing-library/react'
import { useEffect } from 'react'
import { PreferencesProvider, readInitialPrefs, usePreferences, type Preferences } from '@/contexts/PreferencesContext'

/*
 * F095: a guest's picks (theme, language, accent) are carried to the account
 * that signs in next with one PUT. On a shared browser the flag that said
 * "a guest picked something" was a localStorage value bound to no one, so the
 * next person to sign in, days later, in another tab, had a previous visitor's
 * picks written over their own saved preferences. The flag is a tab-session
 * marker now: only the sign-in made in the tab where the picks were made (the
 * same person toggling the theme on the login screen, then signing in)
 * promotes them.
 */

const apiPut = vi.fn()
const apiGet = vi.fn()
let authUser: { id: number } | null = null

vi.mock('@/lib/i18n', () => ({
  default: { language: 'en' },
  loadLocale: vi.fn(),
}))

vi.mock('@/lib/api', () => ({
  api: {
    put: (...args: unknown[]) => apiPut(...args),
    get: (...args: unknown[]) => apiGet(...args),
    delete: vi.fn(),
    post: vi.fn(),
  },
  ApiError: class extends Error {},
}))

vi.mock('@/contexts/AuthContext', () => ({
  useAuth: () => ({ user: authUser, isLoading: false, login: vi.fn(), logout: vi.fn(), updateUser: vi.fn() }),
  AuthProvider: ({ children }: { children: React.ReactNode }) => children,
}))

const CACHE = 'martis-preferences'
const FLAG = 'martis-preferences-guest-modified'

const SERVER_PREFS = {
  data: { theme: 'dark', accent: 'martis', density: 'comfortable', locale: 'en', reducedMotion: false, brandColor: null },
  meta: { locales: ['en'], source: 'user', preset: null, presetsAvailable: [], accents: [], themes: [], densities: [] },
}

let latest: ReturnType<typeof usePreferences> | null = null

function Probe({ pick }: { pick?: Partial<Preferences> }) {
  const ctx = usePreferences()
  latest = ctx
  useEffect(() => {
    if (pick) void ctx.update(pick)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])
  return null
}

function mount(pick?: Partial<Preferences>) {
  return render(
    <PreferencesProvider>
      <Probe pick={pick} />
    </PreferencesProvider>,
  )
}

beforeEach(() => {
  apiPut.mockReset()
  apiGet.mockReset()
  apiPut.mockResolvedValue({ ...SERVER_PREFS, data: { ...SERVER_PREFS.data, theme: 'light' } })
  apiGet.mockResolvedValue(SERVER_PREFS)
  authUser = null
  latest = null
  localStorage.clear()
  sessionStorage.clear()
  ;(window as { MartisConfig?: unknown }).MartisConfig = { preferences: { enabled: true, allowBrandColor: false, initial: null } }
})

afterEach(() => {
  delete (window as { MartisConfig?: unknown }).MartisConfig
})

describe('a guest pick reaches the account of the person who made it', () => {
  it('carries a pick made on the login screen to the account that signs in in the same tab', async () => {
    const view = mount({ theme: 'light' })
    await waitFor(() => expect(sessionStorage.getItem(FLAG)).toBe('1'))
    expect(apiPut).not.toHaveBeenCalled()

    authUser = { id: 1 }
    view.rerender(
      <PreferencesProvider>
        <Probe />
      </PreferencesProvider>,
    )

    await waitFor(() => expect(apiPut).toHaveBeenCalledTimes(1))
    expect(apiPut.mock.calls[0]![0]).toBe('/api/preferences')
    expect((apiPut.mock.calls[0]![1] as Preferences).theme).toBe('light')
    expect(apiGet).not.toHaveBeenCalled()
    await waitFor(() => expect(sessionStorage.getItem(FLAG)).toBeNull())
  })

  it('keeps the pick across the full page load a redirect brings (same tab session)', async () => {
    localStorage.setItem(CACHE, JSON.stringify({ theme: 'light' }))
    sessionStorage.setItem(FLAG, '1')

    expect(readInitialPrefs().theme).toBe('light')

    authUser = { id: 1 }
    mount()

    await waitFor(() => expect(apiPut).toHaveBeenCalledTimes(1))
    expect((apiPut.mock.calls[0]![1] as Preferences).theme).toBe('light')
  })

  it('drops the marker when the promotion fails, so it does not fire again', async () => {
    apiPut.mockRejectedValue(new Error('500'))
    sessionStorage.setItem(FLAG, '1')
    localStorage.setItem(CACHE, JSON.stringify({ theme: 'light' }))
    authUser = { id: 1 }

    mount()

    await waitFor(() => expect(apiPut).toHaveBeenCalledTimes(1))
    await waitFor(() => expect(sessionStorage.getItem(FLAG)).toBeNull())
  })

  it('does not mark a pick made while signed in', async () => {
    authUser = { id: 1 }
    mount()
    await waitFor(() => expect(apiGet).toHaveBeenCalledTimes(1))

    await act(async () => { await latest!.update({ theme: 'light' }) })

    expect(sessionStorage.getItem(FLAG)).toBeNull()
  })
})

describe("a previous visitor's picks never reach the next account", () => {
  it('reads the account preferences instead of promoting a flag a previous visitor left in localStorage', async () => {
    // What an earlier version, or this one on a shared browser before the
    // upgrade, left behind: the flag and the cache of whoever picked last.
    localStorage.setItem(FLAG, '1')
    localStorage.setItem(CACHE, JSON.stringify({ theme: 'light', accent: 'violet' }))
    authUser = { id: 2 }

    mount()

    await waitFor(() => expect(apiGet).toHaveBeenCalledTimes(1))
    expect(apiPut).not.toHaveBeenCalled()
    expect(localStorage.getItem(FLAG)).toBeNull()
    await waitFor(() => expect(latest!.prefs.theme).toBe('dark'))
  })

  it('does not honour the legacy flag when reading the initial preferences', () => {
    localStorage.setItem(FLAG, '1')
    localStorage.setItem(CACHE, JSON.stringify({ theme: 'light' }))
    ;(window as { MartisConfig?: unknown }).MartisConfig = {
      preferences: { enabled: true, initial: { source: 'user', theme: 'dark' } },
    }

    // The server row wins: nothing in this tab session says the guest picked.
    expect(readInitialPrefs().theme).toBe('dark')
  })

  it('does not promote picks cached without a marker (the cache of a signed-out user)', async () => {
    localStorage.setItem(CACHE, JSON.stringify({ theme: 'light' }))
    authUser = { id: 3 }

    mount()

    await waitFor(() => expect(apiGet).toHaveBeenCalledTimes(1))
    expect(apiPut).not.toHaveBeenCalled()
  })
})
