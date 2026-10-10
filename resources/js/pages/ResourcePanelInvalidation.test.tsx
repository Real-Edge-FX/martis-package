import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import type { FieldDefinition, ResourceRecord, ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'
import { allowDataRouterNavigation } from '@/test-support/dataRouterNavigation'

/*
 * A record created or edited from a parent's relationship panel
 * (`?viaResource=…&viaResourceId=…&viaRelationship=…&viaRelationshipType=…`)
 * must not leave the panel serving the list it cached before: the query
 * (30 s staleTime) is invalidated under the panel's own kind (v1.39.6).
 */

const apiGetMock = vi.fn()
const apiPostMock = vi.fn()
const apiPutMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGetMock(...args),
      post: (...args: unknown[]) => apiPostMock(...args),
      put: (...args: unknown[]) => apiPutMock(...args),
    },
  }
})

import { ResourceCreatePage } from '@/pages/ResourceCreate'
import { ResourceUpdatePage } from '@/pages/ResourceUpdate'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()
allowDataRouterNavigation()

const notesField = {
  attribute: 'notes', label: 'Notes', type: 'text',
  nullable: true, readonly: false, required: false, sortable: false, searchable: false,
  showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [],
} as unknown as FieldDefinition

const schema = {
  uriKey: 'profiles',
  label: 'Profiles',
  singularLabel: 'Profile',
  fields: [],
  fieldsForCreate: [notesField],
  fieldsForUpdate: [notesField],
  errorDisplay: 'inline',
  confirmUnsavedChanges: false,
  messages: {},
} as unknown as ResourceSchema

beforeEach(() => {
  apiGetMock.mockReset()
  apiPostMock.mockReset()
  apiPutMock.mockReset()
  apiGetMock.mockImplementation((path: string) => {
    if (path === '/api/resources/profiles/schema') return Promise.resolve({ data: schema })
    if (path.startsWith('/api/resources/profiles/5')) return Promise.resolve({ data: { id: 5, notes: 'Old' } as ResourceRecord })
    return Promise.resolve({ data: [] })
  })
  apiPostMock.mockResolvedValue({ data: { id: 6 } })
  apiPutMock.mockResolvedValue({ data: { id: 5, notes: 'New' } })
})

function renderAt(path: string, route: string, element: React.ReactElement) {
  const router = createMemoryRouter(
    [{ path: route, element }, { path: '*', element: <div data-testid="elsewhere" /> }],
    { initialEntries: [path] },
  )
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  const invalidate = vi.spyOn(qc, 'invalidateQueries')
  render(
    <QueryClientProvider client={qc}>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </QueryClientProvider>,
  )
  return invalidate
}

const kinds = ['has-many', 'has-one', 'morph-many', 'morph-one']
const via = (kind: string) => `viaResource=contacts&viaResourceId=13&viaRelationship=profiles&viaRelationshipType=${kind}`

describe('a create from a relationship panel', () => {
  it.each(kinds)('invalidates the %s panel of the parent', async (kind) => {
    const invalidate = renderAt(`/resources/profiles/create?${via(kind)}`, '/resources/:resource/create', <ResourceCreatePage />)
    await waitFor(() => expect(document.getElementById('notes')).not.toBeNull())
    fireEvent.change(document.getElementById('notes')!, { target: { value: 'Hello' } })
    fireEvent.submit(document.querySelector('form')!)

    await waitFor(() => expect(apiPostMock).toHaveBeenCalled())
    await waitFor(() => expect(invalidate).toHaveBeenCalledWith({ queryKey: [kind, 'contacts', '13', 'profiles'] }))
  })

  it('leaves the panels alone on a plain create', async () => {
    const invalidate = renderAt('/resources/profiles/create', '/resources/:resource/create', <ResourceCreatePage />)
    await waitFor(() => expect(document.getElementById('notes')).not.toBeNull())
    fireEvent.submit(document.querySelector('form')!)

    await waitFor(() => expect(apiPostMock).toHaveBeenCalled())
    await waitFor(() => expect(invalidate).toHaveBeenCalled())
    expect(invalidate.mock.calls.every(([filters]) => (filters?.queryKey as string[])[0] === 'resources')).toBe(true)
  })
})

describe('an edit from a relationship panel', () => {
  it.each(kinds)('invalidates the %s panel of the parent', async (kind) => {
    const invalidate = renderAt(`/resources/profiles/5/edit?${via(kind)}`, '/resources/:resource/:id/edit', <ResourceUpdatePage />)
    await waitFor(() => expect((document.getElementById('notes') as HTMLInputElement | null)?.value).toBe('Old'))
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    await waitFor(() => expect(apiPutMock).toHaveBeenCalled())
    await waitFor(() => expect(invalidate).toHaveBeenCalledWith({ queryKey: [kind, 'contacts', '13', 'profiles'] }))
  })
})
