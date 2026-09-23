import { describe, it, expect, vi, beforeEach } from 'vitest'
import { act, render, screen, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import type { FieldDefinition, ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'
import { allowDataRouterNavigation } from '@/test-support/dataRouterNavigation'

/*
 * The router keeps the lens page's instance when the URL moves to another
 * lens (the lens dropdown on the page itself) or to another resource's lens.
 * Everything the page held for the first lens carried over: the flag that
 * seeds a lens's default filters had already fired, so the second lens
 * loaded without its own; the selection, the drawers and the delete
 * confirmation stayed open for rows of the previous lens. The page now starts
 * over for each lens (the view state lives in the URL, so nothing is lost).
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
allowDataRouterNavigation()

const titleField = {
  attribute: 'title', label: 'Title', type: 'text',
  nullable: false, readonly: false, required: false, sortable: false, searchable: false,
  showOnIndex: true, showOnDetail: true, showOnForms: true, rules: [], reserved: [],
} as unknown as FieldDefinition

function lens(uriKey: string, name: string, defaultFilters: Record<string, unknown>) {
  return {
    type: 'lens', name, uriKey, component: null, perPageOptions: [25], polling: false,
    pollingInterval: 0, showPollingToggle: false, defaultFilters, cacheTtlSeconds: 0, meta: {},
  }
}

const posts = {
  uriKey: 'posts',
  label: 'Posts',
  singularLabel: 'Post',
  softDeletes: false,
  stickyView: false,
  fields: [],
  fieldsForIndex: [titleField],
  errorDisplay: 'inline',
  defaultRowActions: { enabled: true },
  lenses: [lens('popular', 'Popular', { status: 'published' }), lens('drafts', 'Drafts', { status: 'draft' })],
  messages: {},
} as unknown as ResourceSchema

const ROWS: Record<string, Array<Record<string, unknown>>> = {
  popular: [{ id: 5, title: 'Hello world' }],
  drafts: [{ id: 6, title: 'Work in progress' }],
}

beforeEach(() => {
  apiGetMock.mockReset()
  apiDeleteMock.mockReset()
  apiDeleteMock.mockReturnValue(new Promise(() => {}))
  apiGetMock.mockImplementation((path: string) => {
    if (path === '/api/resources/posts/schema') return Promise.resolve({ data: posts })
    const lensPath = /^\/api\/resources\/posts\/lenses\/([^/?]+)\?/.exec(path)
    if (lensPath) {
      const rows = ROWS[lensPath[1]]
      return Promise.resolve({
        data: rows,
        meta: { total: rows.length, current_page: 1, last_page: 1, per_page: 25, from: 1, to: rows.length, fields: [titleField], actions: [] },
      })
    }
    return Promise.resolve({ data: [] })
  })
})

function renderLens() {
  const router = createMemoryRouter(
    // Mirrors router.tsx: the same element for every lens.
    [{ path: '/resources/:resource/lens/:lens', element: <ResourceLensPage /> }],
    { initialEntries: ['/resources/posts/lens/popular'] },
  )
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })}>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </QueryClientProvider>,
  )
  return router
}

const filtersInUrl = (router: ReturnType<typeof renderLens>) =>
  new URLSearchParams(router.state.location.search).get('filters')

describe('ResourceLensPage — the route moves to another lens', () => {
  it("seeds the other lens's default filters", async () => {
    const router = renderLens()
    await waitFor(() => expect(filtersInUrl(router)).toBe('{"status":"published"}'))

    await act(() => router.navigate('/resources/posts/lens/drafts'))

    await waitFor(() => expect(router.state.location.pathname).toBe('/resources/posts/lens/drafts'))
    await waitFor(() => expect(filtersInUrl(router)).toBe('{"status":"draft"}'))
  })

  it('drops a search typed for the previous lens that had not been applied yet', async () => {
    const router = renderLens()
    await screen.findByText('Hello world')
    fireEvent.change(screen.getByPlaceholderText('Search…'), { target: { value: 'hello' } })

    await act(() => router.navigate('/resources/posts/lens/drafts'))
    await screen.findByText('Work in progress')
    // The search is applied 300 ms after the last keystroke.
    await act(async () => { await new Promise((resolve) => setTimeout(resolve, 400)) })

    expect(router.state.location.pathname).toBe('/resources/posts/lens/drafts')
    expect(new URLSearchParams(router.state.location.search).get('search')).toBeNull()
  })

  it('closes the delete confirmation of a row of the previous lens', async () => {
    const router = renderLens()
    await screen.findByText('Hello world')
    fireEvent.click(screen.getByRole('button', { name: 'Delete' }))
    expect(await screen.findByRole('dialog')).toBeTruthy()

    await act(() => router.navigate('/resources/posts/lens/drafts'))

    await screen.findByText('Work in progress')
    expect(screen.queryByRole('dialog')).toBeNull()
    expect(apiDeleteMock).not.toHaveBeenCalled()
  })
})
