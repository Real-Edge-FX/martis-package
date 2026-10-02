import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { render, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'

const { addToast, updateUser, get } = vi.hoisted(() => ({ addToast: vi.fn(), updateUser: vi.fn(), get: vi.fn() }))

vi.mock('@/lib/api', () => ({
  api: { get: (...args: unknown[]) => get(...args) },
  ApiError: class extends Error {},
}))
vi.mock('@/contexts/ToastContext', () => ({ useToast: () => ({ addToast }) }))
vi.mock('@/contexts/AuthContext', () => ({
  useAuth: () => ({ user: { id: 1, name: 'Ada', email: 'ada@example.com' }, updateUser }),
}))
vi.mock('@/lib/config', () => ({
  config: { profile: { sections: ['account'] }, auth: {} },
  BASE_PATH: '/martis',
  API_BASE_URL: 'http://localhost/martis',
}))
vi.mock('@/components/Loader', () => ({ MartisLoader: () => <div>Loading</div> }))
vi.mock('@images/logo.png', () => ({ default: '/logo.png' }))

import { ProfilePage } from './Profile'

const original = window.location

function arriveWith(search: string) {
  Object.defineProperty(window, 'location', {
    configurable: true,
    value: { href: `http://localhost/martis/profile${search}`, pathname: '/martis/profile', origin: 'http://localhost', search },
  })
}

beforeEach(() => {
  addToast.mockReset()
  get.mockResolvedValue({ name: 'Ada', email: 'ada@example.com', avatar_url: null, two_factor_enabled: false })
  vi.spyOn(window.history, 'replaceState').mockImplementation(() => {})
})

afterEach(() => {
  vi.restoreAllMocks()
  Object.defineProperty(window, 'location', { configurable: true, value: original })
})

describe('ProfilePage: back from the confirmation link of a new email address', () => {
  it('toasts the change as a success', async () => {
    arriveWith('?email_change=changed')

    render(<MemoryRouter><ProfilePage /></MemoryRouter>)

    await waitFor(() => expect(addToast).toHaveBeenCalledTimes(1))
    expect(addToast).toHaveBeenCalledWith('success', 'Your email address was changed.')
  })

  it.each(['invalid', 'rejected'])('toasts ?email_change=%s as an error', async (outcome) => {
    arriveWith(`?email_change=${outcome}`)

    render(<MemoryRouter><ProfilePage /></MemoryRouter>)

    await waitFor(() => expect(addToast).toHaveBeenCalledTimes(1))
    expect(addToast.mock.calls[0][0]).toBe('error')
  })

  it('shows nothing without the flag, or with another value', async () => {
    arriveWith('?email_change=whatever')

    render(<MemoryRouter><ProfilePage /></MemoryRouter>)
    await new Promise((resolve) => window.setTimeout(resolve, 10))

    expect(addToast).not.toHaveBeenCalled()
  })
})
