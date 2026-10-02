import { describe, it, expect, vi, beforeEach } from 'vitest'
import type { ReactNode } from 'react'
import { render, screen, waitFor, fireEvent, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import type { FieldDefinition, OverrideProps, ResourceSchema } from '@/types'
import { apiSegments, resolveApiPath, TRAVERSING_ID, TRAVERSING_ID_SEGMENT } from '@/test-support/apiPaths'

/*
 * F012, F015: the drawers load, save and delete the record they were handed
 * (`recordId`: an index row's id, an action response's, a lens row's) through
 * `/api/resources/{resource}/{recordId}`. A string key a less-trusted writer
 * chose (`../users/5`) rewrote those requests to another resource's record
 * under the administrator's session. The id is one encoded segment now.
 */

const apiGetMock = vi.fn()
const apiPutMock = vi.fn()
const apiPostMock = vi.fn()
const apiDeleteMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGetMock(...args),
      put: (...args: unknown[]) => apiPutMock(...args),
      post: (...args: unknown[]) => apiPostMock(...args),
      delete: (...args: unknown[]) => apiDeleteMock(...args),
      upload: vi.fn(() => new Promise(() => {})),
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

import { DrawerDetail } from './DrawerDetail'
import { DrawerUpdate } from './DrawerUpdate'
import { DrawerQuick } from './DrawerQuick'
import { DrawerCreate } from './DrawerCreate'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()

const titleField = {
  attribute: 'title', label: 'Title', type: 'text',
  nullable: true, readonly: false, required: false, sortable: false, searchable: false,
  showOnIndex: true, showOnDetail: true, showOnForms: true, rules: [], reserved: [],
} as unknown as FieldDefinition

const schema = {
  uriKey: 'posts', label: 'Posts', singularLabel: 'Post', softDeletes: false, fields: [],
  fieldsForDetail: [titleField], fieldsForUpdate: [titleField], fieldsForCreate: [titleField], fieldsForPreview: [titleField],
  errorDisplay: 'inline', confirmUnsavedChanges: false, messages: {},
} as unknown as ResourceSchema

function propsFor(extra: Partial<OverrideProps> = {}): OverrideProps {
  return {
    schema,
    resource: 'posts',
    params: {},
    record: null,
    recordId: TRAVERSING_ID,
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
}

function renderDrawer(node: ReactNode) {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })}>
      <MemoryRouter>{node}</MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  apiGetMock.mockReset()
  apiPutMock.mockReset()
  apiPostMock.mockReset()
  apiDeleteMock.mockReset()
  apiGetMock.mockImplementation(() => Promise.resolve({ data: { id: 1, _title: 'A post', title: 'A post', values: { title: 'Copied' } } }))
  apiPutMock.mockResolvedValue({ data: { id: 1 } })
  apiPostMock.mockResolvedValue({ data: { id: 1 } })
  apiDeleteMock.mockResolvedValue({})
})

describe('DrawerDetail — a record id that traverses stays one path segment', () => {
  it('loads and deletes through the resource endpoint', async () => {
    renderDrawer(<DrawerDetail {...propsFor()} />)

    await waitFor(() => expect(apiGetMock).toHaveBeenCalledTimes(1))
    expect(apiSegments(apiGetMock.mock.calls[0]![0] as string)).toEqual(['resources', 'posts', TRAVERSING_ID_SEGMENT])

    fireEvent.click(await within(screen.getByTestId('drawer-footer')).findByRole('button', { name: /Delete/ }))
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Delete permanently' }))

    await waitFor(() => expect(apiDeleteMock).toHaveBeenCalledTimes(1))
    const path = apiDeleteMock.mock.calls[0]![0] as string
    expect(path).toBe(`/api/resources/posts/${TRAVERSING_ID_SEGMENT}`)
    expect(apiSegments(path)).toEqual(['resources', 'posts', TRAVERSING_ID_SEGMENT])
    expect(path).not.toContain(TRAVERSING_ID)
  })
})

describe('DrawerUpdate — a record id that traverses stays one path segment', () => {
  it('loads and saves through the resource endpoint', async () => {
    renderDrawer(<DrawerUpdate {...propsFor()} />)

    await waitFor(() => expect((document.getElementById('title') as HTMLInputElement | null)?.value).toBe('A post'))
    const load = apiGetMock.mock.calls[0]![0] as string
    expect(apiSegments(load)).toEqual(['resources', 'posts', TRAVERSING_ID_SEGMENT])
    expect(resolveApiPath(load).search).toBe('?context=update')

    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(apiPutMock).toHaveBeenCalledTimes(1))
    const path = apiPutMock.mock.calls[0]![0] as string
    expect(apiSegments(path)).toEqual(['resources', 'posts', TRAVERSING_ID_SEGMENT])
    expect(resolveApiPath(path).search).toBe('')
  })
})

describe('DrawerQuick — a record id that traverses stays one path segment', () => {
  it('loads the record through the resource endpoint', async () => {
    renderDrawer(<DrawerQuick {...propsFor()} />)

    await waitFor(() => expect(apiGetMock).toHaveBeenCalledTimes(1))
    expect(apiSegments(apiGetMock.mock.calls[0]![0] as string)).toEqual(['resources', 'posts', TRAVERSING_ID_SEGMENT])
  })
})

describe('DrawerCreate — a record id to replicate that traverses stays one path segment', () => {
  it('reads the replicate endpoint of the record, not another one', async () => {
    renderDrawer(<DrawerCreate {...propsFor({ recordId: null, fromResourceId: TRAVERSING_ID })} />)

    await waitFor(() => expect(apiGetMock).toHaveBeenCalledTimes(1))
    const path = apiGetMock.mock.calls[0]![0] as string
    expect(apiSegments(path)).toEqual(['resources', 'posts', TRAVERSING_ID_SEGMENT, 'replicate'])
  })

  it('posts a new record to the resource endpoint', async () => {
    renderDrawer(<DrawerCreate {...propsFor({ recordId: null, resource: '../users' })} />)

    await waitFor(() => expect(document.getElementById('title')).not.toBeNull())
    fireEvent.change(document.getElementById('title')!, { target: { value: 'New' } })
    fireEvent.click(within(screen.getByTestId('drawer-footer')).getAllByRole('button').find((b) => /create|save/i.test(b.textContent ?? ''))!)

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(1))
    expect(apiSegments(apiPostMock.mock.calls[0]![0] as string)).toEqual(['resources', '..%252Fusers'])
  })
})
