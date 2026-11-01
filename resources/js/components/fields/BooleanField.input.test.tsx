import { describe, it, expect, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { BooleanFieldInput } from './BooleanField'

/*
 * The Boolean switch hands the form the state the user toggled to. No test
 * rendered the input, so a switch that reported the opposite value (saving
 * "off" for an "on" toggle) failed nothing.
 */

const field = {
  attribute: 'active', label: 'Active', type: 'boolean',
  nullable: false, readonly: false, required: false, sortable: false, searchable: false,
  showOnIndex: true, showOnDetail: true, showOnForms: true, rules: [],
} as unknown as FieldDefinition

describe('BooleanFieldInput', () => {
  it('reports true when an unchecked switch is toggled on', () => {
    const onChange = vi.fn()
    render(<BooleanFieldInput field={field} value={false} onChange={onChange} />)

    fireEvent.click(screen.getByRole('switch'))

    expect(onChange).toHaveBeenCalledTimes(1)
    expect(onChange).toHaveBeenCalledWith(true)
  })

  it('reports false when a checked switch is toggled off', () => {
    const onChange = vi.fn()
    render(<BooleanFieldInput field={field} value={true} onChange={onChange} />)

    fireEvent.click(screen.getByRole('switch'))

    expect(onChange).toHaveBeenCalledTimes(1)
    expect(onChange).toHaveBeenCalledWith(false)
  })
})
