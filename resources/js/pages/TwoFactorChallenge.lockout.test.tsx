import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'

/*
 * The 2FA challenge answers a rate limit (429) and a lockout (403, the server
 * ended the session after consecutive wrong codes) differently from a wrong
 * code (422), v2.4.0: a wrong code keeps the user on the form, the rate limit
 * says to wait, the lockout says why they were signed out and goes to the
 * login page.
 */

const { post, addToast, MockApiError } = vi.hoisted(() => {
  class MockApiError extends Error {
    constructor(public status: number, message: string) {
      super(message)
      this.name = 'ApiError'
    }
  }

  return { post: vi.fn(), addToast: vi.fn(), MockApiError }
})

vi.mock('@/lib/api', () => ({
  api: { post: (...args: unknown[]) => post(...args) },
  ApiError: MockApiError,
}))
vi.mock('@/contexts/ToastContext', () => ({ useToast: () => ({ addToast }) }))
vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => ({ user: null }) }))
vi.mock('@/lib/config', () => ({ config: {}, BASE_PATH: '/martis', API_BASE_URL: 'http://localhost/martis' }))
vi.mock('@images/logo.png', () => ({ default: '/logo.png' }))

import { TwoFactorChallengePage } from '@/pages/TwoFactorChallenge'

function submitCode() {
  render(
    <MemoryRouter>
      <TwoFactorChallengePage />
    </MemoryRouter>,
  )
  const cells = screen.getAllByRole('textbox')
  fireEvent.paste(cells[0], { clipboardData: { getData: () => '123456' } })
}

const originalLocation = window.location

beforeEach(() => {
  post.mockReset()
  addToast.mockReset()
  // jsdom cannot navigate: record the assignment instead.
  Object.defineProperty(window, 'location', { configurable: true, value: { href: 'http://localhost/martis/2fa/challenge' } })
})

afterEach(() => {
  cleanup()
  Object.defineProperty(window, 'location', { configurable: true, value: originalLocation })
})

describe('TwoFactorChallengePage: refusals', () => {
  it('keeps the user on the form with "invalid code" after a wrong code', async () => {
    post.mockRejectedValue(new MockApiError(422, 'Invalid code.'))
    submitCode()

    expect(await screen.findByText('Invalid code. Please try again.')).toBeTruthy()
    expect(window.location.href).toBe('http://localhost/martis/2fa/challenge')
  })

  it('tells the user to wait when the server rate limits the challenge', async () => {
    post.mockRejectedValue(new MockApiError(429, 'Too many attempts.'))
    submitCode()

    expect(await screen.findByText('Too many attempts. Wait a moment before you try again.')).toBeTruthy()
    expect(screen.queryByText('Invalid code. Please try again.')).toBeNull()
    expect(window.location.href).toBe('http://localhost/martis/2fa/challenge')
  })

  it('says why and goes to the login page when the lockout ended the session', async () => {
    post.mockRejectedValue(new MockApiError(403, 'Locked.'))
    submitCode()

    await waitFor(() => expect(window.location.href).toBe('/martis/login'))
    expect(addToast).toHaveBeenCalledWith('error', 'Too many incorrect codes. For your security you were signed out. Try again in a few minutes.')
    expect(screen.queryByText('Invalid code. Please try again.')).toBeNull()
  })
})
