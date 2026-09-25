import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import type { FieldDefinition } from '@/types'

/*
 * A one-record card deletes the record it shows: the DELETE names it
 * (`?relatedId=`), so a record that took its place since the card loaded
 * (a newer one-of-many record, a replaced HasOne) answers 409 instead of
 * being deleted. On that answer the card reloads and says why in a toast.
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

import { ApiError } from '@/lib/api'
import { FieldDisplay, registerDefaultFields } from './FieldRenderer'
import { NestedParentProvider } from './NestedParentContext'
import { ToastProvider } from '@/contexts/ToastContext'

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

function cardPaths(): string[] {
  return apiGetMock.mock.calls.map(([path]) => String(path)).filter((path) => path.includes('/profile'))
}

function answerWithRecord(id: number, title: string) {
  apiGetMock.mockImplementation((path: string) => {
    if (path === '/api/resources/profiles/schema') {
      return Promise.resolve({ data: { fieldsForDetail: [titleField], singularLabel: 'Profile' } })
    }
    return Promise.resolve({ data: { id, title, _title: title }, meta: {}, links: [] })
  })
}

function renderCard(field: FieldDefinition) {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <ToastProvider>
        <MemoryRouter>
          <NestedParentProvider value={{ resource: 'users', id: 2 }}>
            <FieldDisplay field={field} value={null} resourceKey="users" context="detail" />
          </NestedParentProvider>
        </MemoryRouter>
      </ToastProvider>
    </QueryClientProvider>,
  )
}

async function confirmDelete() {
  fireEvent.click(await screen.findByRole('button', { name: 'Delete' }))
  const buttons = await screen.findAllByRole('button', { name: /Delete/ })
  fireEvent.click(buttons[buttons.length - 1])
}

beforeEach(() => {
  apiGetMock.mockReset()
  apiDeleteMock.mockReset()
})

describe.each([
  ['has_one', 'hasOneMeta', 'has-one'],
  ['has_one_of_many', 'hasOneMeta', 'has-one'],
  ['has_one_through', 'hasOneMeta', 'has-one'],
  ['morph_one', 'morphOneMeta', 'morph-one'],
  ['morph_one_of_many', 'morphOneMeta', 'morph-one'],
])('%s card', (type, metaKey, endpoint) => {
  it('deletes the record it shows, naming it', async () => {
    answerWithRecord(7, 'Shown profile')
    apiDeleteMock.mockResolvedValue({ data: null })
    renderCard(card(type, metaKey))
    await screen.findByText('Shown profile')

    await confirmDelete()

    await waitFor(() => expect(apiDeleteMock).toHaveBeenCalledWith(`/api/resources/users/2/${endpoint}/profile?relatedId=7`))
  })

  it('reloads the card and says why when the record changed since it loaded', async () => {
    answerWithRecord(7, 'Shown profile')
    apiDeleteMock.mockRejectedValue(new ApiError(409, 'The record changed since the card loaded; reload to see it.'))
    renderCard(card(type, metaKey))
    await screen.findByText('Shown profile')
    const loadsBefore = cardPaths().length

    await confirmDelete()

    expect(await screen.findByText('The record changed since the card loaded; reload to see it.')).toBeTruthy()
    await waitFor(() => expect(cardPaths().length).toBeGreaterThan(loadsBefore))
  })
})
