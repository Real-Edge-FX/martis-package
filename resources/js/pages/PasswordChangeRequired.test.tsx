import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'

const { post, logout, MockApiError } = vi.hoisted(() => {
  class MockApiError extends Error {
    constructor(public status: number, message: string, public errors?: unknown[], private byField: Record<string, string> = {}) {
      super(message)
      this.name = 'ApiError'
    }
    errorsByField(): Record<string, string> {
      return this.byField
    }
  }

  return { post: vi.fn(), logout: vi.fn(), MockApiError }
})

vi.mock('@/lib/api', () => ({ api: { post: (...args: unknown[]) => post(...args) }, ApiError: MockApiError }))
vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => ({ user: null, logout }) }))
vi.mock('@/contexts/ToastContext', () => ({ useToast: () => ({ addToast: vi.fn() }) }))
vi.mock('@/lib/config', () => ({
  config: { auth: { passwordRequirements: { minLength: 12 } } },
  BASE_PATH: '/martis',
  API_BASE_URL: 'http://localhost/martis',
}))
vi.mock('@images/logo.png', () => ({ default: '/logo.png' }))

import { PasswordChangeRequiredPage } from './PasswordChangeRequired'

const original = window.location
let location: { href: string; pathname: string; origin: string }

function fill(current: string, password: string, confirmation = password) {
  fireEvent.change(document.getElementById('current_password')!, { target: { value: current } })
  fireEvent.change(document.getElementById('password')!, { target: { value: password } })
  fireEvent.change(document.getElementById('password_confirmation')!, { target: { value: confirmation } })
  fireEvent.click(screen.getByRole('button', { name: /save new password/i }))
}

beforeEach(() => {
  post.mockReset()
  logout.mockReset()
  location = { href: 'http://localhost/martis/password/change', pathname: '/martis/password/change', origin: 'http://localhost' }
  Object.defineProperty(window, 'location', { configurable: true, value: location })
  render(<MemoryRouter><PasswordChangeRequiredPage /></MemoryRouter>)
})

afterEach(() => {
  Object.defineProperty(window, 'location', { configurable: true, value: original })
})

describe('PasswordChangeRequiredPage', () => {
  it('draws the checklist of the app password policy', () => {
    fireEvent.change(document.getElementById('password')!, { target: { value: 'a' } })

    expect([...document.querySelectorAll('[data-requirement]')].map((row) => row.getAttribute('data-requirement'))).toEqual(['minLength'])
  })

  it('posts nothing while the confirmation differs', () => {
    fill('Temporary-Pass-1', 'Brand-New-Pass-1', 'Brand-New-Pass-2')

    expect(post).not.toHaveBeenCalled()
  })

  it('saves the new password, then loads the dashboard', async () => {
    post.mockResolvedValue({ message: 'Your password was changed.' })
    fill('Temporary-Pass-1', 'Brand-New-Pass-1')

    await waitFor(() => expect(location.href).toBe('/martis/'))
    expect(post).toHaveBeenCalledWith('/api/auth/password/change', {
      current_password: 'Temporary-Pass-1',
      password: 'Brand-New-Pass-1',
      password_confirmation: 'Brand-New-Pass-1',
    })
  })

  it('shows the server refusal on its field', async () => {
    post.mockRejectedValue(new MockApiError(422, 'The given data was invalid.', [{}], { current_password: 'The password is incorrect.' }))
    fill('Wrong-Pass-1', 'Brand-New-Pass-1')

    expect(await screen.findByText('The password is incorrect.')).toBeTruthy()
  })

  it('signs out', () => {
    fireEvent.click(screen.getByRole('button', { name: /sign out/i }))

    expect(logout).toHaveBeenCalled()
  })
})
