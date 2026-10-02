import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { RepeaterFieldInput } from './RepeaterField'
import { registerDefaultFields } from './FieldRenderer'

/*
 * Same root cause as the icon registry finding (F035): the repeater keeps its
 * per-row state (collapsed rows, server errors) in plain objects keyed by the
 * row id, and the ids are record data (a stored JSON document). `collapsed[id]`
 * and `rowErrors[id]` also find what every object inherits, so a row stored
 * with the id `constructor` rendered collapsed and read `Object` as its error
 * set, which threw while rendering the row's fields.
 */

registerDefaultFields()

const field = {
  attribute: 'lines', label: 'Lines', type: 'repeater',
  nullable: true, readonly: false, required: false, sortable: false,
  searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
  rules: [], storage: 'json', collapsible: true, collapsedByDefault: false,
  repeatables: [
    {
      shortName: 'line', uniqueKey: 'line', label: 'Line',
      fields: [{ attribute: 'name', label: 'Name', type: 'text', rules: [] }],
    },
  ],
} as unknown as FieldDefinition

const INHERITED = ['constructor', '__proto__', 'toString', 'valueOf', 'hasOwnProperty']

describe('RepeaterFieldInput with a stored row id spelled like an inherited member', () => {
  it.each(INHERITED)('renders the row %s open, with its fields', (id) => {
    const rows = [{ id, type: 'line', fields: { name: 'first' } }]

    render(<RepeaterFieldInput field={field} value={rows} onChange={() => {}} />)

    expect(screen.getByDisplayValue('first')).toBeTruthy()
  })
})
