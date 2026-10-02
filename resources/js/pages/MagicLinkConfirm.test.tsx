import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router'

const { post, addToast, auth, MockApiError } = vi.hoisted(() => {
  class MockApiError extends Error {
    constructor(
      public status: number,
      message: string,
      public errors?: Array<{ field: string; message: string; code: string }>,
    ) {
      super(message)
      this.name = 'ApiError'
    }
  }

  return {
    post: vi.fn(),
    addToast: vi.fn(),
    auth: { user: null as { email: string } | null },
    MockApiError,
  }
})

vi.mock('@/lib/api', () => ({ api: { post: (...args: unknown[]) => post(...args) }, ApiError: MockApiError }))
vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => ({ user: auth.user }) }))
vi.mock('@/contexts/ToastContext', () => ({ useToast: () => ({ addToast }) }))
vi.mock('@/lib/config', () => ({ config: {}, BASE_PATH: '/martis', API_BASE_URL: 'http://localhost/martis' }))
vi.mock('@images/logo.png', () => ({ default: '/logo.png' }))

import { MagicLinkConfirmPage } from './MagicLinkConfirm'

const original = window.location
let location: { href: string; pathname: string; origin: string; search: string }
let replaceState: ReturnType<typeof vi.spyOn>

function Where() {
  const here = useLocation()
  return <p data-testid="where">{here.pathname + here.search}</p>
}

function renderAt(search: string) {
  return render(
    <MemoryRouter initialEntries={[`/magic-link/confirm${search}`]}>
      <Routes>
        <Route path="/magic-link/confirm" element={<MagicLinkConfirmPage />} />
        <Route path="/login" element={<Where />} />
      </Routes>
    </MemoryRouter>,
  )
}

const LINK = '?email=pedro%40example.com&token=tok-123'

beforeEach(() => {
  post.mockReset()
  addToast.mockReset()
  auth.user = null
  location = {
    href: 'http://localhost/martis/magic-link/confirm' + LINK,
    pathname: '/martis/magic-link/confirm',
    origin: 'http://localhost',
    search: LINK,
  }
  Object.defineProperty(window, 'location', { configurable: true, value: location })
  // jsdom refuses a history URL that is not its own origin: the stubbed location is not.
  replaceState = vi.spyOn(window.history, 'replaceState').mockImplementation(() => {})
})

afterEach(() => {
  replaceState.mockRestore()
  Object.defineProperty(window, 'location', { configurable: true, value: original })
})

describe('MagicLinkConfirmPage', () => {
  it('asks who is about to sign in and sends nothing until the button is clicked', () => {
    renderAt(LINK)

    expect(screen.getByText('You are about to sign in as pedro@example.com.')).toBeTruthy()
    expect(screen.getByRole('button', { name: /sign in as pedro@example\.com/i })).toBeTruthy()
    expect(post).not.toHaveBeenCalled()
  })

  it('signs in with the POST, then loads the panel in full', async () => {
    post.mockResolvedValue({ redirect: '/martis' })
    renderAt(LINK)

    fireEvent.click(screen.getByRole('button', { name: /sign in as/i }))

    await waitFor(() => expect(location.href).toBe('/martis'))
    expect(post).toHaveBeenCalledWith('/api/auth/magic-link/consume', {
      email: 'pedro@example.com',
      token: 'tok-123',
    })
  })

  it('goes to the login page with the reason when the server refuses the token', async () => {
    post.mockRejectedValue(
      new MockApiError(422, 'This sign-in link has expired.', [{ field: 'token', message: 'expired', code: 'expired' }]),
    )
    renderAt(LINK)

    fireEvent.click(screen.getByRole('button', { name: /sign in as/i }))

    expect((await screen.findByTestId('where')).textContent).toBe('/login?magic_link=expired')
  })

  it('goes to the login page when the link has no email or token, without a request', () => {
    renderAt('?email=pedro%40example.com')

    expect(screen.getByTestId('where').textContent).toBe('/login?magic_link=invalid')
    expect(post).not.toHaveBeenCalled()
  })

  it('warns before replacing the session of another user, and says so to the server', async () => {
    auth.user = { email: 'ana@example.com' }
    post.mockResolvedValue({ redirect: '/martis' })
    renderAt(LINK)

    expect(screen.getByRole('alert').textContent).toContain('ana@example.com')
    fireEvent.click(screen.getByRole('button', { name: /sign out and sign in as pedro@example\.com/i }))

    await waitFor(() => expect(post).toHaveBeenCalled())
    expect(post).toHaveBeenCalledWith('/api/auth/magic-link/consume', {
      email: 'pedro@example.com',
      token: 'tok-123',
      replace_session: true,
    })
  })

  it('does not warn when the browser is signed in as the same user', async () => {
    auth.user = { email: 'Pedro@Example.com' }
    post.mockResolvedValue({ redirect: '/martis' })
    renderAt(LINK)

    expect(screen.queryByRole('alert')).toBeNull()
    fireEvent.click(screen.getByRole('button', { name: /sign in as pedro@example\.com/i }))

    await waitFor(() => expect(post).toHaveBeenCalled())
    expect(post.mock.calls[0][1]).not.toHaveProperty('replace_session')
  })

  it('asks again when the server reports a session it was not told about, then replaces it', async () => {
    post.mockRejectedValueOnce(
      new MockApiError(409, 'This browser is signed in as ana@example.com. Confirm to replace that session.', [
        { field: 'session', message: 'x', code: 'session_conflict' },
      ]),
    )
    post.mockResolvedValueOnce({ redirect: '/martis' })
    renderAt(LINK)

    fireEvent.click(screen.getByRole('button', { name: /sign in as pedro@example\.com/i }))

    expect((await screen.findByRole('alert')).textContent).toContain('ana@example.com')
    expect(post).toHaveBeenCalledTimes(1)

    fireEvent.click(screen.getByRole('button', { name: /sign out and sign in as/i }))

    await waitFor(() => expect(post).toHaveBeenCalledTimes(2))
    expect(post.mock.calls[1][1]).toMatchObject({ replace_session: true })
  })

  it('takes the token out of the address bar', () => {
    renderAt(LINK)

    expect(replaceState).toHaveBeenCalledTimes(1)
    expect(String(replaceState.mock.calls[0][2])).not.toContain('token')
    expect(String(replaceState.mock.calls[0][2])).not.toContain('email')
  })
})
