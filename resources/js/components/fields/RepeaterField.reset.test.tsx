import { describe, it, expect } from 'vitest'
import { useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { RepeaterFieldInput } from './RepeaterField'
import { registerDefaultFields } from './FieldRenderer'

/*
 * An update builds on the rows the Repeater emitted last until the form hands
 * it another value. The Repeater told the two apart by comparing the value
 * with the one its last emission started from, so a form that went back to
 * that same value looked like one that had not handed anything back yet: an
 * empty Repeater that emitted once (Add row, a template, a bulk paste) and was
 * then cleared to `null` again ("Create & add another" clears the form for the
 * next record) built the next update on the previous record's rows, and the
 * next record was saved with them.
 */

registerDefaultFields()

const field = {
  attribute: 'lines', label: 'Lines', type: 'repeater',
  nullable: true, readonly: false, required: false, sortable: false,
  searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
  rules: [], storage: 'json',
  repeatables: [
    {
      shortName: 'line', uniqueKey: 'line', label: 'Line',
      fields: [{ attribute: 'name', label: 'Name', type: 'text', rules: [] }],
    },
  ],
} as unknown as FieldDefinition

// A form that reads the Repeater's value as `values[attr] ?? null`, as
// useMartisForm does, and clears every value the way "Create & add another"
// does.
function Form() {
  const [values, setValues] = useState<Record<string, unknown>>({})
  return (
    <>
      <RepeaterFieldInput
        field={field}
        value={values.lines ?? null}
        onChange={(v) => setValues((prev) => ({ ...prev, lines: v }))}
      />
      <button type="button" onClick={() => setValues({})}>Clear form</button>
      <output data-testid="stored">{JSON.stringify(values.lines ?? null)}</output>
    </>
  )
}

type StoredRow = { id: string; type: string; fields: Record<string, unknown> }

const storedRows = (): StoredRow[] | null => JSON.parse(screen.getByTestId('stored').textContent ?? 'null')

describe('RepeaterFieldInput after the form is cleared back to empty', () => {
  it('adds a fresh row instead of bringing back the previous record\'s row', () => {
    render(<Form />)
    fireEvent.click(screen.getByRole('button', { name: 'Add Line' }))
    const [previous] = storedRows()!

    fireEvent.click(screen.getByRole('button', { name: 'Clear form' }))
    expect(storedRows()).toBeNull()
    fireEvent.click(screen.getByRole('button', { name: 'Add Line' }))

    const rows = storedRows()!
    expect(rows).toHaveLength(1)
    expect(rows[0].id).not.toBe(previous.id)
  })

  it('pastes rows onto the cleared form alone', () => {
    render(<Form />)
    fireEvent.click(screen.getByRole('button', { name: 'Add Line' }))

    fireEvent.click(screen.getByRole('button', { name: 'Clear form' }))
    fireEvent.click(screen.getByRole('button', { name: 'Paste rows' }))
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'name\nPasted line' } })
    fireEvent.click(screen.getByRole('button', { name: 'Import' }))

    expect(storedRows()?.map((row) => row.fields.name)).toEqual(['Pasted line'])
  })

  it('builds on the rows of the previous record until the form is cleared', () => {
    render(<Form />)
    fireEvent.click(screen.getByRole('button', { name: 'Add Line' }))
    fireEvent.click(screen.getByRole('button', { name: 'Add Line' }))

    expect(storedRows()).toHaveLength(2)
  })
})
