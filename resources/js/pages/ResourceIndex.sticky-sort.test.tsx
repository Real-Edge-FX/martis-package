import { describe, it, expect, vi, beforeEach } from 'vitest'
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router'
import type { ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * The sort the user picks on an index survives opening a record and coming
 * back. The schema is cached by then, so a resource with a `defaultSort()`
 * used to seed it again on the remount and override the restored sort.
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args) } }
})

import { AuthProvider } from '@/contexts/AuthContext'
import { ResourceIndexPage } from '@/pages/ResourceIndex'

const field = (attribute: string, label: string) => ({
  attribute, label, type: 'text', nullable: false, readonly: false, required: false,
  sortable: true, searchable: false, showOnIndex: true, showOnDetail: true, showOnForms: true, rules: [], reserved: [],
})

function mockApi(defaultSort: string | null) {
  const fields = [field('title', 'Title'), field('created_at', 'Created')]
  apiGetMock.mockImplementation((path: unknown) => {
    if (typeof path === 'string' && path.includes('/schema')) {
      return Promise.resolve({
        data: {
          uriKey: 'posts', label: 'Posts', singularLabel: 'Post', softDeletes: false, stickyView: true,
          group: null, fields, fieldsForIndex: fields, errorDisplay: 'inline', messages: {}, actions: [],
          defaultSort, defaultSortDirection: defaultSort ? 'desc' : null,
        } as unknown as ResourceSchema,
      })
    }
    return Promise.resolve({ data: [{ id: 1, title: 'Alpha', created_at: '2026-01-01' }], meta: { total: 1, current_page: 1, last_page: 1, per_page: 25 } })
  })
}

const lastIndexRequest = () => {
  const urls = apiGetMock.mock.calls.map(([p]) => String(p)).filter((p) => p.startsWith('/api/resources/posts?'))
  return urls[urls.length - 1] ?? ''
}

beforeEach(() => {
  apiGetMock.mockReset()
  sessionStorage.clear()
  localStorage.clear()
})

describe.each([
  ['with a defaultSort', 'created_at'],
  ['without a defaultSort', null],
])('ResourceIndexPage keeps the chosen sort after a record round trip (%s)', (_label, defaultSort) => {
  it('restores the sort column and direction on the way back', async () => {
    mockApi(defaultSort)
    const router = createMemoryRouter(
      [
        { path: '/resources/:resource', element: <ResourceIndexPage /> },
        { path: '/resources/:resource/:id', element: <div>Detail page</div> },
      ],
      { initialEntries: ['/resources/posts'] },
    )
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <AuthProvider initialUser={{ id: 1, name: 'U', email: 'u@example.com' }}>
          <ToastProvider>
            <RouterProvider router={router} />
          </ToastProvider>
        </AuthProvider>
      </QueryClientProvider>,
    )

    await screen.findByText('Alpha')
    fireEvent.click(screen.getByRole('button', { name: /Title/ }))
    fireEvent.click(screen.getByRole('button', { name: /Title/ }))
    await waitFor(() => expect(lastIndexRequest()).toMatch(/sort=title.*direction=desc|direction=desc.*sort=title/))

    await act(async () => { await router.navigate('/resources/posts/1') })
    await screen.findByText('Detail page')
    await act(async () => { await router.navigate(-1) })
    await screen.findByText('Alpha')

    await waitFor(() => {
      const url = lastIndexRequest()
      expect(url).toContain('sort=title')
      expect(url).toContain('direction=desc')
    })
    expect(JSON.parse(sessionStorage.getItem('martis:view:1:posts') ?? '{}')).toMatchObject({ sortBy: 'title', sortDir: 'desc' })
  })
})
