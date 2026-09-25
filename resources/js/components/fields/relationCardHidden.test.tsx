import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import type { FieldDefinition } from '@/types'

/*
 * A one-record card whose record the user may not view is not rendered at
 * all, as Nova drops the panel (nova-dusk-suite HasOneAuthorizationTest):
 * the server answers `data: null` with `meta.hidden`, and the card shows no
 * heading, no Create (which would add a second record to a HasOne), no Edit
 * and no count. A card with no record at all keeps its empty state and its
 * Create.
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args) },
  }
})

import { FieldDisplay, registerDefaultFields } from './FieldRenderer'
import { NestedParentProvider } from './NestedParentContext'

registerDefaultFields()

const titleField = {
  attribute: 'title', label: 'Title', type: 'text',
  nullable: false, readonly: false, required: true, sortable: false, searchable: false,
  showOnIndex: true, showOnDetail: true, showOnForms: true, rules: [],
}

function card(type: string, metaKey: string): FieldDefinition {
  return {
    attribute: 'profile', label: 'Profile card', type,
    nullable: true, readonly: false, required: false, sortable: false, searchable: false,
    showOnIndex: false, showOnDetail: true, showOnForms: false, rules: [],
    relationship: 'profile', relatedResource: 'profiles',
    [metaKey]: { canCreate: true, canUpdate: true, canDelete: true, hideCreateButton: false },
  } as unknown as FieldDefinition
}

function answerWith(meta: Record<string, unknown>) {
  apiGetMock.mockImplementation((path: string) => {
    if (path === '/api/resources/profiles/schema') {
      return Promise.resolve({ data: { fieldsForDetail: [titleField], singularLabel: 'Profile' } })
    }
    return Promise.resolve({ data: null, meta, links: [] })
  })
}

function renderCard(field: FieldDefinition) {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>
        <NestedParentProvider value={{ resource: 'users', id: 2 }}>
          <FieldDisplay field={field} value={null} resourceKey="users" context="detail" />
        </NestedParentProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  apiGetMock.mockReset()
})

describe.each([
  ['has_one', 'hasOneMeta'],
  ['has_one_of_many', 'hasOneMeta'],
  ['has_one_through', 'hasOneMeta'],
  ['morph_one', 'morphOneMeta'],
  ['morph_one_of_many', 'morphOneMeta'],
])('%s card', (type, metaKey) => {
  it('renders nothing when the relationship holds a record the user may not view', async () => {
    answerWith({ hidden: true })
    const { container } = renderCard(card(type, metaKey))

    await waitFor(() => expect(apiGetMock.mock.calls.some(([path]) => String(path).includes('/profile'))).toBe(true))
    await waitFor(() => expect(screen.queryByText(/Loading/)).toBeNull())

    expect(screen.queryByText('Profile card')).toBeNull()
    expect(screen.queryByRole('button', { name: /create/i })).toBeNull()
    expect(screen.queryByRole('link', { name: /create/i })).toBeNull()
    expect(container.textContent ?? '').toBe('')
  })

  it('keeps the card when the relationship holds no record (control)', async () => {
    answerWith({})
    renderCard(card(type, metaKey))

    expect(await screen.findByText('Profile card')).toBeTruthy()
  })
})
