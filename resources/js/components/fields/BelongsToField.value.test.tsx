import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, fireEvent, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import type { FieldDefinition } from '@/types'

/*
 * A BelongsTo input shows the record of its `value`, and `value` can change
 * after the input mounted: an edit form seeds the stored record after
 * rendering its fields, and "Create & add another" clears the form for the
 * next record. The input kept the label of the record it picked (it emits
 * the bare id) and, in multiple mode, the records it picked, so a cleared
 * form still showed the previous record's parent while the value was empty:
 * the next submit sent no key (silently empty when nullable, a 422 on a
 * field that looked filled when required).
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
    attribute: 'author', label: 'Author', type: 'belongs_to', relatedResource: 'users',
    nullable: true, readonly: false, required: false, sortable: false,
    searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
    rules: [],
    ...overrides,
  } as unknown as FieldDefinition
}

function renderInput(field: FieldDefinition, value: unknown, onChange: (v: unknown) => void = () => {}) {
  const qc = new QueryClient()
  const ui = (v: unknown) => (
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <BelongsToFieldInput field={field} value={v} onChange={onChange} resourceKey="posts" />
      </MemoryRouter>
    </QueryClientProvider>
  )
  const view = render(ui(value))
  return { ...view, rerender: (next: unknown) => view.rerender(ui(next)) }
}

const triggerText = () => document.querySelector('.martis-belongs-to-trigger-label')?.textContent ?? null

async function pick(label: string) {
  if (!screen.queryByText(label, { selector: 'button *, button' })) {
    fireEvent.click(document.querySelector('.martis-belongs-to-trigger') as HTMLElement)
  }
  fireEvent.click(await screen.findByText(label, { selector: 'button *, button' }))
}

beforeEach(() => {
  apiGetMock.mockReset()
  apiGetMock.mockResolvedValue({ data: [{ id: 7, _title: 'Ana' }, { id: 8, _title: 'Rui' }] })
})

describe('BelongsToFieldInput value from outside (single)', () => {
  it('shows the record handed in after mount (the edit form seeding the record)', () => {
    const { rerender } = renderInput(makeField(), null)

    rerender({ id: 7, title: 'Ana' })

    expect(triggerText()).toBe('Ana')
  })

  it('keeps the picked record when the form hands back the id it emitted', async () => {
    let current: unknown = null
    const onChange = vi.fn((next: unknown) => {
      current = next
    })
    const { rerender } = renderInput(makeField(), current, onChange)

    await pick('Ana')
    rerender(current)

    expect(onChange).toHaveBeenLastCalledWith(7)
    expect(triggerText()).toBe('Ana')
  })

  it('drops the picked record when the form is cleared', async () => {
    let current: unknown = null
    const { rerender } = renderInput(makeField(), current, (next) => {
      current = next
    })
    const placeholder = triggerText()
    await pick('Ana')
    rerender(current)
    expect(triggerText()).toBe('Ana')

    rerender(null)

    expect(triggerText()).toBe(placeholder)
  })
})

describe('BelongsToFieldInput value from outside (multiple)', () => {
  it('shows the records handed in after mount (the edit form seeding the record)', () => {
    const { rerender } = renderInput(makeField({ multiple: true }), null)

    rerender([{ id: 7, title: 'Ana' }, { id: 8, title: 'Rui' }])

    expect(triggerText()).toBe('Ana, Rui')
  })

  it('drops the picked records when the form is cleared', async () => {
    let current: unknown = null
    const { rerender } = renderInput(makeField({ multiple: true }), current, (next) => {
      current = next
    })
    const placeholder = triggerText()
    await pick('Ana')
    rerender(current)
    await pick('Rui')
    rerender(current)
    expect(current).toEqual([7, 8])
    expect(triggerText()).toBe('Ana, Rui')

    rerender([])

    expect(triggerText()).toBe(placeholder)
  })
})
