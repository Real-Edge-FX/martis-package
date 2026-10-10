import { describe, it, expect, vi, beforeEach } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router'
import type { FieldDefinition, ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'
import { allowDataRouterNavigation } from '@/test-support/dataRouterNavigation'

/*
 * A record whose BelongsTo target is soft-deleted loads the field as
 * `{ id, title, trashed: true }`. The update form reduced the map to its id,
 * dropping the flag the Relatable rule takes as the trashed opt-in, so saving
 * ANY field of that record answered 422 on the BelongsTo. The form now sends
 * `<attribute>_trashed: true` beside the id. A KeyValue map with `id` and
 * `title` keys of its own is no BelongsTo and is sent as it is.
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

function field(attribute: string, label: string, type: string, extra: Record<string, unknown> = {}): FieldDefinition {
  return {
    attribute, label, type,
    nullable: true, readonly: false, required: false, sortable: false, searchable: false,
    showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [], reserved: [],
    ...extra,
  } as unknown as FieldDefinition
}

const notes = {
  uriKey: 'notes',
  label: 'Notes',
  singularLabel: 'Note',
  fields: [],
  fieldsForUpdate: [
    field('title', 'Title', 'text'),
    field('contact_id', 'Contact', 'belongs_to', { relatedResource: 'contacts', relationship: 'contact' }),
    field('meta', 'Meta', 'code'),
  ],
  errorDisplay: 'inline',
  confirmUnsavedChanges: false,
  messages: {},
} as unknown as ResourceSchema

beforeEach(() => {
  apiGetMock.mockReset()
  apiPutMock.mockReset()
  apiGetMock.mockImplementation((path: string) => {
    if (path.endsWith('/schema')) return Promise.resolve({ data: notes })
    return Promise.resolve({
      data: {
        id: 1,
        title: 'A note',
        contact_id: { id: 13, title: 'Ana Silva', trashed: true },
        meta: { id: 'x', title: 'kept' },
      },
    })
  })
  apiPutMock.mockResolvedValue({ data: { id: 1 } })
})

describe('ResourceUpdatePage with a soft-deleted BelongsTo target', () => {
  it('posts the id with the trashed opt-in and leaves a JSON map with id and title keys whole', async () => {
    const router = createMemoryRouter(
      [
        { path: '/resources/:resource/:id/edit', element: <ResourceUpdatePage /> },
        { path: '*', element: <div>landed</div> },
      ],
      { initialEntries: ['/resources/notes/1/edit'] },
    )
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })}>
        <ToastProvider>
          <RouterProvider router={router} />
        </ToastProvider>
      </QueryClientProvider>,
    )
    await waitFor(() => expect(document.getElementById('title')).not.toBeNull())
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    await waitFor(() => expect(apiPutMock).toHaveBeenCalled())
    const body = apiPutMock.mock.calls[0][1] as Record<string, unknown>
    expect(body.contact_id).toBe(13)
    expect(body.contact_id_trashed).toBe(true)
    expect(body.meta).toEqual({ id: 'x', title: 'kept' })
  })
})
