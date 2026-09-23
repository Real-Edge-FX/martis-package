import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import type { ReactNode } from 'react'
import type { FieldDefinition, OverrideProps, ResourceRecord, ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'
import { relationField, requestedUrls } from '@/test-support/relationPanels'

// A BelongsToMany / MorphToMany shown on a create form (`showOnCreating()`)
// rendered its attach panel, and the panel took its parent from the page:
// the create page names no record, so it asked
// /api/resources/projects//belongs-to-many/members (404), and a create drawer
// or the inline-create modal opened over another record's page listed that
// record's related rows, with Attach and Detach writing to it. The schema now
// keeps both fields off every create form, like Nova; a create surface states
// that its record does not exist yet, and a pivot panel with no record renders
// nothing, so a form built by hand does not read another record either.

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { ...actual.api, get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }
})

vi.mock('primereact/datatable', () => ({ DataTable: () => null }))
vi.mock('primereact/column', () => ({ Column: () => null }))

vi.mock('@/components/overrides/DrawerShell', () => ({
  DrawerShell: ({ children, footer }: { children: ReactNode; footer?: ReactNode }) => (
    <div>
      {children}
      {footer}
    </div>
  ),
}))

import { api } from '@/lib/api'
import { registerDefaultFields } from './FieldRenderer'
import { NestedParentProvider } from './NestedParentContext'
import { ResourceCreatePage } from '@/pages/ResourceCreate'
import { DrawerCreate } from '@/components/overrides/DrawerCreate'
import { InlineCreateModal } from '@/components/InlineCreateModal'

registerDefaultFields()

interface Pivot {
  type: string
  relationship: string
  relatedResource: string
  metaKey: string
  segment: string
}

const PIVOTS: Pivot[] = [
  { type: 'belongs_to_many', relationship: 'members', relatedResource: 'team-members', metaKey: 'belongsToManyMeta', segment: 'belongs-to-many' },
  { type: 'morph_to_many', relationship: 'tags', relatedResource: 'tags', metaKey: 'morphToManyMeta', segment: 'morph-to-many' },
]

function projectSchema(fieldsForCreate: FieldDefinition[]): ResourceSchema {
  return {
    uriKey: 'projects',
    label: 'Projects',
    singularLabel: 'Project',
    softDeletes: false,
    confirmUnsavedChanges: false,
    errorDisplay: 'inline',
    fields: [],
    fieldsForCreate,
    messages: {},
  } as unknown as ResourceSchema
}

function answerRequests(pivotField: FieldDefinition) {
  vi.mocked(api.get).mockReset()
  vi.mocked(api.get).mockImplementation(((url: string) => {
    if (url === '/api/resources/projects/schema') return Promise.resolve({ data: projectSchema([pivotField]) })
    if (url === '/api/resources/projects/inline-create-schema') {
      return Promise.resolve({ data: { fields: [pivotField], singularLabel: 'Project', label: 'Projects' } })
    }
    if (url.endsWith('/schema')) {
      return Promise.resolve({ data: { fieldsForIndex: [], fieldsForDetail: [], singularLabel: 'Record', softDeletes: false } })
    }
    if (url.includes('/actions?')) return Promise.resolve({ data: { actions: [] } })
    return Promise.resolve({
      data: [],
      meta: { current_page: 1, from: null, last_page: 1, per_page: 10, to: null, total: 0 },
      links: { first: null, last: null, prev: null, next: null },
    })
  }) as unknown as typeof api.get)
}

const replicaSource = {
  id: 3,
  _title: 'Apollo',
  _resource: { uriKey: 'projects', label: 'Projects', singularLabel: 'Project' },
} as unknown as ResourceRecord

function drawerCreateProps(pivotField: FieldDefinition, record: ResourceRecord | null): OverrideProps {
  return {
    schema: projectSchema([pivotField]),
    resource: 'projects',
    params: {},
    record,
    recordId: null,
    navigate: vi.fn(),
    onClose: vi.fn(),
    onCreated: vi.fn(),
    onUpdated: vi.fn(),
    onDeleted: vi.fn(),
    onEdit: vi.fn(),
    onView: vi.fn(),
    addToast: vi.fn(),
  }
}

/**
 * Render `ui` as the page at `path`, matched by `routePattern`, under a data
 * router like the app's (the create page's unsaved-changes guard needs one).
 */
function renderOnPage(path: string, routePattern: string, ui: ReactNode) {
  window.history.replaceState(null, '', `/martis${path}`)
  const router = createMemoryRouter([{ path: routePattern, element: ui }], { initialEntries: [path] })
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </QueryClientProvider>,
  )
}

/** Once the form is up, give the lazy pivot panel time to mount and query. */
async function settle() {
  await screen.findByRole('button', { name: /Create Project/ })
  await new Promise((resolve) => setTimeout(resolve, 100))
}

function pivotRequests(pivot: Pivot): string[] {
  return requestedUrls().filter((url) => url.includes(`/${pivot.segment}/`))
}

/** Like Nova, no attach panel before the record exists: nothing asked, nothing shown. */
function expectNoPanel(pivot: Pivot) {
  expect(pivotRequests(pivot)).toEqual([])
  expect(screen.queryByRole('heading', { name: pivot.relationship })).toBeNull()
}

beforeAll(async () => {
  // The pivot inputs are lazy chunks: load them once so each test mounts
  // the panel within its settle window.
  await import('./BelongsToManyField')
  await import('./MorphToManyField')
})

afterEach(() => {
  window.history.replaceState(null, '', '/')
})

describe.each(PIVOTS)('$type panel on a create surface', (pivot) => {
  const pivotField = relationField(pivot.type, pivot.relationship, pivot.relatedResource, pivot.metaKey)

  beforeEach(() => {
    answerRequests(pivotField)
  })

  it('asks nothing on the create page, which names no record', async () => {
    renderOnPage('/resources/projects/create', '/resources/:resource/create', <ResourceCreatePage />)

    await settle()

    expectNoPanel(pivot)
  })

  it('does not read the record a replicate drawer copies, on that record\'s page', async () => {
    renderOnPage('/resources/projects/3', '/resources/:resource/:id', (
      <DrawerCreate {...drawerCreateProps(pivotField, replicaSource)} />
    ))

    await settle()

    expectNoPanel(pivot)
  })

  it('does not read the page record when a create drawer opens over another record\'s page', async () => {
    renderOnPage('/resources/team-members/2', '/resources/:resource/:id', (
      <DrawerCreate {...drawerCreateProps(pivotField, null)} />
    ))

    await settle()

    expect(requestedUrls().filter((url) => url.includes('/team-members/2/'))).toEqual([])
    expectNoPanel(pivot)
  })

  it('does not read the page record from the inline-create modal on another record\'s edit page', async () => {
    renderOnPage('/resources/team-members/2/edit', '/resources/:resource/:id/edit', (
      <InlineCreateModal relatedResource="projects" open onClose={() => {}} onCreated={() => {}} />
    ))

    await settle()

    expect(requestedUrls().filter((url) => url.includes('/team-members/2/'))).toEqual([])
    expectNoPanel(pivot)
  })

  it('does not read the drawer record from the inline-create modal opened inside a record drawer', async () => {
    renderOnPage('/resources/clients', '/resources/:resource', (
      <NestedParentProvider value={{ resource: 'clients', id: 5 }}>
        <InlineCreateModal relatedResource="projects" open onClose={() => {}} onCreated={() => {}} />
      </NestedParentProvider>
    ))

    await settle()

    expect(requestedUrls().filter((url) => url.includes('/clients/5/'))).toEqual([])
    expectNoPanel(pivot)
  })
})
