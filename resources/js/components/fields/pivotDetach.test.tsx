import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { Children, isValidElement, type ReactNode } from 'react'
import { api, ApiError } from '@/lib/api'
import type { FieldDefinition } from '@/types'
import { relationField, renderOnPage } from '@/test-support/relationPanels'

/*
 * A detach that fails (a 403 when the policy denies it, a 500) left the
 * confirmation open with nothing said: the mutation had no error handler,
 * and the confirmation awaited `mutateAsync()` without catching, so the
 * rejection went unhandled. The attach and pivot update modals fired
 * `mutateAsync()` with `void`, leaving an unhandled rejection on every 422
 * although their error handlers already showed it. The confirmation now
 * shows why the detach failed, and nothing is left unhandled.
 */

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }
})

vi.mock('primereact/datatable', () => ({
  DataTable: ({ value, children }: { value?: Array<Record<string, unknown>>; children?: ReactNode }) => (
    <div>
      {(value ?? []).map((row) => (
        <div key={String(row.id)}>
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
    return Promise.resolve({ data: [{ id: 5, _title: 'Ann', _pivot: {} }], meta: page, links })
  }) as unknown as typeof api.get)
  vi.mocked(api.delete).mockReset()
})

afterEach(() => {
  window.history.replaceState(null, '', '/')
})

describe.each(PANELS)('$type detach', (panel) => {
  it('says why a detach failed and keeps the confirmation open', async () => {
    vi.mocked(api.delete).mockRejectedValue(new ApiError(403, 'This action is unauthorized.'))
    const { container } = renderOnPage('/resources/projects/3', '/resources/:resource/:id', (
      <FieldDisplay
        field={relationField(panel.type, panel.relationship, panel.relatedResource, panel.metaKey) as FieldDefinition}
        value={null}
        resourceKey="projects"
        context="detail"
      />
    ))

    const detach = await waitFor(() => {
      const el = container.querySelector('[data-pr-tooltip="Detach"]')
      expect(el).not.toBeNull()
      return el as HTMLElement
    })
    fireEvent.click(detach)
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Detach' }))

    expect(await within(dialog).findByRole('alert')).toHaveProperty('textContent', 'This action is unauthorized.')
    expect(api.delete).toHaveBeenCalledTimes(1)
  })
})
