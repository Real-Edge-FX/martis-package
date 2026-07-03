import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor, fireEvent } from '@testing-library/react'
import { useState } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import type { ResourceSchema, FieldDefinition } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * Characterization tests for the Resource CREATE form.
 *
 * Goal: pin the CURRENT observable behaviour of the create form as a safety
 * net BEFORE a later refactor (form-harness extraction). These tests describe
 * what the code does TODAY — not what it should do. They must stay green on the
 * unmodified component and after convergence.
 *
 * Behaviour locked here (what the refactor could break):
 *   1. Slug-from-source: a `slug` field with `sourceAttribute: 'title'` reads
 *      the shared form values; changing `title` updates the slug value.
 *   2. Shared `values` threading: `handleChange(attr, v)` updates the single
 *      `values` object, and a second field reading `formValues` sees the change
 *      (verified both directly and through a layout container).
 *   3. Container items (tab_group / section / panel) render their child fields.
 *
 * -------------------------------------------------------------------------
 * Harness note (IMPORTANT — read before editing):
 *
 * Behaviours (1) and (2) are exercised through the SHARED-VALUES FIELD HARNESS
 * below (`renderFieldForm` / `SharedValuesForm`), not through a full
 * `ResourceCreatePage` mount. The harness mounts the exact prop contract the
 * page feeds each field — `FieldInput({ field, value, onChange, error,
 * resourceKey, context, formValues })` — under a parent that owns a single
 * `values` object and one `handleChange`, mirroring ResourceCreate lines
 * ~336-339 and ~459-467.
 *
 * Why not mount the whole page for the slug-typing assertions: driving a change
 * into `title` while `SlugFieldInput` is mounted inside the full page enters an
 * unbounded re-render loop under jsdom and kills the Vitest worker. Root cause
 * (pre-existing, NOT touched by this task): `SlugField.tsx` rebuilds a fresh
 * `reserved = []` array on every render and lists it in the collision-check
 * effect's dependency array, so once the page's own re-renders start
 * (useDependsOnSync + renderedFormFields recompute), the effect re-fires every
 * render. A referentially-stable field descriptor (as used here, and as the
 * real schema objects are once memoized) does not trip the loop. The harness
 * therefore locks slug-from-source faithfully and deterministically.
 *
 * Container rendering and basic field rendering (behaviour 3, plus the
 * no-interaction render of title+slug) ARE asserted against the full
 * `ResourceCreatePage` — those paths mount cleanly.
 * -------------------------------------------------------------------------
 *
 * Mocks:
 *   - `@/lib/api` is partially mocked (real module + spied `get`/`post`).
 *   - Real i18next is initialized globally by resources/js/test-setup.ts.
 *   - `ResourceCreatePage` calls `useBlocker` (via useUnsavedChangesGuard),
 *     which requires a DATA router — a plain <MemoryRouter> throws. We use
 *     `createMemoryRouter` + `<RouterProvider>`.
 */

// ---------------------------------------------------------------------------
// Mocks
// ---------------------------------------------------------------------------

const apiGetMock = vi.fn()
const apiPostMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGetMock(...args),
      post: (...args: unknown[]) => apiPostMock(...args),
    },
  }
})

import { ResourceCreatePage } from '@/pages/ResourceCreate'
import { registerDefaultFields, FieldInput } from '@/components/fields/FieldRenderer'

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

// ---------------------------------------------------------------------------
// Shared-values field harness — mirrors ResourceCreate's per-field contract:
// one `values` object, one `handleChange`, `formValues={values}` on every field.
// ---------------------------------------------------------------------------

function SharedValuesForm({ fields }: { fields: FieldDefinition[] }) {
  const [values, setValues] = useState<Record<string, unknown>>({})
  // Same shape as ResourceCreate.handleChange (line ~336).
  const handleChange = (attribute: string, value: unknown) =>
    setValues((prev) => ({ ...prev, [attribute]: value }))

  return (
    <div>
      {fields.map((field) => (
        <FieldInput
          key={field.attribute}
          field={field}
          value={values[field.attribute] ?? null}
          onChange={(v) => handleChange(field.attribute, v)}
          error={undefined}
          resourceKey="posts"
          context="create"
          formValues={values}
        />
      ))}
    </div>
  )
}

function renderFieldForm(fields: FieldDefinition[]) {
  return render(<SharedValuesForm fields={fields} />)
}

// ---------------------------------------------------------------------------
// Full-page harness — used for render-only assertions (no slug typing).
// ---------------------------------------------------------------------------

function makeSchema(fieldsForCreate: unknown[]): ResourceSchema {
  return {
    uriKey: 'posts',
    label: 'Posts',
    singularLabel: 'Post',
    softDeletes: false,
    stickyView: true,
    group: null,
    fields: [],
    fieldsForCreate,
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

function mockSchema(fieldsForCreate: unknown[]) {
  apiGetMock.mockImplementation((path: string) => {
    if (path.includes('/schema')) {
      return Promise.resolve({ data: makeSchema(fieldsForCreate) })
    }
    return Promise.resolve({ data: [] })
  })
}

function renderCreatePage() {
  const router = createMemoryRouter(
    [{ path: '/resources/:resource/create', element: <ResourceCreatePage /> }],
    { initialEntries: ['/resources/posts/create'] },
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
  apiPostMock.mockReset()
})

// ---------------------------------------------------------------------------
// (1) Slug-from-source — SlugField reads formValues[sourceAttribute]
// ---------------------------------------------------------------------------

describe('Resource create form — slug auto-generation from source', () => {
  it('updates the slug value when the source (title) field changes', async () => {
    renderFieldForm([titleField, slugField])

    const titleInput = document.getElementById('title') as HTMLInputElement
    const slugInput = screen.getByTestId('slug-input-slug') as HTMLInputElement
    expect(slugInput.value).toBe('')

    // handleChange('title', ...) updates the shared `values`; the slug field
    // reads formValues.title and live-generates a slugified value while the
    // user has not manually edited the slug input.
    fireEvent.change(titleInput, { target: { value: 'Hello World' } })

    await waitFor(() => {
      expect(slugInput.value).toBe('hello-world')
    })
  })
})

// ---------------------------------------------------------------------------
// (2) Shared `values` threading — one field's change is visible to another
// ---------------------------------------------------------------------------

describe('Resource create form — shared values threading', () => {
  it('propagates a change to every field reading formValues', async () => {
    // Two slug fields both sourced from `title`: after changing the title,
    // BOTH slug inputs reflect the slugified value, proving the single shared
    // `values` object is threaded to every field as `formValues`.
    const slugA = baseField({ attribute: 'slug_a', label: 'Slug A', type: 'slug', sourceAttribute: 'title', separator: '-' })
    const slugB = baseField({ attribute: 'slug_b', label: 'Slug B', type: 'slug', sourceAttribute: 'title', separator: '-' })

    renderFieldForm([titleField, slugA, slugB])

    fireEvent.change(document.getElementById('title') as HTMLInputElement, { target: { value: 'Alpha Beta' } })

    await waitFor(() => {
      expect((screen.getByTestId('slug-input-slug_a') as HTMLInputElement).value).toBe('alpha-beta')
      expect((screen.getByTestId('slug-input-slug_b') as HTMLInputElement).value).toBe('alpha-beta')
    })
  })
})

// ---------------------------------------------------------------------------
// (3) Full page — basic field rendering (text + slug), no interaction
// ---------------------------------------------------------------------------

describe('ResourceCreatePage — basic field rendering', () => {
  it('renders a text field and a slug field from the schema', async () => {
    mockSchema([titleField, slugField])

    renderCreatePage()

    expect(await screen.findByText('Title')).toBeTruthy()
    expect(document.getElementById('title')).toBeTruthy()
    expect(screen.getByTestId('slug-input-slug')).toBeTruthy()
  })
})

// ---------------------------------------------------------------------------
// (3) Full page — belongs_to field renders (no interaction, no relatable fetch)
// ---------------------------------------------------------------------------

describe('ResourceCreatePage — belongs_to field', () => {
  it('renders a belongs_to field trigger', async () => {
    const belongsToField = baseField({
      attribute: 'author_id',
      label: 'Author',
      type: 'belongs_to',
      relatedResource: 'authors',
      nullable: true,
    })
    mockSchema([belongsToField])

    renderCreatePage()

    expect(await screen.findByText('Author')).toBeTruthy()
    // BelongsToFieldInput renders a trigger button; with no value selected and
    // no `select_field` translation registered in the test i18n bundle, i18next
    // falls back to rendering the raw key.
    expect(screen.getByText('select_field')).toBeTruthy()
  })
})

// ---------------------------------------------------------------------------
// (3) Full page — layout containers render their child fields
// ---------------------------------------------------------------------------

describe('ResourceCreatePage — layout containers render child fields', () => {
  it('renders a field nested inside a tab_group', async () => {
    mockSchema([
      { type: 'tab_group', tabs: [{ title: 'General', fields: [titleField] }] },
    ])

    renderCreatePage()

    expect(await screen.findByRole('tab', { name: 'General' })).toBeTruthy()
    expect(screen.getByText('Title')).toBeTruthy()
    expect(document.getElementById('title')).toBeTruthy()
  })

  it('renders a field nested inside a section', async () => {
    mockSchema([
      {
        type: 'section',
        title: 'Details',
        fields: [titleField],
        columns: 12,
        collapsible: false,
        collapsedByDefault: false,
        limit: null,
      },
    ])

    renderCreatePage()

    expect(await screen.findByText('Details')).toBeTruthy()
    expect(screen.getByText('Title')).toBeTruthy()
    expect(document.getElementById('title')).toBeTruthy()
  })

  it('renders a field nested inside a panel', async () => {
    mockSchema([
      {
        type: 'panel',
        title: 'Metadata',
        fields: [titleField],
        collapsible: false,
        collapsedByDefault: false,
        limit: null,
      },
    ])

    renderCreatePage()

    expect(await screen.findByText('Metadata')).toBeTruthy()
    expect(screen.getByText('Title')).toBeTruthy()
    expect(document.getElementById('title')).toBeTruthy()
  })
})

// ---------------------------------------------------------------------------
// dependsOn reactivity for fields nested inside layout containers.
//
// Regression guard (Task 4): when ResourceCreate converged onto
// useMartisForm/FieldsForm, the dependsOn override machinery stopped reaching
// fields nested inside `section` / `tab_group` / `panel` because the hook fed
// the container tree (not the flattened leaf list) to useDependsOnSync and
// applied overrides with a flat `.map()` instead of walking containers.
//
// These tests mount a schema with a dependsOn field INSIDE a container, drive
// the watched sibling, and assert the server override (required: true) reaches
// the nested field — visible as the FieldWrapper required asterisk.
// ---------------------------------------------------------------------------

describe('ResourceCreatePage — nested-container dependsOn reactivity', () => {
  // A field that watches `title` and, per the server sync, becomes required.
  const dependentInSection = baseField({
    attribute: 'subtitle',
    label: 'Subtitle',
    type: 'text',
    dependsOn: { fields: ['title'] },
  })

  function mockSchemaWithSync(fieldsForCreate: unknown[]) {
    apiGetMock.mockImplementation((path: string) => {
      if (path.includes('/schema')) {
        return Promise.resolve({ data: makeSchema(fieldsForCreate) })
      }
      return Promise.resolve({ data: [] })
    })
    // sync-field returns the resolved override for the dependent field.
    apiPostMock.mockImplementation((path: string) => {
      if (path.includes('/sync-field')) {
        return Promise.resolve({ ...dependentInSection, required: true })
      }
      return Promise.resolve({ data: {} })
    })
  }

  it('applies a dependsOn override to a field nested inside a section', async () => {
    mockSchemaWithSync([
      titleField,
      {
        type: 'section',
        title: 'Details',
        fields: [dependentInSection],
        columns: 12,
        collapsible: false,
        collapsedByDefault: false,
        limit: null,
      },
    ])

    renderCreatePage()

    // Subtitle renders nested; it is NOT required yet (no watched change).
    expect(await screen.findByText('Subtitle')).toBeTruthy()
    const subtitleLabel = screen.getByText('Subtitle').closest('label')
    expect(subtitleLabel?.querySelector('.martis-input-required')).toBeNull()

    // Drive the watched sibling — triggers a sync-field POST whose override
    // must reach the nested field.
    fireEvent.change(document.getElementById('title') as HTMLInputElement, {
      target: { value: 'Hello' },
    })

    await waitFor(() => {
      const label = screen.getByText('Subtitle').closest('label')
      expect(label?.querySelector('.martis-input-required')).not.toBeNull()
    })
  })

  it('applies a dependsOn override to a field nested inside a tab_group', async () => {
    mockSchemaWithSync([
      titleField,
      {
        type: 'tab_group',
        tabs: [{ title: 'General', fields: [dependentInSection] }],
      },
    ])

    renderCreatePage()

    expect(await screen.findByText('Subtitle')).toBeTruthy()
    const before = screen.getByText('Subtitle').closest('label')
    expect(before?.querySelector('.martis-input-required')).toBeNull()

    fireEvent.change(document.getElementById('title') as HTMLInputElement, {
      target: { value: 'Hello' },
    })

    await waitFor(() => {
      const label = screen.getByText('Subtitle').closest('label')
      expect(label?.querySelector('.martis-input-required')).not.toBeNull()
    })
  })
})
