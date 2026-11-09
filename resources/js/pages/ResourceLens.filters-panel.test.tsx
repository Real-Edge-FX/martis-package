import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router'
import type { FieldDefinition, ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'
import { allowDataRouterNavigation } from '@/test-support/dataRouterNavigation'

/*
 * A lens keeps its filters in the URL. The filter panel opens collapsed on a
 * `?filters=` link, like the resource index, and once the user opens it, it
 * stays open while they change the filters: each change rewrites the URL,
 * and the panel used to start over (collapsed) on every one.
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

const titleField = {
  attribute: 'title', label: 'Title', type: 'text',
  nullable: false, readonly: false, required: false, sortable: false, searchable: false,
  showOnIndex: true, showOnDetail: true, showOnForms: true, rules: [], reserved: [],
} as unknown as FieldDefinition

const tickets = {
  uriKey: 'tickets',
  label: 'Tickets',
  singularLabel: 'Ticket',
  softDeletes: false,
  stickyView: false,
  fields: [],
  fieldsForIndex: [titleField],
  errorDisplay: 'inline',
  lenses: [{
    type: 'lens', name: 'Open', uriKey: 'open', component: null, perPageOptions: [25], polling: false,
    pollingInterval: 0, showPollingToggle: false, defaultFilters: {}, cacheTtlSeconds: 0, meta: {},
  }],
  filters: [
    {
      type: 'filter', filterType: 'select', name: 'Owner', uriKey: 'owner', component: null,
      options: [{ label: 'Mine', value: 'mine' }], default: null, meta: {},
    },
    {
      type: 'filter', filterType: 'select', name: 'Priority', uriKey: 'priority', component: null,
      options: [{ label: 'High', value: 'high' }], default: null, meta: {},
    },
  ],
  messages: {},
} as unknown as ResourceSchema

beforeEach(() => {
  apiGetMock.mockReset()
  apiGetMock.mockImplementation((path: string) => {
    if (path === '/api/resources/tickets/schema') return Promise.resolve({ data: tickets })
    if (path.startsWith('/api/resources/tickets/lenses/open?')) {
      return Promise.resolve({
        data: [{ id: 1, title: 'Refund' }],
        meta: { total: 1, current_page: 1, last_page: 1, per_page: 25, from: 1, to: 1, fields: [titleField], actions: [] },
      })
    }
    return Promise.resolve({ data: [] })
  })
})

function renderLensAt(url: string) {
  const router = createMemoryRouter(
    [{ path: '/resources/:resource/lens/:lens', element: <ResourceLensPage /> }],
    { initialEntries: [url] },
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

const panel = () => document.querySelector('.martis-filter-grid')
const both = encodeURIComponent('{"owner":"mine","priority":"high"}')

describe('ResourceLensPage — the filter panel', () => {
  it('opens collapsed on a ?filters= link, with the chips and the count', async () => {
    renderLensAt(`/resources/tickets/lens/open?filters=${both}`)

    expect((await screen.findByTestId('filter-toggle')).textContent).toContain('2')
    expect(document.querySelectorAll('.martis-filter-chip')).toHaveLength(2)
    expect(panel()).toBeNull()
  })

  it('stays open while the user changes the filters', async () => {
    const router = renderLensAt(`/resources/tickets/lens/open?filters=${both}`)
    fireEvent.click(await screen.findByTestId('filter-toggle'))
    await waitFor(() => expect(panel()).not.toBeNull())

    fireEvent.click(document.querySelector('.martis-filter-pill-clear-priority')!)

    await waitFor(() =>
      expect(new URLSearchParams(router.state.location.search).get('filters')).toBe('{"owner":"mine"}'),
    )
    expect(panel()).not.toBeNull()
  })
})
