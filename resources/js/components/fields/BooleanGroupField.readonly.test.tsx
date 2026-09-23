import { describe, it, expect, vi } from 'vitest'
import { render, fireEvent, screen } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { BooleanGroupFieldInput } from './BooleanGroupField'

/*
 * `BooleanGroup` fills through `Field::fill()`, which skips a `readonly()`
 * field, and an `immutable()` one on update, which the update forms hand to
 * the input as `readonly`. The input never read `field.readonly`: every
 * checkbox stayed live, so the form took a change the save then dropped
 * (200, column unchanged). A readonly group shows its flags with every
 * checkbox disabled; an editable one toggles as before.
 */

function makeField(readonly: boolean): FieldDefinition {
  return {
    attribute: 'permissions', label: 'Permissions', type: 'boolean_group',
    nullable: true, readonly, required: false, sortable: false,
    searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
    rules: [],
    options: { read: 'Read', write: 'Write', delete: 'Delete' },
  } as unknown as FieldDefinition
}

const stored = { read: true, write: false, delete: false }

const checkbox = (label: string) => screen.getByLabelText(label) as HTMLInputElement

describe('BooleanGroupFieldInput readonly', () => {
  it('shows every flag with its checkbox disabled', () => {
    render(<BooleanGroupFieldInput field={makeField(true)} value={stored} onChange={() => {}} />)

    expect(['Read', 'Write', 'Delete'].map((label) => checkbox(label).disabled)).toEqual([true, true, true])
    expect(['Read', 'Write', 'Delete'].map((label) => checkbox(label).checked)).toEqual([true, false, false])
  })

  it('ignores a click on a flag', () => {
    const onChange = vi.fn()
    render(<BooleanGroupFieldInput field={makeField(true)} value={stored} onChange={onChange} />)

    fireEvent.click(checkbox('Write'))
    fireEvent.click(checkbox('Read'))

    expect(onChange).not.toHaveBeenCalled()
  })
})

describe('BooleanGroupFieldInput editable', () => {
  it('toggles a flag', () => {
    const onChange = vi.fn()
    render(<BooleanGroupFieldInput field={makeField(false)} value={stored} onChange={onChange} />)

    expect(checkbox('Write').disabled).toBe(false)
    fireEvent.click(checkbox('Write'))

    expect(onChange).toHaveBeenCalledWith({ read: true, write: true, delete: false })
  })
})
