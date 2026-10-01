import { describe, expect, it } from 'vitest'
import { render } from '@testing-library/react'
import { PasswordFieldInput } from './PasswordField'
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
