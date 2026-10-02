import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { render, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'

const { addToast } = vi.hoisted(() => ({ addToast: vi.fn() }))

vi.mock('@/contexts/AuthContext', () => ({
  useAuth: () => ({ user: null, isLoading: false, login: vi.fn() }),
  TwoFactorRequiredError: class extends Error {},
  EmailVerificationRequiredError: class extends Error {},
  PasswordChangeRequiredError: class extends Error {},
}))
vi.mock('@/contexts/ToastContext', () => ({ useToast: () => ({ addToast }) }))
vi.mock('@/lib/api', () => ({ api: { post: vi.fn() }, ApiError: class extends Error {} }))
vi.mock('@/lib/config', () => ({ config: { auth: {} }, BASE_PATH: '/martis', API_BASE_URL: 'http://localhost/martis' }))
vi.mock('@images/logo.png', () => ({ default: '/logo.png' }))

import { LoginPage } from './Login'

const original = window.location

function arriveWith(search: string) {
  Object.defineProperty(window, 'location', {
    configurable: true,
    value: { href: `http://localhost/martis/login${search}`, pathname: '/martis/login', origin: 'http://localhost', search },
  })
}

beforeEach(() => {
  addToast.mockReset()
  vi.spyOn(window.history, 'replaceState').mockImplementation(() => {})
})

afterEach(() => {
  vi.restoreAllMocks()
  Object.defineProperty(window, 'location', { configurable: true, value: original })
})

describe('LoginPage: arriving from a magic link that did not work', () => {
  it.each(['expired', 'invalid', 'disabled'])('toasts the reason of ?magic_link=%s, once', async (reason) => {
    arriveWith(`?magic_link=${reason}`)

    render(<MemoryRouter><LoginPage /></MemoryRouter>)

    await waitFor(() => expect(addToast).toHaveBeenCalledTimes(1))
    expect(addToast.mock.calls[0][0]).toBe('error')
    expect(String(addToast.mock.calls[0][1])).toBeTruthy()
  })

  it('shows nothing for another value, or none', async () => {
    arriveWith('?magic_link=whatever')

    render(<MemoryRouter><LoginPage /></MemoryRouter>)
    await new Promise((resolve) => window.setTimeout(resolve, 10))

    expect(addToast).not.toHaveBeenCalled()
  })
})

describe('LoginPage: arriving from the confirmation link of a new email address', () => {
  it('toasts the change as a success, and a refusal as an error', async () => {
    arriveWith('?email_change=changed')
    const { unmount } = render(<MemoryRouter><LoginPage /></MemoryRouter>)

    await waitFor(() => expect(addToast).toHaveBeenCalledTimes(1))
    expect(addToast).toHaveBeenCalledWith('success', 'Your email address was changed.')
    unmount()

    addToast.mockReset()
    arriveWith('?email_change=rejected')
    render(<MemoryRouter><LoginPage /></MemoryRouter>)

    await waitFor(() => expect(addToast).toHaveBeenCalledTimes(1))
    expect(addToast.mock.calls[0][0]).toBe('error')
  })
})
