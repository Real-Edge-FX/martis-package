import { describe, it, expect, vi, beforeEach } from 'vitest'
import { act, render, screen, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router'
import type { ReactNode } from 'react'
import type { FieldDefinition, ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'
import { allowDataRouterNavigation } from '@/test-support/dataRouterNavigation'

/*
 * The router keeps the index page's instance when the URL moves to another
 * resource's index (a menu link, the command palette, which opens over any
 * drawer or modal). The page reset its list state for the new resource but
 * kept its overlays open: the create drawer came back as the new resource's
 * create drawer, a drawer opened from a row kept editing the previous
 * resource's record over the new list, and the delete confirmation of a row
 * of the previous resource asked to delete the record with the same id in
 * the new one. The overlays belong to the resource they were opened for, so
 * the page closes them when its resource changes.
 */

const apiGetMock = vi.fn()
const apiDeleteMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGetMock(...args),
      post: vi.fn(() => new Promise(() => {})),
      put: vi.fn(() => new Promise(() => {})),
      delete: (...args: unknown[]) => apiDeleteMock(...args),
    },
  }
})

// DrawerShell renders children + footer into the DOM so assertions work.
vi.mock('@/components/overrides/DrawerShell', () => ({
  DrawerShell: ({ children, footer }: { children: ReactNode; footer?: ReactNode }) => (
    <div data-testid="drawer">
      <div>{children}</div>
      <div>{footer}</div>
    </div>
  ),
}))

import { ResourceIndexPage } from '@/pages/ResourceIndex'
import { DrawerCreate } from '@/components/overrides/DrawerCreate'
import { DrawerUpdate } from '@/components/overrides/DrawerUpdate'
import { componentRegistry } from '@/lib/componentRegistry'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()
componentRegistry.register('martis:drawer-create', DrawerCreate as never)
componentRegistry.register('martis:drawer-update', DrawerUpdate as never)
allowDataRouterNavigation()

const titleField = {
  attribute: 'title', label: 'Title', type: 'text',
  nullable: false, readonly: false, required: false, sortable: false, searchable: false,
  showOnIndex: true, showOnDetail: true, showOnForms: true, rules: [], reserved: [],
} as unknown as FieldDefinition

function schema(uriKey: string, singularLabel: string): ResourceSchema {
  return {
    uriKey,
    label: `${singularLabel}s`,
    singularLabel,
    softDeletes: false,
    stickyView: false,
    group: null,
    fields: [],
    fieldsForIndex: [titleField],
    fieldsForCreate: [titleField],
    fieldsForUpdate: [titleField],
    errorDisplay: 'inline',
    confirmUnsavedChanges: false,
    defaultRowActions: { enabled: true },
    overrides: {
      create: { component: 'martis:drawer-create', params: { backdrop: false } },
      update: { component: 'martis:drawer-update', params: { backdrop: false } },
    },
    messages: {},
  } as unknown as ResourceSchema
}

const SCHEMAS: Record<string, ResourceSchema> = { posts: schema('posts', 'Post'), pages: schema('pages', 'Page') }
const ROWS: Record<string, Array<Record<string, unknown>>> = {
  posts: [{ id: 5, title: 'Hello world' }],
  pages: [{ id: 5, title: 'About us' }],
}
const PAGE_META = { total: 1, current_page: 1, last_page: 1, per_page: 25, from: 1, to: 1 }

beforeEach(() => {
  apiGetMock.mockReset()
  apiDeleteMock.mockReset()
  apiDeleteMock.mockReturnValue(new Promise(() => {}))
  apiGetMock.mockImplementation((path: string) => {
    const schemaPath = /^\/api\/resources\/([^/]+)\/schema$/.exec(path)
    if (schemaPath) return Promise.resolve({ data: SCHEMAS[schemaPath[1]] })
    const indexPath = /^\/api\/resources\/([^/?]+)\?/.exec(path)
    if (indexPath) return Promise.resolve({ data: ROWS[indexPath[1]], meta: PAGE_META })
    const recordPath = /^\/api\/resources\/([^/]+)\/([^/?]+)\?context=update$/.exec(path)
    if (recordPath) return Promise.resolve({ data: ROWS[recordPath[1]][0] })
    return Promise.resolve({ data: [] })
  })
})

function renderIndex() {
  const router = createMemoryRouter(
    // Mirrors router.tsx: the same element for every resource's index.
    [{ path: '/resources/:resource', element: <ResourceIndexPage /> }],
    { initialEntries: ['/resources/posts'] },
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

/** Moves the route to the pages index and waits until its rows render. */
async function moveToPages(router: ReturnType<typeof renderIndex>) {
  await act(() => router.navigate('/resources/pages'))
  await waitFor(() => expect(router.state.location.pathname).toBe('/resources/pages'))
  await screen.findByText('About us')
}

const drawer = () => screen.queryByTestId('drawer')

describe('ResourceIndexPage — the route moves to another resource', () => {
  it('closes the create drawer', async () => {
    const router = renderIndex()
    fireEvent.click(await screen.findByRole('button', { name: '+ Create Post' }))
    fireEvent.change(document.getElementById('title') as HTMLInputElement, { target: { value: 'Draft post' } })

    await moveToPages(router)

    expect(drawer()).toBeNull()
  })

  it('closes the update drawer opened from a row', async () => {
    const router = renderIndex()
    await screen.findByText('Hello world')
    fireEvent.click(screen.getByRole('button', { name: 'Edit' }))
    await waitFor(() => expect((document.getElementById('title') as HTMLInputElement | null)?.value).toBe('Hello world'))

    await moveToPages(router)

    expect(drawer()).toBeNull()
  })

  it("closes the delete confirmation of the previous resource's row", async () => {
    const router = renderIndex()
    await screen.findByText('Hello world')
    fireEvent.click(screen.getByRole('button', { name: 'Delete' }))
    expect(await screen.findByRole('dialog')).toBeTruthy()

    await moveToPages(router)

    expect(screen.queryByRole('dialog')).toBeNull()
    expect(apiDeleteMock).not.toHaveBeenCalled()
  })
})
