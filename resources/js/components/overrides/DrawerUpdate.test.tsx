import { describe, it, expect, vi } from 'vitest'
import { useState } from 'react'
import { render, screen, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import type { FieldDefinition, OverrideProps, ResourceRecord, ResourceSchema } from '@/types'
import type { FieldInputProps } from '@/components/fields/types'

/*
 * The update drawer seeds its form from the record in an effect and mounts
 * the fields only after that, so every input starts from the stored value:
 * an input that reads its value at mount (a custom one included) shows it,
 * and a stored slug counts as set, so editing its source leaves it alone.
 */

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: vi.fn(() => new Promise(() => {})),
      put: vi.fn(() => new Promise(() => {})),
    },
  }
})

// DrawerShell renders children + footer into the DOM so assertions work.
vi.mock('./DrawerShell', () => ({
  DrawerShell: ({ children, footer }: { children: React.ReactNode; footer?: React.ReactNode }) => (
    <div>
      <div data-testid="drawer-content">{children}</div>
      <div data-testid="drawer-footer">{footer}</div>
    </div>
  ),
}))

import { DrawerUpdate } from './DrawerUpdate'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'
import { componentRegistry } from '@/lib/componentRegistry'

registerDefaultFields()

// A custom input (registered the way a consumer registers one) that reads its
// value once, at mount.
function MountValueProbe({ value }: FieldInputProps) {
  const [mounted] = useState(() => String(value ?? ''))
  return <span data-testid="mount-probe">{mounted}</span>
}
componentRegistry.registerFieldInput('mount_probe', MountValueProbe)

function baseField(overrides: Record<string, unknown>): FieldDefinition {
  return {
    nullable: false, readonly: false, required: false, sortable: false,
    searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
    rules: [], reserved: [],
    ...overrides,
  } as unknown as FieldDefinition
}

const titleField = baseField({ attribute: 'title', label: 'Title', type: 'text' })
const slugField = baseField({ attribute: 'slug', label: 'Slug', type: 'slug', sourceAttribute: 'title', separator: '-' })

function renderDrawer(fieldsForUpdate: FieldDefinition[], record: Record<string, unknown>) {
  const schema = {
    uriKey: 'posts', label: 'Posts', singularLabel: 'Post', fields: [], fieldsForUpdate,
    errorDisplay: 'inline', confirmUnsavedChanges: false,
  } as unknown as ResourceSchema
  const props: OverrideProps = {
    schema,
    resource: 'posts',
    params: {},
    record: { id: 1, ...record } as unknown as ResourceRecord,
    recordId: '1',
    navigate: vi.fn(),
    onClose: vi.fn(),
    onCreated: vi.fn(),
    onUpdated: vi.fn(),
    onDeleted: vi.fn(),
    onEdit: vi.fn(),
    onView: vi.fn(),
    addToast: vi.fn(),
  }
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <DrawerUpdate {...props} />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('DrawerUpdate record hydration', () => {
  it('mounts the fields with the record values', async () => {
    renderDrawer([baseField({ attribute: 'notes', label: 'Notes', type: 'mount_probe' })], { notes: 'Stored notes' })

    const probe = await screen.findByTestId('mount-probe')
    expect(probe.textContent).toBe('Stored notes')
  })

  it('keeps the stored slug when the title changes', async () => {
    renderDrawer([titleField, slugField], { title: 'Old Title', slug: 'old-title' })

    const titleInput = await waitFor(() => {
      const el = document.getElementById('title') as HTMLInputElement
      expect(el.value).toBe('Old Title')
      return el
    })
    const slugInput = screen.getByTestId('slug-input-slug') as HTMLInputElement

    fireEvent.change(titleInput, { target: { value: 'Brand New' } })

    await waitFor(() => expect(titleInput.value).toBe('Brand New'))
    expect(slugInput.value).toBe('old-title')
  })
})
