import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, fireEvent, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import type { FieldDefinition } from '@/types'

/*
 * A Tag input shows the tags of its `value`, and `value` can change after the
 * input mounted: a form seeds the stored tags after rendering its fields, and
 * "Create & add another" clears the form for the next record. The input used
 * to copy its value into local state at mount only, so an edit form showed no
 * tags (and adding one saved only the new tag, detaching the stored ones) and
 * a cleared form kept the previous record's tags, which the next pick sent
 * along.
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args) },
  }
})

import { TagFieldInput } from './TagField'

const field = {
  attribute: 'tags', label: 'Tags', type: 'tag', relatedResource: 'tags',
  nullable: true, readonly: false, required: false, sortable: false,
  searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
  rules: [],
} as unknown as FieldDefinition

const stored = [
  { id: 1, title: 'php' },
  { id: 2, title: 'laravel' },
]

function renderInput(value: unknown, onChange: (v: unknown) => void = () => {}) {
  const view = render(
    <MemoryRouter>
      <TagFieldInput field={field} value={value} onChange={onChange} resourceKey="posts" recordId={1} />
    </MemoryRouter>,
  )
  const rerender = (next: unknown) =>
    view.rerender(
      <MemoryRouter>
        <TagFieldInput field={field} value={next} onChange={onChange} resourceKey="posts" recordId={1} />
      </MemoryRouter>,
    )
  return { ...view, rerender }
}

beforeEach(() => {
  apiGetMock.mockReset()
  apiGetMock.mockResolvedValue({
    data: [
      { id: 1, _title: 'php' },
      { id: 2, _title: 'laravel' },
      { id: 3, _title: 'react' },
    ],
  })
})

describe('TagFieldInput value from outside', () => {
  it('adopts the tags handed in after mount (the edit form hydrating the record)', () => {
    const { rerender } = renderInput(null)
    expect(screen.queryByText('php')).toBeNull()

    rerender(stored)

    expect(screen.getByText('php')).toBeTruthy()
    expect(screen.getByText('laravel')).toBeTruthy()
  })

  it('keeps the adopted tags when a new one is picked', async () => {
    const onChange = vi.fn()
    const { rerender } = renderInput(null, onChange)
    rerender(stored)

    fireEvent.click(screen.getByRole('button', { name: 'Add Tags' }))
    fireEvent.click(await waitFor(() => screen.getByRole('button', { name: 'react' })))

    expect(onChange).toHaveBeenLastCalledWith([...stored, { id: 3, title: 'react' }])
  })

  it('drops the tags of the previous record when the form is cleared', () => {
    const { rerender } = renderInput(stored)
    expect(screen.getByText('php')).toBeTruthy()

    rerender(null)

    expect(screen.queryByText('php')).toBeNull()
    expect(screen.queryByText('laravel')).toBeNull()
  })

  it('keeps its tags when the form hands back the value it just emitted', () => {
    let current: unknown = stored
    const onChange = vi.fn((next: unknown) => {
      current = next
    })
    const { container, rerender } = renderInput(current, onChange)

    fireEvent.click(container.querySelector('[data-pr-tooltip="Remove php"]') as HTMLElement)
    rerender(current)

    expect(onChange).toHaveBeenLastCalledWith([{ id: 2, title: 'laravel' }])
    expect(screen.queryByText('php')).toBeNull()
    expect(screen.getByText('laravel')).toBeTruthy()
  })
})
