import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import type { FieldDefinition } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * The context-free relatable endpoint (`/api/resources/_/_/relatable/...`)
 * serialises a picker row as its key, its title and only the attributes the
 * request names: a MorphTo and a Tag picker without a form scope name theirs
 * as the BelongsTo one does.
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args) },
  }
})

import { MorphToFieldInput } from './MorphToField'
import { TagFieldInput } from './TagField'

function field(overrides: Record<string, unknown>): FieldDefinition {
  return {
    nullable: true, readonly: false, required: false, sortable: false, searchable: false,
    showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [],
    ...overrides,
  } as unknown as FieldDefinition
}

function wrap(ui: React.ReactElement) {
  return render(
    <QueryClientProvider client={new QueryClient()}>
      <ToastProvider>
        <MemoryRouter>{ui}</MemoryRouter>
      </ToastProvider>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  apiGetMock.mockReset()
  apiGetMock.mockResolvedValue({ data: [{ id: 7, _title: 'Ana', name: 'Ana' }] })
})

describe('MorphToFieldInput context-free relatable URL', () => {
  const morphField = (overrides: Record<string, unknown> = {}) => field({
    attribute: 'commentable', label: 'Commentable', type: 'morph_to', titleAttribute: 'name',
    morphTypes: [{ value: 'posts', label: 'Post', authorizedToViewAny: true, authorizedToCreate: true }],
    ...overrides,
  })

  function open(f: FieldDefinition) {
    wrap(<MorphToFieldInput field={f} value={{ resourceType: 'posts', id: null, title: null }} onChange={() => {}} />)
    fireEvent.click(document.querySelector('.martis-belongs-to-trigger') as HTMLElement)
  }

  it('names the title attribute the picker reads', async () => {
    open(morphField())

    await waitFor(() => expect(apiGetMock).toHaveBeenCalled())
    expect(apiGetMock.mock.calls[0][0]).toBe(
      '/api/resources/_/_/relatable/commentable?per_page=20&related_resource=posts&title_attribute=name',
    )
  })

  it('names the subtitle attribute too when the picker shows subtitles', async () => {
    open(morphField({ withSubtitles: true, subtitleAttribute: 'region' }))

    await waitFor(() => expect(apiGetMock).toHaveBeenCalled())
    expect(apiGetMock.mock.calls[0][0]).toBe(
      '/api/resources/_/_/relatable/commentable?per_page=20&related_resource=posts&title_attribute=name&subtitle_attribute=region',
    )
  })
})

describe('TagFieldInput context-free relatable URL', () => {
  it('names the title attribute the picker reads', async () => {
    const tagField = field({
      attribute: 'tags', label: 'Tags', type: 'tag', relatedResource: 'tags', titleAttribute: 'label', preload: true,
    })
    wrap(<TagFieldInput field={tagField} value={[]} onChange={() => {}} />)

    await waitFor(() => expect(apiGetMock).toHaveBeenCalled())
    expect(apiGetMock.mock.calls[0][0]).toBe(
      '/api/resources/_/_/relatable/tags?per_page=30&related_resource=tags&title_attribute=label',
    )
  })
})
