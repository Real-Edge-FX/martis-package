import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import type { FieldDefinition, ResourceRecord } from '@/types'
import { Table } from '@/components/Table'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

/*
 * The index and a lens render a column for each index field, and a row that
 * hides a field (`canSeeForModel()`, listed under the record's `_hidden`,
 * v1.38.0) has no value for it. Its cell stays empty instead of rendering the
 * field's display of an empty value: a hidden Boolean read "No".
 */

registerDefaultFields()

function field(attribute: string, label: string, type = 'text'): FieldDefinition {
  return {
    attribute, label, type,
    nullable: true, readonly: false, required: false, sortable: false, searchable: false,
    showOnIndex: true, showOnDetail: true, showOnForms: true, rules: [], reserved: [],
  } as unknown as FieldDefinition
}

function renderTable(rows: Array<Record<string, unknown>>) {
  render(
    <MemoryRouter>
      <Table
        columns={[{ field: field('name', 'Name') }, { field: field('active', 'Active', 'boolean') }]}
        rows={rows as unknown as ResourceRecord[]}
        sortBy={null}
        sortDir="asc"
        onSort={vi.fn()}
        selectedIds={new Set()}
        onToggleSelect={vi.fn()}
        onToggleAll={vi.fn()}
        resourceKey="employees"
        selectable={false}
      />
    </MemoryRouter>,
  )
}

describe('Table — the fields a row hides', () => {
  it('leaves the cell of a field the row hides empty', () => {
    renderTable([
      { id: 1, name: 'Ann', active: true },
      { id: 2, name: 'Bo', _hidden: ['active'] },
    ])

    expect(screen.getByText('Bo')).toBeTruthy()
    expect(screen.getAllByText('Yes')).toHaveLength(1)
    expect(screen.queryByText('No')).toBeNull()
  })

  it('renders the cell of a field the row shows', () => {
    renderTable([{ id: 2, name: 'Bo', active: false }])

    expect(screen.getByText('No')).toBeTruthy()
  })
})
