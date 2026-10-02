import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import type { FieldDefinition } from '@/types'

/*
 * The context-free relatable endpoint (`/api/resources/_/_/relatable/...`)
 * has no field to read the picker's attributes from, and serialises a picker
 * row as its key, its title and only the attributes the request names, so
 * the picker names the title attribute and, when it shows subtitles, the
 * subtitle attribute.
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

function makeField(overrides: Record<string, unknown> = {}): FieldDefinition {
  return {
    attribute: 'owner_id', label: 'Owner', type: 'belongs_to', relatedResource: 'users',
    titleAttribute: 'name', nullable: true, readonly: false, required: false, sortable: false,
    searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
    rules: [],
    ...overrides,
  } as unknown as FieldDefinition
}

function open(field: FieldDefinition, scope: { resourceKey?: string } = {}) {
  render(
    <QueryClientProvider client={new QueryClient()}>
      <MemoryRouter>
        <BelongsToFieldInput field={field} value={null} onChange={() => {}} resourceKey={scope.resourceKey} />
      </MemoryRouter>
    </QueryClientProvider>,
  )
  fireEvent.click(document.querySelector('.martis-belongs-to-trigger') as HTMLElement)
}

beforeEach(() => {
  apiGetMock.mockReset()
  apiGetMock.mockResolvedValue({ data: [{ id: 7, _title: 'Ana', name: 'Ana' }] })
})

describe('BelongsToFieldInput context-free relatable URL', () => {
  it('names the title attribute the picker reads', async () => {
    open(makeField())

    await waitFor(() => expect(apiGetMock).toHaveBeenCalled())
    expect(apiGetMock.mock.calls[0][0]).toBe(
      '/api/resources/_/_/relatable/owner_id?per_page=20&related_resource=users&title_attribute=name',
    )
  })

  it('names the subtitle attribute too when the picker shows subtitles', async () => {
    open(makeField({ titleAttribute: 'full_name', withSubtitles: true, subtitleAttribute: 'region' }))

    await waitFor(() => expect(apiGetMock).toHaveBeenCalled())
    expect(apiGetMock.mock.calls[0][0]).toBe(
      '/api/resources/_/_/relatable/owner_id?per_page=20&related_resource=users&title_attribute=full_name&subtitle_attribute=region',
    )
  })

  it('leaves the attributes out of a form-scoped URL: the server reads them from the field', async () => {
    open(makeField(), { resourceKey: 'posts' })

    await waitFor(() => expect(apiGetMock).toHaveBeenCalled())
    expect(apiGetMock.mock.calls[0][0]).toBe('/api/resources/posts/_/relatable/owner_id?per_page=20')
  })
})
