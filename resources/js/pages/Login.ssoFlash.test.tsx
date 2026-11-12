import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { render, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'

/*
 * v2.21.0: a refused SSO sign-in comes back to the login page with its text in
 * `MartisConfig.flash.sso` (the shell reads the flash of the request). The page
 * shows it as an error toast on arrival, once, also on an SSO-only page.
 */

const { addToast, configMock } = vi.hoisted(() => ({
  addToast: vi.fn(),
  configMock: { value: {} as Record<string, unknown> },
}))

vi.mock('@/contexts/AuthContext', () => ({
  useAuth: () => ({ user: null, isLoading: false, login: vi.fn() }),
  TwoFactorRequiredError: class extends Error {},
  EmailVerificationRequiredError: class extends Error {},
  PasswordChangeRequiredError: class extends Error {},
}))
vi.mock('@/contexts/ToastContext', () => ({ useToast: () => ({ addToast }) }))
vi.mock('@/lib/api', () => ({ api: { post: vi.fn() }, ApiError: class extends Error {} }))
vi.mock('@/lib/config', () => ({
  get config() { return configMock.value },
  BASE_PATH: '/martis',
  API_BASE_URL: 'http://localhost/martis',
}))
vi.mock('@images/logo.png', () => ({ default: '/logo.png' }))

import { LoginPage } from './Login'

const sso = {
  enabled: true,
  providers: { azure: { enabled: true, label: 'Continue with Microsoft', icon: null } },
}

function renderLogin() {
  return render(<MemoryRouter><LoginPage /></MemoryRouter>)
}

beforeEach(() => {
  addToast.mockReset()
  vi.spyOn(window.history, 'replaceState').mockImplementation(() => {})
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe('LoginPage: arriving from a refused SSO sign-in', () => {
  it('toasts the flashed text as an error, once, also when the page re-renders', async () => {
    configMock.value = { auth: { sso }, flash: { sso: 'You are not allowed to sign in.' } }

    const { rerender } = renderLogin()
    rerender(<MemoryRouter><LoginPage /></MemoryRouter>)

    await waitFor(() => expect(addToast).toHaveBeenCalledTimes(1))
    expect(addToast).toHaveBeenCalledWith('error', 'You are not allowed to sign in.')

    await new Promise((resolve) => window.setTimeout(resolve, 10))
    expect(addToast).toHaveBeenCalledTimes(1)
  })

  it('consumes the text, so a page that mounts again shows nothing', async () => {
    configMock.value = { auth: { sso }, flash: { sso: 'Sign-in failed.' } }

    const first = renderLogin()
    await waitFor(() => expect(addToast).toHaveBeenCalledTimes(1))
    first.unmount()

    renderLogin()
    await new Promise((resolve) => window.setTimeout(resolve, 10))

    expect(addToast).toHaveBeenCalledTimes(1)
  })

  it('shows it on an SSO-only page (no password form) too', async () => {
    configMock.value = {
      auth: { sso, passwordLogin: { enabled: false } },
      flash: { sso: 'Sign-in failed.' },
    }

    renderLogin()

    await waitFor(() => expect(addToast).toHaveBeenCalledWith('error', 'Sign-in failed.'))
  })

  it.each([
    ['no flash entry', {}],
    ['a null text', { flash: { sso: null } }],
    ['an empty text', { flash: { sso: '' } }],
    ['a text that is not a string', { flash: { sso: 5 } }],
  ])('shows nothing for %s', async (_label, extra) => {
    configMock.value = { auth: { sso }, ...extra }

    renderLogin()
    await new Promise((resolve) => window.setTimeout(resolve, 10))

    expect(addToast).not.toHaveBeenCalled()
  })
})
