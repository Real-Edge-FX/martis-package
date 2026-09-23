import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, fireEvent, screen, waitFor, act } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { FieldDefinition } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * `Tag::showCreateRelationButton()` is documented to add an inline create
 * button, and the field serialised the flag, but the input never read it: a
 * tag that did not exist yet could only be created by leaving the form. The
 * picker now offers "Create" at the foot of its dropdown, which opens the
 * related resource's inline-create modal and adds the record it creates to
 * the selection. A readonly field offers neither the picker nor the button,
 * and a record created while the field turned readonly leaves its tags alone.
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

import { TagFieldInput } from './TagField'
import { registerDefaultFields } from './FieldRenderer'

registerDefaultFields()

function makeField(overrides: Record<string, unknown> = {}): FieldDefinition {
  return {
    attribute: 'tags', label: 'Tags', type: 'tag', relatedResource: 'tags',
    showCreateRelationButton: true,
    nullable: true, readonly: false, required: false, sortable: false,
    searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
    rules: [],
    ...overrides,
  } as unknown as FieldDefinition
}

const nameField = {
  attribute: 'name', label: 'Name', type: 'text',
  nullable: false, readonly: false, required: true, sortable: false, searchable: false,
  showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [],
}

const stored = [{ id: 1, title: 'php' }]

function renderInput(field: FieldDefinition) {
  const onChange = vi.fn()
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  const ui = (f: FieldDefinition) => (
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <ToastProvider>
          <TagFieldInput field={f} value={stored} onChange={onChange} resourceKey="posts" recordId={1} />
        </ToastProvider>
      </MemoryRouter>
    </QueryClientProvider>
  )
  const view = render(ui(field))
  return { onChange, lock: () => view.rerender(ui({ ...field, readonly: true } as FieldDefinition)) }
}

const createButton = () => screen.queryByRole('button', { name: 'Create' })

/** Opens the picker, then the inline-create modal, types a name and submits it. */
async function createInline(name: string) {
  fireEvent.click(screen.getByRole('button', { name: 'Add Tags' }))
  fireEvent.click(await waitFor(() => screen.getByRole('button', { name: 'Create' })))
  const input = await waitFor(() => {
    const el = document.getElementById('name') as HTMLInputElement | null
    expect(el).not.toBeNull()
    return el as HTMLInputElement
  })
  fireEvent.change(input, { target: { value: name } })
  fireEvent.click(screen.getByRole('button', { name: 'Create Tag' }))
  await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(1))
}

beforeEach(() => {
  apiGetMock.mockReset()
  apiPostMock.mockReset()
  apiGetMock.mockImplementation((path: string) =>
    path === '/api/resources/tags/inline-create-schema'
      ? Promise.resolve({ data: { fields: [nameField], singularLabel: 'Tag', label: 'Tags' } })
      : Promise.resolve({ data: [{ id: 1, _title: 'php' }, { id: 2, _title: 'laravel' }] }),
  )
})

describe('TagFieldInput inline create', () => {
  it('creates a tag from the picker and adds it to the selection', async () => {
    apiPostMock.mockResolvedValue({ data: { id: 9, title: 'react' } })
    const { onChange } = renderInput(makeField())

    await createInline('react')

    await waitFor(() => expect(onChange).toHaveBeenCalledWith([...stored, { id: 9, title: 'react' }]))
    expect(apiPostMock).toHaveBeenCalledWith('/api/resources/tags/inline-create', { name: 'react' })
    expect(screen.getByText('react')).toBeTruthy()
  })

  it('lists the created tag among preloaded options', async () => {
    let created = false
    apiGetMock.mockImplementation((path: string) =>
      path === '/api/resources/tags/inline-create-schema'
        ? Promise.resolve({ data: { fields: [nameField], singularLabel: 'Tag', label: 'Tags' } })
        : Promise.resolve({ data: [{ id: 1, _title: 'php' }, ...(created ? [{ id: 9, _title: 'react' }] : [])] }),
    )
    apiPostMock.mockImplementation(() => {
      created = true
      return Promise.resolve({ data: { id: 9, title: 'react' } })
    })
    const { onChange } = renderInput(makeField({ preload: true }))

    await createInline('react')
    await waitFor(() => expect(onChange).toHaveBeenCalled())
    fireEvent.click(screen.getByRole('button', { name: 'Add Tags' }))

    expect(await screen.findByRole('button', { name: 'react' })).toBeTruthy()
  })

  it('offers no create button without showCreateRelationButton()', async () => {
    renderInput(makeField({ showCreateRelationButton: false }))

    fireEvent.click(screen.getByRole('button', { name: 'Add Tags' }))

    await screen.findByRole('button', { name: 'laravel' })
    expect(createButton()).toBeNull()
  })

  it('keeps its tags when it turns readonly while an inline create is saving', async () => {
    let settle: (res: unknown) => void = () => {}
    apiPostMock.mockReturnValue(new Promise((resolve) => { settle = resolve }))
    const { onChange, lock } = renderInput(makeField())
    await createInline('react')

    lock()
    await act(async () => { settle({ data: { id: 9, title: 'react' } }) })

    expect(onChange).not.toHaveBeenCalled()
    expect(screen.queryByText('react')).toBeNull()
    expect(screen.queryByRole('button', { name: 'Add Tags' })).toBeNull()
  })
})
