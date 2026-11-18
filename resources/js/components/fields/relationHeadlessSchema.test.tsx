import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { Children, isValidElement, type ReactNode } from 'react'
import { screen, waitFor } from '@testing-library/react'
import { api } from '@/lib/api'
import type { FieldDefinition } from '@/types'
import { relationField, renderOnPage, requestedUrls } from '@/test-support/relationPanels'

/*
 * A relationship panel reads the related resource's columns from its schema
 * endpoint, which answers 404 for a headless (`routable(false)`) resource. The
 * panel names its relationship so the endpoint can serve it, and when the
 * schema still yields no index fields it falls back to a `_title` column.
 */

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }
})

// Render each column's header and, per row, its body, so a test sees columns.
vi.mock('primereact/datatable', () => ({
  DataTable: ({ value, children }: { value?: Array<Record<string, unknown>>; children?: ReactNode }) => {
    const columns = Children.toArray(children).filter(isValidElement) as Array<
      { props: { field?: string; header?: ReactNode; body?: (row: unknown) => ReactNode } }
    >

    return (
      <div>
        <div data-testid="headers">
          {columns.map((column, index) => <span key={index} data-field={column.props.field ?? ''}>{column.props.header}</span>)}
        </div>
        {(value ?? []).map((row) => (
          <div key={String(row.id)} data-testid="row">
            {columns.map((column, index) => (
              <div key={index} data-field={column.props.field ?? ''}>
                {column.props.body ? column.props.body(row) : null}
              </div>
            ))}
          </div>
        ))}
      </div>
    )
  },
}))
vi.mock('primereact/column', () => ({ Column: () => null }))

import { FieldDisplay, registerDefaultFields } from './FieldRenderer'
import { NestedParentProvider } from './NestedParentContext'

registerDefaultFields()

const PANELS = [
  { type: 'has_many', relationship: 'tasks', relatedResource: 'tasks', metaKey: 'hasManyMeta', segment: 'has-many' },
  { type: 'belongs_to_many', relationship: 'members', relatedResource: 'team-members', metaKey: 'belongsToManyMeta', segment: 'belongs-to-many' },
]

const NAME_FIELD = {
  attribute: 'name', label: 'Name', type: 'text', nullable: false, readonly: false, required: false,
  sortable: false, searchable: false, showOnIndex: true, showOnDetail: true, showOnForms: true, rules: [],
} as unknown as FieldDefinition

function answer(fieldsForIndex: FieldDefinition[], schemaExtra: Record<string, unknown> = {}, trashed = false): void {
  vi.mocked(api.get).mockReset()
  vi.mocked(api.get).mockImplementation(((url: string) => {
    if (url.includes('/schema')) {
      return Promise.resolve({ data: { fieldsForIndex, fieldsForDetail: [], singularLabel: 'Record', softDeletes: false, ...schemaExtra } })
    }
    if (url.includes('/actions?')) return Promise.resolve({ data: { actions: [] } })

    return Promise.resolve({
      data: [
        { id: 1, name: 'alpha-name', _title: 'Alpha title', _pivot: {}, _authorization: {}, ...(trashed ? { deleted_at: '2026-01-01T00:00:00Z' } : {}) },
        { id: 2, name: 'beta-name', _title: 'Beta title', _pivot: {}, _authorization: { authorizedToView: false }, ...(trashed ? { deleted_at: '2026-01-01T00:00:00Z' } : {}) },
      ],
      meta: { current_page: 1, from: 1, last_page: 1, per_page: 10, to: 2, total: 2 },
      links: { first: null, last: null, prev: null, next: null },
    })
  }) as unknown as typeof api.get)
}

function renderPanel(panel: (typeof PANELS)[number]) {
  return renderOnPage('/resources/projects/9', '/resources/:resource/:id', (
    <NestedParentProvider value={{ resource: 'projects', id: '9' }}>
      <FieldDisplay
        field={relationField(panel.type, panel.relationship, panel.relatedResource, panel.metaKey)}
        value={null}
        resourceKey="projects"
        context="detail"
      />
    </NestedParentProvider>
  ))
}

afterEach(() => {
  window.history.replaceState(null, '', '/')
})

describe.each(PANELS)('$type panel — headless related resource', (panel) => {
  beforeEach(() => answer([]))

  it('requests the schema naming the parent resource and relationship', async () => {
    renderPanel(panel)

    await waitFor(() => expect(requestedUrls().some((url) => url.includes('/schema'))).toBe(true))
    const schemaUrl = requestedUrls().find((url) => url.includes('/schema'))!
    const [path, query] = schemaUrl.split('?')

    expect(path).toBe(`/api/resources/${panel.relatedResource}/schema`)
    const params = new URLSearchParams(query)
    expect(params.get('viaResource')).toBe('projects')
    expect(params.get('viaRelationship')).toBe(panel.relationship)
  })

  it('falls back to a title column when the schema yields no index fields', async () => {
    const { container } = renderPanel(panel)

    expect(await screen.findByText('Alpha title')).toBeTruthy()
    expect(screen.getByText('Beta title')).toBeTruthy()
    expect(container.querySelectorAll('[data-testid="headers"] [data-field="_title"]')).toHaveLength(1)
    expect(container.querySelectorAll('[data-testid="row"] [data-field="_title"]')).toHaveLength(2)
  })

  it('links a viewable row title to the record and leaves a denied one as plain text', async () => {
    const { container } = renderPanel(panel)

    const alpha = await screen.findByText('Alpha title')
    expect(alpha.closest('a')?.getAttribute('href')).toContain(`/resources/${panel.relatedResource}/1`)
    expect(screen.getByText('Beta title').closest('a')).toBeNull()
    expect(container.querySelectorAll('[data-testid="row"]')).toHaveLength(2)
  })
})

describe.each(PANELS)('$type panel — schema with index fields', (panel) => {
  beforeEach(() => answer([NAME_FIELD]))

  it('renders the schema columns and no title fallback column', async () => {
    const { container } = renderPanel(panel)

    await waitFor(() => expect(container.querySelectorAll('[data-testid="row"]')).toHaveLength(2))
    await waitFor(() => expect(container.querySelectorAll('[data-testid="headers"] [data-field="name"]')).toHaveLength(1))

    expect(container.querySelectorAll('[data-field="_title"]')).toHaveLength(0)
    expect(screen.queryByText('Alpha title')).toBeNull()
    expect(screen.getAllByText('alpha-name').length).toBeGreaterThan(0)
  })
})

/*
 * A headless related resource has no page surface, and its restore endpoint
 * answers 404 with it, so the panel must not offer the trash controls that
 * the schema's `softDeletes` would otherwise turn on.
 */
describe.each(PANELS)('$type panel — trash controls follow the related resource routability', (panel) => {
  it('offers no restore row action for a headless related resource', async () => {
    answer([NAME_FIELD], { softDeletes: true, routable: false }, true)
    const { container } = renderPanel(panel)

    await waitFor(() => expect(container.querySelectorAll('[data-testid="row"]')).toHaveLength(2))

    expect(container.querySelectorAll('[data-pr-tooltip="Restore"]')).toHaveLength(0)
  })

  it('keeps it for a routable related resource', async () => {
    answer([NAME_FIELD], { softDeletes: true, routable: true }, true)
    const { container } = renderPanel(panel)

    await waitFor(() => expect(container.querySelectorAll('[data-testid="row"]')).toHaveLength(2))
    await waitFor(() => expect(container.querySelectorAll('[data-pr-tooltip="Restore"]').length).toBeGreaterThan(0))
  })
})
