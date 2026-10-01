import { afterEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import type { ComponentType } from 'react'

// Register, reset password, invitation accept and the forced password change
// draw the checklist of the app's password policy (v2.3.0), as Profile does,
// and keep what the auth pages did before it: the first input focused, a
// field error announced (`role="alert"`), the inputs disabled while the
// request runs. The requests go through the real api client to a stubbed
// fetch, so a 422 has the body Laravel sends.

const { configMock } = vi.hoisted(() => ({
  configMock: {
    auth: {
      registration: { enabled: true },
      passwordReset: { enabled: true },
      passwordRequirements: { minLength: 12, symbol: true },
    },
  },
}))

vi.mock('@/lib/config', () => ({ config: configMock, BASE_PATH: '/martis', API_BASE_URL: 'http://localhost/martis' }))
vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => ({ user: null, isLoading: false, logout: vi.fn() }) }))
vi.mock('@/contexts/ToastContext', () => ({ useToast: () => ({ addToast: vi.fn() }) }))
vi.mock('@images/logo.png', () => ({ default: '/logo.png' }))

import { RegisterPage } from './Register'
import { ResetPasswordPage } from './ResetPassword'
import { InvitationAcceptPage } from './InvitationAccept'
import { PasswordChangeRequiredPage } from './PasswordChangeRequired'

const PAGES: [string, string, string, ComponentType][] = [
  ['register', '/register', '/register', RegisterPage],
  ['reset password', '/reset-password/:token', '/reset-password/abc?email=ada@example.com', ResetPasswordPage],
  ['invitation accept', '/invitations/accept/:token', '/invitations/accept/abc', InvitationAcceptPage],
  ['password change', '/password/change', '/password/change', PasswordChangeRequiredPage],
]

function renderPage(pattern: string, path: string, Page: ComponentType) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path={pattern} element={<Page />} />
      </Routes>
    </MemoryRouter>,
  )
}

const input = (id: string) => document.getElementById(id) as HTMLInputElement

/** Fill what the page asks for, with a password the checklist accepts, and submit. */
function submit(password = 'correct-horse-battery!', confirmation = password) {
  for (const [id, value] of [['register-name', 'Ada'], ['register-email', 'ada@example.com'], ['name', 'Ada'], ['current_password', 'old-secret']]) {
    if (document.getElementById(id) !== null) fireEvent.change(input(id), { target: { value } })
  }
  fireEvent.change(input('password'), { target: { value: password } })
  fireEvent.change(input('password_confirmation'), { target: { value: confirmation } })
  fireEvent.click(document.querySelector('form button[type="submit"]')!)
}

/** The body Laravel answers a failed validation with. */
function answer422(field: string, message: string) {
  vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ message, errors: { [field]: [message] } }), {
    status: 422,
    headers: { 'Content-Type': 'application/json' },
  })))
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('the password pages', () => {
  it.each(PAGES)('%s draws the checklist of the app password policy', (_name, pattern, path, Page) => {
    renderPage(pattern, path, Page)
    fireEvent.change(input('password'), { target: { value: 'a' } })

    const rows = [...document.querySelectorAll('[data-testid="password-requirements-password"] [data-requirement]')].map((row) => row.getAttribute('data-requirement'))
    expect(rows).toEqual(['minLength', 'symbol'])
    expect(input('password_confirmation')).not.toBeNull()
  })

  it.each([
    ['reset password', '/reset-password/:token', '/reset-password/abc?email=ada@example.com', ResetPasswordPage, 'password'],
    ['invitation accept', '/invitations/accept/:token', '/invitations/accept/abc', InvitationAcceptPage, 'name'],
    ['password change', '/password/change', '/password/change', PasswordChangeRequiredPage, 'current_password'],
  ] as [string, string, string, ComponentType, string][])('%s focuses its first input to fill on mount', (_name, pattern, path, Page, first) => {
    renderPage(pattern, path, Page)

    expect(document.activeElement).toBe(input(first))
  })

  it.each(PAGES)('%s announces a 422 on the password', async (_name, pattern, path, Page) => {
    answer422('password', 'The given password has appeared in a data leak.')
    renderPage(pattern, path, Page)

    submit()

    const alert = await screen.findByRole('alert')
    expect(alert.textContent).toContain('The given password has appeared in a data leak.')
  })

  it.each(PAGES)('%s announces a 422 the checklist also shows', async (_name, pattern, path, Page) => {
    answer422('password', 'The password field must be at least 12 characters.')
    renderPage(pattern, path, Page)

    submit('short!')

    const alert = await screen.findByRole('alert')
    expect(alert.textContent).toContain('The password field must be at least 12 characters.')
    expect(document.querySelector('[data-requirement="minLength"]')!.getAttribute('data-passes')).toBe('false')
  })

  it.each(PAGES)('%s announces a confirmation that differs', async (_name, pattern, path, Page) => {
    const fetch = vi.fn()
    vi.stubGlobal('fetch', fetch)
    renderPage(pattern, path, Page)

    submit('correct-horse-battery!', 'correct-horse-battery?')

    expect((await screen.findByRole('alert')).textContent).toMatch(/do not match/i)
    expect(fetch).not.toHaveBeenCalled()
  })

  it.each(PAGES.filter(([name]) => name !== 'register'))('%s disables the password inputs while the request runs', async (_name, pattern, path, Page) => {
    let settle: (response: Response) => void = () => {}
    vi.stubGlobal('fetch', vi.fn(() => new Promise<Response>((resolve) => { settle = resolve })))
    renderPage(pattern, path, Page)

    submit()

    await waitFor(() => expect(input('password').disabled).toBe(true))
    expect(input('password_confirmation').disabled).toBe(true)

    settle(new Response(JSON.stringify({ message: 'No.', errors: { password: ['No.'] } }), { status: 422, headers: { 'Content-Type': 'application/json' } }))
    await waitFor(() => expect(input('password').disabled).toBe(false))
    expect(input('password_confirmation').disabled).toBe(false)
  })
})
