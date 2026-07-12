import { describe, it, expect, vi } from 'vitest'
import { render } from '@testing-library/react'
import { AccountSection } from './AccountSection'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (_k: string, o?: { defaultValue?: string }) => o?.defaultValue ?? _k }),
}))
vi.mock('@/lib/api', () => ({ api: { patch: vi.fn() }, ApiError: class extends Error {} }))
vi.mock('@/contexts/ToastContext', () => ({ useToast: () => ({ addToast: vi.fn() }) }))

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
