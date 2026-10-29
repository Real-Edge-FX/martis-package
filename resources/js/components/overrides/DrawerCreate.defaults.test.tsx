import { describe, it, expect, vi, beforeEach } from 'vitest'
import type { ReactNode } from 'react'
import { render, screen, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import type { FieldDefinition, OverrideProps, ResourceSchema } from '@/types'

/* The create drawer starts from each field's `default()`; a copy replaces them. */

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: vi.fn((url: string) => {
        if (/\/replicate$/.test(url)) return Promise.resolve({ data: { values: { code: 'copied' }, fromResourceId: 3 } })
        return new Promise(() => {})
      }),
      post: vi.fn(),
    },
  }
})

vi.mock('./DrawerShell', () => ({
  DrawerShell: ({ children, footer }: { children: ReactNode; footer?: ReactNode }) => (
    <div>
      <div>{children}</div>
      <div>{footer}</div>
    </div>
  ),
}))

import { DrawerCreate } from './DrawerCreate'
import { api } from '@/lib/api'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()

function field(attribute: string, label: string, extra: Record<string, unknown> = {}): FieldDefinition {
  return {
    attribute, label, type: 'text',
    nullable: true, readonly: false, required: false, sortable: false,
    searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
    rules: [], reserved: [], ...extra,
  } as unknown as FieldDefinition
}

const schema = {
  uriKey: 'posts', label: 'Posts', singularLabel: 'Post', fields: [],
  fieldsForCreate: [field('code', 'Code', { defaultValue: 'abc' }), field('kind', 'Kind', { defaultValue: 'draft' })],
  errorDisplay: 'inline', confirmUnsavedChanges: false,
} as unknown as ResourceSchema

function renderDrawer(extra: Partial<OverrideProps> = {}): OverrideProps {
  const props = {
    schema, resource: 'posts', params: {}, record: null, recordId: null,
    navigate: vi.fn(), onClose: vi.fn(), onCreated: vi.fn(), onUpdated: vi.fn(),
    onDeleted: vi.fn(), onEdit: vi.fn(), onView: vi.fn(), addToast: vi.fn(), ...extra,
  } as OverrideProps
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>
        <DrawerCreate {...props} />
      </MemoryRouter>
    </QueryClientProvider>,
  )
  return props
}

const input = (attribute: string) => document.getElementById(attribute) as HTMLInputElement | null

beforeEach(() => {
  vi.mocked(api.post).mockReset()
})

describe('DrawerCreate: field defaults', () => {
  it('starts from each field default', async () => {
    renderDrawer()

    await waitFor(() => expect(input('code')?.value).toBe('abc'))
    expect(input('kind')?.value).toBe('draft')
  })

  it('replaces a default with the replicated value', async () => {
    renderDrawer({ fromResourceId: 3 })

    await waitFor(() => expect(input('code')?.value).toBe('copied'))
    expect(input('kind')?.value).toBe('draft')
  })

  it('returns to the defaults once a record is created', async () => {
    const props = renderDrawer()
    await waitFor(() => expect(input('code')?.value).toBe('abc'))
    fireEvent.change(input('code')!, { target: { value: 'typed' } })

    vi.mocked(api.post).mockResolvedValueOnce({ data: { id: 9 } } as never)
    fireEvent.click(screen.getByRole('button', { name: 'Create Post' }))
    await waitFor(() => expect(props.onCreated).toHaveBeenCalled())

    await waitFor(() => expect(input('code')?.value).toBe('abc'))
  })
})
