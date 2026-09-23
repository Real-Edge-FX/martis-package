import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import type { ResourceSchema, ResourceRecord, FieldDefinition } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * Characterization tests for the Resource UPDATE form.
 *
 * Goal: pin the CURRENT observable, UPDATE-SPECIFIC behaviour of the edit form
 * as a safety net BEFORE the form-harness convergence (Task 5). These describe
 * what the code does TODAY — not what it should do. They must stay green on the
 * unmodified component and after convergence onto useMartisForm/FieldsForm.
 *
 * The create characterization already locks the shared-values / slug / container
 * paths. What it does NOT cover, and what these tests add:
 *   1. Pre-filled record values render into the inputs (the page seeds its form
 *      state from the loaded record).
 *   2. context: 'update' — the record is fetched with `?context=update` and the
 *      update source of fields (`fieldsForUpdate`) is what gets rendered.
 *   3. An immutable field (readonly on update) renders disabled and stays so.
 *   4. Slug-from-source on a PRE-FILLED record: the stored slug stays when the
 *      source field changes (the fields mount with the record's values, so
 *      the slug counts as set); clearing it regenerates it from the source.
 *
 * Mocks:
 *   - `@/lib/api` is partially mocked (real module + spied `get`/`put`).
 *   - Real i18next is initialized globally by resources/js/test-setup.ts.
 *   - `ResourceUpdatePage` calls `useBlocker` (via useUnsavedChangesGuard),
 *     which requires a DATA router — a plain <MemoryRouter> throws. We use
 *     `createMemoryRouter` + `<RouterProvider>`.
 */

// ---------------------------------------------------------------------------
// Mocks
// ---------------------------------------------------------------------------

const apiGetMock = vi.fn()
const apiPutMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGetMock(...args),
      put: (...args: unknown[]) => apiPutMock(...args),
    },
  }
})

import { useState } from 'react'
import { ResourceUpdatePage } from '@/pages/ResourceUpdate'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'
import { componentRegistry } from '@/lib/componentRegistry'
import type { FieldInputProps } from '@/components/fields/types'

// FieldInput resolves its concrete component through the global registry, so
// the default field components must be registered (app.tsx does this at boot).
registerDefaultFields()

// A custom input (registered the way a consumer registers one) that reads its
// value once, at mount, the way many third-party inputs do.
function MountValueProbe({ value }: FieldInputProps) {
  const [mounted] = useState(() => String(value ?? ''))
  return <span data-testid="mount-probe">{mounted}</span>
}
componentRegistry.registerFieldInput('mount_probe', MountValueProbe)

// ---------------------------------------------------------------------------
// Field fixtures — referentially stable (module constants), matching how the
// page keeps schema field objects stable across renders via useMemo.
// ---------------------------------------------------------------------------

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
    reserved: [],
    ...overrides,
  } as unknown as FieldDefinition
}

const titleField = baseField({ attribute: 'title', label: 'Title', type: 'text' })
const slugField = baseField({
  attribute: 'slug',
  label: 'Slug',
  type: 'slug',
  sourceAttribute: 'title',
  separator: '-',
})
// Immutable-on-update field: readonly renders the input disabled.
const immutableField = baseField({
  attribute: 'uuid',
  label: 'UUID',
  type: 'text',
  readonly: true,
})

// ---------------------------------------------------------------------------
// Full-page harness
// ---------------------------------------------------------------------------

function makeSchema(fieldsForUpdate: unknown[]): ResourceSchema {
  return {
    uriKey: 'posts',
    label: 'Posts',
    singularLabel: 'Post',
    softDeletes: false,
    stickyView: true,
    group: null,
    fields: [],
    fieldsForUpdate,
    errorDisplay: 'inline',
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

/**
 * Mock the two queries the update page fires:
 *   - GET .../schema           → schema with fieldsForUpdate
 *   - GET .../:id?context=update → the pre-filled record
 * Records the context path so the test can assert `?context=update`.
 */
function mockSchemaAndRecord(fieldsForUpdate: unknown[], record: ResourceRecord) {
  apiGetMock.mockImplementation((path: string) => {
    if (path.includes('/schema')) {
      return Promise.resolve({ data: makeSchema(fieldsForUpdate) })
    }
    // record fetch: /api/resources/posts/1?context=update
    if (path.includes('/api/resources/posts/1')) {
      return Promise.resolve({ data: record })
    }
    return Promise.resolve({ data: [] })
  })
}

function renderUpdatePage() {
  const router = createMemoryRouter(
    [{ path: '/resources/:resource/:id/edit', element: <ResourceUpdatePage /> }],
    { initialEntries: ['/resources/posts/1/edit'] },
  )
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return render(
    <QueryClientProvider client={qc}>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  apiGetMock.mockReset()
  apiPutMock.mockReset()
})

// ---------------------------------------------------------------------------
// (1) Pre-filled record values render into the inputs
// ---------------------------------------------------------------------------

describe('ResourceUpdatePage — pre-filled record values', () => {
  it('seeds the form inputs from the loaded record', async () => {
    mockSchemaAndRecord([titleField, slugField], {
      id: 1,
      title: 'Existing Title',
      slug: 'existing-title',
    } as unknown as ResourceRecord)

    renderUpdatePage()

    await waitFor(() => {
      const titleInput = document.getElementById('title') as HTMLInputElement
      expect(titleInput).toBeTruthy()
      expect(titleInput.value).toBe('Existing Title')
    })
    const slugInput = screen.getByTestId('slug-input-slug') as HTMLInputElement
    expect(slugInput.value).toBe('existing-title')
  })
})

describe('ResourceUpdatePage — inputs that keep their own state', () => {
  // The page mounts the fields once the record has seeded the form, so an
  // input that copies its value into local state at mount (a custom one
  // included) starts from the stored value.
  it('mounts the fields with the record values', async () => {
    mockSchemaAndRecord([baseField({ attribute: 'notes', label: 'Notes', type: 'mount_probe' })], {
      id: 1,
      notes: 'Stored notes',
    } as unknown as ResourceRecord)

    renderUpdatePage()

    const probe = await screen.findByTestId('mount-probe')
    expect(probe.textContent).toBe('Stored notes')
  })

  it('shows the stored tags of a Tag field and keeps them when one is added', async () => {
    mockSchemaAndRecord([baseField({ attribute: 'tags', label: 'Tags', type: 'tag', relatedResource: 'tags' })], {
      id: 1,
      tags: [{ id: 1, title: 'php' }, { id: 2, title: 'laravel' }],
    } as unknown as ResourceRecord)
    const recordFetch = apiGetMock.getMockImplementation()!
    apiGetMock.mockImplementation((path: string) =>
      path.includes('/relatable/tags')
        ? Promise.resolve({ data: [{ id: 1, _title: 'php' }, { id: 2, _title: 'laravel' }, { id: 3, _title: 'react' }] })
        : recordFetch(path),
    )
    apiPutMock.mockReturnValue(new Promise(() => {}))

    renderUpdatePage()

    expect(await screen.findByText('php')).toBeTruthy()
    expect(screen.getByText('laravel')).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: 'Add Tags' }))
    fireEvent.click(await screen.findByRole('button', { name: 'react' }))
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    await waitFor(() => expect(apiPutMock).toHaveBeenCalled())
    const [, body] = apiPutMock.mock.calls[0] as [string, Record<string, unknown>]
    expect(body.tags).toEqual([
      { id: 1, title: 'php' },
      { id: 2, title: 'laravel' },
      { id: 3, title: 'react' },
    ])
  })

  it('shows the stored rows of a KeyValue field', async () => {
    mockSchemaAndRecord([baseField({ attribute: 'metadata', label: 'Metadata', type: 'key_value' })], {
      id: 1,
      metadata: [{ key: 'size', value: '50-200' }, { key: 'industry', value: 'Technology' }],
    } as unknown as ResourceRecord)

    renderUpdatePage()

    await waitFor(() => {
      const values = [...document.querySelectorAll('input')].map((input) => input.value)
      expect(values).toEqual(['size', '50-200', 'industry', 'Technology'])
    })
  })
})

describe('ResourceUpdatePage — submitted relation values', () => {
  it('reduces a BelongsTo to its id and keeps the MorphTo target map', async () => {
    const commentable = { type: 'App\\Models\\Post', id: 3, title: 'Hello', resourceType: 'posts' }
    mockSchemaAndRecord(
      [
        titleField,
        baseField({ attribute: 'author', label: 'Author', type: 'belongs_to', relatedResource: 'users' }),
        baseField({ attribute: 'commentable', label: 'Commentable', type: 'morph_to', morphTypes: [{ value: 'posts', label: 'Posts' }] }),
      ],
      {
        id: 1,
        title: 'Existing Title',
        author: { id: 7, title: 'Ana' },
        commentable,
      } as unknown as ResourceRecord,
    )
    // Left pending: only the request body matters here, and a settled save
    // would redirect through the data router, which jsdom cannot drive.
    apiPutMock.mockReturnValue(new Promise(() => {}))

    renderUpdatePage()

    await waitFor(() => {
      expect((document.getElementById('title') as HTMLInputElement | null)?.value).toBe('Existing Title')
    })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    await waitFor(() => expect(apiPutMock).toHaveBeenCalled())
    const [, body] = apiPutMock.mock.calls[0] as [string, Record<string, unknown>]
    expect(body.author).toBe(7)
    expect(body.commentable).toEqual(commentable)
  })
})

// ---------------------------------------------------------------------------
// (2) context: 'update' — record fetched with ?context=update; fieldsForUpdate
//     is the rendered source of fields.
// ---------------------------------------------------------------------------

describe('ResourceUpdatePage — update context', () => {
  it('fetches the record with ?context=update and renders fieldsForUpdate', async () => {
    mockSchemaAndRecord([titleField], {
      id: 1,
      title: 'Ctx',
    } as unknown as ResourceRecord)

    renderUpdatePage()

    // fieldsForUpdate rendered.
    await waitFor(() => {
      expect(document.getElementById('title')).toBeTruthy()
    })

    // The record query was issued against the ?context=update endpoint.
    const recordCall = apiGetMock.mock.calls.find(
      ([p]) => typeof p === 'string' && (p as string).includes('/api/resources/posts/1'),
    )
    expect(recordCall).toBeTruthy()
    expect(recordCall?.[0]).toContain('context=update')
  })
})

// ---------------------------------------------------------------------------
// (3) Immutable field renders disabled and stays disabled
// ---------------------------------------------------------------------------

describe('ResourceUpdatePage — immutable field', () => {
  it('renders a readonly (immutable) field as disabled', async () => {
    mockSchemaAndRecord([titleField, immutableField], {
      id: 1,
      title: 'T',
      uuid: 'abc-123',
    } as unknown as ResourceRecord)

    renderUpdatePage()

    // Wait for the record pre-fill: the fields mount once both queries have
    // resolved and the record has seeded the form.
    await waitFor(() => {
      const el = document.getElementById('uuid') as HTMLInputElement | null
      expect(el?.value).toBe('abc-123')
    })
    const uuidInput = document.getElementById('uuid') as HTMLInputElement
    expect(uuidInput.disabled).toBe(true)
  })
})

// ---------------------------------------------------------------------------
// (4) Slug-from-source starting from a pre-filled record
// ---------------------------------------------------------------------------

describe('ResourceUpdatePage — slug auto-generation from a pre-filled record', () => {
  async function loadTitleAndSlug(slug: FieldDefinition) {
    mockSchemaAndRecord([titleField, slug], {
      id: 1,
      title: 'Old Title',
      slug: 'old-title',
    } as unknown as ResourceRecord)

    renderUpdatePage()

    const titleInput = await waitFor(() => {
      const el = document.getElementById('title') as HTMLInputElement
      expect(el.value).toBe('Old Title')
      return el
    })
    const slugInput = screen.getByTestId('slug-input-slug') as HTMLInputElement
    expect(slugInput.value).toBe('old-title')
    return { titleInput, slugInput }
  }

  it('keeps the stored slug when the source (title) changes on an existing record', async () => {
    const { titleInput, slugInput } = await loadTitleAndSlug(slugField)

    fireEvent.change(titleInput, { target: { value: 'Brand New' } })

    await waitFor(() => expect(titleInput.value).toBe('Brand New'))
    expect(slugInput.value).toBe('old-title')
  })

  it('regenerates a cleared slug from the source and follows it again', async () => {
    const { titleInput, slugInput } = await loadTitleAndSlug({ ...slugField, nullable: true } as FieldDefinition)

    fireEvent.click(screen.getByRole('button', { name: 'Clear' }))
    await waitFor(() => expect(slugInput.value).toBe('old-title'))

    fireEvent.change(titleInput, { target: { value: 'Brand New' } })
    await waitFor(() => expect(slugInput.value).toBe('brand-new'))
  })
})
