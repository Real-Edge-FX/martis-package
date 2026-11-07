import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { act, render, screen, fireEvent, cleanup } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createBrowserRouter, RouterProvider } from 'react-router'
import type { FieldDefinition, OverrideProps, ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'
import { allowDataRouterNavigation } from '@/test-support/dataRouterNavigation'

/*
 * A record opened over its index, against the real browser history: the
 * real DrawerShell (its Back sentinel), DrawerDetail and DeleteModal (its
 * history lock), and a browser router. The in-place suite mocks the drawer
 * shell, so it could not see what these raw history entries do to the step
 * back on close (v2.18.0 review):
 *
 * - a delete from the drawer stepped back over the confirmation's sentinel
 *   only, which the modal pushed again: drawer and modal stayed open;
 * - a modal opened and closed over the drawer popped back onto the drawer's
 *   sentinel, whose state the router read without the in-place marker, so
 *   close pushed the index (Back reopened the record);
 * - a second record opened from the override left the first one's sentinel
 *   behind, so close landed on the first record.
 *
 * Each scenario ends with one Back press, which must leave the app (the
 * stack is back to [start, index]).
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
      delete: vi.fn(() => Promise.resolve({})),
    },
  }
})

import { ResourcePage } from '@/pages/ResourcePage'
import { DrawerDetail } from '@/components/overrides/DrawerDetail'
import { DrawerShell } from '@/components/overrides/DrawerShell'
import { componentRegistry } from '@/lib/componentRegistry'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

/** A custom override on the bundled shell that opens another record and swaps to its update override. */
function CustomDetail(props: OverrideProps) {
  return (
    <DrawerShell title={`Custom ${props.recordId}`} onClose={props.onClose}>
      <button type="button" onClick={() => props.onView(8)}>Open record 8</button>
      <button type="button" onClick={() => props.onEdit(props.recordId ?? undefined)}>Edit here</button>
    </DrawerShell>
  )
}
function CustomUpdate(props: OverrideProps) {
  return (
    <DrawerShell title="Custom update" onClose={props.onClose}>
      <button type="button" onClick={props.onClose}>Leave update</button>
    </DrawerShell>
  )
}

registerDefaultFields()
componentRegistry.register('martis:drawer-detail', DrawerDetail as never)
componentRegistry.register('test:custom-detail', CustomDetail as never)
componentRegistry.register('test:custom-update', CustomUpdate as never)
allowDataRouterNavigation()

const titleField = {
  attribute: 'title', label: 'Title', type: 'text',
  nullable: false, readonly: false, required: false, sortable: false, searchable: false,
  showOnIndex: true, showOnDetail: true, showOnForms: true, rules: [], reserved: [],
} as unknown as FieldDefinition

function schema(detail: string, update?: string): ResourceSchema {
  return {
    uriKey: 'conversations',
    label: 'Conversations',
    singularLabel: 'Conversation',
    titleAttribute: 'title',
    softDeletes: false,
    stickyView: false,
    group: null,
    fields: [titleField],
    fieldsForIndex: [titleField],
    fieldsForDetail: [titleField],
    errorDisplay: 'inline',
    confirmUnsavedChanges: false,
    defaultRowActions: { enabled: true },
    overrides: {
      detail: { component: detail, params: {} },
      ...(update ? { update: { component: update, params: {} } } : {}),
    },
    messages: {},
  } as unknown as ResourceSchema
}

let SCHEMA: ResourceSchema
const PAGE_META = { total: 1, current_page: 1, last_page: 1, per_page: 25, from: 1, to: 1 }

beforeEach(() => {
  apiGetMock.mockReset()
  apiGetMock.mockImplementation((path: string) => {
    if (/\/schema$/.test(path)) return Promise.resolve({ data: SCHEMA })
    if (/^\/api\/resources\/[^/?]+\?/.test(path)) {
      return Promise.resolve({ data: [{ id: 7, title: 'Row seven', _title: 'Row seven' }], meta: PAGE_META })
    }
    const record = /^\/api\/resources\/[^/]+\/(\d+)$/.exec(path)
    if (record) return Promise.resolve({ data: { id: Number(record[1]), title: `Row ${record[1]}`, _title: `Row ${record[1]}` } })
    return Promise.resolve({ data: [] })
  })
})

// In short steps: React commits what an act() scope queued only when the
// scope ends, so one long scope would hide every commit until its end.
async function tick(ms = 30) {
  for (let waited = 0; waited < ms; waited += 25) {
    await act(async () => { await new Promise((resolve) => setTimeout(resolve, 25)) })
  }
}

let router: ReturnType<typeof createBrowserRouter> | null = null

afterEach(async () => {
  cleanup()
  // Let a pop a drawer issued on unmount land (the router still listens),
  // then stop the router.
  await tick(250)
  router?.dispose()
  router = null
})

async function mount(path: string) {
  window.history.replaceState(null, '', '/start')
  window.history.pushState(null, '', path)
  router = createBrowserRouter([
    { path: '/resources/:resource', element: <ResourcePage /> },
    { path: '/resources/:resource/:id', element: <ResourcePage /> },
    // The entry before the app, where the last Back of a scenario lands.
    { path: '/start', element: null },
  ])
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={qc}>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </QueryClientProvider>,
  )
  await screen.findAllByText('Row seven')
  return router
}

const drawerOpen = () => document.querySelector('.martis-drawer-shell') !== null

async function openRow() {
  fireEvent.click(screen.getAllByText('Row seven')[0])
  await tick(150)
  expect(window.location.pathname).toBe('/resources/conversations/7')
  expect(drawerOpen()).toBe(true)
}

async function expectClosedOnIndexThenBackLeaves() {
  expect(window.location.pathname).toBe('/resources/conversations')
  expect(drawerOpen()).toBe(false)
  expect(document.querySelector('[role="dialog"]')).toBeNull()
  act(() => { window.history.back() })
  await tick(100)
  expect(window.location.pathname).toBe('/start')
}

describe('ResourcePage over the real history', () => {
  it('closes on Escape back to the index entry', async () => {
    SCHEMA = schema('martis:drawer-detail')
    await mount('/resources/conversations')
    await openRow()

    fireEvent.keyDown(document, { key: 'Escape' })
    await tick(500)

    await expectClosedOnIndexThenBackLeaves()
  })

  it('closes on the browser Back button', async () => {
    SCHEMA = schema('martis:drawer-detail')
    await mount('/resources/conversations')
    await openRow()

    act(() => { window.history.back() })
    await tick(500)

    await expectClosedOnIndexThenBackLeaves()
  })

  it('closes after a delete from the drawer, confirmation included', async () => {
    SCHEMA = schema('martis:drawer-detail')
    await mount('/resources/conversations')
    await openRow()
    fireEvent.click(screen.getByText('Delete'))
    await tick(100)
    expect(document.querySelector('[role="dialog"]')).not.toBeNull()

    fireEvent.click(screen.getByText('Delete permanently'))
    await tick(600)

    await expectClosedOnIndexThenBackLeaves()
  })

  it('still steps back after a modal opened and closed over the drawer', async () => {
    SCHEMA = schema('martis:drawer-detail')
    await mount('/resources/conversations')
    await openRow()
    fireEvent.click(screen.getByText('Delete'))
    await tick(100)
    fireEvent.click(screen.getAllByText('Cancel').pop() as HTMLElement)
    await tick(300)
    expect(drawerOpen()).toBe(true)

    fireEvent.keyDown(document, { key: 'Escape' })
    await tick(600)

    await expectClosedOnIndexThenBackLeaves()
  })

  it('closes to the index after another record opened from the override', async () => {
    SCHEMA = schema('test:custom-detail')
    await mount('/resources/conversations')
    await openRow()
    fireEvent.click(screen.getByText('Open record 8'))
    await tick(200)
    expect(window.location.pathname).toBe('/resources/conversations/8')

    fireEvent.keyDown(document, { key: 'Escape' })
    await tick(600)

    expect(window.location.pathname).toBe('/resources/conversations')
    expect(drawerOpen()).toBe(false)
  })

  it('closes to the index after a swap to the update override and back', async () => {
    SCHEMA = schema('test:custom-detail', 'test:custom-update')
    await mount('/resources/conversations')
    await openRow()
    fireEvent.click(screen.getByText('Edit here'))
    await tick(200)
    fireEvent.click(screen.getByText('Leave update'))
    await tick(400)
    expect(drawerOpen()).toBe(true)

    fireEvent.keyDown(document, { key: 'Escape' })
    await tick(600)

    await expectClosedOnIndexThenBackLeaves()
  })

  it('closes a deep-linked record to the index', async () => {
    SCHEMA = schema('martis:drawer-detail')
    await mount('/resources/conversations/7')
    expect(drawerOpen()).toBe(true)

    fireEvent.keyDown(document, { key: 'Escape' })
    await tick(600)

    expect(window.location.pathname).toBe('/resources/conversations')
    expect(drawerOpen()).toBe(false)
  })
})
