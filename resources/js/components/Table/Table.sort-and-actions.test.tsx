import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import type { FieldDefinition, ResourceRecord } from '@/types'
import type { ActionMeta } from '@/components/Actions'
import { Table } from '@/components/Table'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

/*
 * The table shows the active sort direction (the rows' order) from `sortDir`, and disables a destructive inline action on a
 * row whose record policy forbids destructive runs.
 */

registerDefaultFields()

const titleField = {
  attribute: 'title', label: 'Title', type: 'text',
  nullable: false, readonly: false, required: false, sortable: true, searchable: false,
  showOnIndex: true, showOnDetail: true, showOnForms: true, rules: [], reserved: [],
} as unknown as FieldDefinition

const purge = {
  uriKey: 'purge', name: 'Purge', standalone: false, destructive: true, showInline: true, group: null,
} as unknown as ActionMeta

function renderTable(props: { sortDir?: 'asc' | 'desc'; rows?: Array<Record<string, unknown>>; inlineActions?: ActionMeta[] }) {
  render(
    <MemoryRouter>
      <Table
        columns={[{ field: titleField }]}
        rows={(props.rows ?? [{ id: 1, title: 'Alpha' }]) as unknown as ResourceRecord[]}
        sortBy="title"
        sortDir={props.sortDir ?? 'asc'}
        onSort={vi.fn()}
        selectedIds={new Set()}
        onToggleSelect={vi.fn()}
        onToggleAll={vi.fn()}
        resourceKey="posts"
        selectable={false}
        inlineActions={props.inlineActions}
      />
    </MemoryRouter>,
  )
}

const sortRows = [{ id: 1, title: 'Bravo' }, { id: 2, title: 'Alpha' }, { id: 3, title: 'Charlie' }]
const renderedOrder = () => screen.getAllByText(/^(Alpha|Bravo|Charlie)$/).map((el) => el.textContent)

describe('Table sort direction', () => {
  it('orders the rows ascending for sortDir asc', () => {
    renderTable({ sortDir: 'asc', rows: sortRows })

    expect(renderedOrder()).toEqual(['Alpha', 'Bravo', 'Charlie'])
  })

  it('orders the rows descending for sortDir desc', () => {
    renderTable({ sortDir: 'desc', rows: sortRows })

    expect(renderedOrder()).toEqual(['Charlie', 'Bravo', 'Alpha'])
  })
})

describe('Table destructive inline action', () => {
  it('is disabled when the record forbids destructive runs even if plain runs are allowed', () => {
    renderTable({
      rows: [{ id: 1, title: 'Alpha', _authorization: { authorizedToRunAction: true, authorizedToRunDestructiveAction: false } }],
      inlineActions: [purge],
    })

    expect((screen.getByRole('button', { name: /Purge/ }) as HTMLButtonElement).disabled).toBe(true)
  })

  it('is enabled when the record allows destructive runs', () => {
    renderTable({
      rows: [{ id: 1, title: 'Alpha', _authorization: { authorizedToRunAction: false, authorizedToRunDestructiveAction: true } }],
      inlineActions: [purge],
    })

    expect((screen.getByRole('button', { name: /Purge/ }) as HTMLButtonElement).disabled).toBe(false)
  })
})
