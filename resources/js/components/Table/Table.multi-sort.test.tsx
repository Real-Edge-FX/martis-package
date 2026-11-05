import { describe, it, expect, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import type { FieldDefinition, ResourceRecord } from '@/types'
import { Table } from '@/components/Table'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'
import type { SortKey } from '@/lib/multiSort'

/*
 * The header of a sortable column: a click and a Shift+click (or Shift+Enter) tell the page whether the column is added to
 * the sort; with more than one key each sorted header shows its place, and only the first carries `aria-sort`.
 */

registerDefaultFields()

const field = (attribute: string, label: string, sortable = true) => ({
  attribute, label, type: 'text',
  nullable: false, readonly: false, required: false, sortable, searchable: false,
  showOnIndex: true, showOnDetail: true, showOnForms: true, rules: [], reserved: [],
}) as unknown as FieldDefinition

const columns = [
  { field: field('category', 'Category') },
  { field: field('position', 'Position') },
  { field: field('locale', 'Locale') },
  { field: field('note', 'Note', false) },
]

function renderTable(sort: SortKey[], onSort = vi.fn()) {
  render(
    <MemoryRouter>
      <Table
        columns={columns}
        rows={[{ id: 1, category: 'a', position: 1, locale: 'pt', note: 'n' }] as unknown as ResourceRecord[]}
        sort={sort}
        onSort={onSort}
        selectedIds={new Set()}
        onToggleSelect={vi.fn()}
        onToggleAll={vi.fn()}
        resourceKey="items"
        selectable={false}
      />
    </MemoryRouter>,
  )
  return onSort
}

describe('Table sortable header', () => {
  it('reports a plain click as not additive', () => {
    const onSort = renderTable([])

    fireEvent.click(screen.getByRole('button', { name: /Category/ }))

    expect(onSort).toHaveBeenCalledWith('category', false)
  })

  it('reports Shift+click and Shift+Enter as additive', () => {
    const onSort = renderTable([{ attribute: 'category', direction: 'asc' }])

    fireEvent.click(screen.getByRole('button', { name: /Position/ }), { shiftKey: true })
    fireEvent.keyDown(screen.getByRole('button', { name: /Locale/ }), { key: 'Enter', shiftKey: true })

    expect(onSort).toHaveBeenNthCalledWith(1, 'position', true)
    expect(onSort).toHaveBeenNthCalledWith(2, 'locale', true)
  })

  it('draws no header button for a column that is not sortable', () => {
    renderTable([])

    expect(screen.queryByRole('button', { name: /Note/ })).toBeNull()
  })
})

describe('Table order badges and aria-sort', () => {
  it('shows no badge for a single key', () => {
    renderTable([{ attribute: 'category', direction: 'asc' }])

    expect(document.querySelector('.martis-th-sort-order')).toBeNull()
  })

  it('numbers the sorted headers when there are several keys', () => {
    renderTable([
      { attribute: 'locale', direction: 'desc' },
      { attribute: 'category', direction: 'asc' },
    ])

    const badges = Array.from(document.querySelectorAll('.martis-th-sort-order')).map((el) => ({
      text: el.textContent,
      header: el.closest('th')?.textContent,
    }))
    // In column order: Category (second key), then Locale (first key).
    expect(badges).toHaveLength(2)
    expect(badges[0].header).toContain('Category')
    expect(badges[0].text).toBe('2')
    expect(badges[1].header).toContain('Locale')
    expect(badges[1].text).toBe('1')
  })

  it('puts aria-sort on the first key only and says the others place in hidden text', () => {
    renderTable([
      { attribute: 'locale', direction: 'desc' },
      { attribute: 'category', direction: 'asc' },
      { attribute: 'position', direction: 'asc' },
    ])

    const header = (name: RegExp) => screen.getByRole('columnheader', { name })
    expect(header(/Locale/).getAttribute('aria-sort')).toBe('descending')
    expect(header(/Category/).hasAttribute('aria-sort')).toBe(false)
    expect(header(/Position/).hasAttribute('aria-sort')).toBe(false)
    expect(header(/Category/).querySelector('.sr-only')?.textContent).toBe('sort 2 of 3, ascending')
    expect(header(/Position/).querySelector('.sr-only')?.textContent).toBe('sort 3 of 3, ascending')
    expect(header(/Locale/).querySelector('.sr-only')).toBeNull()
  })
})
