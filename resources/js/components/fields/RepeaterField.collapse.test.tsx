import { describe, it, expect, vi } from 'vitest'
import { useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { RepeaterFieldInput } from './RepeaterField'
import { registerDefaultFields } from './FieldRenderer'

/*
 * `collapsedByDefault()` starts every row collapsed. The collapsed set was
 * built once, from the rows the input mounted with, so a form that hands the
 * stored rows in after mount (the edit form seeding the record) showed every
 * stored row expanded. Rows that arrive from outside start collapsed; the
 * rows the user opens, collapses or adds keep their state when the form hands
 * back what the input emitted, including a value a row's own field emits
 * while it mounts (a slug generated from a default), which reaches the
 * Repeater before the Repeater's effects run.
 *
 * Real field inputs render the rows: a mocked child never emits.
 */

registerDefaultFields()

function repeaterField(collapsedByDefault: boolean): FieldDefinition {
  return {
    attribute: 'lines', label: 'Lines', type: 'repeater',
    nullable: true, readonly: false, required: false, sortable: false,
    searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
    rules: [], storage: 'json', collapsible: true, collapsedByDefault,
    repeatables: [
      {
        shortName: 'line', uniqueKey: 'line', label: 'Line',
        fields: [
          { attribute: 'name', label: 'Name', type: 'text', defaultValue: 'Item', rules: [] },
          { attribute: 'slug', label: 'Slug', type: 'slug', sourceAttribute: 'name', separator: '-', rules: [], reserved: [] },
        ],
      },
    ],
  } as unknown as FieldDefinition
}

const stored = [
  { id: 'a', type: 'line', fields: { name: 'first', slug: 'first' } },
  { id: 'b', type: 'line', fields: { name: 'second', slug: 'second' } },
]

// A form that stores what the input emits, as the edit and create forms do.
function Form({ field, initial }: { field: FieldDefinition; initial: unknown }) {
  const [value, setValue] = useState<unknown>(initial)
  return <RepeaterFieldInput field={field} value={value} onChange={setValue} />
}

const openRows = () => document.querySelectorAll('[data-testid^="slug-input-"]').length
const rowHeaders = () => screen.getAllByText('Line').length

describe('RepeaterFieldInput collapsedByDefault', () => {
  it('collapses the stored rows handed in after mount (the edit form hydrating the record)', () => {
    const field = repeaterField(true)
    const { rerender } = render(<RepeaterFieldInput field={field} value={null} onChange={() => {}} />)

    rerender(<RepeaterFieldInput field={field} value={stored} onChange={() => {}} />)

    expect(rowHeaders()).toBe(2)
    expect(openRows()).toBe(0)
  })

  it('keeps the rows the user opened or added open when the form hands back what it emitted', () => {
    let current: unknown = stored
    const onChange = vi.fn((next: unknown) => {
      current = next
    })
    const field = repeaterField(true)
    const { container, rerender } = render(<RepeaterFieldInput field={field} value={current} onChange={onChange} />)
    expect(openRows()).toBe(0)

    fireEvent.click(container.querySelector('[data-pr-tooltip="Expand"]') as HTMLElement)
    fireEvent.click(screen.getByRole('button', { name: 'Add Line' }))
    rerender(<RepeaterFieldInput field={field} value={current} onChange={onChange} />)

    expect(rowHeaders()).toBe(3)
    expect(openRows()).toBe(2)
  })

  it('keeps an opened row open when an added row fills its own field in while it mounts', () => {
    const { container } = render(<Form field={repeaterField(true)} initial={stored} />)
    fireEvent.click(container.querySelector('[data-pr-tooltip="Expand"]') as HTMLElement)
    expect(openRows()).toBe(1)

    // The new row's slug generates itself from the default name on mount.
    fireEvent.click(screen.getByRole('button', { name: 'Add Line' }))

    expect(screen.getByDisplayValue('item')).toBeTruthy()
    expect(openRows()).toBe(2)
  })

  it('keeps a row the user collapsed collapsed when an added row fills its own field in', () => {
    const { container } = render(<Form field={repeaterField(false)} initial={stored} />)
    expect(openRows()).toBe(2)
    fireEvent.click(container.querySelector('[data-pr-tooltip="Collapse"]') as HTMLElement)
    expect(openRows()).toBe(1)

    fireEvent.click(screen.getByRole('button', { name: 'Add Line' }))

    expect(screen.getByDisplayValue('item')).toBeTruthy()
    expect(openRows()).toBe(2)
  })
})
