import { describe, it, expect, vi, beforeEach } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import type { FieldDefinition } from '@/types'
import { api } from '@/lib/api'

/*
 * The pivot values of an attached record (`_pivot`) list under `_hidden` the
 * pivot fields `canSeeForModel()` hides for their pivot row (v1.38.0). The
 * panel leaves the cell of such a field empty, and the pivot edit form leaves
 * the field out, so it neither renders it empty nor sends it.
 */

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { ...actual.api, get: vi.fn(), put: vi.fn(() => new Promise(() => {})) } }
})

import { EditPivotModal } from './BelongsToManyField'
import { RelationshipTableShell } from './relation/RelationshipTableShell'
import { NestedParentProvider } from './NestedParentContext'
import { registerDefaultFields } from './FieldRenderer'

registerDefaultFields()

function field(attribute: string, label: string, type = 'text'): FieldDefinition {
  return {
    attribute, label, type,
    nullable: true, readonly: false, required: false, sortable: false, searchable: false,
    showOnIndex: true, showOnDetail: true, showOnForms: true, rules: [], reserved: [],
  } as unknown as FieldDefinition
}

const pivotFields = [field('role', 'Role'), field('rate', 'Rate'), field('shared', 'Shared', 'boolean')]

beforeEach(() => {
  vi.mocked(api.get).mockReset()
  vi.mocked(api.put).mockClear()
})

describe('pivot values — the pivot fields a pivot row hides', () => {
  it('leaves them out of the pivot edit form and of its save', async () => {
    render(
      <QueryClientProvider client={new QueryClient()}>
        <EditPivotModal
          title="Ann"
          endpoint="/api/resources/projects/1/belongs-to-many/people/3/pivot"
          pivotEndpoint="/api/resources/projects/1/belongs-to-many/people/pivot-fields/3"
          pivotFields={pivotFields}
          initialValues={{ role: 'Dev', _hidden: ['rate', 'shared'] }}
          onSuccess={vi.fn()}
          onCancel={vi.fn()}
        />
      </QueryClientProvider>,
    )

    expect(screen.getByText('Role')).toBeTruthy()
    expect(screen.queryByText('Rate')).toBeNull()
    expect(screen.queryByText('Shared')).toBeNull()

    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    await waitFor(() => expect(api.put).toHaveBeenCalled())
    expect(vi.mocked(api.put).mock.calls[0][1]).toEqual({ role: 'Dev' })
  })

  it('leaves the cell of a pivot field a pivot row hides empty', async () => {
    vi.mocked(api.get).mockImplementation((path: string) => {
      if (path === '/api/resources/people/schema') {
        return Promise.resolve({ data: { fieldsForIndex: [field('name', 'Name')], softDeletes: false } })
      }
      return Promise.resolve({
        data: [
          { id: 1, name: 'Ann', _pivot: { role: 'Dev', shared: true } },
          { id: 2, name: 'Bo', _pivot: { role: 'Lead', _hidden: ['shared'] } },
        ],
        meta: { total: 2, current_page: 1, last_page: 1, per_page: 10 },
      })
    })

    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter>
          <NestedParentProvider value={{ resource: 'projects', id: 1 }}>
            <RelationshipTableShell
              title="People"
              relatedResource="people"
              queryKey={['belongs-to-many', 'projects', 1, 'people']}
              fetchUrl={() => '/api/resources/projects/1/belongs-to-many/people'}
              perPage={10}
              perPageOptions={[10]}
              searchable={false}
              canCreate={false}
              canUpdate={false}
              canDelete={false}
              pivotFields={[field('shared', 'Shared', 'boolean')]}
            />
          </NestedParentProvider>
        </MemoryRouter>
      </QueryClientProvider>,
    )

    expect(await screen.findByText('Bo')).toBeTruthy()
    expect(screen.getAllByText('Yes')).toHaveLength(1)
    expect(screen.queryByText('No')).toBeNull()
  })
})
