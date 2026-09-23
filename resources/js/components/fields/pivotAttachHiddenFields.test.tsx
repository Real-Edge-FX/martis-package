import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { Children, isValidElement, type ReactNode } from 'react'
import { api } from '@/lib/api'
import type { FieldDefinition } from '@/types'
import { relationField, renderOnPage } from '@/test-support/relationPanels'

/*
 * The attach modal of a BelongsToMany / MorphToMany panel offered an input
 * for a pivot field whose `canSeeForModel()` hides it on the row the attach
 * writes, although the attach ignores what the form sends for it (it stores
 * the field's `default()`). The attachable list now names those fields
 * (`meta.hiddenPivotFields`), and the modal leaves them out.
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

import { FieldDisplay, registerDefaultFields } from './FieldRenderer'

registerDefaultFields()

const base = {
  nullable: true, readonly: false, required: false, sortable: false,
  searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
  rules: [],
}

const PANELS = [
  { type: 'belongs_to_many', relationship: 'members', relatedResource: 'team-members', metaKey: 'belongsToManyMeta' },
  { type: 'morph_to_many', relationship: 'tags', relatedResource: 'tags', metaKey: 'morphToManyMeta' },
]

beforeEach(() => {
  vi.mocked(api.get).mockReset()
  vi.mocked(api.get).mockImplementation(((url: string) => {
    const page = { current_page: 1, from: 1, last_page: 1, per_page: 10, to: 1, total: 1 }
    const links = { first: null, last: null, prev: null, next: null }
    if (url.endsWith('/schema')) {
      return Promise.resolve({ data: { fieldsForIndex: [], fieldsForDetail: [], singularLabel: 'Record', softDeletes: false } })
    }
    if (url.includes('/actions?')) return Promise.resolve({ data: { actions: [] } })
    if (url.includes('/attachable?')) {
      return Promise.resolve({ data: [{ id: 9, _title: 'Bob' }], meta: { ...page, hiddenPivotFields: ['rate'] }, links })
    }
    return Promise.resolve({ data: [], meta: page, links })
  }) as unknown as typeof api.get)
})

afterEach(() => {
  window.history.replaceState(null, '', '/')
})

describe.each(PANELS)('$type attach modal', (panel) => {
  it('leaves out a pivot field the new row hides', async () => {
    const field = {
      ...relationField(panel.type, panel.relationship, panel.relatedResource, panel.metaKey),
      pivotFields: [
        { ...base, attribute: 'role', label: 'Role', type: 'text' },
        { ...base, attribute: 'rate', label: 'Rate', type: 'text' },
      ],
    } as FieldDefinition
    renderOnPage('/resources/projects/3', '/resources/:resource/:id', (
      <FieldDisplay field={field} value={null} resourceKey="projects" context="detail" />
    ))

    fireEvent.click(await screen.findByRole('button', { name: 'Attach' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Select Bob' }))

    await waitFor(() => expect(document.getElementById('role')).not.toBeNull())
    expect(document.getElementById('rate')).toBeNull()
  })
})
