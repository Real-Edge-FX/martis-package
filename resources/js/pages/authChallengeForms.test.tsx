import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'

/*
 * v2.21.0: the challenge token wiring of the registration, password reset and
 * invitation pages (the login and forgot-password pages are covered with the
 * component). Each page renders the widget of its own surface, sends the
 * token as `challenge_token`, and renews the widget after a failed submit.
 */

const { configMock, postMock, MockApiError } = vi.hoisted(() => {
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

  return { configMock: { value: {} as Record<string, unknown> }, postMock: vi.fn(), MockApiError }
})

vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => ({ user: null, isLoading: false }) }))
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
import { RegisterPage } from '@/pages/Register'
import { ResetPasswordPage } from '@/pages/ResetPassword'
import { InvitationAcceptPage } from '@/pages/InvitationAccept'

/** A widget that solves on click, and renders the resetSignal it was given. */
function TestWidget(props: AuthChallengeProps) {
  return (
    <div>
      <span data-testid="reset-signal">{props.resetSignal}</span>
      <span data-testid="surface">{props.surface}</span>
      <button type="button" onClick={() => props.onToken(`token-${props.resetSignal}`)}>solve</button>
    </div>
  )
}

function challenge(appliesTo: string[]) {
  return { enabled: true, siteKey: 'site-123', appliesTo }
}

function fillPasswords(password = 'Sup3rSecret!') {
  fireEvent.change(document.getElementById('password')!, { target: { value: password } })
  fireEvent.change(document.getElementById('password_confirmation')!, { target: { value: password } })
}

const pages = [
  {
    label: 'registration',
    surface: 'register',
    endpoint: '/api/auth/register',
    auth: { registration: { enabled: true, url: null } },
    render: () => render(<MemoryRouter><RegisterPage /></MemoryRouter>),
    fill: () => {
      fireEvent.change(document.getElementById('register-name')!, { target: { value: 'Ada Lovelace' } })
      fireEvent.change(document.getElementById('register-email')!, { target: { value: 'ada@example.com' } })
      fillPasswords()
    },
    submit: () => fireEvent.click(screen.getByRole('button', { name: /create account/i })),
    body: { name: 'Ada Lovelace', email: 'ada@example.com', password: 'Sup3rSecret!', password_confirmation: 'Sup3rSecret!' },
  },
  {
    label: 'password reset',
    surface: 'reset',
    endpoint: '/api/auth/password/reset',
    auth: { passwordReset: { enabled: true, url: null } },
    render: () => render(
      <MemoryRouter initialEntries={['/reset-password#token=reset-token&email=ada%40example.com']}>
        <Routes><Route path="/reset-password" element={<ResetPasswordPage />} /></Routes>
      </MemoryRouter>,
    ),
    fill: () => fillPasswords(),
    submit: () => fireEvent.click(screen.getByRole('button', { name: /update password/i })),
    body: { token: 'reset-token', email: 'ada@example.com', password: 'Sup3rSecret!', password_confirmation: 'Sup3rSecret!' },
  },
  {
    label: 'invitation',
    surface: 'invitation',
    endpoint: '/api/invitations/accept',
    auth: {},
    render: () => render(
      <MemoryRouter initialEntries={['/invitations/accept#token=invite-token']}>
        <Routes><Route path="/invitations/accept" element={<InvitationAcceptPage />} /></Routes>
      </MemoryRouter>,
    ),
    fill: () => {
      fireEvent.change(screen.getByLabelText(/name/i), { target: { value: 'Ada Lovelace' } })
      fillPasswords()
    },
    submit: () => fireEvent.click(screen.getByRole('button', { name: /accept/i })),
    body: { token: 'invite-token', name: 'Ada Lovelace', password: 'Sup3rSecret!', password_confirmation: 'Sup3rSecret!' },
  },
]

const originalLocation = window.location

beforeEach(() => {
  postMock.mockReset()
  componentRegistry.register(CHALLENGE_REGISTRY_KEY, TestWidget)
  Object.defineProperty(window, 'location', {
    configurable: true,
    value: { href: 'http://localhost/martis/x', pathname: '/martis/x', origin: 'http://localhost', hash: '', search: '' },
  })
})

afterEach(() => {
  componentRegistry.unregister(CHALLENGE_REGISTRY_KEY)
  Object.defineProperty(window, 'location', { configurable: true, value: originalLocation })
})

describe.each(pages)('$label page and the challenge', (page) => {
  it('tells the widget its own surface, sends the token, and renews the widget after a failed submit', async () => {
    configMock.value = { auth: { ...page.auth, challenge: challenge([page.surface]) } }
    postMock.mockRejectedValueOnce(new MockApiError(422, 'The security check failed.', [
      { field: 'challenge_token', message: 'The security check failed.', code: 'x' },
    ]))

    page.render()
    expect(screen.getByTestId('surface').textContent).toBe(page.surface)

    fireEvent.click(screen.getByText('solve'))
    page.fill()
    page.submit()

    await waitFor(() => expect(postMock).toHaveBeenCalledTimes(1))
    expect(postMock).toHaveBeenCalledWith(page.endpoint, { ...page.body, challenge_token: 'token-0' })
    await waitFor(() => expect(screen.getByTestId('reset-signal').textContent).toBe('1'))
    expect(screen.getByText('The security check failed.')).toBeTruthy()
  })

  it('sends no token, and renders no widget, when the surface is not listed', async () => {
    configMock.value = { auth: { ...page.auth, challenge: challenge(['login']) } }
    postMock.mockResolvedValue({ ok: true, redirect: '/martis' })

    page.render()
    expect(screen.queryByTestId('surface')).toBeNull()

    page.fill()
    page.submit()

    await waitFor(() => expect(postMock).toHaveBeenCalledTimes(1))
    expect(postMock).toHaveBeenCalledWith(page.endpoint, page.body)
  })

  it('does not post, and says why, when the challenge is on and no component is registered', async () => {
    componentRegistry.unregister(CHALLENGE_REGISTRY_KEY)
    configMock.value = { auth: { ...page.auth, challenge: challenge([page.surface]) } }

    page.render()
    expect(screen.getByTestId('auth-challenge-misconfigured')).toBeTruthy()

    page.fill()
    page.submit()
    await new Promise((resolve) => window.setTimeout(resolve, 20))

    expect(postMock).not.toHaveBeenCalled()
  })
})
