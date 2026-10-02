import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router'
import type { FieldDefinition, ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'
import { apiSegments, resolveApiPath, TRAVERSING_ID, TRAVERSING_ID_SEGMENT } from '@/test-support/apiPaths'

/*
 * F011: the index's row actions send the clicked row's `id` (the record's key,
 * as the index API returns it) in the path of the delete, restore and
 * force-delete requests. A string key a less-trusted writer chose (`../users/5`)
 * rewrote the request to another resource's record under the administrator's
 * session: the browser removes the dot segments. The id is one encoded
 * segment now, whatever the key holds.
 */

const apiGetMock = vi.fn()
const apiDeleteMock = vi.fn()
const apiPutMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGetMock(...args),
      delete: (...args: unknown[]) => apiDeleteMock(...args),
      put: (...args: unknown[]) => apiPutMock(...args),
    },
  }
})

import { ResourceIndexPage } from '@/pages/ResourceIndex'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()

const nameField = {
  attribute: 'name', label: 'Name', type: 'text',
  nullable: true, readonly: false, required: false, sortable: false, searchable: false,
  showOnIndex: true, showOnDetail: true, showOnForms: true, rules: [], reserved: [],
} as unknown as FieldDefinition

const schema = {
  uriKey: 'posts',
  label: 'Posts',
  singularLabel: 'Post',
  softDeletes: true,
  stickyView: false,
  group: null,
  fields: [],
  fieldsForIndex: [nameField],
  defaultRowActions: { enabled: true },
  errorDisplay: 'inline',
  messages: {},
} as unknown as ResourceSchema

const ROWS = [
  { id: TRAVERSING_ID, name: 'Live row' },
  { id: '../users/6', name: 'Trashed row', deleted_at: '2026-01-01T00:00:00Z' },
]

beforeEach(() => {
  apiGetMock.mockReset()
  apiDeleteMock.mockReset()
  apiPutMock.mockReset()
  apiDeleteMock.mockResolvedValue({})
  apiPutMock.mockResolvedValue({})
  apiGetMock.mockImplementation((path: string) => {
    if (path.includes('/schema')) return Promise.resolve({ data: schema })
    if (path.startsWith('/api/resources/posts?')) {
      return Promise.resolve({ data: ROWS, meta: { total: 2, current_page: 1, last_page: 1, per_page: 25, from: 1, to: 2 } })
    }
    return Promise.resolve({ data: [] })
  })
})

function renderIndex() {
  const router = createMemoryRouter(
    [{ path: '/resources/:resource', element: <ResourceIndexPage /> }],
    { initialEntries: ['/resources/posts'] },
  )
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })}>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </QueryClientProvider>,
  )
}

describe('ResourceIndexPage — a record key that traverses stays one path segment', () => {
  it('deletes the clicked row through the Posts endpoint', async () => {
    renderIndex()
    await screen.findByText('Live row')

    fireEvent.click(screen.getByRole('button', { name: 'Delete' }))
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Archive' }))

    await waitFor(() => expect(apiDeleteMock).toHaveBeenCalledTimes(1))
    const path = apiDeleteMock.mock.calls[0]![0] as string
    expect(path).toBe(`/api/resources/posts/${TRAVERSING_ID_SEGMENT}`)
    expect(apiSegments(path)).toEqual(['resources', 'posts', TRAVERSING_ID_SEGMENT])
    expect(path).not.toContain(TRAVERSING_ID)
  })

  it('restores a trashed row through the Posts endpoint', async () => {
    renderIndex()
    await screen.findByText('Trashed row')

    fireEvent.click(screen.getByRole('button', { name: 'Restore' }))
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Restore' }))

    await waitFor(() => expect(apiPutMock).toHaveBeenCalledTimes(1))
    const path = apiPutMock.mock.calls[0]![0] as string
    expect(apiSegments(path)).toEqual(['resources', 'posts', '..%252Fusers%252F6', 'restore'])
    expect(resolveApiPath(path).search).toBe('')
  })

  it('force deletes a trashed row through the Posts endpoint', async () => {
    renderIndex()
    await screen.findByText('Trashed row')

    fireEvent.click(screen.getByRole('button', { name: 'Force delete' }))
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Delete permanently' }))

    await waitFor(() => expect(apiDeleteMock).toHaveBeenCalledTimes(1))
    const path = apiDeleteMock.mock.calls[0]![0] as string
    expect(apiSegments(path)).toEqual(['resources', 'posts', '..%252Fusers%252F6', 'force'])
  })
})
