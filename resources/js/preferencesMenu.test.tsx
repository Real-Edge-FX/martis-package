import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'

// -----------------------------------------------------------------------------
// PreferencesMenu — theme.allowToggle gate
//
// Asserts that `config.theme.allowToggle = false` hides the entire Theme
// section from the preferences overlay (host app forces a single theme).
// -----------------------------------------------------------------------------

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, fallback?: string) => fallback ?? key,
  }),
}))

const updatePref = vi.fn()
vi.mock('@/contexts/PreferencesContext', () => ({
  usePreferences: () => ({
    enabled: true,
    prefs: {
      theme: 'dark',
      accent: 'martis',
      density: 'comfortable',
      locale: 'en',
      reducedMotion: false,
      brandColor: null,
    },
    update: updatePref,
    meta: { locales: ['en'], allowBrandColor: false },
  }),
}))

const configMock = vi.hoisted(() => ({ value: {} as Record<string, unknown> }))
vi.mock('@/lib/config', () => ({
  get config() { return configMock.value },
}))

vi.mock('@/lib/i18n', () => ({
  loadLocale: vi.fn(() => Promise.resolve()),
}))

// PrimeReact OverlayPanel renders inside a portal — short-circuit it so the
// content is in the document tree from the first render.
vi.mock('primereact/overlaypanel', () => ({
  OverlayPanel: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}))

import { PreferencesMenu } from '@/components/PreferencesMenu'

describe('PreferencesMenu — theme.allowToggle', () => {
  beforeEach(() => {
    updatePref.mockReset()
  })

  it('renders the Theme section by default (allowToggle defaults to true)', () => {
    configMock.value = { theme: { allowToggle: true } }
    render(<PreferencesMenu />)
    expect(screen.queryByText('Theme')).toBeTruthy()
  })

  it('renders the Theme section when allowToggle is omitted (backwards compat)', () => {
    configMock.value = { theme: { default: 'dark' } }
    render(<PreferencesMenu />)
    expect(screen.queryByText('Theme')).toBeTruthy()
  })

  it('hides the Theme section when theme.allowToggle is explicitly false', () => {
    configMock.value = { theme: { allowToggle: false } }
    render(<PreferencesMenu />)
    expect(screen.queryByText('Theme')).toBeNull()
    // Sibling sections still render — only the theme picker is gone.
    expect(screen.queryByText('Accent')).toBeTruthy()
    expect(screen.queryByText('Density')).toBeTruthy()
  })
})
