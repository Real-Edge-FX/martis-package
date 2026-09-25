import { describe, it, expect, vi, beforeEach } from 'vitest'
import { useState, type ReactNode } from 'react'
import { render, screen, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import type { FieldDefinition, OverrideProps, ResourceRecord, ResourceSchema } from '@/types'
import type { FieldInputProps } from '@/components/fields/types'

/*
 * A host can keep the create drawer open once the record is created (a
 * `redirectAfter('stay')` override on the create page), and the drawer then
 * clears its form for the next record. The next record must start from
 * empty inputs, a custom one that reads its value at mount included, and
 * the empty form must count as clean, also when the drawer opened with a
 * copy of a record.
 */

// The replicate endpoint answers with the values of the record the drawer
// copies, as the server does (`GET /api/resources/{resource}/{id}/replicate`).
const { replicas } = vi.hoisted(() => ({ replicas: new Map<string, Record<string, unknown>>() }))

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: vi.fn((url: string) => {
        const copy = /^\/api\/resources\/([^/]+)\/([^/]+)\/replicate$/.exec(url)
        if (copy) {
          return Promise.resolve({ data: { values: replicas.get(`${copy[1]}/${copy[2]}`) ?? {}, fromResourceId: copy[2] } })
        }
        return new Promise(() => {})
      }),
      post: vi.fn(() => new Promise(() => {})),
    },
  }
})

/** The values the replicate endpoint answers for a record: all but its id. */
function copyOf(resource: string, record: Record<string, unknown>): void {
  const { id, ...values } = record
  replicas.set(`${resource}/${String(id)}`, values)
}

// DrawerShell renders children + footer into the DOM so assertions work.
vi.mock('./DrawerShell', () => ({
  DrawerShell: ({ children, footer }: { children: ReactNode; footer?: ReactNode }) => (
    <div>
      <div data-testid="drawer-content">{children}</div>
      <div data-testid="drawer-footer">{footer}</div>
    </div>
  ),
}))

import { DrawerCreate } from './DrawerCreate'
import { api } from '@/lib/api'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'
import { componentRegistry } from '@/lib/componentRegistry'

registerDefaultFields()

// A custom input (registered the way a consumer registers one) that keeps
// what the user types in its own state, read from `value` once, at mount.
function MountDraftInput({ field, value, onChange }: FieldInputProps) {
  const [draft, setDraft] = useState(() => String(value ?? ''))
  return (
    <input
      id={field.attribute}
      value={draft}
      onChange={(e) => {
        setDraft(e.target.value)
        onChange(e.target.value)
      }}
    />
  )
}
componentRegistry.registerFieldInput('mount_draft', MountDraftInput)

function field(attribute: string, label: string, type: string): FieldDefinition {
  return {
    attribute, label, type,
    nullable: true, readonly: false, required: false, sortable: false,
    searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
    rules: [], reserved: [],
  } as unknown as FieldDefinition
}

function renderStaying(fieldsForCreate: FieldDefinition[], record: Record<string, unknown> | null = null) {
  if (record) copyOf('posts', record)
  const props: OverrideProps = {
    schema: {
      uriKey: 'posts', label: 'Posts', singularLabel: 'Post', fields: [],
      fieldsForCreate, errorDisplay: 'inline', confirmUnsavedChanges: true,
    } as unknown as ResourceSchema,
    resource: 'posts',
    params: {},
    record: record as ResourceRecord | null,
    recordId: null,
    navigate: vi.fn(),
    onClose: vi.fn(),
    // Stays open, as a `redirectAfter('stay')` host does.
    onCreated: vi.fn(),
    onUpdated: vi.fn(),
    onDeleted: vi.fn(),
    onEdit: vi.fn(),
    onView: vi.fn(),
    addToast: vi.fn(),
  }
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <DrawerCreate {...props} />
      </MemoryRouter>
    </QueryClientProvider>,
  )
  return props
}

const input = (attribute: string) => document.getElementById(attribute) as HTMLInputElement

async function create(props: OverrideProps) {
  vi.mocked(api.post).mockResolvedValueOnce({ data: { id: 9 } } as never)
  fireEvent.click(screen.getByRole('button', { name: 'Create Post' }))
  await waitFor(() => expect(props.onCreated).toHaveBeenCalled())
}

beforeEach(() => {
  vi.mocked(api.post).mockClear()
})

describe('DrawerCreate — a host keeps it open for the next record', () => {
  it('starts the next record from empty inputs', async () => {
    const props = renderStaying([field('code', 'Code', 'mount_draft')])
    fireEvent.change(input('code'), { target: { value: 'FIRST-01' } })

    await create(props)

    await waitFor(() => expect(input('code').value).toBe(''))
  })

  it('closes the emptied form without a prompt after creating from a copy', async () => {
    const props = renderStaying([field('title', 'Title', 'text')], { id: 3, title: 'First post' } as Record<string, unknown>)
    await waitFor(() => expect(input('title').value).toBe('First post'))

    await create(props)
    await waitFor(() => expect(input('title').value).toBe(''))
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }))

    await waitFor(() => expect(props.onClose).toHaveBeenCalled())
    expect(screen.queryByTestId('unsaved-changes-dialog')).toBeNull()
  })
})
