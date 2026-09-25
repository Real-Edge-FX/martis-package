import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, fireEvent, screen, waitFor, act } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { FieldDefinition } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * `BelongsTo::fill()` skips a `readonly()` field, and an `immutable()` one on
 * update, which the update forms hand to the input as `readonly`; a nested
 * create locks the parent's BelongsTo the same way. The input disabled its
 * trigger but kept the inline-create "+" of `showCreateRelationButton()`
 * live: it created a real record and selected it, so an update dropped the
 * new value silently (an orphan record) and a nested create showed another
 * parent than the one the record is created under. A readonly BelongsTo
 * offers no "+", and a record created while the field turned readonly does
 * not change its value; an editable one creates and selects as before.
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

import { BelongsToFieldInput } from './BelongsToField'
import { registerDefaultFields } from './FieldRenderer'

registerDefaultFields()

function makeField(readonly: boolean): FieldDefinition {
  return {
    attribute: 'author', label: 'Author', type: 'belongs_to', relatedResource: 'users',
    showCreateRelationButton: true,
    nullable: false, readonly, required: false, sortable: false, searchable: false,
    showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [],
  } as unknown as FieldDefinition
}

const nameField = {
  attribute: 'name', label: 'Name', type: 'text',
  nullable: false, readonly: false, required: true, sortable: false, searchable: false,
  showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [],
}

const stored = { id: 1, title: 'Ann' }

function renderInput(readonly: boolean) {
  const onChange = vi.fn()
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  const ui = (locked: boolean) => (
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <ToastProvider>
          <BelongsToFieldInput field={makeField(locked)} value={stored} onChange={onChange} resourceKey="posts" />
        </ToastProvider>
      </MemoryRouter>
    </QueryClientProvider>
  )
  const view = render(ui(readonly))
  return { onChange, lock: () => view.rerender(ui(true)) }
}

const createButton = () => document.querySelector<HTMLButtonElement>('.martis-create-related-btn')
const trigger = () => document.querySelector<HTMLButtonElement>('.martis-belongs-to-trigger')
const triggerText = () => document.querySelector('.martis-belongs-to-trigger-label')?.textContent ?? null

/** Opens the inline-create modal, types a name and submits it. */
async function createInline(name: string) {
  fireEvent.click(createButton()!)
  const input = await waitFor(() => {
    const el = document.getElementById('name') as HTMLInputElement | null
    expect(el).not.toBeNull()
    return el as HTMLInputElement
  })
  fireEvent.change(input, { target: { value: name } })
  fireEvent.click(screen.getByRole('button', { name: 'Create User' }))
  await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(1))
}

beforeEach(() => {
  apiGetMock.mockReset()
  apiPostMock.mockReset()
  apiGetMock.mockImplementation((path: string) =>
    path === '/api/resources/users/inline-create-schema'
      ? Promise.resolve({ data: { fields: [nameField], singularLabel: 'User', label: 'Users' } })
      : Promise.resolve({ data: [] }),
  )
})

describe('BelongsToFieldInput readonly', () => {
  it('offers no inline-create button', () => {
    renderInput(true)

    expect(trigger()?.disabled).toBe(true)
    expect(triggerText()).toBe('Ann')
    expect(createButton()).toBeNull()
  })

  it('keeps its value when it turns readonly while an inline create is saving', async () => {
    let settle: (res: unknown) => void = () => {}
    apiPostMock.mockReturnValue(new Promise((resolve) => { settle = resolve }))
    const { onChange, lock } = renderInput(false)
    await createInline('Eva')

    lock()
    await act(async () => { settle({ data: { id: 9, title: 'Eva' } }) })

    expect(onChange).not.toHaveBeenCalled()
    expect(triggerText()).toBe('Ann')
    expect(createButton()).toBeNull()
  })
})

describe('BelongsToFieldInput editable', () => {
  it('creates a record through the "+" and selects it', async () => {
    apiPostMock.mockResolvedValue({ data: { id: 9, title: 'Eva' } })
    const { onChange } = renderInput(false)

    expect(trigger()?.disabled).toBe(false)
    await createInline('Eva')

    await waitFor(() => expect(onChange).toHaveBeenCalledWith(9))
    expect(apiPostMock).toHaveBeenCalledWith('/api/resources/users/inline-create', { name: 'Eva' })
    expect(triggerText()).toBe('Eva')
  })
})
