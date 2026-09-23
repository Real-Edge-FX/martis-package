import { describe, it, expect, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { IconFieldInput } from './IconField'

/*
 * `IconFieldInput` returned the display-only read-out for a field that is
 * not `stored` after its translation hook but before the picker's state,
 * ref, effect and memo, so the same element rendered more hooks once the
 * field it was handed became a stored one (a `dependsOn` sync handing the
 * form a new field definition) and fewer once it stopped being one: React
 * threw ("Rendered more hooks than during the previous render") and the form
 * crashed. The hooks now run on every render and the output is the same.
 */

function makeField(stored: boolean): FieldDefinition {
  return {
    attribute: 'badge', label: 'Badge', type: 'icon', stored, palette: ['rocket', 'star'],
    nullable: true, readonly: false, required: false, sortable: false,
    searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
    rules: [],
  } as unknown as FieldDefinition
}

describe('IconFieldInput stored changes', () => {
  it('shows the picker once the field becomes stored, and the read-out once it stops being', () => {
    const onChange = vi.fn()
    const { container, rerender } = render(<IconFieldInput field={makeField(false)} value="rocket" onChange={onChange} />)
    expect(container.textContent).toContain('Display only')
    expect(screen.queryByTestId('icon-input-badge')).toBeNull()

    rerender(<IconFieldInput field={makeField(true)} value="rocket" onChange={onChange} />)
    fireEvent.click(screen.getByTestId('icon-input-badge'))
    fireEvent.click(screen.getByTestId('icon-picker-badge').querySelector('[data-icon-name="star"]')!)
    expect(onChange).toHaveBeenCalledWith('star')

    rerender(<IconFieldInput field={makeField(false)} value="star" onChange={onChange} />)
    expect(container.textContent).toContain('Display only')
    expect(screen.queryByTestId('icon-input-badge')).toBeNull()
  })

  it('shows the read-out once a stored field stops being one', () => {
    const { container, rerender } = render(<IconFieldInput field={makeField(true)} value={null} onChange={() => {}} />)
    expect(screen.getByTestId('icon-input-badge')).toBeTruthy()

    rerender(<IconFieldInput field={makeField(false)} value={null} onChange={() => {}} />)
    expect(container.textContent).toContain('Display only')
  })
})
