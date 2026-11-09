import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router'
import type { ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * The filter panel never opens by itself. A `?filters=` deep link (a
 * `MenuItem::filter()->applies()` entry, a shared URL) applies the filters
 * and shows them as chips next to the "Filters (n)" toggle, with the panel
 * collapsed; it used to open the panel and push the table down. Only the
 * user opens it, by clicking the toggle, or a saved view restores a panel
 * the user left open.
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args) } }
})

import { AuthProvider } from '@/contexts/AuthContext'
import { ResourceIndexPage } from '@/pages/ResourceIndex'

const schema = {
  uriKey: 'tickets',
  label: 'Tickets',
  singularLabel: 'Ticket',
  softDeletes: false,
  stickyView: true,
  group: null,
  fields: [],
  fieldsForIndex: [],
  errorDisplay: 'inline',
  messages: {},
  filters: [
    {
      type: 'filter',
      filterType: 'select',
      name: 'Owner',
      uriKey: 'owner',
      component: null,
      options: [{ label: 'Mine', value: 'mine' }, { label: 'Anyone', value: 'any' }],
      default: null,
      meta: {},
    },
  ],
} as unknown as ResourceSchema

beforeEach(() => {
  apiGetMock.mockReset()
  sessionStorage.clear()
  localStorage.clear()
  apiGetMock.mockImplementation((path: string) => {
    if (path.includes('/schema')) return Promise.resolve({ data: schema })
    return Promise.resolve({ data: [], meta: { total: 0, current_page: 1, last_page: 1, per_page: 25, from: null, to: null } })
  })
})

function renderIndexAt(url: string) {
  const router = createMemoryRouter(
    [{ path: '/resources/:resource', element: <ResourceIndexPage /> }],
    { initialEntries: [url] },
  )
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })}>
      <AuthProvider initialUser={{ id: 1, name: 'U', email: 'u@example.com' }}>
        <ToastProvider>
          <RouterProvider router={router} />
        </ToastProvider>
      </AuthProvider>
    </QueryClientProvider>,
  )
}

const toggle = () => screen.findByTestId('filter-toggle')
// The panel's only content is the filter grid.
const panel = () => document.querySelector('.martis-filter-grid')

describe('ResourceIndexPage — the filter panel opens collapsed', () => {
  it('applies ?filters= as a chip and a count, with the panel collapsed', async () => {
    renderIndexAt(`/resources/tickets?filters=${encodeURIComponent('{"owner":"mine"}')}`)

    expect((await toggle()).textContent).toContain('1')
    expect(document.querySelector('.martis-filter-chip')?.textContent).toBe('Owner:Mine')
    expect(panel()).toBeNull()
  })

  it('opens the panel when the user clicks the toggle', async () => {
    renderIndexAt(`/resources/tickets?filters=${encodeURIComponent('{"owner":"mine"}')}`)

    fireEvent.click(await toggle())

    await waitFor(() => expect(panel()).not.toBeNull())
  })

  it('keeps the panel collapsed on a ?search= deep link', async () => {
    renderIndexAt('/resources/tickets?search=refund')
    await toggle()
    expect(panel()).toBeNull()
  })

  it('restores a panel the user left open in the saved view', async () => {
    sessionStorage.setItem('martis:view:1:tickets', JSON.stringify({ filtersOpen: true }))

    renderIndexAt('/resources/tickets')

    await waitFor(() => expect(panel()).not.toBeNull())
  })
})
