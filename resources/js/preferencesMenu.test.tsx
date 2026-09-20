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
  resolveTheme: (theme: string) => (theme === 'light' ? 'light' : 'dark'),
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
  BUNDLED_LOCALES: ['en', 'pt_PT', 'pt_BR'],
  resolvePickerLocales: (metaLocales?: string[] | null) => {
    if (metaLocales && metaLocales.length > 0) return metaLocales
    const configured = (configMock.value as { preferences?: { locales?: string[] } }).preferences?.locales
    if (configured && configured.length > 0) return configured
    return ['en', 'pt_PT', 'pt_BR']
  },
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

describe('PreferencesMenu — accent swatches follow the stylesheets', () => {
  beforeEach(() => {
    document.querySelectorAll('style').forEach((s) => s.remove())
  })

  it('paints each swatch with the accent the cascade resolves for its key (theme-aware), not the literal', () => {
    const style = document.createElement('style')
    style.textContent = `
      :root { --martis-accent: #0070f0; }                       /* branded theme redefines the default accent */
      html.dark[data-accent="blue"] { --martis-accent: #123456; } /* package rule for a bundled accent */
      html[data-accent="imoray-teal"] { --martis-accent: #00837a; } /* inline custom-accent block */
    `
    document.head.appendChild(style)
    configMock.value = { preferences: { customAccents: [{ name: 'imoray-teal', color: '#14b8a6' }] } }

    render(<PreferencesMenu />)

    const bg = (label: string) => (screen.getByLabelText(label) as HTMLElement).style.backgroundColor
    // jsdom normalises hex to rgb().
    expect(bg('Martis')).toBe('rgb(0, 112, 240)')
    expect(bg('Blue')).toBe('rgb(18, 52, 86)')
    expect(bg('Imoray teal')).toBe('rgb(0, 131, 122)')
    // No rule for teal → the literal fallback stays.
    expect(bg('Teal')).toBe('rgb(20, 184, 166)')
  })
})
