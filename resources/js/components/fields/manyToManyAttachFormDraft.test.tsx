import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { Children, isValidElement, type ReactNode } from 'react'
import { api } from '@/lib/api'
import type { FieldDefinition } from '@/types'
import { relationField, renderOnPage } from '@/test-support/relationPanels'

/*
 * The attach of a BelongsToMany / MorphToMany panel checks the picked records against the
 * query its picker ran (Nova's RelatableAttachment rule). A 3-argument
 * `relatableQueryUsing()` closure filters the picker on the parent form
 * draft (`?form[attribute]=value`), so the attach sends the same draft, or a
 * record picked under it would be refused.
 */

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }
})

vi.mock('primereact/datatable', () => ({
  DataTable: ({ value, children, onSelectionChange }: {
    value?: Array<Record<string, unknown>>
    children?: ReactNode
    onSelectionChange?: (event: { value: unknown[] }) => void
  }) => (
    <div>
      {(value ?? []).map((row) => (
        <div key={String(row.id)}>
          {onSelectionChange && (
            <button type="button" onClick={() => onSelectionChange({ value: [row] })}>Select {String(row._title)}</button>
          )}
          {Children.toArray(children).map((column, index) => {
            const body = isValidElement<{ body?: (row: unknown) => ReactNode }>(column) ? column.props.body : undefined
            return <div key={index}>{body ? body(row) : null}</div>
          })}
        </div>
      ))}
    </div>
  ),
}))
vi.mock('primereact/column', () => ({ Column: () => null }))

import { BelongsToManyFieldInput } from './BelongsToManyField'
import { MorphToManyFieldInput } from './MorphToManyField'

const PANELS = [
  { type: 'belongs_to_many', path: 'belongs-to-many', metaKey: 'belongsToManyMeta', Input: BelongsToManyFieldInput },
  { type: 'morph_to_many', path: 'morph-to-many', metaKey: 'morphToManyMeta', Input: MorphToManyFieldInput },
]

beforeEach(() => {
  vi.mocked(api.get).mockReset()
  vi.mocked(api.post).mockReset()
  vi.mocked(api.post).mockResolvedValue({ data: {} } as never)
  vi.mocked(api.get).mockImplementation(((url: string) => {
    const page = { current_page: 1, from: 1, last_page: 1, per_page: 10, to: 1, total: 1 }
    const links = { first: null, last: null, prev: null, next: null }
    if (url.endsWith('/schema')) {
      return Promise.resolve({ data: { fieldsForIndex: [], fieldsForDetail: [], singularLabel: 'Record', softDeletes: false } })
    }
    if (url.includes('/actions?')) return Promise.resolve({ data: { actions: [] } })
    if (url.includes('/attachable?')) {
      return Promise.resolve({ data: [{ id: 9, _title: 'Edit posts' }], meta: page, links })
    }
    return Promise.resolve({ data: [], meta: page, links })
  }) as unknown as typeof api.get)
})

afterEach(() => {
  window.history.replaceState(null, '', '/')
})

describe.each(PANELS)('$type attach', ({ type, path, metaKey, Input }) => {
  it('sends the parent form draft the picker filtered on', async () => {
    const field = {
      ...relationField(type, 'permissions', 'permissions', metaKey),
      dependsOn: { fields: ['guard_name'] },
    } as FieldDefinition
    renderOnPage('/resources/roles/3/edit', '/resources/:resource/:id/edit', (
      <Input field={field} value={null} onChange={() => {}} formValues={{ guard_name: 'api', name: 'Editor' }} />
    ))

    fireEvent.click(await screen.findByRole('button', { name: 'Attach' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Select Edit posts' }))

    const attachButtons = await screen.findAllByRole('button', { name: /^Attach/ })
    fireEvent.click(attachButtons[attachButtons.length - 1])

    await waitFor(() => expect(api.post).toHaveBeenCalled())
    expect(vi.mocked(api.get).mock.calls.some(([url]) => String(url).includes('form%5Bguard_name%5D=api'))).toBe(true)
    expect(vi.mocked(api.post).mock.calls[0][0]).toBe(`/api/resources/roles/3/${path}/permissions/attach?form%5Bguard_name%5D=api`)
    expect(vi.mocked(api.post).mock.calls[0][1]).toEqual({ related_id: 9 })
  })
})
