import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'

// Profile's password form draws the checklist of the app's password policy
// (window.MartisConfig.auth.passwordRequirements) and leaves the verdict to
// the server, which validates with Password::defaults() (v2.3.0).

const { post, addToast, configMock, MockApiError } = vi.hoisted(() => {
  class MockApiError extends Error {
    constructor(public status: number, message: string, public errors?: unknown[], private byField: Record<string, string> = {}) {
      super(message)
      this.name = 'ApiError'
    }
    errorsByField(): Record<string, string> {
      return this.byField
    }
  }

  return {
    post: vi.fn(),
    addToast: vi.fn(),
    configMock: { auth: { passwordRequirements: { minLength: 8 } as Record<string, unknown> | null } },
    MockApiError,
  }
})

vi.mock('@/lib/api', () => ({ api: { post: (...args: unknown[]) => post(...args) }, ApiError: MockApiError }))
vi.mock('@/contexts/ToastContext', () => ({ useToast: () => ({ addToast }) }))
vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => ({ user: { id: 1, name: 'Ada', email: 'ada@example.com' } }) }))
vi.mock('@/lib/config', () => ({ config: configMock, BASE_PATH: '/martis', API_BASE_URL: 'http://localhost/martis' }))

import { PasswordSection } from './PasswordSection'

function submit(current: string, next: string, confirm = next) {
  fireEvent.change(document.getElementById('current-password')!, { target: { value: current } })
  fireEvent.change(document.getElementById('password')!, { target: { value: next } })
  fireEvent.change(document.getElementById('password_confirmation')!, { target: { value: confirm } })
  fireEvent.click(screen.getByRole('button', { name: 'Update Password' }))
}

beforeEach(() => {
  post.mockReset()
  addToast.mockReset()
  configMock.auth.passwordRequirements = { minLength: 8 }
})

describe('PasswordSection', () => {
  it('draws the checklist of the app password policy', () => {
    configMock.auth.passwordRequirements = { minLength: 12, uppercase: true }
    render(<PasswordSection />)
    fireEvent.change(document.getElementById('password')!, { target: { value: 'a' } })

    const rows = [...document.querySelectorAll('[data-requirement]')].map((row) => row.getAttribute('data-requirement'))
    expect(rows).toEqual(['minLength', 'uppercase'])
  })

  it('has no checklist when the server cannot describe the policy', () => {
    configMock.auth.passwordRequirements = null
    render(<PasswordSection />)
    fireEvent.change(document.getElementById('password')!, { target: { value: 'a' } })

    expect(document.querySelector('[data-testid="password-requirements-password"]')).toBeNull()
  })

  it('submits a password without a symbol when the policy asks for none', async () => {
    post.mockResolvedValue({ message: 'Password updated successfully.' })
    render(<PasswordSection />)
    submit('Current-1', 'abcdefgh')

    await waitFor(() => expect(post).toHaveBeenCalledWith('/api/profile/password', {
      current_password: 'Current-1',
      password: 'abcdefgh',
      password_confirmation: 'abcdefgh',
    }))
  })

  it('shows the server refusal on the password field', async () => {
    post.mockRejectedValue(new MockApiError(422, 'The given data was invalid.', [{}], { password: 'The password field must be at least 12 characters.' }))
    render(<PasswordSection />)
    submit('Current-1', 'abcdefgh')

    expect(await screen.findByText('The password field must be at least 12 characters.')).toBeTruthy()
  })
})
