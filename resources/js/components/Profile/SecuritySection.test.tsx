import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'

// Regenerating the recovery codes needs the current password, as disabling
// 2FA does: a stolen or left-open session must not mint itself a second
// factor (v2.4.0).

const { post, del, addToast, MockApiError } = vi.hoisted(() => {
  class MockApiError extends Error {
    constructor(public status: number, message: string) {
      super(message)
      this.name = 'ApiError'
    }
  }

  return { post: vi.fn(), del: vi.fn(), addToast: vi.fn(), MockApiError }
})

vi.mock('@/lib/api', () => ({
  api: { post: (...args: unknown[]) => post(...args), delete: (...args: unknown[]) => del(...args) },
  ApiError: MockApiError,
}))
vi.mock('@/contexts/ToastContext', () => ({ useToast: () => ({ addToast }) }))
vi.mock('@/lib/historyLock', () => ({ useModalHistoryLock: () => {} }))

import { SecuritySection } from './SecuritySection'

function openRegenerateDialog() {
  fireEvent.click(screen.getByRole('button', { name: /view recovery codes/i }))
}

function typePasswordAndConfirm(password: string) {
  fireEvent.change(document.getElementById('regen-2fa-password')!, { target: { value: password } })
  fireEvent.click(screen.getByRole('button', { name: /generate new codes/i }))
}

beforeEach(() => {
  post.mockReset()
  del.mockReset()
  addToast.mockReset()
})

describe('SecuritySection: recovery codes', () => {
  it('asks for the current password before it calls the server', () => {
    render(<SecuritySection twoFactorEnabled onUpdate={vi.fn()} />)

    openRegenerateDialog()

    expect(post).not.toHaveBeenCalled()
    expect(document.getElementById('regen-2fa-password')).not.toBeNull()
    expect((screen.getByRole('button', { name: /generate new codes/i }) as HTMLButtonElement).disabled).toBe(true)
  })

  it('sends the current password with the request and then shows the new codes', async () => {
    post.mockResolvedValue({ recovery_codes: ['aaaa-bbbb', 'cccc-dddd'] })
    render(<SecuritySection twoFactorEnabled onUpdate={vi.fn()} />)

    openRegenerateDialog()
    typePasswordAndConfirm('Current-1')

    await waitFor(() => expect(post).toHaveBeenCalledWith('/api/profile/2fa/recovery-codes', { current_password: 'Current-1' }))
    expect(await screen.findByText('aaaa-bbbb')).toBeTruthy()
    expect(screen.getByText('cccc-dddd')).toBeTruthy()
    expect(document.getElementById('regen-2fa-password')).toBeNull()
  })

  it('shows a wrong password on the field and no codes', async () => {
    post.mockRejectedValue(new MockApiError(422, 'The password is incorrect.'))
    render(<SecuritySection twoFactorEnabled onUpdate={vi.fn()} />)

    openRegenerateDialog()
    typePasswordAndConfirm('wrong')

    expect(await screen.findByText('Incorrect password. Please try again.')).toBeTruthy()
    expect(document.getElementById('regen-2fa-password')).not.toBeNull()
    expect(screen.queryByText('aaaa-bbbb')).toBeNull()
  })

  it('says why when the server refuses it while impersonating', async () => {
    post.mockRejectedValue(new MockApiError(403, 'This action is not available while you are impersonating another user.'))
    render(<SecuritySection twoFactorEnabled onUpdate={vi.fn()} />)

    openRegenerateDialog()
    typePasswordAndConfirm('Current-1')

    await waitFor(() => expect(addToast).toHaveBeenCalledWith('error', 'This action is not available while you are impersonating another user.'))
  })

  it('does not call the server when the dialog is cancelled', () => {
    render(<SecuritySection twoFactorEnabled onUpdate={vi.fn()} />)

    openRegenerateDialog()
    fireEvent.click(screen.getAllByRole('button', { name: /cancel/i })[0])

    expect(post).not.toHaveBeenCalled()
    expect(document.getElementById('regen-2fa-password')).toBeNull()
  })
})
