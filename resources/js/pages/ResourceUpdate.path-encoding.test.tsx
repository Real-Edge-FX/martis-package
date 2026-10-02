import { describe, it, expect, vi, beforeEach } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router'
import type { FieldDefinition, ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'
import { allowDataRouterNavigation } from '@/test-support/dataRouterNavigation'
import { apiSegments, resolveApiPath, QUERY_SMUGGLING_ID_ROUTE, QUERY_SMUGGLING_ID_SEGMENT } from '@/test-support/apiPaths'

/*
 * F007: the edit page takes `resource` and `id` from the route. React Router
 * decodes `%2F` and `%3F` in a param, so a crafted link
 * `/resources/posts/..%2Fusers%2F5%3Femail%3Da%40b.c/edit` hands the page the id
 * `../users/5?email=a@b.c`. Interpolated raw into the record fetch and the
 * save, the browser rewrote them to `PUT /api/resources/users/5?email=a@b.c`.
 * Both requests must name the Posts endpoint, with the id as one segment.
 */

const apiGetMock = vi.fn()
const apiPutMock = vi.fn()
const apiUploadMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGetMock(...args),
      put: (...args: unknown[]) => apiPutMock(...args),
      upload: (...args: unknown[]) => apiUploadMock(...args),
    },
  }
})

import { ResourceUpdatePage } from '@/pages/ResourceUpdate'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()
allowDataRouterNavigation()

const titleField = {
  attribute: 'title',
  label: 'Title',
  type: 'text',
  nullable: false,
  readonly: false,
  required: false,
  sortable: false,
  searchable: false,
  showOnIndex: false,
  showOnDetail: true,
  showOnForms: true,
  rules: [],
  reserved: [],
} as unknown as FieldDefinition

const postsSchema = {
  uriKey: 'posts',
  label: 'Posts',
  singularLabel: 'Post',
  fields: [],
  fieldsForUpdate: [titleField],
  errorDisplay: 'inline',
  confirmUnsavedChanges: true,
  messages: {},
} as unknown as ResourceSchema

beforeEach(() => {
  apiGetMock.mockReset()
  apiPutMock.mockReset()
  apiUploadMock.mockReset()
  apiGetMock.mockImplementation((path: string) => {
    if (path.endsWith('/schema')) return Promise.resolve({ data: postsSchema })
    return Promise.resolve({ data: { id: 1, title: 'A post' } })
  })
  apiPutMock.mockReturnValue(new Promise(() => {}))
  apiUploadMock.mockReturnValue(new Promise(() => {}))
})

function renderAt(entry: string) {
  const router = createMemoryRouter(
    [{ path: '/resources/:resource/:id/edit', element: <ResourceUpdatePage /> }],
    { initialEntries: [entry] },
  )
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  render(
    <QueryClientProvider client={qc}>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </QueryClientProvider>,
  )
}

describe('ResourceUpdatePage — a route param that traverses stays one path segment', () => {
  it('loads and saves through the Posts endpoint, never another resource', async () => {
    renderAt(`/resources/posts/${QUERY_SMUGGLING_ID_ROUTE}/edit`)

    await waitFor(() => expect(document.getElementById('title')).not.toBeNull())

    const recordFetch = apiGetMock.mock.calls.map(([path]) => path as string).find((path) => path.includes('context=update'))!
    expect(apiSegments(recordFetch)).toEqual(['resources', 'posts', QUERY_SMUGGLING_ID_SEGMENT])
    expect(resolveApiPath(recordFetch).search).toBe('?context=update')

    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(apiPutMock).toHaveBeenCalledTimes(1))

    const savePath = apiPutMock.mock.calls[0]![0] as string
    expect(savePath).toBe(`/api/resources/posts/${QUERY_SMUGGLING_ID_SEGMENT}`)
    expect(apiSegments(savePath)).toEqual(['resources', 'posts', QUERY_SMUGGLING_ID_SEGMENT])
    expect(resolveApiPath(savePath).search).toBe('')
  })

  it('encodes the resource param the same way', async () => {
    renderAt('/resources/..%2Fusers/5/edit')

    await waitFor(() => expect(apiGetMock).toHaveBeenCalled())

    for (const [path] of apiGetMock.mock.calls as [string][]) {
      expect(apiSegments(path)[1]).toBe('..%252Fusers')
    }
  })
})
