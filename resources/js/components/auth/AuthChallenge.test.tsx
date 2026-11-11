import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'

/*
 * v2.21.0: the challenge (captcha) hook of the public forms. Each listed
 * form renders the `auth:challenge` component, sends its token as
 * `challenge_token`, and renews the widget (resetSignal) after a failed
 * submit. Enabled with nothing registered, the form shows a configuration
 * error and does not submit.
 */

const { configMock, postMock, loginMock, MockApiError } = vi.hoisted(() => {
  class MockApiError extends Error {
    constructor(
      public status: number,
      message: string,
      public errors?: Array<{ field: string; message: string; code: string }>,
    ) {
      super(message)
      this.name = 'ApiError'
    }
    errorsByField(): Record<string, string> {
      const result: Record<string, string> = {}
      for (const err of this.errors ?? []) {
        if (err.field && !result[err.field]) result[err.field] = err.message
      }
      return result
    }
  }

  return {
    configMock: { value: {} as Record<string, unknown> },
    postMock: vi.fn(),
    loginMock: vi.fn(),
    MockApiError,
  }
})

vi.mock('@/contexts/AuthContext', () => ({
  useAuth: () => ({ user: null, isLoading: false, login: loginMock }),
  TwoFactorRequiredError: class extends Error {},
  EmailVerificationRequiredError: class extends Error {},
  PasswordChangeRequiredError: class extends Error {},
}))
vi.mock('@/contexts/ToastContext', () => ({ useToast: () => ({ addToast: vi.fn() }) }))
vi.mock('@/lib/api', () => ({ api: { post: (...args: unknown[]) => postMock(...args) }, ApiError: MockApiError }))
vi.mock('@/lib/config', () => ({
  get config() { return configMock.value },
  BASE_PATH: '/martis',
  API_BASE_URL: 'http://localhost/martis',
}))
vi.mock('@images/logo.png', () => ({ default: '/logo.png' }))

import { componentRegistry } from '@/lib/componentRegistry'
import { CHALLENGE_REGISTRY_KEY, type AuthChallengeProps } from '@/components/auth/AuthChallenge'
import { LoginPage } from '@/pages/Login'
import { ForgotPasswordPage } from '@/pages/ForgotPassword'

const seen: AuthChallengeProps[] = []

/** A widget that solves on click, and renders the resetSignal it was given. */
function TestWidget(props: AuthChallengeProps) {
  seen.push(props)
  return (
    <div>
      <span data-testid="reset-signal">{props.resetSignal}</span>
      <span data-testid="site-key">{props.siteKey}</span>
      <span data-testid="surface">{props.surface}</span>
      <button type="button" onClick={() => props.onToken(`token-${props.resetSignal}`)}>solve</button>
    </div>
  )
}

function challenge(appliesTo: string[], extra: Record<string, unknown> = {}) {
  return { enabled: true, siteKey: 'site-123', appliesTo, ...extra }
}

beforeEach(() => {
  seen.length = 0
  postMock.mockReset()
  loginMock.mockReset()
  componentRegistry.register(CHALLENGE_REGISTRY_KEY, TestWidget)
})

afterEach(() => {
  componentRegistry.unregister(CHALLENGE_REGISTRY_KEY)
})

function submitLogin() {
  fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'person@example.com' } })
  fireEvent.change(document.querySelector('input[name="password"]') as HTMLInputElement, { target: { value: 'secret' } })
  fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))
}

describe('login page', () => {
  it('renders the widget with the site key and surface, and sends its token with the sign-in', async () => {
    configMock.value = { auth: { challenge: challenge(['login']) } }
    loginMock.mockResolvedValue(undefined)

    render(<MemoryRouter><LoginPage /></MemoryRouter>)

    expect(screen.getByTestId('site-key').textContent).toBe('site-123')
    expect(screen.getByTestId('surface').textContent).toBe('login')

    fireEvent.click(screen.getByText('solve'))
    submitLogin()

    await waitFor(() => expect(loginMock).toHaveBeenCalledTimes(1))
    expect(loginMock).toHaveBeenCalledWith('person@example.com', 'secret', true, { challenge_token: 'token-0' })
  })

  it('bumps resetSignal and drops the token after a failed submit, and shows the server message', async () => {
    configMock.value = { auth: { challenge: challenge(['login']) } }
    loginMock.mockRejectedValue(new MockApiError(422, 'The security check failed.', [
      { field: 'challenge_token', message: 'The security check failed.', code: 'x' },
    ]))

    render(<MemoryRouter><LoginPage /></MemoryRouter>)
    fireEvent.click(screen.getByText('solve'))
    submitLogin()

    await waitFor(() => expect(screen.getByTestId('reset-signal').textContent).toBe('1'))
    expect(screen.getByText('The security check failed.')).toBeTruthy()

    // Nothing is sent until the renewed widget hands a new token over.
    loginMock.mockClear()
    loginMock.mockResolvedValue(undefined)
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))
    await waitFor(() => expect(loginMock).toHaveBeenCalledTimes(1))
    expect(loginMock.mock.calls[0][3]).toEqual({})

    fireEvent.click(screen.getByText('solve'))
    loginMock.mockClear()
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))
    await waitFor(() => expect(loginMock).toHaveBeenCalledTimes(1))
    expect(loginMock.mock.calls[0][3]).toEqual({ challenge_token: 'token-1' })
  })

  it('renders no widget and sends no token when the login surface is not listed', async () => {
    configMock.value = { auth: { challenge: challenge(['forgot']) } }
    loginMock.mockResolvedValue(undefined)

    render(<MemoryRouter><LoginPage /></MemoryRouter>)
    expect(screen.queryByText('solve')).toBeNull()

    submitLogin()
    await waitFor(() => expect(loginMock).toHaveBeenCalledTimes(1))
    expect(loginMock).toHaveBeenCalledWith('person@example.com', 'secret', true, {})
  })

  it('renders no widget while the challenge is disabled', () => {
    configMock.value = { auth: { challenge: challenge(['login'], { enabled: false }) } }

    render(<MemoryRouter><LoginPage /></MemoryRouter>)

    expect(screen.queryByText('solve')).toBeNull()
  })

  it('shows a visible configuration error and does not submit when no component is registered', async () => {
    componentRegistry.unregister(CHALLENGE_REGISTRY_KEY)
    configMock.value = { auth: { challenge: challenge(['login']) } }

    render(<MemoryRouter><LoginPage /></MemoryRouter>)

    expect(screen.getByTestId('auth-challenge-misconfigured').textContent).toContain('auth:challenge')

    submitLogin()
    await new Promise((resolve) => window.setTimeout(resolve, 20))

    expect(loginMock).not.toHaveBeenCalled()
  })

  it('sends the token with the magic-link request, and not with a sign-in when only magic_link is listed', async () => {
    configMock.value = { auth: { challenge: challenge(['magic_link']), magicLink: { enabled: true } } }
    postMock.mockResolvedValue({})
    loginMock.mockResolvedValue(undefined)

    render(<MemoryRouter><LoginPage /></MemoryRouter>)
    expect(screen.getByTestId('surface').textContent).toBe('magic_link')

    fireEvent.click(screen.getByText('solve'))
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'person@example.com' } })
    fireEvent.click(screen.getByRole('button', { name: 'Email me a sign-in link' }))

    await waitFor(() => expect(postMock).toHaveBeenCalledTimes(1))
    expect(postMock).toHaveBeenCalledWith('/api/auth/magic-link/request', {
      email: 'person@example.com',
      challenge_token: 'token-0',
    })
  })
})

describe('forgot-password page', () => {
  function renderForgot() {
    return render(<MemoryRouter><ForgotPasswordPage /></MemoryRouter>)
  }

  it('sends the token with the request, and renews the widget after a failure', async () => {
    configMock.value = {
      auth: { passwordReset: { enabled: true, url: null }, challenge: challenge(['forgot']) },
    }
    postMock.mockRejectedValueOnce(new MockApiError(422, 'The security check failed.', [
      { field: 'challenge_token', message: 'The security check failed.', code: 'x' },
    ]))

    renderForgot()
    expect(screen.getByTestId('surface').textContent).toBe('forgot')

    fireEvent.click(screen.getByText('solve'))
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'person@example.com' } })
    fireEvent.click(screen.getByRole('button', { name: /Send reset link/ }))

    await waitFor(() => expect(postMock).toHaveBeenCalledTimes(1))
    expect(postMock).toHaveBeenCalledWith('/api/auth/password/email', {
      email: 'person@example.com',
      challenge_token: 'token-0',
    })
    await waitFor(() => expect(screen.getByTestId('reset-signal').textContent).toBe('1'))
    expect(screen.getByText('The security check failed.')).toBeTruthy()
  })

  it('does not post, and says why, when no component is registered', async () => {
    componentRegistry.unregister(CHALLENGE_REGISTRY_KEY)
    configMock.value = {
      auth: { passwordReset: { enabled: true, url: null }, challenge: challenge(['forgot']) },
    }

    renderForgot()
    expect(screen.getByTestId('auth-challenge-misconfigured')).toBeTruthy()

    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'person@example.com' } })
    fireEvent.click(screen.getByRole('button', { name: /Send reset link/ }))
    await new Promise((resolve) => window.setTimeout(resolve, 20))

    expect(postMock).not.toHaveBeenCalled()
  })

  it('sends only the email when the forgot surface is not listed', async () => {
    configMock.value = {
      auth: { passwordReset: { enabled: true, url: null }, challenge: challenge(['login']) },
    }
    postMock.mockResolvedValue({})

    renderForgot()
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'person@example.com' } })
    fireEvent.click(screen.getByRole('button', { name: /Send reset link/ }))

    await waitFor(() => expect(postMock).toHaveBeenCalledTimes(1))
    expect(postMock).toHaveBeenCalledWith('/api/auth/password/email', { email: 'person@example.com' })
  })
})
