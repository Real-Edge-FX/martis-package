import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import type { FieldDefinition, PanelDefinition, ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * A field `canSeeForModel()` hides for a record is left out of that
 * record's values, and the record lists it under `_hidden` (v1.38.0). The
 * schema describes the resource, so the detail page drops those fields from
 * the lists it renders instead of showing them empty: a hidden Boolean read
 * "No", a panel of hidden fields showed its title over nothing.
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGetMock(...args),
    },
  }
})

import { ResourceDetailPage } from '@/pages/ResourceDetail'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()

function field(attribute: string, label: string, type = 'text'): FieldDefinition {
  return {
    attribute, label, type,
    nullable: true, readonly: false, required: false, sortable: false, searchable: false,
    showOnIndex: true, showOnDetail: true, showOnForms: true, rules: [], reserved: [],
  } as unknown as FieldDefinition
}

function panel(title: string, fields: FieldDefinition[]): PanelDefinition {
  return { type: 'panel', title, description: null, fields, collapsible: false, collapsedByDefault: false, limit: null }
}

const employees = {
  uriKey: 'employees',
  label: 'Employees',
  singularLabel: 'Employee',
  softDeletes: false,
  fields: [],
  fieldsForDetail: [
    field('name', 'Name'),
    field('salary', 'Salary'),
    field('active', 'Active', 'boolean'),
    panel('Pay', [field('bonus', 'Bonus')]),
    panel('Career', [field('grade', 'Grade'), field('bonus_note', 'Bonus note')]),
  ],
  detailSidebar: [field('owner', 'Owner')],
  actions: [],
  messages: {},
} as unknown as ResourceSchema

const HIDDEN = ['salary', 'active', 'bonus', 'bonus_note', 'owner']

function renderDetail(record: Record<string, unknown>) {
  apiGetMock.mockImplementation((path: string) => {
    if (path === '/api/resources/employees/schema') return Promise.resolve({ data: employees })
    if (path === '/api/resources/employees/1') return Promise.resolve({ data: record })
    return Promise.resolve({ data: [] })
  })
  const router = createMemoryRouter(
    [{ path: '/resources/:resource/:id', element: <ResourceDetailPage /> }],
    { initialEntries: ['/resources/employees/1'] },
  )
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  apiGetMock.mockReset()
})

describe('ResourceDetailPage — the fields a record hides', () => {
  it('leaves out the fields the record hides, and a panel left without fields', async () => {
    renderDetail({ id: 1, _title: 'Ann', name: 'Ann', grade: 'B', _hidden: HIDDEN })

    await screen.findByRole('heading', { name: 'Ann' })

    expect(screen.getByText('Name')).toBeTruthy()
    expect(screen.getByText('Career')).toBeTruthy()
    expect(screen.getByText('Grade')).toBeTruthy()
    for (const label of ['Salary', 'Active', 'No', 'Pay', 'Bonus', 'Bonus note', 'Owner']) {
      expect(screen.queryByText(label)).toBeNull()
    }
  })

  it('renders every field of a record that hides none', async () => {
    renderDetail({ id: 1, _title: 'Ann', name: 'Ann', salary: '100', active: false, bonus: '10', grade: 'B', bonus_note: 'Q3', owner: 'Bo' })

    await screen.findByRole('heading', { name: 'Ann' })

    for (const label of ['Salary', 'Active', 'Pay', 'Bonus', 'Bonus note', 'Owner']) {
      expect(screen.getByText(label)).toBeTruthy()
    }
  })
})
