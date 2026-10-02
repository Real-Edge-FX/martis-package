import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router'
import type { FieldDefinition, ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'
import { apiSegments, TRAVERSING_ID_SEGMENT } from '@/test-support/apiPaths'

/*
 * F011: the lens page's row delete interpolated the clicked row's `id` raw into
 * `DELETE /api/resources/{resource}/{id}`, so a record keyed `../users/5` made
 * the browser send the delete to another resource's record.
 */

const apiGetMock = vi.fn()
const apiDeleteMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGetMock(...args),
      delete: (...args: unknown[]) => apiDeleteMock(...args),
    },
  }
})

import { ResourceLensPage } from '@/pages/ResourceLens'
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
  softDeletes: false,
  stickyView: false,
  group: null,
  fields: [],
  fieldsForIndex: [],
  defaultRowActions: { enabled: true },
  errorDisplay: 'inline',
  lenses: [{
    type: 'lens', name: 'Popular', uriKey: 'popular', component: null, perPageOptions: [10, 25],
    polling: false, pollingInterval: 0, showPollingToggle: false, defaultFilters: {}, cacheTtlSeconds: 0, meta: {},
  }],
  messages: {},
} as unknown as ResourceSchema

beforeEach(() => {
  apiGetMock.mockReset()
  apiDeleteMock.mockReset()
  apiDeleteMock.mockResolvedValue({})
  apiGetMock.mockImplementation((path: string) => {
    if (path.includes('/schema')) return Promise.resolve({ data: schema })
    if (path.includes('/lenses/')) {
      return Promise.resolve({
        data: [{ id: '../users/5', name: 'Lens row' }],
        meta: { total: 1, current_page: 1, last_page: 1, per_page: 25, from: 1, to: 1, fields: [nameField], actions: [] },
      })
    }
    return Promise.resolve({ data: [] })
  })
})

function renderLens(entry: string) {
  const router = createMemoryRouter(
    [{ path: '/resources/:resource/lens/:lens', element: <ResourceLensPage /> }],
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

describe('ResourceLensPage — a record key that traverses stays one path segment', () => {
  it('deletes the clicked row through the resource endpoint', async () => {
    renderLens('/resources/posts/lens/popular')
    await screen.findByText('Lens row')

    fireEvent.click(screen.getByRole('button', { name: 'Delete' }))
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Delete permanently' }))

    await waitFor(() => expect(apiDeleteMock).toHaveBeenCalledTimes(1))
    const path = apiDeleteMock.mock.calls[0]![0] as string
    expect(path).toBe(`/api/resources/posts/${TRAVERSING_ID_SEGMENT}`)
    expect(apiSegments(path)).toEqual(['resources', 'posts', TRAVERSING_ID_SEGMENT])
  })
})
