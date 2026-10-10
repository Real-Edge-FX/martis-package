import { describe, it, expect, vi, beforeEach } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router'
import type { FieldDefinition, ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'
import { allowDataRouterNavigation } from '@/test-support/dataRouterNavigation'

/*
 * A record edited from a parent's relationship panel sends the SPA back to
 * the parent client-side. The edit invalidated the panel's `has-one` query
 * for a has-one panel and the `has-many` one for every other kind, so a
 * morph-many or morph-one panel kept the list it had before the edit.
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
allowDataRouterNavigation()

const notes = {
  uriKey: 'notes',
  label: 'Notes',
  singularLabel: 'Note',
  fields: [],
  fieldsForUpdate: [{
    attribute: 'title', label: 'Title', type: 'text',
    nullable: false, readonly: false, required: false, sortable: false, searchable: false,
    showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [], reserved: [],
  } as unknown as FieldDefinition],
  errorDisplay: 'inline',
  confirmUnsavedChanges: false,
  messages: {},
} as unknown as ResourceSchema

beforeEach(() => {
  apiGetMock.mockReset()
  apiPutMock.mockReset()
  apiGetMock.mockImplementation((path: string) => {
    if (path.endsWith('/schema')) return Promise.resolve({ data: notes })
    return Promise.resolve({ data: { id: 1, title: 'A note' } })
  })
  apiPutMock.mockResolvedValue({ data: { id: 1, title: 'A note' } })
})

async function saveFrom(query: string) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  const invalidate = vi.spyOn(qc, 'invalidateQueries')
  const router = createMemoryRouter(
    [
      { path: '/resources/:resource/:id/edit', element: <ResourceUpdatePage /> },
      { path: '*', element: <div>landed</div> },
    ],
    { initialEntries: [`/resources/notes/1/edit${query}`] },
  )
  render(
    <QueryClientProvider client={qc}>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </QueryClientProvider>,
  )
  await waitFor(() => expect(document.getElementById('title')).not.toBeNull())
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
  await waitFor(() => expect(apiPutMock).toHaveBeenCalled())
  await waitFor(() => expect(invalidate.mock.calls.length).toBeGreaterThan(2))
  return invalidate.mock.calls.map(([filters]) => (filters as { queryKey: unknown[] }).queryKey)
}

const VIA = 'viaResource=contacts&viaResourceId=13&viaRelationship=notes'

describe('ResourceUpdatePage nested under a parent', () => {
  it.each(['has-many', 'has-one', 'morph-many', 'morph-one'])("invalidates the parent's %s panel and no other kind", async (kind) => {
    const keys = await saveFrom(`?${VIA}&viaRelationshipType=${kind}`)

    expect(keys).toContainEqual([kind, 'contacts', '13', 'notes'])
    for (const other of ['has-many', 'has-one', 'morph-many', 'morph-one'].filter((k) => k !== kind)) {
      expect(keys).not.toContainEqual([other, 'contacts', '13', 'notes'])
    }
  })

  it('keeps invalidating the has-many panel when the link names no kind', async () => {
    const keys = await saveFrom(`?${VIA}`)

    expect(keys).toContainEqual(['has-many', 'contacts', '13', 'notes'])
  })
})
