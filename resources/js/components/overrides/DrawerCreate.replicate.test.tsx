import { describe, it, expect, vi, beforeEach } from 'vitest'
import type { ReactNode } from 'react'
import { render, screen, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import type { FieldDefinition, OverrideProps, ResourceRecord, ResourceSchema } from '@/types'

/*
 * Replicate through a create override. The detail page opens the create
 * drawer with the record it shows, and the drawer copied that record's
 * detail payload into the form: File paths (the copy would point at the same
 * stored file), fields the user may not see for the record, and no
 * `authorizedToReplicate()` check, unlike the create page, which reads the
 * replicate endpoint. The drawer now reads the endpoint too, mounts its
 * fields once the copy's values have arrived, and sends `fromResourceId` with
 * the create, so the server answers with the replicated message.
 */

const { replicaResponse } = vi.hoisted(() => ({
  replicaResponse: { current: null as null | (() => Promise<unknown>) },
}))

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: vi.fn((url: string) => {
        if (/\/replicate$/.test(url) && replicaResponse.current) return replicaResponse.current()
        return new Promise(() => {})
      }),
      post: vi.fn(() => new Promise(() => {})),
    },
  }
})

vi.mock('./DrawerShell', () => ({
  DrawerShell: ({ children, footer }: { children: ReactNode; footer?: ReactNode }) => (
    <div>
      <div data-testid="drawer-content">{children}</div>
      <div data-testid="drawer-footer">{footer}</div>
    </div>
  ),
}))

import { DrawerCreate } from './DrawerCreate'
import { api, ApiError } from '@/lib/api'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()

function field(attribute: string, label: string): FieldDefinition {
  return {
    attribute, label, type: 'text',
    nullable: true, readonly: false, required: false, sortable: false,
    searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
    rules: [], reserved: [],
  } as unknown as FieldDefinition
}

const schema = {
  uriKey: 'posts', label: 'Posts', singularLabel: 'Post', fields: [],
  fieldsForCreate: [field('title', 'Title'), field('cover', 'Cover')],
  errorDisplay: 'inline', confirmUnsavedChanges: false,
} as unknown as ResourceSchema

function renderDrawer(extra: Partial<OverrideProps>): OverrideProps {
  const props: OverrideProps = {
    schema,
    resource: 'posts',
    params: {},
    record: null,
    recordId: null,
    navigate: vi.fn(),
    onClose: vi.fn(),
    onCreated: vi.fn(),
    onUpdated: vi.fn(),
    onDeleted: vi.fn(),
    onEdit: vi.fn(),
    onView: vi.fn(),
    addToast: vi.fn(),
    ...extra,
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

const input = (attribute: string) => document.getElementById(attribute) as HTMLInputElement | null

beforeEach(() => {
  vi.mocked(api.get).mockClear()
  vi.mocked(api.post).mockClear()
  replicaResponse.current = () => Promise.resolve({ data: { values: { title: 'First post' }, fromResourceId: 3 } })
})

describe('DrawerCreate — replicate', () => {
  it("fills the form from the replicate endpoint, not from the record's detail payload", async () => {
    // The detail payload carries the stored cover path; the endpoint leaves
    // File fields out, as it does for the create page.
    renderDrawer({ record: { id: 3, title: 'First post', cover: 'covers/first.png' } as unknown as ResourceRecord })

    await waitFor(() => expect(input('title')?.value).toBe('First post'))
    expect(input('cover')?.value).toBe('')
    expect(api.get).toHaveBeenCalledWith('/api/resources/posts/3/replicate')
  })

  it('mounts the fields only once the copy has arrived', async () => {
    let answer: (value: unknown) => void = () => {}
    replicaResponse.current = () => new Promise((resolve) => { answer = resolve })
    renderDrawer({ fromResourceId: 3 })

    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/api/resources/posts/3/replicate'))
    expect(input('title')).toBeNull()

    answer({ data: { values: { title: 'First post' }, fromResourceId: 3 } })

    await waitFor(() => expect(input('title')?.value).toBe('First post'))
  })

  it('sends the id it copied with the create, and only with the first create', async () => {
    const props = renderDrawer({ fromResourceId: 3 })
    await waitFor(() => expect(input('title')?.value).toBe('First post'))
    fireEvent.change(input('title')!, { target: { value: 'First post (copy)' } })

    vi.mocked(api.post).mockResolvedValueOnce({ data: { id: 9 } } as never)
    fireEvent.click(screen.getByRole('button', { name: 'Create Post' }))
    await waitFor(() => expect(props.onCreated).toHaveBeenCalled())
    expect(api.post).toHaveBeenLastCalledWith('/api/resources/posts', { title: 'First post (copy)', fromResourceId: 3 })

    // A host that keeps the drawer open creates plain records next.
    await waitFor(() => expect(input('title')?.value).toBe(''))
    fireEvent.change(input('title')!, { target: { value: 'Second post' } })
    vi.mocked(api.post).mockResolvedValueOnce({ data: { id: 10 } } as never)
    fireEvent.click(screen.getByRole('button', { name: 'Create Post' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(2))
    expect(api.post).toHaveBeenLastCalledWith('/api/resources/posts', { title: 'Second post' })
  })

  it('shows why when the record cannot be replicated, and no form', async () => {
    replicaResponse.current = () => Promise.reject(new ApiError(403, 'This action is unauthorized.'))
    renderDrawer({ fromResourceId: 3 })

    expect(await screen.findByRole('alert')).toHaveProperty('textContent', 'This action is unauthorized.')
    expect(input('title')).toBeNull()
  })

  it('creates a plain record without asking the replicate endpoint', async () => {
    renderDrawer({})

    await waitFor(() => expect(input('title')).not.toBeNull())
    expect(api.get).not.toHaveBeenCalled()
  })
})
