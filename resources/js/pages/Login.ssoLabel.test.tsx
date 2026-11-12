import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import i18n from 'i18next'

/*
 * v2.22.0: the label of an SSO provider button is a string or a map of
 * locale => string, resolved like `auth.copy` (active language, then its
 * prefix, then en, then the first entry). Unset, it falls back to
 * "Continue with <name>".
 */

const { configMock } = vi.hoisted(() => ({ configMock: { value: {} as Record<string, unknown> } }))

vi.mock('@/contexts/AuthContext', () => ({
  useAuth: () => ({ user: null, isLoading: false, login: vi.fn() }),
  TwoFactorRequiredError: class extends Error {},
  EmailVerificationRequiredError: class extends Error {},
  PasswordChangeRequiredError: class extends Error {},
}))
vi.mock('@/contexts/ToastContext', () => ({ useToast: () => ({ addToast: vi.fn() }) }))
vi.mock('@/lib/api', () => ({ api: { post: vi.fn() }, ApiError: class extends Error {} }))
vi.mock('@/lib/config', () => ({
  get config() { return configMock.value },
  BASE_PATH: '/martis',
  API_BASE_URL: 'http://localhost/martis',
}))
vi.mock('@images/logo.png', () => ({ default: '/logo.png' }))

import { LoginPage } from './Login'

function withLabel(label: unknown) {
  configMock.value = {
    auth: { sso: { enabled: true, providers: { panel_a: { enabled: true, label, icon: null } } } },
  }
}

function renderLogin() {
  return render(<MemoryRouter><LoginPage /></MemoryRouter>)
}

beforeEach(async () => {
  await i18n.changeLanguage('en')
})

afterEach(async () => {
  await i18n.changeLanguage('en')
})

describe('LoginPage: provider label', () => {
  it('shows a string label as it is', () => {
    withLabel('Continue with Panel A')

    renderLogin()

    expect(screen.getByRole('button', { name: 'Continue with Panel A' })).toBeTruthy()
  })

  it('shows the entry of the active language of a map', async () => {
    withLabel({ en: 'Continue with Panel A', pt_PT: 'Continuar com o Painel A' })
    await i18n.changeLanguage('pt_PT')

    renderLogin()

    expect(screen.getByRole('button', { name: 'Continuar com o Painel A' })).toBeTruthy()
  })

  it('follows a language change of the page', async () => {
    withLabel({ en: 'Continue with Panel A', pt_PT: 'Continuar com o Painel A' })

    renderLogin()
    expect(screen.getByRole('button', { name: 'Continue with Panel A' })).toBeTruthy()

    await act(async () => { await i18n.changeLanguage('pt_PT') })

    expect(screen.getByRole('button', { name: 'Continuar com o Painel A' })).toBeTruthy()
  })

  it('falls back from a regional language to its prefix, then to en, then to the first entry', async () => {
    withLabel({ pt: 'Continuar', en: 'Continue' })
    await i18n.changeLanguage('pt_BR')
    const first = renderLogin()
    expect(screen.getByRole('button', { name: 'Continuar' })).toBeTruthy()
    first.unmount()

    await i18n.changeLanguage('fr')
    const second = renderLogin()
    expect(screen.getByRole('button', { name: 'Continue' })).toBeTruthy()
    second.unmount()

    withLabel({ de: 'Weiter' })
    renderLogin()
    expect(screen.getByRole('button', { name: 'Weiter' })).toBeTruthy()
  })

  it.each([
    ['null', null],
    ['an empty string', ''],
    ['a map with nothing usable', { pt_PT: '' }],
    ['a missing label', undefined],
  ])('keeps the "Continue with" fallback for %s', (_name, label) => {
    withLabel(label)

    renderLogin()

    expect(screen.getByRole('button', { name: 'Continue with panel_a' })).toBeTruthy()
  })
})
