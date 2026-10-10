import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router'
import type { FieldDefinition, ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * A record created from a parent's relationship panel sends the SPA back to
 * the parent client-side, and the query client keeps the panel's list for
 * 30 s, so the panel showed the list it had before the create. The create
 * now invalidates the panel's query, of the kind the link names.
 */

const apiGetMock = vi.fn()
const apiPostMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGetMock(...args),
      post: (...args: unknown[]) => apiPostMock(...args),
    },
  }
})

import { ResourceCreatePage } from '@/pages/ResourceCreate'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()

const notes = {
  uriKey: 'notes',
  label: 'Notes',
  singularLabel: 'Note',
  fields: [],
  fieldsForCreate: [{
    attribute: 'title', label: 'Title', type: 'text',
    nullable: true, readonly: false, required: false, sortable: false, searchable: false,
    showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [],
  } as unknown as FieldDefinition],
  errorDisplay: 'inline',
  confirmUnsavedChanges: false,
  messages: {},
} as unknown as ResourceSchema

beforeEach(() => {
  apiGetMock.mockReset()
  apiPostMock.mockReset()
  apiGetMock.mockImplementation((path: string) => {
    if (path === '/api/resources/notes/schema') return Promise.resolve({ data: notes })
    return Promise.resolve({ data: [] })
  })
  apiPostMock.mockResolvedValue({ data: { id: 5 } })
})

async function createFrom(url: string) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  const invalidate = vi.spyOn(qc, 'invalidateQueries')
  const router = createMemoryRouter(
    [
      { path: '/resources/:resource/create', element: <ResourceCreatePage /> },
      { path: '*', element: <div>landed</div> },
    ],
    { initialEntries: [url] },
  )
  render(
    <QueryClientProvider client={qc}>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </QueryClientProvider>,
  )
  await waitFor(() => expect(document.getElementById('title')).not.toBeNull())
  fireEvent.change(document.getElementById('title')!, { target: { value: 'Call back' } })
  fireEvent.submit(document.querySelector('form')!)
  await waitFor(() => expect(apiPostMock).toHaveBeenCalled())
  await waitFor(() => expect(invalidate).toHaveBeenCalled())
  return invalidate.mock.calls.map(([filters]) => (filters as { queryKey: unknown[] }).queryKey)
}

describe('ResourceCreatePage nested under a parent', () => {
  it("invalidates the parent's has-many panel", async () => {
    const keys = await createFrom('/resources/notes/create?viaResource=contacts&viaResourceId=13&viaRelationship=notes')

    expect(keys).toContainEqual(['has-many', 'contacts', '13', 'notes'])
  })

  it.each(['has-one', 'morph-many', 'morph-one'])("invalidates the parent's %s panel", async (kind) => {
    const keys = await createFrom(`/resources/notes/create?viaResource=contacts&viaResourceId=13&viaRelationship=notes&viaRelationshipType=${kind}`)

    expect(keys).toContainEqual([kind, 'contacts', '13', 'notes'])
    expect(keys).not.toContainEqual(['has-many', 'contacts', '13', 'notes'])
  })

  it('invalidates no panel for a plain create', async () => {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
    const invalidate = vi.spyOn(qc, 'invalidateQueries')
    const router = createMemoryRouter(
      [
        { path: '/resources/:resource/create', element: <ResourceCreatePage /> },
        { path: '*', element: <div>landed</div> },
      ],
      { initialEntries: ['/resources/notes/create'] },
    )
    render(
      <QueryClientProvider client={qc}>
        <ToastProvider>
          <RouterProvider router={router} />
        </ToastProvider>
      </QueryClientProvider>,
    )
    await waitFor(() => expect(document.getElementById('title')).not.toBeNull())
    fireEvent.submit(document.querySelector('form')!)
    await waitFor(() => expect(invalidate).toHaveBeenCalledWith({ queryKey: ['resources', 'notes'] }))

    const keys = invalidate.mock.calls.map(([filters]) => (filters as { queryKey: unknown[] }).queryKey[0])
    expect(keys).not.toContain('has-many')
  })
})
