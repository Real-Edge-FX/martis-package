import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, render, waitFor } from '@testing-library/react'
import { useEffect } from 'react'
import { PreferencesProvider, readInitialPrefs, usePreferences, type Preferences } from '@/contexts/PreferencesContext'
import { isPreferenceEditable, onlyEditable } from '@/lib/preferencesEditable'

/*
 * v2.12.0: `martis.preferences.editable` names the preferences users may
 * change. The SPA drops the others from every patch and every PUT, and at
 * boot takes them from the server payload, never from a cached pick.
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

const SERVER_DATA: Preferences = { theme: 'dark', accent: 'martis', density: 'comfortable', locale: 'en', reducedMotion: false, brandColor: null }
const SERVER = {
  data: SERVER_DATA,
  meta: { locales: ['en'], source: 'user', preset: null, presetsAvailable: [], accents: [], themes: [], densities: [], editable: ['theme', 'density', 'reducedMotion'] },
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

function setConfig(editable: string[] | undefined, initial: Partial<Preferences> & { source?: string } | null = null) {
  ;(window as { MartisConfig?: unknown }).MartisConfig = {
    preferences: { enabled: true, allowBrandColor: true, initial, ...(editable ? { editable } : {}) },
  }
}

beforeEach(() => {
  apiPut.mockReset()
  apiGet.mockReset()
  apiPut.mockImplementation((_url: string, body: Record<string, unknown>) =>
    Promise.resolve({ ...SERVER, data: { ...SERVER.data, ...body } }))
  apiGet.mockResolvedValue(SERVER)
  authUser = null
  latest = null
  localStorage.clear()
  sessionStorage.clear()
})

afterEach(() => {
  ;(window as { MartisConfig?: unknown }).MartisConfig = undefined
})

describe('isPreferenceEditable / onlyEditable', () => {
  it('treats every preference as editable when the page config has no list', () => {
    setConfig(undefined)
    expect(isPreferenceEditable('accent')).toBe(true)
    expect(onlyEditable({ accent: 'teal', theme: 'light' })).toEqual({ accent: 'teal', theme: 'light' })
  })

  it('drops the preferences left out of the list and keeps unrelated keys', () => {
    setConfig(['theme', 'density'])
    expect(isPreferenceEditable('accent')).toBe(false)
    expect(onlyEditable({ accent: 'teal', brandColor: null, theme: 'light', other: 1 })).toEqual({ theme: 'light', other: 1 })
  })

  it('keeps brandColor null with an editable accent (clearing a default hex), drops a hex', () => {
    setConfig(['accent'])
    expect(onlyEditable({ accent: 'teal', brandColor: null })).toEqual({ accent: 'teal', brandColor: null })
    expect(onlyEditable({ accent: 'custom', brandColor: '#112233' })).toEqual({ accent: 'custom' })
  })
})

describe('readInitialPrefs with a locked preference', () => {
  it('takes a locked accent and locale from the server payload, not from the cache or a guest pick', () => {
    setConfig(['theme', 'density', 'reducedMotion'], { theme: 'dark', accent: 'martis', locale: 'en', source: 'default' })
    localStorage.setItem('martis-preferences', JSON.stringify({ theme: 'light', accent: 'teal', locale: 'pt_PT' }))
    sessionStorage.setItem('martis-preferences-guest-modified', '1')

    const prefs = readInitialPrefs()
    expect(prefs.theme).toBe('light')
    expect(prefs.accent).toBe('martis')
    expect(prefs.locale).toBe('en')
  })
})

describe('getLocale() with a locked locale', () => {
  it('ignores the cached locale and boots in the server locale', async () => {
    const { getLocale } = await vi.importActual<typeof import('@/lib/i18n')>('@/lib/i18n')
    setConfig(['theme'])
    ;(window as { MartisConfig?: { locale?: string } }).MartisConfig!.locale = 'en'
    localStorage.setItem('martis-preferences', JSON.stringify({ locale: 'pt_PT' }))
    expect(getLocale()).toBe('en')

    setConfig(undefined)
    expect(getLocale()).toBe('pt_PT')
  })
})

describe('update() with a locked preference', () => {
  it('keeps the locked preference out of the browser cache', async () => {
    setConfig(['theme', 'density', 'reducedMotion'], { ...SERVER.data, source: 'default' })
    render(<PreferencesProvider><Probe pick={{ density: 'dense' }} /></PreferencesProvider>)
    await waitFor(() => expect(localStorage.getItem('martis-preferences')).not.toBeNull())

    const cached = JSON.parse(localStorage.getItem('martis-preferences')!)
    expect(cached.density).toBe('dense')
    expect(cached).not.toHaveProperty('accent')
    expect(cached).not.toHaveProperty('locale')
  })

  it('leaves a locked preference out of the post-login promotion PUT', async () => {
    setConfig(['theme', 'density', 'reducedMotion'], { ...SERVER.data, source: 'user' })
    localStorage.setItem('martis-preferences', JSON.stringify({ theme: 'light', accent: 'teal', locale: 'pt_PT' }))
    sessionStorage.setItem('martis-preferences-guest-modified', '1')
    authUser = { id: 1 }
    render(<PreferencesProvider><Probe /></PreferencesProvider>)
    await waitFor(() => expect(apiPut).toHaveBeenCalled())

    const body = apiPut.mock.calls[0]![1] as Record<string, unknown>
    expect(body.theme).toBe('light')
    expect(body).not.toHaveProperty('accent')
    expect(body).not.toHaveProperty('locale')
  })

  it('ignores a patch of a locked accent and leaves it out of the PUT', async () => {
    setConfig(['theme', 'density', 'reducedMotion'], { ...SERVER.data, source: 'user' })
    authUser = { id: 1 }
    render(<PreferencesProvider><Probe /></PreferencesProvider>)
    await waitFor(() => expect(latest!.meta).not.toBeNull())

    await act(async () => { await latest!.update({ accent: 'teal', density: 'dense' }) })

    expect(latest!.prefs.accent).toBe('martis')
    expect(latest!.prefs.density).toBe('dense')
    const body = apiPut.mock.calls[apiPut.mock.calls.length - 1]![1] as Record<string, unknown>
    expect(body).not.toHaveProperty('accent')
    expect(body).not.toHaveProperty('brandColor')
    expect(body).not.toHaveProperty('locale')
    expect(body.density).toBe('dense')
  })

  it('sends the full state when every preference is editable (unchanged behaviour)', async () => {
    setConfig(undefined, { ...SERVER.data, source: 'user' })
    authUser = { id: 1 }
    render(<PreferencesProvider><Probe /></PreferencesProvider>)
    await waitFor(() => expect(latest!.meta).not.toBeNull())

    await act(async () => { await latest!.update({ accent: 'teal' }) })

    const body = apiPut.mock.calls[apiPut.mock.calls.length - 1]![1] as Record<string, unknown>
    expect(body).toMatchObject({ accent: 'teal', theme: 'dark', locale: 'en', brandColor: null })
  })
})
