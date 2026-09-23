import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { Children, isValidElement, type ReactNode } from 'react'
import { api } from '@/lib/api'
import type { FieldDefinition } from '@/types'
import { relationField, renderOnPage, requestedUrls } from '@/test-support/relationPanels'

/*
 * The attach modal and the pivot edit modal of a BelongsToMany / MorphToMany
 * panel render the relationship's pivot fields with no scope, so a BelongsTo,
 * MorphTo or Tag pivot field asked the page's resource for the attribute
 * (`/api/resources/{parent}/{id}/relatable/{attribute}`), which reads the
 * parent's forms: no pivot field is declared there (404, empty picker). The
 * modals now hand the pickers the panel's pivot-fields base, so the options
 * come from `{panel}/pivot-fields/relatable/{attribute}` (attach) and
 * `{panel}/pivot-fields/{relatedId}/relatable/{attribute}` (pivot edit).
 */

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }
})

// A light table: each row renders its column bodies (the row actions among
// them) and, in a selectable table, a button that selects the row.
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

const rolePivotField = { ...base, attribute: 'role_id', label: 'Role', type: 'belongs_to', relatedResource: 'roles' }

const PANELS = [
  { type: 'belongs_to_many', relationship: 'members', relatedResource: 'team-members', metaKey: 'belongsToManyMeta', segment: 'belongs-to-many' },
  { type: 'morph_to_many', relationship: 'tags', relatedResource: 'tags', metaKey: 'morphToManyMeta', segment: 'morph-to-many' },
]

function answerRequests() {
  vi.mocked(api.get).mockReset()
  vi.mocked(api.get).mockImplementation(((url: string) => {
    const page = { current_page: 1, from: 1, last_page: 1, per_page: 10, to: 1, total: 1 }
    const links = { first: null, last: null, prev: null, next: null }
    if (url.endsWith('/schema')) {
      return Promise.resolve({ data: { fieldsForIndex: [], fieldsForDetail: [], singularLabel: 'Record', softDeletes: false } })
    }
    if (url.includes('/actions?')) return Promise.resolve({ data: { actions: [] } })
    if (url.includes('/relatable/')) return Promise.resolve({ data: [], meta: page, links })
    // The attach modal's list of records to attach.
    if (url.includes('/attachable?')) return Promise.resolve({ data: [{ id: 9, _title: 'Bob' }], meta: page, links })
    // The panel's attached records.
    return Promise.resolve({ data: [{ id: 5, _title: 'Ann', _pivot: { role_id: null } }], meta: page, links })
  }) as unknown as typeof api.get)
}

function panelField(panel: typeof PANELS[number]): FieldDefinition {
  return {
    ...relationField(panel.type, panel.relationship, panel.relatedResource, panel.metaKey),
    pivotFields: [rolePivotField],
  } as FieldDefinition
}

function renderPanel(panel: typeof PANELS[number]) {
  return renderOnPage('/resources/projects/3', '/resources/:resource/:id', (
    <FieldDisplay field={panelField(panel)} value={null} resourceKey="projects" context="detail" />
  ))
}

/** Opens the role picker of the open modal and returns the path of its relatable request. */
async function relatablePathOfOpenModal(): Promise<string> {
  const trigger = await waitFor(() => {
    const el = document.body.querySelector('[role="dialog"] .martis-belongs-to-trigger')
    expect(el).not.toBeNull()
    return el as HTMLElement
  })
  fireEvent.click(trigger)

  await waitFor(() => expect(requestedUrls().filter((url) => url.includes('/relatable/'))).toHaveLength(1))
  return requestedUrls().find((url) => url.includes('/relatable/'))!.split('?')[0]
}

beforeEach(() => {
  answerRequests()
})

afterEach(() => {
  window.history.replaceState(null, '', '/')
})

describe.each(PANELS)('$type pivot field pickers', (panel) => {
  const panelUrl = `/api/resources/projects/3/${panel.segment}/${panel.relationship}`

  it('ask the panel for the options of a pivot field in the attach modal', async () => {
    renderPanel(panel)

    fireEvent.click(await screen.findByRole('button', { name: 'Attach' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Select Bob' }))

    expect(await relatablePathOfOpenModal()).toBe(`${panelUrl}/pivot-fields/relatable/role_id`)
  })

  it('ask the panel for the options of a pivot field of the attached record in the pivot edit modal', async () => {
    const { container } = renderPanel(panel)

    const edit = await waitFor(() => {
      const el = container.querySelector('[data-pr-tooltip="Edit"]')
      expect(el).not.toBeNull()
      return el as HTMLElement
    })
    fireEvent.click(edit)

    expect(await relatablePathOfOpenModal()).toBe(`${panelUrl}/pivot-fields/5/relatable/role_id`)
  })
})
