import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'

/*
 * v2.21.0: with `martis.auth.password_login.enabled` false the login page
 * renders the SSO buttons only (no divider, no form, no forgot link, the
 * SSO-only subtitle). `?password_login=1` opens the form again: the
 * break-glass entry; which address may use it is the server's call.
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

const original = window.location

function arriveWith(search: string) {
  Object.defineProperty(window, 'location', {
    configurable: true,
    value: { href: `http://localhost/martis/login${search}`, pathname: '/martis/login', origin: 'http://localhost', search },
  })
}

const sso = {
  enabled: true,
  providers: { azure: { enabled: true, label: 'Continue with Microsoft', icon: null } },
}

function renderLogin() {
  return render(<MemoryRouter><LoginPage /></MemoryRouter>)
}

beforeEach(() => {
  arriveWith('')
})

afterEach(() => {
  Object.defineProperty(window, 'location', { configurable: true, value: original })
})

describe('LoginPage: password sign-in switch', () => {
  it('by default renders the SSO buttons, the divider, the form and the forgot link', () => {
    configMock.value = { auth: { sso, passwordReset: { enabled: true, url: null }, passwordLogin: { enabled: true } } }

    const { container } = renderLogin()

    expect(screen.getByText('Continue with Microsoft')).toBeTruthy()
    expect(container.querySelector('.martis-auth-divider')).not.toBeNull()
    expect(container.querySelector('form')).not.toBeNull()
    expect(screen.getByLabelText('Email')).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Sign in' })).toBeTruthy()
    expect(screen.getByText('Forgot?')).toBeTruthy()
    expect(screen.getByText('Welcome back. Continue with SSO or use your email.')).toBeTruthy()
  })

  it('treats a config without the key as on', () => {
    configMock.value = { auth: {} }

    const { container } = renderLogin()

    expect(container.querySelector('form')).not.toBeNull()
  })

  it('when off, renders the SSO buttons only: no divider, no form, no forgot link, the SSO-only subtitle', () => {
    // The server publishes passwordReset off with the switch.
    configMock.value = { auth: { sso, passwordReset: { enabled: false, url: null }, passwordLogin: { enabled: false } } }

    const { container } = renderLogin()

    expect(screen.getByText('Continue with Microsoft')).toBeTruthy()
    expect(container.querySelector('.martis-auth-divider')).toBeNull()
    expect(container.querySelector('form')).toBeNull()
    expect(container.querySelector('input')).toBeNull()
    expect(screen.queryByText('Forgot?')).toBeNull()
    expect(screen.getByText('Welcome back. Continue with your organisation sign-in.')).toBeTruthy()
  })

  it('when off, a copy override of the SSO-only subtitle wins', () => {
    configMock.value = {
      auth: { sso, passwordLogin: { enabled: false }, copy: { login: { subtitle_sso_only: 'Use your company account.' } } },
    }

    renderLogin()

    expect(screen.getByText('Use your company account.')).toBeTruthy()
  })

  it('when off, still shows the form on ?password_login=1', () => {
    arriveWith('?password_login=1')
    configMock.value = { auth: { sso, passwordReset: { enabled: false, url: null }, passwordLogin: { enabled: false } } }

    const { container } = renderLogin()

    expect(container.querySelector('form')).not.toBeNull()
    expect(screen.getByLabelText('Email')).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Sign in' })).toBeTruthy()
    expect(container.querySelector('.martis-auth-divider')).not.toBeNull()
    expect(screen.queryByText('Forgot?')).toBeNull()
  })

  it('when off, ignores any other value of the password_login parameter', () => {
    arriveWith('?password_login=0')
    configMock.value = { auth: { sso, passwordLogin: { enabled: false } } }

    const { container } = renderLogin()

    expect(container.querySelector('form')).toBeNull()
  })

  it('when off, keeps the magic-link request reachable with the email field only', () => {
    configMock.value = { auth: { sso, passwordLogin: { enabled: false }, magicLink: { enabled: true } } }

    const { container } = renderLogin()

    expect(screen.getByLabelText('Email')).toBeTruthy()
    expect(container.querySelector('input[type="password"]')).toBeNull()
    expect(screen.getByRole('button', { name: 'Email me a sign-in link' })).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'Sign in' })).toBeNull()
  })
})
