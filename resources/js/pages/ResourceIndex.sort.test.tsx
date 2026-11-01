import { describe, it, expect, vi, beforeEach } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router'
import type { ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * Clicking a sortable header sorts ascending, a second click on the same
 * header flips to descending, and the index request carries the direction.
 * A request that always sent `direction=asc` left the descending sort
 * a no-op for the user while the arrow flipped.
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args) } }
})

import { ResourceIndexPage } from '@/pages/ResourceIndex'

const titleField = {
  attribute: 'title', label: 'Title', type: 'text', nullable: false, readonly: false, required: false,
  sortable: true, searchable: false, showOnIndex: true, showOnDetail: true, showOnForms: true, rules: [], reserved: [],
}

function mockIndex() {
  apiGetMock.mockImplementation((path: unknown) => {
    if (typeof path === 'string' && path.includes('/schema')) {
      return Promise.resolve({
        data: {
          uriKey: 'posts', label: 'Posts', singularLabel: 'Post', softDeletes: false, stickyView: false,
          group: null, fields: [titleField], fieldsForIndex: [titleField], errorDisplay: 'inline', messages: {},
          actions: [],
        } as unknown as ResourceSchema,
      })
    }
    return Promise.resolve({ data: [{ id: 1, title: 'Alpha' }], meta: { total: 1, current_page: 1, last_page: 1, per_page: 25 } })
  })
}

const indexRequests = () =>
  apiGetMock.mock.calls.map(([p]) => String(p)).filter((p) => p.startsWith('/api/resources/posts?'))

beforeEach(() => apiGetMock.mockReset())

describe('ResourceIndexPage sorting', () => {
  it('sends direction=asc on the first click and direction=desc on the second', async () => {
    mockIndex()
    const router = createMemoryRouter([{ path: '/resources/:resource', element: <ResourceIndexPage /> }], { initialEntries: ['/resources/posts'] })
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <ToastProvider>
          <RouterProvider router={router} />
        </ToastProvider>
      </QueryClientProvider>,
    )

    await screen.findByText('Alpha')
    const header = () => screen.getByRole('button', { name: /Title/ })

    fireEvent.click(header())
    await waitFor(() => expect(indexRequests().some((u) => u.includes('sort=title') && u.includes('direction=asc'))).toBe(true))

    fireEvent.click(header())
    await waitFor(() => expect(indexRequests().some((u) => u.includes('sort=title') && u.includes('direction=desc'))).toBe(true))
  })
})
