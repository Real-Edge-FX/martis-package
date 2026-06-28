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

beforeEach(async () => {
  loadLocaleSpy.mockClear()
  // Wire the config so `config.preferences.enabled` resolves to true.
  // The PreferencesContext reads: `config.preferences?.enabled !== false`
  // where `config = window.MartisConfig ?? {}`.
  ;(window as { MartisConfig?: unknown }).MartisConfig = {
    preferences: { enabled: true, allowBrandColor: false, initial: null },
  }
  // Restore i18n mock language to the initial value so cases that mutate it
  // (same-locale negative, catch branch) don't bleed into each other.
  const { default: i18nMock } = await import('@/lib/i18n')
  ;(i18nMock as { language: string }).language = 'pt_BR'
})

afterEach(() => {
  delete (window as { MartisConfig?: unknown }).MartisConfig
})

function Caller() {
  const { reset } = usePreferences()
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { void reset() }, [])
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

  it('does NOT call loadLocale when the restored locale matches the current i18n.language', async () => {
    // Override the i18n mock so `language` matches the locale the API
    // is going to restore. Without this guard the spy would fire on
    // every reset — wasteful and would invalidate downstream caches
    // unnecessarily.
    const { default: i18nMock } = await import('@/lib/i18n')
    ;(i18nMock as { language: string }).language = 'en'

    await act(async () => {
      render(
        <PreferencesProvider>
          <Caller />
        </PreferencesProvider>,
      )
    })

    expect(loadLocaleSpy).not.toHaveBeenCalled()
  })

  it('falls back to DEFAULTS.locale and calls loadLocale when the API call rejects', async () => {
    // Force the DELETE to throw. The reset callback's catch branch
    // is supposed to call setPrefs(DEFAULTS) and loadLocale(DEFAULTS.locale)
    // — without that, a server-failure path leaves i18next stuck on
    // the prior language.
    const { api } = await import('@/lib/api')
    ;(api.delete as ReturnType<typeof vi.fn>).mockRejectedValueOnce(new Error('500'))

    const { default: i18nMock } = await import('@/lib/i18n')
    ;(i18nMock as { language: string }).language = 'pt_BR'

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
