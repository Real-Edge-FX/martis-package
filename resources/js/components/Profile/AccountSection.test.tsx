import { describe, it, expect, vi, beforeEach } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { AccountSection } from './AccountSection'

const { patch, addToast, MockApiError } = vi.hoisted(() => {
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
      return Object.fromEntries((this.errors ?? []).map((e) => [e.field, e.message]))
    }
  }

  return { patch: vi.fn(), addToast: vi.fn(), MockApiError }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, o?: { defaultValue?: string; email?: string }) =>
      (o?.defaultValue ?? key).replace('{{email}}', o?.email ?? ''),
  }),
}))
vi.mock('@/lib/api', () => ({ api: { patch: (...args: unknown[]) => patch(...args) }, ApiError: MockApiError }))
vi.mock('@/contexts/ToastContext', () => ({ useToast: () => ({ addToast }) }))

beforeEach(() => {
  patch.mockReset()
  addToast.mockReset()
})

describe('AccountSection — email lock', () => {
  it('renders the e-mail editable by default', () => {
    const { container } = render(<AccountSection name="A" email="a@x.com" onUpdate={vi.fn()} />)
    const email = container.querySelector('#profile-email') as HTMLInputElement
    expect(email.readOnly).toBe(false)
    expect(email.disabled).toBe(false)
  })

  it('renders the e-mail read-only when emailReadOnly is set', () => {
    const { container, getByText } = render(
      <AccountSection name="A" email="a@x.com" onUpdate={vi.fn()} emailReadOnly />,
    )
    const email = container.querySelector('#profile-email') as HTMLInputElement
    expect(email.readOnly).toBe(true)
    expect(email.disabled).toBe(true)
    expect(getByText('Your e-mail cannot be changed.')).toBeTruthy()
  })

  it('keeps the name field editable even when the e-mail is locked', () => {
    const { container } = render(
      <AccountSection name="A" email="a@x.com" onUpdate={vi.fn()} emailReadOnly />,
    )
    const name = container.querySelector('#profile-name') as HTMLInputElement
    expect(name.readOnly).toBe(false)
    expect(name.disabled).toBe(false)
  })
})

describe('AccountSection: changing the email (v2.4.0)', () => {
  function typeEmail(value: string) {
    fireEvent.change(document.getElementById('profile-email')!, { target: { value } })
  }

  it('asks no password while the e-mail is the current one, whatever its letter case', () => {
    render(<AccountSection name="A" email="a@x.com" onUpdate={vi.fn()} />)

    expect(document.getElementById('profile-current-password')).toBeNull()

    typeEmail('A@X.com')
    expect(document.getElementById('profile-current-password')).toBeNull()
  })

  it('asks the current password once the e-mail differs, and drops the field when it goes back', () => {
    render(<AccountSection name="A" email="a@x.com" onUpdate={vi.fn()} />)

    typeEmail('new@x.com')
    const field = document.getElementById('profile-current-password') as HTMLInputElement
    expect(field.type).toBe('password')
    expect(field.autocomplete).toBe('current-password')
    expect(screen.getByText('Enter your current password to change your email address.')).toBeTruthy()

    typeEmail('a@x.com')
    expect(document.getElementById('profile-current-password')).toBeNull()
  })

  it('never asks it of a locked e-mail', () => {
    render(<AccountSection name="A" email="a@x.com" onUpdate={vi.fn()} emailReadOnly />)

    expect(document.getElementById('profile-current-password')).toBeNull()
  })

  it('sends the current password with a new e-mail, and none with the same one', async () => {
    patch.mockResolvedValue({ name: 'A', email: 'a@x.com' })
    render(<AccountSection name="A" email="a@x.com" onUpdate={vi.fn()} />)

    fireEvent.submit(document.querySelector('form')!)
    await waitFor(() => expect(patch).toHaveBeenCalledTimes(1))
    expect(patch).toHaveBeenLastCalledWith('/api/profile', { name: 'A', email: 'a@x.com' })

    typeEmail('new@x.com')
    fireEvent.change(document.getElementById('profile-current-password')!, { target: { value: 'secret' } })
    fireEvent.submit(document.querySelector('form')!)
    await waitFor(() => expect(patch).toHaveBeenCalledTimes(2))
    expect(patch).toHaveBeenLastCalledWith('/api/profile', { name: 'A', email: 'new@x.com', current_password: 'secret' })
  })

  it('shows the pending state when the server keeps the address and mails the link', async () => {
    patch.mockResolvedValue({ name: 'A', email: 'a@x.com', pending_email: 'new@x.com' })
    const onUpdate = vi.fn()
    render(<AccountSection name="A" email="a@x.com" onUpdate={onUpdate} />)

    typeEmail('new@x.com')
    fireEvent.change(document.getElementById('profile-current-password')!, { target: { value: 'secret' } })
    fireEvent.submit(document.querySelector('form')!)

    const notice = await screen.findByTestId('email-change-pending')
    expect(notice.textContent).toBe('We sent a confirmation link to new@x.com. Your email address changes when you follow it.')
    // The field is back on the current address, the password is gone, the person was told.
    expect((document.getElementById('profile-email') as HTMLInputElement).value).toBe('a@x.com')
    expect(document.getElementById('profile-current-password')).toBeNull()
    expect(addToast).toHaveBeenCalledWith('success', 'Check your inbox: we sent a confirmation link to new@x.com.')
    expect(onUpdate).toHaveBeenCalledWith({ name: 'A', email: 'a@x.com', pending_email: 'new@x.com' })
  })

  it('shows a wrong current password under its field and keeps the e-mail typed', async () => {
    patch.mockRejectedValue(
      new MockApiError(422, 'The password is incorrect.', [
        { field: 'current_password', message: 'The password is incorrect.', code: 'invalid' },
      ]),
    )
    render(<AccountSection name="A" email="a@x.com" onUpdate={vi.fn()} />)

    typeEmail('new@x.com')
    fireEvent.change(document.getElementById('profile-current-password')!, { target: { value: 'nope' } })
    fireEvent.submit(document.querySelector('form')!)

    expect(await screen.findByText('The password is incorrect.')).toBeTruthy()
    expect((document.getElementById('profile-email') as HTMLInputElement).value).toBe('new@x.com')
    expect(screen.queryByTestId('email-change-pending')).toBeNull()
  })
})
