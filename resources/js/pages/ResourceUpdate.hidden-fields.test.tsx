import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router'
import type { FieldDefinition, PanelDefinition, ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * The update page renders the resource's `fieldsForUpdate`, and the record it
 * edits (`?context=update`) lists under `_hidden` the fields
 * `canSeeForModel()` hides for it (v1.38.0). The page leaves those fields out
 * of the form, so it neither shows them empty nor sends them.
 */

const apiGetMock = vi.fn()
const apiPutMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGetMock(...args),
      put: (...args: unknown[]) => apiPutMock(...args),
    },
  }
})

import { ResourceUpdatePage } from '@/pages/ResourceUpdate'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()

function field(attribute: string, label: string, type = 'text'): FieldDefinition {
  return {
    attribute, label, type,
    nullable: true, readonly: false, required: false, sortable: false, searchable: false,
    showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [], reserved: [],
  } as unknown as FieldDefinition
}

function panel(title: string, fields: FieldDefinition[]): PanelDefinition {
  return { type: 'panel', title, description: null, fields, collapsible: false, collapsedByDefault: false, limit: null }
}

const fieldsForUpdate = [
  field('title', 'Title'),
  field('salary', 'Salary'),
  field('active', 'Active', 'boolean'),
  panel('Pay', [field('bonus', 'Bonus')]),
]

function renderUpdate(record: Record<string, unknown>) {
  const schema = {
    uriKey: 'employees', label: 'Employees', singularLabel: 'Employee', softDeletes: false,
    fields: [], fieldsForUpdate, errorDisplay: 'inline', confirmUnsavedChanges: false, messages: {},
  } as unknown as ResourceSchema
  apiGetMock.mockImplementation((path: string) => {
    if (path.includes('/schema')) return Promise.resolve({ data: schema })
    if (path === '/api/resources/employees/1?context=update') return Promise.resolve({ data: record })
    return Promise.resolve({ data: [] })
  })
  const router = createMemoryRouter(
    [{ path: '/resources/:resource/:id/edit', element: <ResourceUpdatePage /> }],
    { initialEntries: ['/resources/employees/1/edit'] },
  )
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })}>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  apiGetMock.mockReset()
  apiPutMock.mockReset()
  apiPutMock.mockReturnValue(new Promise(() => {}))
})

describe('ResourceUpdatePage — the fields a record hides', () => {
  it('leaves the fields the record hides out of the form and of the save', async () => {
    renderUpdate({ id: 1, title: 'Existing', _hidden: ['salary', 'active', 'bonus'] })

    await waitFor(() => expect((document.getElementById('title') as HTMLInputElement | null)?.value).toBe('Existing'))

    expect(document.getElementById('salary')).toBeNull()
    for (const label of ['Salary', 'Active', 'Pay', 'Bonus']) {
      expect(screen.queryByText(label)).toBeNull()
    }

    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    await waitFor(() => expect(apiPutMock).toHaveBeenCalled())
    const [, body] = apiPutMock.mock.calls[0] as [string, Record<string, unknown>]
    expect(Object.keys(body)).toEqual(['title'])
  })

  it('renders every field of a record that hides none', async () => {
    renderUpdate({ id: 1, title: 'Existing', salary: '100', active: true, bonus: '10' })

    await waitFor(() => expect((document.getElementById('salary') as HTMLInputElement | null)?.value).toBe('100'))
    expect(screen.getByText('Pay')).toBeTruthy()
  })
})
