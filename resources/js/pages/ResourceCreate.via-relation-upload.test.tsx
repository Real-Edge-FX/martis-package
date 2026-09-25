import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router'
import type { FieldDefinition, ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * A create form opened from a parent's relationship panel
 * (`?viaResource=…&viaResourceId=…&viaRelationship=…`) posts to the
 * relationship's endpoint. It always sent JSON, so a file picked in the form
 * never reached the server (a File object serialises to `{}`), while the
 * same form opened on its own sends multipart when it carries a file. It now
 * uploads the same way.
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

function field(attribute: string, label: string, type: string): FieldDefinition {
  return {
    attribute, label, type,
    nullable: true, readonly: false, required: false, sortable: false, searchable: false,
    showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [],
  } as unknown as FieldDefinition
}

const attachments = {
  uriKey: 'attachments',
  label: 'Attachments',
  singularLabel: 'Attachment',
  fields: [],
  fieldsForCreate: [field('title', 'Title', 'text'), field('document', 'Document', 'file')],
  errorDisplay: 'inline',
  confirmUnsavedChanges: false,
  messages: {},
} as unknown as ResourceSchema

beforeEach(() => {
  apiGetMock.mockReset()
  apiPostMock.mockClear()
  apiUploadMock.mockClear()
  apiGetMock.mockImplementation((path: string) => {
    if (path === '/api/resources/attachments/schema') return Promise.resolve({ data: attachments })
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

async function pickFileAndSubmit() {
  await waitFor(() => expect(document.querySelector('input[type="file"]')).not.toBeNull())
  const document_ = new File(['%PDF-1.4'], 'contract.pdf', { type: 'application/pdf' })
  fireEvent.change(document.getElementById('title')!, { target: { value: 'Contract' } })
  fireEvent.change(document.querySelector('input[type="file"]')!, { target: { files: [document_] } })
  fireEvent.submit(document.querySelector('form')!)
  return document_
}

describe('ResourceCreatePage nested under a parent', () => {
  it("uploads a picked file to the relationship's endpoint", async () => {
    renderCreate('/resources/attachments/create?viaResource=projects&viaResourceId=7&viaRelationship=attachments')

    const picked = await pickFileAndSubmit()

    await waitFor(() => expect(apiUploadMock).toHaveBeenCalled())
    expect(apiUploadMock).toHaveBeenCalledWith(
      'POST',
      '/api/resources/projects/7/has-many/attachments',
      expect.objectContaining({ title: 'Contract', document: picked }),
    )
    expect(apiPostMock).not.toHaveBeenCalled()
  })

  it('still posts JSON when no file was picked', async () => {
    renderCreate('/resources/attachments/create?viaResource=projects&viaResourceId=7&viaRelationship=attachments')
    await waitFor(() => expect(document.getElementById('title')).not.toBeNull())
    fireEvent.change(document.getElementById('title')!, { target: { value: 'Notes' } })
    fireEvent.submit(document.querySelector('form')!)

    await waitFor(() => expect(apiPostMock).toHaveBeenCalled())
    expect(apiPostMock).toHaveBeenCalledWith('/api/resources/projects/7/has-many/attachments', expect.objectContaining({ title: 'Notes' }))
    expect(apiUploadMock).not.toHaveBeenCalled()
  })
})
