import { describe, it, expect } from 'vitest'
import { useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { RepeaterFieldInput } from './RepeaterField'
import { registerDefaultFields } from './FieldRenderer'

/*
 * Each row field of a Repeater emits through the Repeater's `onChange`, and
 * every update copied the rows of the Repeater's last render. When several
 * row fields emitted before the form handed the rows back (every stored row
 * whose slug generates itself from its source while it mounts), each update
 * started from the same rows and only the last one reached the form: two
 * stored rows with empty slugs were stored as `[null, 'second']`. An update
 * now builds on the rows the Repeater emitted last, unless the form has
 * handed it other rows since (a reset), which it then builds on.
 *
 * Real field inputs render the rows: a mocked child never emits.
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
      fields: [
        { attribute: 'name', label: 'Name', type: 'text', rules: [] },
        { attribute: 'slug', label: 'Slug', type: 'slug', sourceAttribute: 'name', separator: '-', rules: [], reserved: [] },
      ],
    },
  ],
} as unknown as FieldDefinition

const stored = [
  { id: 'a', type: 'line', fields: { name: 'First', slug: null } },
  { id: 'b', type: 'line', fields: { name: 'Second', slug: null } },
]

// A form that stores what the input emits, as the edit and create forms do,
// and can replace the rows from outside, as a reset does.
function Form({ replacement }: { replacement?: unknown }) {
  const [value, setValue] = useState<unknown>(stored)
  return (
    <>
      <RepeaterFieldInput field={field} value={value} onChange={setValue} />
      <button type="button" onClick={() => setValue(replacement)}>Replace rows</button>
      <output data-testid="stored">{JSON.stringify(value)}</output>
    </>
  )
}

type StoredRow = { id: string; type: string; fields: Record<string, unknown> }

const storedRows = (): StoredRow[] => JSON.parse(screen.getByTestId('stored').textContent ?? 'null')
const storedSlugs = () => storedRows().map((row) => row.fields.slug)

describe('RepeaterFieldInput row field updates', () => {
  it('keeps the slug every stored row generates while it mounts', () => {
    render(<Form />)

    expect(storedSlugs()).toEqual(['first', 'second'])
    expect(screen.getByDisplayValue('first')).toBeTruthy()
    expect(screen.getByDisplayValue('second')).toBeTruthy()
  })

  it('keeps the generated slugs when a row field is edited afterwards', () => {
    render(<Form />)

    fireEvent.change(screen.getByDisplayValue('Second'), { target: { value: 'Second line' } })

    expect(storedRows()).toEqual([
      { id: 'a', type: 'line', fields: { name: 'First', slug: 'first' } },
      { id: 'b', type: 'line', fields: { name: 'Second line', slug: 'second-line' } },
    ])
  })

  it('builds on the rows the form hands in after its own updates', () => {
    const replacement = [{ id: 'c', type: 'line', fields: { name: 'Third', slug: 'third' } }]
    render(<Form replacement={replacement} />)
    expect(storedSlugs()).toEqual(['first', 'second'])

    fireEvent.click(screen.getByRole('button', { name: 'Replace rows' }))
    fireEvent.change(screen.getByDisplayValue('Third'), { target: { value: 'Third line' } })

    expect(storedRows()).toEqual([
      { id: 'c', type: 'line', fields: { name: 'Third line', slug: 'third' } },
    ])
  })
})
