import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { MemoryRouter, Routes, Route } from 'react-router-dom'

// --- Mocks (mirrors resources/js/profile.test.tsx and preferencesMenu.test.tsx
// conventions — `vi.hoisted` because the factory below references these
// directly, not just through a lazily-invoked closure). ---

const { postMock, MockApiError } = vi.hoisted(() => {
  const postMock = vi.fn()

  class MockApiError extends Error {
    constructor(
      public status: number,
      message: string,
      public errors?: Array<{ field: string; message: string; code: string }>,
    ) {
      super(message)
      this.name = 'ApiError'
    }
    errorsByField(): Record<string, string> {
      const result: Record<string, string> = {}
      if (this.errors) {
        for (const err of this.errors) {
          if (err.field && !result[err.field]) result[err.field] = err.message
        }
      }
      return result
    }
    errorSummary(): string {
      if (!this.errors || this.errors.length === 0) return this.message
      return this.errors.map((e) => e.message).join('. ')
    }
  }

  return { postMock, MockApiError }
})

vi.mock('@/lib/api', () => ({
  api: {
    get: vi.fn(),
    post: (...args: unknown[]) => postMock(...args),
    patch: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    upload: vi.fn(),
  },
  ApiError: MockApiError,
}))

vi.mock('@/contexts/ToastContext', () => ({
  useToast: () => ({ addToast: vi.fn() }),
}))

vi.mock('@/lib/config', () => ({
  config: {},
  BASE_PATH: '/martis',
  API_BASE_URL: 'http://localhost/martis',
}))

vi.mock('@images/logo.png', () => ({ default: '/logo.png' }))

import { InvitationAcceptPage } from './InvitationAccept'

function renderAt(token: string) {
  return render(
    <MemoryRouter initialEntries={[`/invitations/accept/${token}`]}>
      <Routes>
        <Route path="/invitations/accept/:token" element={<InvitationAcceptPage />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  postMock.mockReset()
})

describe('InvitationAcceptPage', () => {
  it('renders the set-password form for a valid-token path', () => {
    renderAt('valid-token-123')

    expect(screen.getByLabelText(/name/i)).toBeDefined()
    expect(document.getElementById('password')).not.toBeNull()
    expect(document.getElementById('password_confirmation')).not.toBeNull()
    expect(screen.queryByText(/invalid or has expired/i)).toBeNull()
  })

  it('submits to /api/invitations/accept with the token and form fields', async () => {
    postMock.mockResolvedValue({ ok: true, redirect: '/martis' })
    renderAt('valid-token-123')

    fireEvent.change(screen.getByLabelText(/name/i), { target: { value: 'Ada Lovelace' } })
    fireEvent.change(document.getElementById('password')!, { target: { value: 'Sup3rSecret!' } })
    fireEvent.change(document.getElementById('password_confirmation')!, { target: { value: 'Sup3rSecret!' } })

    fireEvent.click(screen.getByRole('button', { name: /accept/i }))

    await waitFor(() => {
      expect(postMock).toHaveBeenCalledWith('/api/invitations/accept', {
        token: 'valid-token-123',
        name: 'Ada Lovelace',
        password: 'Sup3rSecret!',
        password_confirmation: 'Sup3rSecret!',
      })
    })
  })

  it('renders the neutral invalid-link message when the server flags the token invalid', async () => {
    postMock.mockRejectedValue(
      new MockApiError(422, 'This invitation link is invalid or has expired.', [
        { field: 'token', message: 'This invitation link is invalid or has expired.', code: 'invalid' },
      ]),
    )
    renderAt('bad-token')

    fireEvent.change(screen.getByLabelText(/name/i), { target: { value: 'Ada Lovelace' } })
    fireEvent.change(document.getElementById('password')!, { target: { value: 'Sup3rSecret!' } })
    fireEvent.change(document.getElementById('password_confirmation')!, { target: { value: 'Sup3rSecret!' } })

    fireEvent.click(screen.getByRole('button', { name: /accept/i }))

    await waitFor(() => {
      expect(screen.getByText(/invalid or has expired/i)).toBeDefined()
    })
    // The form should no longer be present once the neutral state renders.
    expect(document.getElementById('password')).toBeNull()
  })

  it('shows an inline field error on a bad-password 422 without revealing token state', async () => {
    postMock.mockRejectedValue(
      new MockApiError(422, 'The password field confirmation does not match.', [
        { field: 'password', message: 'The password field confirmation does not match.', code: 'invalid' },
      ]),
    )
    renderAt('valid-token-123')

    fireEvent.change(screen.getByLabelText(/name/i), { target: { value: 'Ada Lovelace' } })
    fireEvent.change(document.getElementById('password')!, { target: { value: 'Sup3rSecret!' } })
    fireEvent.change(document.getElementById('password_confirmation')!, { target: { value: 'Sup3rSecret!' } })

    fireEvent.click(screen.getByRole('button', { name: /accept/i }))

    await waitFor(() => {
      expect(screen.getByText(/does not match/i)).toBeDefined()
    })
    // Still the form, not the neutral invalid-link screen.
    expect(document.getElementById('password')).not.toBeNull()
    expect(screen.queryByText(/invalid or has expired/i)).toBeNull()
  })
})
