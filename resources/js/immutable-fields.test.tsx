import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, MemoryRouter, RouterProvider } from 'react-router-dom'
import type { ReactElement, ReactNode } from 'react'
import type { FieldDefinition, OverrideProps, ResourceRecord, ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * `immutable()` makes a field writable on create and skipped on every update
 * endpoint, so the update surfaces render it read-only, as they render a
 * `readonly()` field, and the create surfaces keep it editable:
 *   - the update page, also when a relation panel opens it (`?viaResource=…`);
 *   - the update drawer;
 *   - the form that edits a BelongsToMany / MorphToMany pivot row;
 *   - the create page and the create drawer keep the input editable.
 * The descriptors mirror `Field::toArray()`: `Text::make('code', 'Code')->immutable()`
 * serialises `immutable: true` next to `readonly: false`.
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGetMock(...args),
      post: vi.fn(() => new Promise(() => {})),
      put: vi.fn(() => new Promise(() => {})),
    },
  }
})

// DrawerShell renders children + footer into the DOM so assertions work.
vi.mock('@/components/overrides/DrawerShell', () => ({
  DrawerShell: ({ children, footer }: { children: ReactNode; footer?: ReactNode }) => (
    <div>
      <div>{children}</div>
      <div>{footer}</div>
    </div>
  ),
}))

import { ResourceUpdatePage } from '@/pages/ResourceUpdate'
import { ResourceCreatePage } from '@/pages/ResourceCreate'
import { DrawerUpdate } from '@/components/overrides/DrawerUpdate'
import { DrawerCreate } from '@/components/overrides/DrawerCreate'
import { EditPivotModal } from '@/components/fields/BelongsToManyField'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()

function baseField(overrides: Record<string, unknown>): FieldDefinition {
  return {
    nullable: false,
    readonly: false,
    required: false,
    sortable: false,
    searchable: false,
    showOnIndex: false,
    showOnDetail: true,
    showOnForms: true,
    rules: [],
    immutable: false,
    ...overrides,
  } as unknown as FieldDefinition
}

const titleField = baseField({ attribute: 'title', label: 'Title', type: 'text' })
const codeField = baseField({ attribute: 'code', label: 'Code', type: 'text', immutable: true })
const slugField = baseField({
  attribute: 'slug',
  label: 'Slug',
  type: 'slug',
  sourceAttribute: 'title',
  separator: '-',
  reserved: [],
  immutable: true,
})
const identityPanel = {
  type: 'panel',
  title: 'Identity',
  description: null,
  collapsible: false,
  collapsedByDefault: false,
  limit: null,
  fields: [codeField],
}

const record = { title: 'Launch', code: 'INV-001', slug: 'launch' }

function input(id: string): HTMLInputElement {
  return document.getElementById(id) as HTMLInputElement
}

function makeSchema(fields: unknown[]): ResourceSchema {
  return {
    uriKey: 'posts',
    label: 'Posts',
    singularLabel: 'Post',
    softDeletes: false,
    stickyView: true,
    group: null,
    fields: [],
    fieldsForCreate: fields,
    fieldsForUpdate: fields,
    errorDisplay: 'inline',
    confirmUnsavedChanges: false,
    messages: {
      created: 'Record created successfully.',
      updated: 'Record updated successfully.',
      deleted: 'Record deleted successfully.',
      restored: 'Record restored successfully.',
      deleteConfirm: 'Delete?',
      archiveConfirm: 'Archive?',
    },
  } as unknown as ResourceSchema
}

/** The schema and the `posts/1` record the pages load. */
function mockApi(fields: unknown[]) {
  apiGetMock.mockImplementation((path: string) => {
    if (path.includes('/schema')) return Promise.resolve({ data: makeSchema(fields) })
    if (path.startsWith('/api/resources/posts/1')) return Promise.resolve({ data: { id: 1, ...record } })
    return Promise.resolve({ data: [] })
  })
}

function queryClient() {
  return new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
}

// The pages call `useBlocker` (unsaved-changes guard), which needs a data router.
function renderPage(route: string, entry: string, element: ReactElement) {
  const router = createMemoryRouter([{ path: route, element }], { initialEntries: [entry] })
  return render(
    <QueryClientProvider client={queryClient()}>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </QueryClientProvider>,
  )
}

function renderUpdatePage(fields: unknown[], query = '') {
  mockApi(fields)
  return renderPage('/resources/:resource/:id/edit', `/resources/posts/1/edit${query}`, <ResourceUpdatePage />)
}

function drawerProps(fields: unknown[], withRecord: boolean): OverrideProps {
  return {
    schema: makeSchema(fields),
    resource: 'posts',
    params: {},
    record: withRecord ? ({ id: 1, ...record } as unknown as ResourceRecord) : null,
    recordId: withRecord ? '1' : null,
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

function renderDrawer(element: ReactElement) {
  return render(
    <QueryClientProvider client={queryClient()}>
      <MemoryRouter>{element}</MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  apiGetMock.mockReset()
})

describe('immutable fields on the update page', () => {
  it('renders an immutable field read-only and keeps the other fields editable', async () => {
    renderUpdatePage([titleField, codeField])

    await waitFor(() => expect(input('code')?.value).toBe('INV-001'))
    expect(input('code').disabled).toBe(true)
    expect(input('title').disabled).toBe(false)
  })

  it('renders an immutable field inside a panel read-only', async () => {
    renderUpdatePage([titleField, identityPanel])

    await waitFor(() => expect(input('code')?.value).toBe('INV-001'))
    expect(input('code').disabled).toBe(true)
    expect(input('title').disabled).toBe(false)
  })

  it('locks an immutable slug', async () => {
    renderUpdatePage([titleField, slugField])

    const slugInput = (await screen.findByTestId('slug-input-slug')) as HTMLInputElement
    expect(slugInput.value).toBe('launch')
    expect(slugInput.disabled).toBe(true)
    expect(screen.getByTestId('slug-locked-slug')).toBeTruthy()
  })

  it('renders an immutable field read-only when a relation panel opens the record', async () => {
    renderUpdatePage(
      [titleField, codeField],
      '?viaResource=users&viaResourceId=7&viaRelationship=posts&viaRelationshipType=has-many',
    )

    await waitFor(() => expect(input('code')?.value).toBe('INV-001'))
    expect(input('code').disabled).toBe(true)
    expect(input('title').disabled).toBe(false)
  })
})

describe('immutable fields in the update drawer', () => {
  it('renders an immutable field read-only and keeps the other fields editable', async () => {
    renderDrawer(<DrawerUpdate {...drawerProps([titleField, identityPanel], true)} />)

    await waitFor(() => expect(input('code')?.value).toBe('INV-001'))
    expect(input('code').disabled).toBe(true)
    expect(input('title').disabled).toBe(false)
  })
})

describe('immutable fields in the pivot edit form', () => {
  it('renders an immutable pivot field read-only and keeps the other pivot fields editable', () => {
    render(
      <QueryClientProvider client={queryClient()}>
        <EditPivotModal
          title="Laravel"
          endpoint="/api/resources/posts/1/belongs-to-many/tags/3/pivot"
          pivotEndpoint="/api/resources/posts/1/belongs-to-many/tags/pivot-fields/3"
          pivotFields={[baseField({ attribute: 'note', label: 'Note', type: 'text' }), codeField]}
          initialValues={{ note: 'Pinned', code: 'P-7' }}
          onSuccess={vi.fn()}
          onCancel={vi.fn()}
        />
      </QueryClientProvider>,
    )

    expect(input('code').value).toBe('P-7')
    expect(input('code').disabled).toBe(true)
    expect(input('note').disabled).toBe(false)
  })
})

describe('immutable fields on the create surfaces', () => {
  it('keeps an immutable field editable on the create page', async () => {
    mockApi([titleField, identityPanel, slugField])
    renderPage('/resources/:resource/create', '/resources/posts/create', <ResourceCreatePage />)

    await waitFor(() => expect(input('code')).toBeTruthy())
    expect(input('code').disabled).toBe(false)
    expect((screen.getByTestId('slug-input-slug') as HTMLInputElement).disabled).toBe(false)
  })

  it('keeps an immutable field editable in the create drawer', () => {
    renderDrawer(<DrawerCreate {...drawerProps([titleField, identityPanel], false)} />)

    expect(input('code').disabled).toBe(false)
  })
})
