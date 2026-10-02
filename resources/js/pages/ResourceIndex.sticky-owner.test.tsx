import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router'
import type { ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * F084: the index restores the view (search text, filters) saved for the
 * signed-in user, under `martis:view:{userId}:{uriKey}`, and never one saved
 * for another user or in the unscoped format.
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args) } }
})

import { AuthProvider } from '@/contexts/AuthContext'
import { ResourceIndexPage } from '@/pages/ResourceIndex'

const schema = {
  uriKey: 'customers',
  label: 'Customers',
  singularLabel: 'Customer',
  softDeletes: false,
  stickyView: true,
  group: null,
  fields: [],
  fieldsForIndex: [],
  errorDisplay: 'inline',
  messages: {},
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

function renderIndexFor(userId: number | null) {
  const router = createMemoryRouter(
    [{ path: '/resources/:resource', element: <ResourceIndexPage /> }],
    { initialEntries: ['/resources/customers'] },
  )
  const user = userId === null ? null : { id: userId, name: 'U', email: 'u@example.com' }
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })}>
      <AuthProvider initialUser={user}>
        <ToastProvider>
          <RouterProvider router={router} />
        </ToastProvider>
      </AuthProvider>
    </QueryClientProvider>,
  )
}

function searchBox(): HTMLInputElement | null {
  return document.querySelector<HTMLInputElement>('input.martis-resource-search')
}

describe('ResourceIndexPage — the saved view belongs to the signed-in user', () => {
  it("restores the user's own saved search", async () => {
    sessionStorage.setItem('martis:view:1:customers', JSON.stringify({ search: 'alice@example.com' }))

    renderIndexFor(1)

    await waitFor(() => expect(searchBox()?.value).toBe('alice@example.com'))
  })

  it("never shows another user's saved search, nor the unscoped one", async () => {
    sessionStorage.setItem('martis:view:1:customers', JSON.stringify({ search: 'alice@example.com' }))
    sessionStorage.setItem('martis:view:customers', JSON.stringify({ search: 'unscoped' }))

    renderIndexFor(2)

    await waitFor(() => expect(searchBox()).not.toBeNull())
    expect(searchBox()!.value).toBe('')
  })

  it('writes the view under the user, never in the unscoped key', async () => {
    renderIndexFor(5)
    await waitFor(() => expect(searchBox()).not.toBeNull())

    fireEvent.change(searchBox()!, { target: { value: 'bob' } })

    await waitFor(() => expect(JSON.parse(sessionStorage.getItem('martis:view:5:customers') ?? '{}').search).toBe('bob'))
    expect(sessionStorage.getItem('martis:view:customers')).toBeNull()
  })

  it('reads and writes nothing without a signed-in user', async () => {
    sessionStorage.setItem('martis:view:customers', JSON.stringify({ search: 'unscoped' }))

    renderIndexFor(null)

    await waitFor(() => expect(searchBox()).not.toBeNull())
    expect(searchBox()!.value).toBe('')
    fireEvent.change(searchBox()!, { target: { value: 'carol' } })
    await new Promise((resolve) => setTimeout(resolve, 400))
    expect(Object.keys(sessionStorage).filter((key) => key.startsWith('martis:view:'))).toEqual(['martis:view:customers'])
  })
})
