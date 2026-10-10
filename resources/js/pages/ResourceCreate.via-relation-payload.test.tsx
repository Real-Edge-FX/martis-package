import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import type { FieldDefinition, ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * A create form opened from a parent's relationship panel pre-fills the
 * BelongsTo that points back to the parent with the `{ id, title }` it shows
 * read-only. That map was posted as it was, so the consumer's
 * `Rule::exists()` received an array (a multi-value whereIn). The form posts
 * the parent's id, as the update form does (v1.39.6).
 */

const apiGetMock = vi.fn()
const apiPostMock = vi.fn(() => new Promise(() => {}))

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGetMock(...args),
      post: (...args: unknown[]) => apiPostMock(...(args as [])),
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
    showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [], ...extra,
  } as unknown as FieldDefinition
}

const profiles = {
  uriKey: 'buyer-profiles',
  label: 'Buyer profiles',
  singularLabel: 'Buyer profile',
  fields: [],
  fieldsForCreate: [field('contact_id', 'Contact', 'belongs_to', { relatedResource: 'contacts' }), field('notes', 'Notes', 'text')],
  errorDisplay: 'inline',
  confirmUnsavedChanges: false,
  messages: {},
} as unknown as ResourceSchema

beforeEach(() => {
  apiGetMock.mockReset()
  apiPostMock.mockClear()
  apiGetMock.mockImplementation((path: string) => {
    if (path === '/api/resources/buyer-profiles/schema') return Promise.resolve({ data: profiles })
    if (path === '/api/resources/contacts/13') return Promise.resolve({ data: { id: 13, _title: 'Comprador Demo' } })
    return Promise.resolve({ data: [] })
  })
})

describe('ResourceCreatePage nested under a parent', () => {
  it('posts the parent as its id, not as the { id, title } it shows', async () => {
    const router = createMemoryRouter(
      [{ path: '/resources/:resource/create', element: <ResourceCreatePage /> }],
      { initialEntries: ['/resources/buyer-profiles/create?viaResource=contacts&viaResourceId=13&viaRelationship=buyerProfiles'] },
    )
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <ToastProvider>
          <RouterProvider router={router} />
        </ToastProvider>
      </QueryClientProvider>,
    )

    await waitFor(() => expect(document.getElementById('notes')).not.toBeNull())
    await waitFor(() => expect(apiGetMock).toHaveBeenCalledWith('/api/resources/contacts/13'))
    // Let the pre-fill land before the form is submitted.
    await waitFor(() => expect(document.body.textContent).toContain('Comprador Demo'))
    fireEvent.change(document.getElementById('notes')!, { target: { value: 'Looking' } })
    fireEvent.submit(document.querySelector('form')!)

    await waitFor(() => expect(apiPostMock).toHaveBeenCalled())
    const [url, body] = (apiPostMock.mock.calls[0] as unknown) as [string, Record<string, unknown>]
    expect(url).toBe('/api/resources/contacts/13/has-many/buyerProfiles')
    expect(body.contact_id).toBe('13')
    expect(body.notes).toBe('Looking')
  })
})
