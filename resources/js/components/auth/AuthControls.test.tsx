import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'

/*
 * v2.12.0: the sign-in strip hides the theme cycle and the language picker
 * when the preference is left out of `martis.preferences.editable`.
 */

const { configMock } = vi.hoisted(() => ({ configMock: { value: {} as Record<string, unknown> } }))
vi.mock('@/lib/config', () => ({
  get config() { return configMock.value },
  resolvePickerLocales: () => ['en', 'pt_PT'],
}))
vi.mock('@/lib/i18n', () => ({ loadLocale: vi.fn() }))
vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string, opts?: { defaultValue?: string }) => opts?.defaultValue ?? key }),
}))
vi.mock('@/contexts/PreferencesContext', () => ({
  usePreferencesOptional: () => ({
    enabled: true,
    prefs: { theme: 'dark', accent: 'martis', density: 'comfortable', locale: 'en', reducedMotion: false, brandColor: null },
    update: vi.fn(),
    meta: null,
  }),
}))

import { AuthControls } from '@/components/auth/AuthControls'

describe('AuthControls — preferences.editable', () => {
  beforeEach(() => {
    ;(window as { MartisConfig?: unknown }).MartisConfig = undefined
  })

  it('shows the theme cycle and the language picker by default', () => {
    configMock.value = {}
    render(<AuthControls />)
    expect(screen.queryByLabelText('Theme: Dark')).toBeTruthy()
    expect(screen.queryByLabelText('Language')).toBeTruthy()
  })

  it('hides the language picker when locale is not editable', () => {
    configMock.value = { preferences: { editable: ['theme'] } }
    render(<AuthControls />)
    expect(screen.queryByLabelText('Theme: Dark')).toBeTruthy()
    expect(screen.queryByLabelText('Language')).toBeNull()
  })

  it('renders nothing when neither theme nor locale is editable', () => {
    configMock.value = { preferences: { editable: ['density'] } }
    const { container } = render(<AuthControls />)
    expect(container.innerHTML).toBe('')
  })
})
