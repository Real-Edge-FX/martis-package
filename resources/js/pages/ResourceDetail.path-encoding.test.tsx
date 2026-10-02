import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router'
import type { FieldDefinition, ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'
import { allowDataRouterNavigation } from '@/test-support/dataRouterNavigation'
import { apiSegments, resolveApiPath, QUERY_SMUGGLING_ID_ROUTE, QUERY_SMUGGLING_ID_SEGMENT, TRAVERSING_ID_ROUTE, TRAVERSING_ID_SEGMENT } from '@/test-support/apiPaths'

/*
 * F066: the detail page takes `resource` and `id` from the route, which React
 * Router decodes (`%2F` back to `/`, `%3F` to `?`). Interpolated raw, a link
 * `/resources/posts/..%2Fusers%2F5` showed the Posts page over user 5's record
 * and Delete removed users/5; `?` and `#` cut the `/restore` or `/force`
 * suffix off. Every request the page sends keeps the id as ONE segment.
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

import { ResourceDetailPage } from '@/pages/ResourceDetail'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()
allowDataRouterNavigation()

const titleField = {
  attribute: 'title', label: 'Title', type: 'text',
  nullable: false, readonly: false, required: false, sortable: false, searchable: false,
  showOnIndex: true, showOnDetail: true, showOnForms: true, rules: [], reserved: [],
} as unknown as FieldDefinition

function posts(softDeletes: boolean): ResourceSchema {
  return {
    uriKey: 'posts',
    label: 'Posts',
    singularLabel: 'Post',
    softDeletes,
    fields: [],
    fieldsForDetail: [titleField],
    actions: [],
    messages: {},
  } as unknown as ResourceSchema
}

function serve(softDeletes: boolean, trashed: boolean): void {
  apiGetMock.mockImplementation((path: string) => {
    if (path.endsWith('/schema')) return Promise.resolve({ data: posts(softDeletes) })
    return Promise.resolve({ data: { id: 1, _title: 'A post', title: 'A post', ...(trashed ? { deleted_at: '2026-01-01' } : {}) } })
  })
}

function renderAt(entry: string) {
  const router = createMemoryRouter(
    [
      { path: '/resources/:resource/:id', element: <ResourceDetailPage /> },
      { path: '/resources/:resource', element: <div data-testid="index" /> },
    ],
    { initialEntries: [entry] },
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
  apiDeleteMock.mockReset()
  apiPutMock.mockReset()
  apiDeleteMock.mockResolvedValue({})
  apiPutMock.mockResolvedValue({})
})

describe('ResourceDetailPage — a route param that traverses stays one path segment', () => {
  it('fetches and deletes through the Posts endpoint', async () => {
    serve(false, false)
    renderAt(`/resources/posts/${TRAVERSING_ID_ROUTE}`)

    await screen.findByRole('heading', { name: 'A post' })
    const recordFetch = apiGetMock.mock.calls.map(([path]) => path as string).find((path) => !path.endsWith('/schema'))!
    expect(apiSegments(recordFetch)).toEqual(['resources', 'posts', TRAVERSING_ID_SEGMENT])

    fireEvent.click(screen.getByRole('button', { name: 'Delete' }))
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Delete permanently' }))

    await waitFor(() => expect(apiDeleteMock).toHaveBeenCalledTimes(1))
    const path = apiDeleteMock.mock.calls[0]![0] as string
    expect(apiSegments(path)).toEqual(['resources', 'posts', TRAVERSING_ID_SEGMENT])
  })

  it('restores through the Posts endpoint, with the query smuggled in the id left in the id', async () => {
    serve(true, true)
    renderAt(`/resources/posts/${QUERY_SMUGGLING_ID_ROUTE}`)

    await screen.findByRole('heading', { name: 'A post' })
    fireEvent.click(screen.getByRole('button', { name: 'Restore' }))
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Restore' }))

    await waitFor(() => expect(apiPutMock).toHaveBeenCalledTimes(1))
    const path = apiPutMock.mock.calls[0]![0] as string
    expect(apiSegments(path)).toEqual(['resources', 'posts', QUERY_SMUGGLING_ID_SEGMENT, 'restore'])
    expect(resolveApiPath(path).search).toBe('')
  })

  it('force deletes through the Posts endpoint', async () => {
    serve(true, true)
    renderAt(`/resources/posts/${TRAVERSING_ID_ROUTE}`)

    await screen.findByRole('heading', { name: 'A post' })
    fireEvent.click(screen.getByRole('button', { name: 'Delete permanently' }))
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Delete permanently' }))

    await waitFor(() => expect(apiDeleteMock).toHaveBeenCalledTimes(1))
    const path = apiDeleteMock.mock.calls[0]![0] as string
    expect(apiSegments(path)).toEqual(['resources', 'posts', TRAVERSING_ID_SEGMENT, 'force'])
  })
})
