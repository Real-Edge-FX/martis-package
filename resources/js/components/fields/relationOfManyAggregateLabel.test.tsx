import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import type { FieldDefinition } from '@/types'

/*
 * The one-of-many aggregate tile names its column in a hover tooltip; a
 * screen reader gets the same sentence from a visually hidden span in the
 * tile (`ofmany_aggregate_column`). A `count(*)` names no column: neither.
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args) } }
})

import { FieldDisplay, registerDefaultFields } from './FieldRenderer'
import { NestedParentProvider } from './NestedParentContext'
import { ToastProvider } from '@/contexts/ToastContext'

registerDefaultFields()

const card = {
  attribute: 'notes', label: 'Latest note', type: 'has_one_of_many',
  nullable: true, readonly: false, required: false, sortable: false, searchable: false,
  showOnIndex: false, showOnDetail: true, showOnForms: false, rules: [],
  relationship: 'notes', relatedResource: 'notes',
  hasOneMeta: { canCreate: false, canUpdate: false, canDelete: false, hideCreateButton: true },
} as unknown as FieldDefinition

function answer(column: string) {
  apiGetMock.mockImplementation((path: string) => {
    if (path === '/api/resources/notes/schema') {
      return Promise.resolve({ data: { fieldsForDetail: [], singularLabel: 'Note' } })
    }
    return Promise.resolve({
      data: { id: 7, _title: 'Newest note' },
      meta: { ofMany: { totalCount: 3, aggregate: { fn: 'sum', column, value: 42 } } },
      links: [],
    })
  })
}

function renderCard() {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <ToastProvider>
        <MemoryRouter>
          <NestedParentProvider value={{ resource: 'users', id: 2 }}>
            <FieldDisplay field={card} value={null} resourceKey="users" context="detail" />
          </NestedParentProvider>
        </MemoryRouter>
      </ToastProvider>
    </QueryClientProvider>,
  )
}

beforeEach(() => apiGetMock.mockReset())

describe('one-of-many aggregate tile', () => {
  it('names its column to screen readers with the tooltip sentence', async () => {
    answer('amount')
    const { container } = renderCard()

    const hidden = await screen.findByText('Aggregated column: amount')
    const tile = container.querySelector('.martis-ofmany-tile')
    expect(hidden.className).toBe('sr-only')
    expect(tile?.contains(hidden)).toBe(true)
    expect(tile?.getAttribute('data-pr-tooltip')).toBe('Aggregated column: amount')
  })

  it('names no column for a count(*)', async () => {
    answer('*')
    const { container } = renderCard()

    await screen.findByText('42')
    expect(container.querySelector('.martis-ofmany-tile .sr-only')).toBeNull()
    expect(container.querySelector('.martis-ofmany-tile')?.hasAttribute('data-pr-tooltip')).toBe(false)
  })
})
