import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import type { FieldDefinition } from '@/types'

/*
 * The relationship panels render the related resource's field lists against
 * each related record, which lists under `_hidden` the fields
 * `canSeeForModel()` hides for it (v1.38.0). A row of a panel's table leaves
 * the cell of such a field empty, and the card of a HasOne / MorphOne leaves
 * the field out, instead of rendering its display of an empty value (a
 * hidden Boolean read "No").
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args) },
  }
})

import { RelationshipTableShell } from './relation/RelationshipTableShell'
import { HasOneFieldDisplay } from './HasOneField'
import { MorphOneFieldDisplay } from './MorphOneField'
import { NestedParentProvider } from './NestedParentContext'
import { registerDefaultFields } from './FieldRenderer'

registerDefaultFields()

function field(attribute: string, label: string, type = 'text', extra: Record<string, unknown> = {}): FieldDefinition {
  return {
    attribute, label, type,
    nullable: true, readonly: false, required: false, sortable: false, searchable: false,
    showOnIndex: true, showOnDetail: true, showOnForms: true, rules: [], reserved: [],
    ...extra,
  } as unknown as FieldDefinition
}

const employeeFields = [field('name', 'Name'), field('active', 'Active', 'boolean')]

function mockApi(records: Array<Record<string, unknown>>) {
  apiGetMock.mockImplementation((path: string) => {
    if (path === '/api/resources/employees/schema') {
      return Promise.resolve({ data: { fieldsForIndex: employeeFields, fieldsForDetail: employeeFields, singularLabel: 'Employee', softDeletes: false } })
    }
    if (path.includes('/has-one/') || path.includes('/morph-one/')) return Promise.resolve({ data: records[0] })
    return Promise.resolve({ data: records, meta: { total: records.length, current_page: 1, last_page: 1, per_page: 10 } })
  })
}

function renderWithProviders(node: React.ReactNode) {
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>
        <NestedParentProvider value={{ resource: 'teams', id: 1 }}>{node}</NestedParentProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  apiGetMock.mockReset()
})

describe('relationship panels — the fields a related record hides', () => {
  it('leaves the cell of a field a row hides empty in a panel table', async () => {
    mockApi([
      { id: 1, name: 'Ann', active: true },
      { id: 2, name: 'Bo', _hidden: ['active'] },
    ])

    renderWithProviders(
      <RelationshipTableShell
        title="Members"
        relatedResource="employees"
        queryKey={['has-many', 'teams', 1, 'members']}
        fetchUrl={() => '/api/resources/teams/1/has-many/members'}
        perPage={10}
        perPageOptions={[10]}
        searchable={false}
        canCreate={false}
        canUpdate={false}
        canDelete={false}
      />,
    )

    expect(await screen.findByText('Bo')).toBeTruthy()
    expect(screen.getAllByText('Yes')).toHaveLength(1)
    expect(screen.queryByText('No')).toBeNull()
  })

  it.each([
    ['HasOne', HasOneFieldDisplay, 'has_one'],
    ['MorphOne', MorphOneFieldDisplay, 'morph_one'],
  ] as const)('leaves a field the record hides out of the %s card', async (_name, Display, type) => {
    mockApi([{ id: 7, _title: 'Ann', name: 'Ann', _hidden: ['active'] }])

    renderWithProviders(
      <Display
        field={field('lead', 'Lead', type, { relationship: 'lead', relatedResource: 'employees' })}
        value={null}
      />,
    )

    expect(await screen.findByText('Name')).toBeTruthy()
    expect(screen.queryByText('Active')).toBeNull()
    expect(screen.queryByText('No')).toBeNull()
  })
})
