import { describe, it, expect, vi, beforeEach } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * A relationship panel sorts through the same header as the index: click sorts by the column alone, Shift+click adds it,
 * and the request carries the list as `?sort=a:asc,b:desc`.
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args) } }
})

import { RelationshipTableShell } from '@/components/fields/relation/RelationshipTableShell'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()

const field = (attribute: string, label: string) => ({
  attribute, label, type: 'text', nullable: false, readonly: false, required: false,
  sortable: true, searchable: false, showOnIndex: true, showOnDetail: true, showOnForms: true, rules: [], reserved: [],
})

beforeEach(() => {
  apiGetMock.mockReset()
  apiGetMock.mockImplementation((path: string) => {
    if (path.includes('/schema')) {
      return Promise.resolve({ data: { fieldsForIndex: [field('name', 'Name'), field('rank', 'Rank'), field('code', 'Code'), field('note', 'Note')], softDeletes: false } })
    }
    return Promise.resolve({ data: [{ id: 1, name: 'Row one', rank: 1, code: 'x', note: 'n' }], meta: { total: 1, current_page: 1, last_page: 1, per_page: 10 } })
  })
})

const lastSort = () => {
  const urls = apiGetMock.mock.calls.map(([p]) => String(p)).filter((p) => p.startsWith('/api/resources/events?'))
  return new URLSearchParams((urls[urls.length - 1] ?? '').split('?')[1] ?? '').get('sort')
}

function renderShell() {
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <ToastProvider>
        <MemoryRouter>
          <RelationshipTableShell
            title="Events"
            relatedResource="events"
            queryKey={['rel', 'events']}
            fetchUrl={(params: URLSearchParams) => `/api/resources/events?${params.toString()}`}
            perPage={10}
            perPageOptions={[10]}
            searchable={false}
            canCreate={false}
            canUpdate={false}
            canDelete={false}
          />
        </MemoryRouter>
      </ToastProvider>
    </QueryClientProvider>,
  )
}

describe('RelationshipTableShell sorting', () => {
  it('sorts by one column, flips it, adds a second with Shift and refuses a fourth', async () => {
    renderShell()
    await screen.findByText('Row one')

    fireEvent.click(screen.getByRole('button', { name: /^Name/ }))
    await waitFor(() => expect(lastSort()).toBe('name:asc'))
    fireEvent.click(screen.getByRole('button', { name: /^Name/ }))
    await waitFor(() => expect(lastSort()).toBe('name:desc'))

    fireEvent.click(screen.getByRole('button', { name: /^Rank/ }), { shiftKey: true })
    await waitFor(() => expect(lastSort()).toBe('name:desc,rank:asc'))
    fireEvent.click(screen.getByRole('button', { name: /^Code/ }), { shiftKey: true })
    await waitFor(() => expect(lastSort()).toBe('name:desc,rank:asc,code:asc'))

    fireEvent.click(screen.getByRole('button', { name: /^Note/ }), { shiftKey: true })
    expect(await screen.findByText('Up to 3 sort columns')).toBeTruthy()
    expect(lastSort()).toBe('name:desc,rank:asc,code:asc')
  })
})
