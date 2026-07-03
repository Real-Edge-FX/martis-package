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
 *   4. Slug-from-source works starting from a PRE-FILLED record: editing the
 *      source field re-generates the slug through the shared form values.
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

import { ResourceUpdatePage } from '@/pages/ResourceUpdate'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

// FieldInput resolves its concrete component through the global registry, so
// the default field components must be registered (app.tsx does this at boot).
registerDefaultFields()

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

    await waitFor(() => {
      expect(document.getElementById('uuid')).toBeTruthy()
    })
    const uuidInput = document.getElementById('uuid') as HTMLInputElement
    expect(uuidInput.value).toBe('abc-123')
    expect(uuidInput.disabled).toBe(true)
  })
})

// ---------------------------------------------------------------------------
// (4) Slug-from-source starting from a pre-filled record
// ---------------------------------------------------------------------------

describe('ResourceUpdatePage — slug auto-generation from a pre-filled record', () => {
  it('re-generates the slug when the source (title) changes on an existing record', async () => {
    mockSchemaAndRecord([titleField, slugField], {
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

    // Editing the source field re-slugifies through the shared form values.
    fireEvent.change(titleInput, { target: { value: 'Brand New' } })

    await waitFor(() => {
      expect(slugInput.value).toBe('brand-new')
    })
  })
})
