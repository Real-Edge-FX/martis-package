import { describe, it, expect, vi, afterEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import type { FieldDefinition, ResourceRecord } from '@/types'
import { FieldDisplay, registerDefaultFields } from '@/components/fields/FieldRenderer'
import { PanelDisplay } from '@/components/fields/PanelRenderer'
import { Table } from '@/components/Table'
import { componentRegistry } from '@/lib/componentRegistry'
import type { FieldDisplayProps } from '@/components/fields/types'

/*
 * A custom field Display used to receive `{ field, value }` only, so it could
 * not tell which record it shows. `FieldDisplay` now hands it `resourceKey`,
 * `resourceId` and `context`, as Nova hands a custom component the resource
 * name and id.
 */

registerDefaultFields()

function Probe({ value, resourceKey, resourceId, context }: FieldDisplayProps) {
  return <span data-testid="probe">{`${String(value)}|${resourceKey}|${String(resourceId)}|${context}`}</span>
}

function field(attribute: string): FieldDefinition {
  return {
    attribute, label: attribute, type: 'record_probe',
    nullable: true, readonly: false, required: false, sortable: false, searchable: false,
    showOnIndex: true, showOnDetail: true, showOnForms: true, rules: [], reserved: [],
  } as unknown as FieldDefinition
}

componentRegistry.registerFieldDisplay('record_probe', Probe)

afterEach(() => vi.restoreAllMocks())

describe('FieldDisplay record context', () => {
  it('passes resourceKey, resourceId and context to a custom display', () => {
    render(<FieldDisplay field={field('links')} value="v" resourceKey="products" resourceId={7} context="detail" />)

    expect(screen.getByTestId('probe').textContent).toBe('v|products|7|detail')
  })

  it('reaches a display inside a panel on the detail page', () => {
    const panel = { type: 'panel', title: 'P', description: null, fields: [field('links')], collapsible: false, collapsedByDefault: false, limit: null }
    render(<PanelDisplay panel={panel as never} values={{ links: 'v' }} resourceKey="products" resourceId={7} />)

    expect(screen.getByTestId('probe').textContent).toBe('v|products|7|detail')
  })

  it('reaches a display in an index table row, with that row id', () => {
    render(
      <MemoryRouter>
        <Table
          columns={[{ field: field('links') }]}
          rows={[{ id: 4, links: 'a' }, { id: 5, links: 'b' }] as unknown as ResourceRecord[]}
          sortBy={null}
          sortDir="asc"
          onSort={vi.fn()}
          selectedIds={new Set()}
          onToggleSelect={vi.fn()}
          onToggleAll={vi.fn()}
          resourceKey="products"
          selectable={false}
        />
      </MemoryRouter>,
    )

    expect(screen.getAllByTestId('probe').map((el) => el.textContent)).toEqual(['a|products|4|index', 'b|products|5|index'])
  })
})
