import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, render, renderHook, waitFor } from '@testing-library/react'

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))

vi.mock('@/lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api')>()),
  api: { get: (...args: unknown[]) => get(...args), post: (...args: unknown[]) => post(...args) },
}))
vi.mock('@/lib/config', () => ({ config: { auth: {} }, BASE_PATH: '/martis', API_BASE_URL: 'http://localhost/martis' }))

import { AuthProvider, PasswordChangeRequiredError, useAuth } from './AuthContext'

const original = window.location
let location: { href: string; pathname: string; origin: string }

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  location = { href: 'http://localhost/martis/', pathname: '/martis/', origin: 'http://localhost' }
  Object.defineProperty(window, 'location', { configurable: true, value: location })
})

afterEach(() => {
  Object.defineProperty(window, 'location', { configurable: true, value: original })
})

describe('AuthContext and the forced password change', () => {
  it('throws PasswordChangeRequiredError from login() when the gate holds the user', async () => {
    get.mockResolvedValue(null)
    post.mockResolvedValue({ password_change_required: true, message: 'Password change required.' })
    const { result } = renderHook(() => useAuth(), { wrapper: AuthProvider })
    await waitFor(() => expect(result.current.isLoading).toBe(false))

    await expect(result.current.login('held@example.com', 'Temporary-Pass-1')).rejects.toBeInstanceOf(PasswordChangeRequiredError)
    expect(result.current.user).toBeNull()
  })

  it('sends a held user to the change page on bootstrap, but not from the page itself', async () => {
    get.mockResolvedValue({ password_change_pending: true, message: 'Password change required.' })
    render(<AuthProvider><span /></AuthProvider>)
    await waitFor(() => expect(location.href).toBe('/martis/password/change'))

    location.pathname = '/martis/password/change'
    location.href = 'unchanged'
    render(<AuthProvider><span /></AuthProvider>)
    await waitFor(() => expect(get).toHaveBeenCalledTimes(2))
    await act(async () => {})
    expect(location.href).toBe('unchanged')
  })
})
