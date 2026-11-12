import { describe, it, expect, vi } from 'vitest'
import { render } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import type { FieldDefinition, ResourceRecord } from '@/types'
import { Table } from '@/components/Table'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

/*
 * `Field::textAlign()`, `Field::hideBelow()` and `Resource::stickyActions()`
 * reach the table as column metadata and table config: alignment on the
 * header and the cells (the sort icon stays beside the label), a responsive
 * hide class on both, and a sticky actions column.
 */

registerDefaultFields()

function field(attribute: string, label: string, column: Record<string, unknown> = {}, sortable = false): FieldDefinition {
  return {
    attribute, label, type: 'text',
    nullable: true, readonly: false, required: false, sortable, searchable: false,
    showOnIndex: true, showOnDetail: true, showOnForms: true, rules: [], reserved: [],
    column,
  } as unknown as FieldDefinition
}

function renderTable(columns: FieldDefinition[], tableConfig?: Record<string, unknown>) {
  return render(
    <MemoryRouter>
      <Table
        columns={columns.map((f) => ({ field: f }))}
        rows={[{ id: 1, name: 'Ann', amount: '1,200', note: 'x' }] as unknown as ResourceRecord[]}
        sort={[]}
        onSort={vi.fn()}
        selectedIds={new Set()}
        onToggleSelect={vi.fn()}
        onToggleAll={vi.fn()}
        resourceKey="employees"
        selectable={false}
        tableConfig={tableConfig}
        onDefaultView={vi.fn()}
        defaultRowActions={{ enabled: true, view: true, edit: false, delete: false }}
      />
    </MemoryRouter>,
  )
}

describe('Table column options', () => {
  it('leaves a column without options untouched', () => {
    const { container } = renderTable([field('name', 'Name')])
    const th = container.querySelector('thead th')!
    const td = container.querySelector('tbody td')!
    expect((th as HTMLElement).style.textAlign).toBe('')
    expect((td as HTMLElement).style.textAlign).toBe('')
    expect(container.querySelector('[class*="martis-col-hide"]')).toBeNull()
    expect(container.querySelector('[class*="martis-col-align"]')).toBeNull()
  })

  it('aligns header and cells, and marks a right-aligned column for tabular digits', () => {
    const { container } = renderTable([field('amount', 'Amount', { textAlign: 'right' })])
    const th = container.querySelector('thead th') as HTMLElement
    const td = container.querySelector('tbody td') as HTMLElement
    expect(th.style.textAlign).toBe('right')
    expect(td.style.textAlign).toBe('right')
    expect(td.classList.contains('martis-col-align-right')).toBe(true)
  })

  it('moves the header content of a non-sortable column, not only its text-align', () => {
    // PrimeReact draws the header in a flex box (`.p-column-header-content`),
    // which `text-align` on the th does not move: the header must carry the
    // class its `justify-content` rule is keyed on (the rule itself is pinned
    // in tableColumnCss.test.ts; jsdom cannot read PrimeReact's `@layer`).
    const { container } = renderTable([
      field('amount', 'Amount', { textAlign: 'right' }),
      field('note', 'Note', { textAlign: 'center' }),
      field('name', 'Name'),
    ])
    const ths = container.querySelectorAll('thead th')
    expect(ths[0].classList.contains('p-align-right')).toBe(true)
    expect(ths[1].classList.contains('p-align-center')).toBe(true)
    expect(ths[2].className).not.toMatch(/p-align-/)
  })

  it('keeps the sort icon beside the label of an aligned sortable column', () => {
    const { container } = renderTable([field('amount', 'Amount', { textAlign: 'right' }, true)])
    const button = container.querySelector('thead th button') as HTMLElement
    expect(button.style.justifyContent).toBe('flex-end')
    expect(button.querySelector('svg')).toBeTruthy()
  })

  it('adds the hideBelow class to the header and the cells', () => {
    const { container } = renderTable([field('name', 'Name'), field('note', 'Note', { hideBelow: 'md' })])
    const ths = container.querySelectorAll('thead th')
    const tds = container.querySelectorAll('tbody td')
    expect(ths[0].classList.contains('martis-col-hide-md')).toBe(false)
    expect(ths[1].classList.contains('martis-col-hide-md')).toBe(true)
    expect(tds[1].classList.contains('martis-col-hide-md')).toBe(true)
  })

  it('keeps truncation next to the column classes', () => {
    const { container } = renderTable([field('note', 'Note', { truncate: true, textAlign: 'center' })])
    const td = container.querySelector('tbody td')!
    expect(td.classList.contains('martis-col-truncate')).toBe(true)
    expect(td.classList.contains('martis-col-align-center')).toBe(true)
  })

  it('marks the table and the actions column when actions are sticky', () => {
    const { container } = renderTable([field('name', 'Name')], { stickyActions: true })
    expect(container.querySelector('.martis-datatable-sticky-actions')).toBeTruthy()
    expect(container.querySelector('thead th.martis-actions-col')).toBeTruthy()
    expect(container.querySelector('tbody td.martis-actions-col')).toBeTruthy()
  })

  it('does not pin the actions column by default', () => {
    const { container } = renderTable([field('name', 'Name')])
    expect(container.querySelector('.martis-datatable-sticky-actions')).toBeNull()
  })
})
