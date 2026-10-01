import { describe, expect, it } from 'vitest'
import { render } from '@testing-library/react'
import { PasswordFieldInput } from './PasswordField'
import { PasswordConfirmationFieldInput } from './PasswordConfirmationField'
import type { FieldDefinition } from '@/types'
import type { PasswordRequirements } from '@/lib/config'

function field(requirements: PasswordRequirements): FieldDefinition {
  return {
    attribute: 'password',
    label: 'Password',
    type: 'password',
    nullable: false,
    readonly: false,
    required: true,
    showRequirements: true,
    requirements,
  } as unknown as FieldDefinition
}

function rows(value: string, requirements: PasswordRequirements): Record<string, string> {
  const view = render(<PasswordFieldInput field={field(requirements)} value={value} onChange={() => {}} />)
  const result: Record<string, string> = {}
  view.container.querySelectorAll('[data-requirement]').forEach((row) => {
    result[row.getAttribute('data-requirement')!] = row.getAttribute('data-passes')!
  })
  view.unmount()
  return result
}

describe('PasswordFieldInput requirements', () => {
  it('checks Unicode letters, numbers and symbols as the server does', () => {
    expect(rows('éclairÉ', { uppercase: true, lowercase: true })).toEqual({ uppercase: 'true', lowercase: 'true' })
    expect(rows('abc٣', { number: true })).toEqual({ number: 'true' })
    expect(rows('éclair', { symbol: true })).toEqual({ symbol: 'false' })
    expect(rows('éclair€', { symbol: true, letters: true })).toEqual({ symbol: 'true', letters: 'true' })
  })

  it('counts characters, not UTF-16 units, for the length rows', () => {
    // Four emoji: four characters, eight UTF-16 units.
    expect(rows('😀😀😀😀', { minLength: 5, maxLength: 4 })).toEqual({ minLength: 'false', maxLength: 'true' })
  })

  it('shows the server-only row without a verdict, and never as a failure', () => {
    const { container } = render(<PasswordFieldInput field={field({ minLength: 2, uncompromised: true })} value="ok" onChange={() => {}} error="The password has appeared in a data leak." />)

    expect(container.querySelector('[data-requirement="uncompromised"]')!.getAttribute('data-passes')).toBe('server')
    // Every checkable row passes, so the server's message shows.
    expect(container.textContent).toContain('The password has appeared in a data leak.')
  })

  it('updates as the user types', () => {
    const { container, rerender } = render(<PasswordFieldInput field={field({ minLength: 3 })} value="a" onChange={() => {}} />)
    expect(container.querySelector('[data-requirement="minLength"]')!.getAttribute('data-passes')).toBe('false')
    rerender(<PasswordFieldInput field={field({ minLength: 3 })} value="abc" onChange={() => {}} />)
    expect(container.querySelector('[data-requirement="minLength"]')!.getAttribute('data-passes')).toBe('true')
  })
})

// The set-password pages pass `announceError`, `autoFocus` and `disabled`
// (v2.3.0); a resource form passes none, and keeps what it had.
describe('the password inputs in a resource form', () => {
  it('shows the error as a plain line, behind a failing checklist row', () => {
    const shown = render(<PasswordFieldInput field={field({ minLength: 2 })} value="ok" onChange={() => {}} error="Taken." />)
    expect(shown.container.querySelector('small')!.textContent).toBe('Taken.')
    expect(shown.queryByRole('alert')).toBeNull()
    shown.unmount()

    const hidden = render(<PasswordFieldInput field={field({ minLength: 12 })} value="short" onChange={() => {}} error="Too short." />)
    expect(hidden.container.textContent).not.toContain('Too short.')
    hidden.unmount()
  })

  it('hides the confirmation error behind the mismatch indicator', () => {
    const confirm = { attribute: 'password_confirmation', type: 'password_confirmation', confirms: 'password', nullable: true, readonly: false, required: true } as unknown as FieldDefinition
    const { container, queryByRole } = render(<PasswordConfirmationFieldInput field={confirm} value="a" formValues={{ password: 'b' }} onChange={() => {}} error="Does not match." />)

    expect(container.textContent).not.toContain('Does not match.')
    expect(queryByRole('alert')).toBeNull()
  })

  it('disables a readonly field, and only it', () => {
    const editable = render(<PasswordFieldInput field={field({})} value="" onChange={() => {}} />)
    expect((editable.container.querySelector('input') as HTMLInputElement).disabled).toBe(false)
    editable.unmount()

    const readonly = render(<PasswordFieldInput field={{ ...field({}), readonly: true } as FieldDefinition} value="" onChange={() => {}} />)
    expect((readonly.container.querySelector('input') as HTMLInputElement).disabled).toBe(true)
  })
})
