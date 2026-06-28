import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest'
import { render, act } from '@testing-library/react'
import { useEffect } from 'react'
import { PreferencesProvider, usePreferences } from '@/contexts/PreferencesContext'

const loadLocaleSpy = vi.fn()

vi.mock('@/lib/i18n', () => ({
  default: { language: 'pt_BR' },
  loadLocale: (locale: string) => loadLocaleSpy(locale),
}))

vi.mock('@/lib/api', () => ({
  api: {
    delete: vi.fn(async () => ({
      data: { theme: 'dark', accent: 'martis', density: 'comfortable', locale: 'en', reducedMotion: false, brandColor: null },
      meta: { locales: ['en', 'pt_PT', 'pt_BR'], source: 'default', preset: null, presetsAvailable: [], accents: [], themes: [], densities: [] },
    })),
    put: vi.fn(),
    get: vi.fn(async () => null),
    post: vi.fn(async () => null),
  },
  ApiError: class extends Error {},
}))

// PreferencesProvider internally calls useAuth(), which requires AuthProvider.
// We stub the entire AuthContext module so the provider mounts without a real
// auth round-trip (which would hit the unmocked /api/auth/user endpoint).
vi.mock('@/contexts/AuthContext', () => ({
  useAuth: () => ({ user: { id: 1 }, isLoading: false, login: vi.fn(), logout: vi.fn(), updateUser: vi.fn() }),
  AuthProvider: ({ children }: { children: React.ReactNode }) => children,
}))

beforeEach(() => {
  loadLocaleSpy.mockClear()
  // Wire the config so `config.preferences.enabled` resolves to true.
  // The PreferencesContext reads: `config.preferences?.enabled !== false`
  // where `config = window.MartisConfig ?? {}`.
  ;(window as { MartisConfig?: unknown }).MartisConfig = {
    preferences: { enabled: true, allowBrandColor: false, initial: null },
  }
})

afterEach(() => {
  delete (window as { MartisConfig?: unknown }).MartisConfig
})

function Caller() {
  const { reset } = usePreferences()
  useEffect(() => { void reset() }, [reset])
  return null
}

describe('PreferencesContext.reset()', () => {
  it('calls loadLocale(restoredLocale) when the reset response carries a locale different from the current i18n.language', async () => {
    await act(async () => {
      render(
        <PreferencesProvider>
          <Caller />
        </PreferencesProvider>,
      )
    })

    expect(loadLocaleSpy).toHaveBeenCalledWith('en')
  })
})
