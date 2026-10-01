import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import type { ComponentType } from 'react'

// Register, reset password and invitation accept draw the checklist of the
// app's password policy (v2.3.0), as Profile does.

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
vi.mock('@/lib/api', async (importOriginal) => ({ ...(await importOriginal<typeof import('@/lib/api')>()), api: { post: vi.fn() } }))
vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => ({ user: null, isLoading: false }) }))
vi.mock('@/contexts/ToastContext', () => ({ useToast: () => ({ addToast: vi.fn() }) }))
vi.mock('@images/logo.png', () => ({ default: '/logo.png' }))

import { RegisterPage } from './Register'
import { ResetPasswordPage } from './ResetPassword'
import { InvitationAcceptPage } from './InvitationAccept'

const PAGES: [string, string, string, ComponentType][] = [
  ['register', '/register', '/register', RegisterPage],
  ['reset password', '/reset-password/:token', '/reset-password/abc?email=ada@example.com', ResetPasswordPage],
  ['invitation accept', '/invitations/accept/:token', '/invitations/accept/abc', InvitationAcceptPage],
]

describe('the password pages', () => {
  it.each(PAGES)('%s draws the checklist of the app password policy', (_name, pattern, path, Page) => {
    render(
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path={pattern} element={<Page />} />
        </Routes>
      </MemoryRouter>,
    )
    fireEvent.change(document.getElementById('password')!, { target: { value: 'a' } })

    const rows = [...document.querySelectorAll('[data-testid="password-requirements-password"] [data-requirement]')].map((row) => row.getAttribute('data-requirement'))
    expect(rows).toEqual(['minLength', 'symbol'])
    expect(document.getElementById('password_confirmation')).not.toBeNull()
  })
})
