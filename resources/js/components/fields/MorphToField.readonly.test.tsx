import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, fireEvent, screen, waitFor, act } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { FieldDefinition } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * `MorphTo::fill()` skips a `readonly()` field, and an `immutable()` one on
 * update, which the update forms hand to the input as `readonly`. The input
 * disabled its type select and record trigger but kept the inline-create "+"
 * of `showCreateRelationButton()` live: it created a real record and selected
 * it, and the save dropped the new target silently (an orphan record). A
 * readonly MorphTo offers no "+", and a record created while the field turned
 * readonly does not change its value; an editable one creates and selects as
 * before.
 */

const apiGetMock = vi.fn()
const apiPostMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGetMock(...args),
      post: (...args: unknown[]) => apiPostMock(...args),
    },
  }
})

import { MorphToFieldInput } from './MorphToField'
import { registerDefaultFields } from './FieldRenderer'

registerDefaultFields()

function makeField(readonly: boolean): FieldDefinition {
  return {
    attribute: 'commentable', label: 'Commentable', type: 'morph_to',
    morphTypes: [
      { value: 'posts', label: 'Post', authorizedToViewAny: true, authorizedToCreate: true },
      { value: 'videos', label: 'Video', authorizedToViewAny: true, authorizedToCreate: true },
    ],
    showCreateRelationButton: true,
    nullable: false, readonly, required: false, sortable: false, searchable: false,
    showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [],
  } as unknown as FieldDefinition
}

const titleField = {
  attribute: 'title', label: 'Title', type: 'text',
  nullable: false, readonly: false, required: true, sortable: false, searchable: false,
  showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [],
}

const stored = { type: 'App\\Models\\Post', id: 1, title: 'Hello', resourceType: 'posts' }

function renderInput(readonly: boolean) {
  const onChange = vi.fn()
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  const ui = (locked: boolean) => (
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <ToastProvider>
          <MorphToFieldInput field={makeField(locked)} value={stored} onChange={onChange} resourceKey="comments" />
        </ToastProvider>
      </MemoryRouter>
    </QueryClientProvider>
  )
  const view = render(ui(readonly))
  return { onChange, lock: () => view.rerender(ui(true)) }
}

const createButton = () => document.querySelector<HTMLButtonElement>('.martis-morphto-create-btn')
const trigger = () => document.querySelector<HTMLButtonElement>('.martis-belongs-to-trigger')
const triggerText = () => document.querySelector('.martis-belongs-to-trigger-label')?.textContent ?? null

/** Opens the inline-create modal, types a title and submits it. */
async function createInline(title: string) {
  fireEvent.click(createButton()!)
  const input = await waitFor(() => {
    const el = document.getElementById('title') as HTMLInputElement | null
    expect(el).not.toBeNull()
    return el as HTMLInputElement
  })
  fireEvent.change(input, { target: { value: title } })
  fireEvent.click(screen.getByRole('button', { name: 'Create Post' }))
  await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(1))
}

beforeEach(() => {
  apiGetMock.mockReset()
  apiPostMock.mockReset()
  apiGetMock.mockImplementation((path: string) =>
    path === '/api/resources/posts/inline-create-schema'
      ? Promise.resolve({ data: { fields: [titleField], singularLabel: 'Post', label: 'Posts' } })
      : Promise.resolve({ data: [] }),
  )
})

describe('MorphToFieldInput readonly', () => {
  it('offers no inline-create button', () => {
    renderInput(true)

    expect(trigger()?.disabled).toBe(true)
    expect(triggerText()).toBe('Hello')
    expect(createButton()).toBeNull()
  })

  it('keeps its value when it turns readonly while an inline create is saving', async () => {
    let settle: (res: unknown) => void = () => {}
    apiPostMock.mockReturnValue(new Promise((resolve) => { settle = resolve }))
    const { onChange, lock } = renderInput(false)
    await createInline('Launch')

    lock()
    await act(async () => { settle({ data: { id: 9, title: 'Launch' } }) })

    expect(onChange).not.toHaveBeenCalled()
    expect(triggerText()).toBe('Hello')
    expect(createButton()).toBeNull()
  })
})

describe('MorphToFieldInput editable', () => {
  it('creates a record of the selected type through the "+" and selects it', async () => {
    apiPostMock.mockResolvedValue({ data: { id: 9, title: 'Launch' } })
    const { onChange } = renderInput(false)

    expect(trigger()?.disabled).toBe(false)
    await createInline('Launch')

    await waitFor(() => expect(onChange).toHaveBeenCalledWith({ resourceType: 'posts', id: 9, title: 'Launch' }))
    expect(apiPostMock).toHaveBeenCalledWith('/api/resources/posts/inline-create', { title: 'Launch' })
    expect(triggerText()).toBe('Launch')
  })
})
