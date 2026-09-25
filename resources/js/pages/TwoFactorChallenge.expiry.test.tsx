import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { act, cleanup, render } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import i18n from 'i18next'

/*
 * The 2FA challenge expires five minutes after it opens: a timer set on
 * mount signs the user out and says why. The timer ran the `handleCancel`
 * of the first render, with that render's `t`: after a language switch on
 * the challenge page, the expiry message came in the language the page
 * opened in. It now runs the latest `handleCancel`. The timer must still
 * start only once, although the code countdown re-renders the page every
 * second (a timer restarted on every render would never fire).
 */

const post = vi.fn()
const addToast = vi.fn()

vi.mock('@/lib/api', () => ({
  api: { post: (...args: unknown[]) => post(...args) },
  ApiError: class ApiError extends Error {},
}))

vi.mock('@/contexts/ToastContext', () => ({
  useToast: () => ({ addToast }),
}))

vi.mock('@/contexts/AuthContext', () => ({
  useAuth: () => ({ user: null }),
}))

vi.mock('@/lib/config', () => ({
  config: {},
  BASE_PATH: '/martis',
  API_BASE_URL: 'http://localhost/martis',
}))

vi.mock('@images/logo.png', () => ({ default: '/logo.png' }))

import { TwoFactorChallengePage } from '@/pages/TwoFactorChallenge'

const CHALLENGE_TIMEOUT_MS = 5 * 60 * 1000
const PSEUDO_LOCALE = 'en-XA'

describe('TwoFactorChallengePage expiry', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    post.mockReset().mockResolvedValue({})
    addToast.mockReset()
    i18n.addResourceBundle(PSEUDO_LOCALE, 'profile', { '2fa_session_expired': '[Challenge expired]' }, true, true)
  })

  afterEach(async () => {
    // Unmount first: the page would re-render on the language switch back.
    cleanup()
    vi.useRealTimers()
    await i18n.changeLanguage('en')
  })

  it('signs out five minutes after the challenge opens, in the language shown by then', async () => {
    render(
      <MemoryRouter>
        <TwoFactorChallengePage />
      </MemoryRouter>,
    )

    await act(async () => {
      await i18n.changeLanguage(PSEUDO_LOCALE)
    })

    await act(async () => {
      await vi.advanceTimersByTimeAsync(CHALLENGE_TIMEOUT_MS - 1_000)
    })
    expect(post).not.toHaveBeenCalledWith('/api/auth/logout')

    await act(async () => {
      await vi.advanceTimersByTimeAsync(1_000)
    })
    expect(post).toHaveBeenCalledWith('/api/auth/logout')
    expect(addToast).toHaveBeenCalledWith('info', '[Challenge expired]')
  })
})
