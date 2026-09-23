import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, fireEvent, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactElement } from 'react'
import type { FieldDefinition } from '@/types'

/*
 * `relationSearchable(false)` on a `BelongsTo`, `MorphTo` or `Tag` is
 * documented to turn off the text search of the picker, as it turns off the
 * search box of a relationship panel, and the fields serialised the flag, but
 * no picker read it: the search box always showed. A picker without search
 * now lists its options without a search box, and asks for up to 100 of them
 * (the relatable endpoint's cap) instead of the first page a search would
 * narrow down.
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args) },
  }
})

import { BelongsToFieldInput } from './BelongsToField'
import { MorphToFieldInput } from './MorphToField'
import { TagFieldInput } from './TagField'

const base = {
  nullable: true, readonly: false, required: false, sortable: false, searchable: false,
  showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [],
}

function belongsTo(relationSearchable: boolean): FieldDefinition {
  return { ...base, attribute: 'author', label: 'Author', type: 'belongs_to', relatedResource: 'users', relationSearchable } as unknown as FieldDefinition
}

function morphTo(relationSearchable: boolean): FieldDefinition {
  return {
    ...base, attribute: 'commentable', label: 'Commentable', type: 'morph_to', relationSearchable,
    morphTypes: [{ value: 'posts', label: 'Post', authorizedToViewAny: true, authorizedToCreate: true }],
  } as unknown as FieldDefinition
}

function tag(relationSearchable: boolean): FieldDefinition {
  return { ...base, attribute: 'tags', label: 'Tags', type: 'tag', relatedResource: 'tags', relationSearchable } as unknown as FieldDefinition
}

function renderPicker(ui: ReactElement) {
  render(
    <QueryClientProvider client={new QueryClient()}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  )
}

const pickers: Array<[string, (searchable: boolean) => ReactElement, () => void]> = [
  [
    'BelongsTo',
    (searchable) => <BelongsToFieldInput field={belongsTo(searchable)} value={null} onChange={() => {}} resourceKey="posts" />,
    () => fireEvent.click(document.querySelector('.martis-belongs-to-trigger') as HTMLElement),
  ],
  [
    'MorphTo',
    (searchable) => (
      <MorphToFieldInput
        field={morphTo(searchable)}
        value={{ type: 'App\\Models\\Post', id: 1, title: 'Ana', resourceType: 'posts' }}
        onChange={() => {}}
        resourceKey="comments"
      />
    ),
    () => fireEvent.click(document.querySelector('.martis-belongs-to-trigger') as HTMLElement),
  ],
  [
    'Tag',
    (searchable) => <TagFieldInput field={tag(searchable)} value={[]} onChange={() => {}} resourceKey="posts" />,
    () => fireEvent.click(screen.getByRole('button', { name: 'Add Tags' })),
  ],
]

/** The page size the picker asked the relatable endpoint for. */
function requestedPerPage(): string | null {
  const url = apiGetMock.mock.calls.map((call) => String(call[0])).find((path) => path.includes('/relatable/'))
  return url ? new URL(url, 'http://localhost').searchParams.get('per_page') : null
}

beforeEach(() => {
  apiGetMock.mockReset()
  apiGetMock.mockResolvedValue({ data: [{ id: 1, _title: 'Ana' }, { id: 2, _title: 'Rui' }] })
})

describe.each(pickers)('%s picker', (_name, picker, open) => {
  it('lists its options without a search box when relationSearchable(false)', async () => {
    renderPicker(picker(false))
    open()

    await screen.findByRole('button', { name: 'Rui' })
    expect(document.querySelector('input[type="text"]')).toBeNull()
    expect(requestedPerPage()).toBe('100')
  })

  it('keeps its search box by default', async () => {
    renderPicker(picker(true))
    open()

    await screen.findByRole('button', { name: 'Rui' })
    expect(document.querySelector('input[type="text"]')).not.toBeNull()
    await waitFor(() => expect(requestedPerPage()).not.toBe('100'))
  })
})
