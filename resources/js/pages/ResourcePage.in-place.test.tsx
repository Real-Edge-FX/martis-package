import { describe, it, expect, vi, beforeEach } from 'vitest'
import { act, render, screen, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router'
import type { ReactNode } from 'react'
import type { FieldDefinition, ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'
import { allowDataRouterNavigation } from '@/test-support/dataRouterNavigation'

/*
 * A record of a resource with a detail override opens over its index
 * (v2.18.0). It used to navigate to a route whose page showed a full-page
 * loader until the schema and the record arrived, then mounted the index
 * again behind the drawer; closing remounted the index once more. Both
 * paths are now served by `ResourcePage`, so the index stays mounted: the
 * list stays on screen and is not fetched again, the drawer shows its own
 * skeleton, and the record URL still lands in the history.
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
      delete: vi.fn(() => new Promise(() => {})),
    },
  }
})

vi.mock('@/components/overrides/DrawerShell', () => ({
  DrawerShell: ({ title, children, onClose }: { title: string; children: ReactNode; onClose: () => void }) => (
    <div data-testid="drawer">
      <h2>{title}</h2>
      <button type="button" onClick={onClose}>Close drawer</button>
      <div>{children}</div>
    </div>
  ),
}))

import { ResourcePage } from '@/pages/ResourcePage'
import { DrawerDetail } from '@/components/overrides/DrawerDetail'
import { componentRegistry } from '@/lib/componentRegistry'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()
componentRegistry.register('martis:drawer-detail', DrawerDetail as never)
allowDataRouterNavigation()

const titleField = {
  attribute: 'title', label: 'Title', type: 'text',
  nullable: false, readonly: false, required: false, sortable: false, searchable: false,
  showOnIndex: true, showOnDetail: true, showOnForms: true, rules: [], reserved: [],
} as unknown as FieldDefinition

function schema(uriKey: string, withDrawer: boolean): ResourceSchema {
  return {
    uriKey,
    label: 'Conversations',
    singularLabel: 'Conversation',
    titleAttribute: 'title',
    softDeletes: false,
    stickyView: false,
    group: null,
    fields: [],
    fieldsForIndex: [titleField],
    fieldsForDetail: [titleField],
    errorDisplay: 'inline',
    confirmUnsavedChanges: false,
    defaultRowActions: { enabled: true },
    overrides: withDrawer ? { detail: { component: 'martis:drawer-detail', params: {} } } : {},
    messages: {},
  } as unknown as ResourceSchema
}

const SCHEMAS: Record<string, ResourceSchema> = {
  conversations: schema('conversations', true),
  notes: schema('notes', false),
  // A detail override whose component is not registered (a typo, an
  // extension bundle that did not load): the full detail page, as before.
  tickets: {
    ...schema('tickets', false),
    overrides: { detail: { component: 'app:not-registered', params: {} } },
  } as unknown as ResourceSchema,
}
const ROW = { id: 7, title: 'Conversation of 7 Nov', _title: 'Conversation of 7 Nov' }
const PAGE_META = { total: 1, current_page: 1, last_page: 1, per_page: 25, from: 1, to: 1 }

let recordResponse: Promise<unknown>

beforeEach(() => {
  apiGetMock.mockReset()
  recordResponse = Promise.resolve({ data: { ...ROW, _documentTitle: 'Conversation of 7 Nov · Visitor' } })
  apiGetMock.mockImplementation((path: string) => {
    const schemaPath = /^\/api\/resources\/([^/]+)\/schema$/.exec(path)
    if (schemaPath) return Promise.resolve({ data: SCHEMAS[schemaPath[1]] })
    if (/^\/api\/resources\/[^/?]+\?/.test(path)) return Promise.resolve({ data: [ROW], meta: PAGE_META })
    if (/^\/api\/resources\/[^/]+\/7$/.test(path)) return recordResponse
    return Promise.resolve({ data: [] })
  })
})

function renderAt(path: string) {
  const router = createMemoryRouter(
    // Mirrors router.tsx: the index and the record route share the element.
    [
      { path: '/resources/:resource', element: <ResourcePage /> },
      { path: '/resources/:resource/:id', element: <ResourcePage /> },
    ],
    { initialEntries: [path] },
  )
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  render(
    <QueryClientProvider client={qc}>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </QueryClientProvider>,
  )
  return router
}

const calls = (pattern: RegExp) => apiGetMock.mock.calls.filter(([path]) => pattern.test(String(path))).length
const SCHEMA_GET = /\/schema$/
const LIST_GET = /^\/api\/resources\/[^/?]+\?/
const RECORD_GET = /^\/api\/resources\/[^/]+\/7$/

async function listTable(): Promise<HTMLTableElement> {
  await screen.findAllByText('Conversation of 7 Nov')
  const table = document.querySelector('table')
  expect(table).not.toBeNull()
  return table as HTMLTableElement
}

describe('ResourcePage — a record with a drawer detail opens over the index', () => {
  it('opens on a row click without remounting or refetching the list', async () => {
    const router = renderAt('/resources/conversations')
    const table = await listTable()

    fireEvent.click(screen.getByText('Conversation of 7 Nov'))

    await waitFor(() => expect(router.state.location.pathname).toBe('/resources/conversations/7'))
    expect(await screen.findByTestId('drawer')).toBeTruthy()
    // The same table node: the index was not mounted again.
    expect(document.querySelector('table')).toBe(table)
    expect(calls(SCHEMA_GET)).toBe(1)
    expect(calls(LIST_GET)).toBe(1)
    await waitFor(() => expect(calls(RECORD_GET)).toBe(1))
  })

  it('shows the drawer at once, over the list, while the record loads', async () => {
    recordResponse = new Promise(() => {})
    renderAt('/resources/conversations')
    const table = await listTable()

    fireEvent.click(screen.getByText('Conversation of 7 Nov'))

    expect(await screen.findByTestId('drawer')).toBeTruthy()
    expect(document.querySelector('table')).toBe(table)
  })

  it('closes back to the index entry without refetching the list', async () => {
    const router = renderAt('/resources/conversations')
    const table = await listTable()
    fireEvent.click(screen.getByText('Conversation of 7 Nov'))
    await screen.findByTestId('drawer')

    fireEvent.click(screen.getByRole('button', { name: 'Close drawer' }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/resources/conversations'))
    // A step back, not a new entry.
    expect(router.state.historyAction).toBe('POP')
    expect(screen.queryByTestId('drawer')).toBeNull()
    expect(document.querySelector('table')).toBe(table)
    expect(calls(SCHEMA_GET)).toBe(1)
    expect(calls(LIST_GET)).toBe(1)
  })

  it('closes on the back button the same way', async () => {
    const router = renderAt('/resources/conversations')
    const table = await listTable()
    fireEvent.click(screen.getByText('Conversation of 7 Nov'))
    await screen.findByTestId('drawer')

    await act(() => router.navigate(-1))

    await waitFor(() => expect(screen.queryByTestId('drawer')).toBeNull())
    expect(document.querySelector('table')).toBe(table)
    expect(calls(LIST_GET)).toBe(1)
  })

  it('renders the index with the drawer on a direct visit to the record URL', async () => {
    const router = renderAt('/resources/conversations/7')

    const table = await listTable()
    expect(await screen.findByTestId('drawer')).toBeTruthy()

    // Closing a deep-linked record pushes the index, which stays mounted.
    fireEvent.click(screen.getByRole('button', { name: 'Close drawer' }))
    await waitFor(() => expect(router.state.location.pathname).toBe('/resources/conversations'))
    expect(router.state.historyAction).toBe('PUSH')
    expect(document.querySelector('table')).toBe(table)
    expect(calls(LIST_GET)).toBe(1)
  })

  it("titles the browser tab with the resource's document title", async () => {
    renderAt('/resources/conversations/7')

    await waitFor(() => expect(document.title.startsWith('Conversation of 7 Nov · Visitor')).toBe(true))
  })
})

describe('ResourcePage — a resource without a detail override', () => {
  it('navigates to the full detail page', async () => {
    const router = renderAt('/resources/notes')
    await listTable()

    fireEvent.click(screen.getByText('Conversation of 7 Nov'))

    await waitFor(() => expect(router.state.location.pathname).toBe('/resources/notes/7'))
    await waitFor(() => expect(document.querySelector('table')).toBeNull())
    expect(screen.queryByTestId('drawer')).toBeNull()
    expect(await screen.findByRole('heading', { level: 1, name: 'Conversation of 7 Nov' })).toBeTruthy()
  })

  it('renders the full detail page for a detail override that is not registered', async () => {
    const router = renderAt('/resources/tickets')
    await listTable()

    fireEvent.click(screen.getByText('Conversation of 7 Nov'))

    await waitFor(() => expect(router.state.location.pathname).toBe('/resources/tickets/7'))
    await waitFor(() => expect(document.querySelector('table')).toBeNull())
    expect(await screen.findByRole('heading', { level: 1, name: 'Conversation of 7 Nov' })).toBeTruthy()
  })
})
