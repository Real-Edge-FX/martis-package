import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router'
import type { FieldDefinition, ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * A create form opened from a parent's relationship panel makes the
 * BelongsTo back to the parent readonly and pre-fills it with the
 * `{ id, title }` map the field holds. The form posted that map as it was, so
 * a consumer `Rule::exists('contacts', 'id')` on the field received an array
 * (a multi-value `whereIn`: a 500 on PostgreSQL, a false 422 elsewhere). The
 * form now posts the id, as an update does.
 */

const apiGetMock = vi.fn()
const apiPostMock = vi.fn(() => new Promise(() => {}))
const apiUploadMock = vi.fn(() => new Promise(() => {}))

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGetMock(...args),
      post: (...args: unknown[]) => apiPostMock(...(args as [])),
      upload: (...args: unknown[]) => apiUploadMock(...(args as [])),
    },
  }
})

import { ResourceCreatePage } from '@/pages/ResourceCreate'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()

function field(attribute: string, label: string, type: string, extra: Record<string, unknown> = {}): FieldDefinition {
  return {
    attribute, label, type,
    nullable: true, readonly: false, required: false, sortable: false, searchable: false,
    showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [],
    ...extra,
  } as unknown as FieldDefinition
}

const notes = {
  uriKey: 'notes',
  label: 'Notes',
  singularLabel: 'Note',
  fields: [],
  fieldsForCreate: [
    field('title', 'Title', 'text'),
    field('contact_id', 'Contact', 'belongs_to', { relatedResource: 'contacts', relationship: 'contact' }),
  ],
  errorDisplay: 'inline',
  confirmUnsavedChanges: false,
  messages: {},
} as unknown as ResourceSchema

beforeEach(() => {
  apiGetMock.mockReset()
  apiPostMock.mockClear()
  apiUploadMock.mockClear()
  apiGetMock.mockImplementation((path: string) => {
    if (path === '/api/resources/notes/schema') return Promise.resolve({ data: notes })
    if (path === '/api/resources/contacts/13') return Promise.resolve({ data: { id: 13, _title: 'Ana Silva' } })
    return Promise.resolve({ data: [] })
  })
})

function renderCreate(url: string) {
  const router = createMemoryRouter(
    [{ path: '/resources/:resource/create', element: <ResourceCreatePage /> }],
    { initialEntries: [url] },
  )
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </QueryClientProvider>,
  )
}

describe('ResourceCreatePage nested under a parent', () => {
  it('posts the id of the pre-filled parent, not its { id, title } map', async () => {
    renderCreate('/resources/notes/create?viaResource=contacts&viaResourceId=13&viaRelationship=notes')

    await waitFor(() => expect(document.getElementById('title')).not.toBeNull())
    // The parent record is fetched for the title the pre-filled picker shows.
    await waitFor(() => expect(apiGetMock).toHaveBeenCalledWith('/api/resources/contacts/13'))
    fireEvent.change(document.getElementById('title')!, { target: { value: 'Call back' } })
    // Let the pre-fill land before submitting.
    await new Promise((resolve) => setTimeout(resolve, 0))
    fireEvent.submit(document.querySelector('form')!)

    await waitFor(() => expect(apiPostMock).toHaveBeenCalled())
    const [url, body] = apiPostMock.mock.calls[0] as unknown as [string, Record<string, unknown>]
    expect(url).toBe('/api/resources/contacts/13/has-many/notes')
    expect(body).toMatchObject({ title: 'Call back', contact_id: '13' })
  })
})
