import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router'
import type { FieldDefinition, ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'
import { allowDataRouterNavigation } from '@/test-support/dataRouterNavigation'

/*
 * A lens keeps its sort in the URL, in the list format `?sort=a:asc,b:desc`: a click and a Shift+click on the headers write
 * it, and a link of the earlier `?sort=a&direction=desc` shape still reads.
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args) } }
})

import { ResourceLensPage } from '@/pages/ResourceLens'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()
allowDataRouterNavigation()

const field = (attribute: string, label: string) => ({
  attribute, label, type: 'text',
  nullable: false, readonly: false, required: false, sortable: true, searchable: false,
  showOnIndex: true, showOnDetail: true, showOnForms: true, rules: [], reserved: [],
}) as unknown as FieldDefinition

const fields = [field('title', 'Title'), field('author', 'Author')]

const posts = {
  uriKey: 'posts', label: 'Posts', singularLabel: 'Post', softDeletes: false, stickyView: false,
  fields: [], fieldsForIndex: fields, errorDisplay: 'inline', defaultRowActions: { enabled: true },
  lenses: [{
    type: 'lens', name: 'Popular', uriKey: 'popular', component: null, perPageOptions: [25], polling: false,
    pollingInterval: 0, showPollingToggle: false, defaultFilters: {}, cacheTtlSeconds: 0, meta: {},
  }],
  messages: {},
} as unknown as ResourceSchema

beforeEach(() => {
  apiGetMock.mockReset()
  apiGetMock.mockImplementation((path: string) => {
    if (path === '/api/resources/posts/schema') return Promise.resolve({ data: posts })
    if (path.startsWith('/api/resources/posts/lenses/popular?')) {
      return Promise.resolve({
        data: [{ id: 5, title: 'Hello world', author: 'Ann' }],
        meta: { total: 1, current_page: 1, last_page: 1, per_page: 25, from: 1, to: 1, fields, actions: [] },
      })
    }
    return Promise.resolve({ data: [] })
  })
})

function renderLens(initial = '/resources/posts/lens/popular') {
  const router = createMemoryRouter(
    [{ path: '/resources/:resource/lens/:lens', element: <ResourceLensPage /> }],
    { initialEntries: [initial] },
  )
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </QueryClientProvider>,
  )
  return router
}

const params = (router: ReturnType<typeof renderLens>) => new URLSearchParams(router.state.location.search)
const lastLensSort = () => {
  const urls = apiGetMock.mock.calls.map(([p]) => String(p)).filter((p) => p.startsWith('/api/resources/posts/lenses/popular?'))
  return new URLSearchParams((urls[urls.length - 1] ?? '').split('?')[1] ?? '').get('sort')
}

describe('ResourceLensPage sort in the URL', () => {
  it('writes the list format: click alone, Shift+click adds, a direction parameter is not written', async () => {
    const router = renderLens('/resources/posts/lens/popular?sort=title&direction=desc')
    await screen.findByText('Hello world')
    // A link of the earlier shape reads as one key.
    await waitFor(() => expect(lastLensSort()).toBe('title:desc'))

    fireEvent.click(screen.getByRole('button', { name: /^Author/ }))
    await waitFor(() => expect(params(router).get('sort')).toBe('author:asc'))
    expect(params(router).has('direction')).toBe(false)

    fireEvent.click(screen.getByRole('button', { name: /^Title/ }), { shiftKey: true })
    await waitFor(() => expect(params(router).get('sort')).toBe('author:asc,title:asc'))
    await waitFor(() => expect(lastLensSort()).toBe('author:asc,title:asc'))

    fireEvent.click(screen.getByRole('button', { name: /^Title/ }), { shiftKey: true })
    await waitFor(() => expect(params(router).get('sort')).toBe('author:asc,title:desc'))
    fireEvent.click(screen.getByRole('button', { name: /^Title/ }), { shiftKey: true })
    await waitFor(() => expect(params(router).get('sort')).toBe('author:asc'))
  })
})
