import { describe, it, expect, vi, beforeEach } from 'vitest'
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router'
import type { ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * Sorting an index by several columns: click replaces, click again flips, Shift+click adds (and cycles asc, desc, removed),
 * a fourth key is refused with a toast, the "Sorted by" chip edits the list, and the saved view holds the list (an entry
 * of the earlier `sortBy` / `sortDir` shape is read once as one key).
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

const defaultSort = [{ attribute: 'category', direction: 'asc' }, { attribute: 'position', direction: 'asc' }]

function mockApi(withDefault: boolean) {
  const fields = [field('title', 'Title'), field('category', 'Category'), field('position', 'Position'), field('locale', 'Language')]
  apiGetMock.mockImplementation((path: unknown) => {
    if (typeof path === 'string' && path.includes('/schema')) {
      return Promise.resolve({
        data: {
          uriKey: 'posts', label: 'Posts', singularLabel: 'Post', softDeletes: false, stickyView: true,
          group: null, fields, fieldsForIndex: fields, errorDisplay: 'inline', messages: {}, actions: [],
          defaultSort: withDefault ? defaultSort : [],
        } as unknown as ResourceSchema,
      })
    }
    return Promise.resolve({
      data: [{ id: 1, title: 'Alpha', category: 'a', position: 1, locale: 'pt' }],
      meta: { total: 1, current_page: 1, last_page: 1, per_page: 25 },
    })
  })
}

const lastSort = () => {
  const urls = apiGetMock.mock.calls.map(([p]) => String(p)).filter((p) => p.startsWith('/api/resources/posts?'))
  return new URLSearchParams((urls[urls.length - 1] ?? '').split('?')[1] ?? '').get('sort')
}

const stored = () => JSON.parse(sessionStorage.getItem('martis:view:1:posts') ?? '{}')

async function renderIndex() {
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
  return router
}

const header = (label: string) => screen.getByRole('button', { name: new RegExp(`^${label}`) })
const shiftClick = (label: string) => fireEvent.click(header(label), { shiftKey: true })

beforeEach(() => {
  apiGetMock.mockReset()
  sessionStorage.clear()
  localStorage.clear()
})

describe('ResourceIndexPage header sorting', () => {
  it('click sorts by that column alone and click again flips it', async () => {
    mockApi(false)
    await renderIndex()

    fireEvent.click(header('Title'))
    await waitFor(() => expect(lastSort()).toBe('title:asc'))
    fireEvent.click(header('Title'))
    await waitFor(() => expect(lastSort()).toBe('title:desc'))
    fireEvent.click(header('Language'))
    await waitFor(() => expect(lastSort()).toBe('locale:asc'))
  })

  it('shift+click adds columns and cycles asc, desc, removed', async () => {
    mockApi(false)
    await renderIndex()

    fireEvent.click(header('Language'))
    await waitFor(() => expect(lastSort()).toBe('locale:asc'))
    shiftClick('Category')
    await waitFor(() => expect(lastSort()).toBe('locale:asc,category:asc'))
    shiftClick('Category')
    await waitFor(() => expect(lastSort()).toBe('locale:asc,category:desc'))
    shiftClick('Category')
    await waitFor(() => expect(lastSort()).toBe('locale:asc'))
  })

  it('refuses a fourth key with a toast and keeps the request as it was', async () => {
    mockApi(false)
    await renderIndex()

    fireEvent.click(header('Title'))
    shiftClick('Category')
    shiftClick('Position')
    await waitFor(() => expect(lastSort()).toBe('title:asc,category:asc,position:asc'))

    shiftClick('Language')

    expect(await screen.findByText('Up to 3 sort columns')).toBeTruthy()
    expect(lastSort()).toBe('title:asc,category:asc,position:asc')
  })
})

describe('ResourceIndexPage "Sorted by" chip', () => {
  it('shows only when the sort differs from the default, and edits the list', async () => {
    mockApi(true)
    await renderIndex()

    // The default seeds the sort: no chip.
    await waitFor(() => expect(lastSort()).toBe('category:asc,position:asc'))
    expect(screen.queryByRole('button', { name: /Sorted by/ })).toBeNull()

    fireEvent.click(header('Language'))
    await waitFor(() => expect(lastSort()).toBe('locale:asc'))
    fireEvent.click(await screen.findByRole('button', { name: /Sorted by/ }))
    const dialog = screen.getByRole('dialog')

    fireEvent.click(within(dialog).getByRole('button', { name: /Add column/ }))
    fireEvent.change(within(dialog).getByRole('combobox'), { target: { value: 'category' } })
    await waitFor(() => expect(lastSort()).toBe('locale:asc,category:asc'))

    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: /Language: Move down/ }))
    await waitFor(() => expect(lastSort()).toBe('category:asc,locale:asc'))

    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: /Category: Change direction/ }))
    await waitFor(() => expect(lastSort()).toBe('category:desc,locale:asc'))

    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: /Language: Remove/ }))
    await waitFor(() => expect(lastSort()).toBe('category:desc'))
  })

  it('hides again when the user sorts back to the default, and Reset view returns to it', async () => {
    mockApi(true)
    await renderIndex()
    await waitFor(() => expect(lastSort()).toBe('category:asc,position:asc'))

    fireEvent.click(header('Title'))
    await waitFor(() => expect(lastSort()).toBe('title:asc'))
    expect(screen.getByRole('button', { name: /Sorted by/ })).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: /Reset view/ }))

    await waitFor(() => expect(lastSort()).toBe('category:asc,position:asc'))
    expect(screen.queryByRole('button', { name: /Sorted by/ })).toBeNull()
  })

  it('an emptied list falls back to the default', async () => {
    mockApi(true)
    await renderIndex()

    fireEvent.click(header('Title'))
    await waitFor(() => expect(lastSort()).toBe('title:asc'))
    fireEvent.click(await screen.findByRole('button', { name: /Sorted by/ }))
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: /Title: Remove/ }))

    await waitFor(() => expect(lastSort()).toBe('category:asc,position:asc'))
  })
})

describe('ResourceIndexPage saved sort', () => {
  it('stores the list and restores it after a record round trip, over the default', async () => {
    mockApi(true)
    const router = await renderIndex()
    await waitFor(() => expect(lastSort()).toBe('category:asc,position:asc'))

    fireEvent.click(header('Language'))
    shiftClick('Title')
    await waitFor(() => expect(lastSort()).toBe('locale:asc,title:asc'))
    expect(stored().sort).toEqual([{ attribute: 'locale', direction: 'asc' }, { attribute: 'title', direction: 'asc' }])

    await act(async () => { await router.navigate('/resources/posts/1') })
    await screen.findByText('Detail page')
    await act(async () => { await router.navigate(-1) })
    await screen.findByText('Alpha')

    await waitFor(() => expect(lastSort()).toBe('locale:asc,title:asc'))
  })

  it('reads an entry of the earlier sortBy / sortDir shape as one key and writes the list next', async () => {
    mockApi(false)
    sessionStorage.setItem('martis:view:1:posts', JSON.stringify({ page: 1, sortBy: 'title', sortDir: 'desc' }))
    await renderIndex()

    await waitFor(() => expect(lastSort()).toBe('title:desc'))
    fireEvent.click(header('Language'))
    await waitFor(() => expect(lastSort()).toBe('locale:asc'))
    expect(stored().sort).toEqual([{ attribute: 'locale', direction: 'asc' }])
    expect(stored()).not.toHaveProperty('sortBy')
  })
})
