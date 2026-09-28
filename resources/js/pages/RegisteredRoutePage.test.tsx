import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useEffect } from 'react'
import { act, render, screen } from '@testing-library/react'
import { createMemoryRouter, MemoryRouter, Route, RouterProvider, Routes, useNavigate, useParams } from 'react-router'
import { ApiError } from '@/lib/api'

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args) } }
})

const gateOpen = vi.fn()
// One object for every render, as the provider's value is: a new object per
// render would re-run the fetch effect, which depends on the gate.
const gate = { isOpen: false, lock: null, open: gateOpen, close: vi.fn() }

vi.mock('@/contexts/GateContext', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/contexts/GateContext')>()),
  useGateOptional: () => gate,
}))

import { componentRegistry } from '@/lib/componentRegistry'
import { ToastProvider } from '@/contexts/ToastContext'
import type { RegisteredRoute, RegisteredRoutePageProps } from '@/lib/routeRegistry'
import { RegisteredRoutePage } from './RegisteredRoutePage'

function registered(overrides: Partial<RegisteredRoute>): RegisteredRoute {
  return { path: 'findings/:findingId', component: () => null, crumb: null, tool: null, ...overrides }
}

function renderPage(route: RegisteredRoute, path = '/findings/0192f7c1') {
  const router = createMemoryRouter(
    [{ path: '/findings/:findingId', element: <RegisteredRoutePage route={route} /> }],
    { initialEntries: [path] },
  )
  render(
    <ToastProvider>
      <RouterProvider router={router} />
    </ToastProvider>,
  )
  return router
}

function FindingPage() {
  const { findingId } = useParams<{ findingId: string }>()
  return <p>Finding {findingId}</p>
}

const descriptor = {
  type: 'tool' as const,
  name: 'Findings',
  breadcrumb: null,
  uriKey: 'findings',
  icon: null,
  component: null,
  menuSection: null,
  meta: {},
}

describe('RegisteredRoutePage', () => {
  beforeEach(() => {
    apiGetMock.mockReset()
    gateOpen.mockReset()
  })

  afterEach(() => {
    componentRegistry.unregister('page:finding-detail')
  })

  it('renders the component it was given, with the route parameters', async () => {
    renderPage(registered({ component: FindingPage }))

    expect(await screen.findByText('Finding 0192f7c1')).toBeTruthy()
    expect(apiGetMock).not.toHaveBeenCalled()
  })

  it('resolves a componentRegistry key when the page renders', async () => {
    const route = registered({ component: 'page:finding-detail' })
    componentRegistry.register('page:finding-detail', FindingPage as never)

    renderPage(route)

    expect(await screen.findByText('Finding 0192f7c1')).toBeTruthy()
  })

  it('shows a developer notice for a key nothing registered', async () => {
    renderPage(registered({ component: 'page:missing' }))

    const alert = await screen.findByRole('alert')
    expect(alert.textContent).toContain('No React component is registered for the key "page:missing"')
  })

  it('remounts the page when the path changes, not when only the query string does', async () => {
    let mounts = 0
    function Counting() {
      useEffect(() => {
        mounts++
      }, [])
      return <FindingPage />
    }

    // A data router's navigate() trips jsdom's AbortSignal check, so this
    // test drives a MemoryRouter through a captured useNavigate, as
    // ToolPage.test.tsx does.
    let navigate: ((to: string) => void) | null = null
    function Navigator() {
      navigate = useNavigate()
      return null
    }
    const route = registered({ component: Counting })

    render(
      <ToastProvider>
        <MemoryRouter initialEntries={['/findings/1']}>
          <Navigator />
          <Routes>
            <Route path="/findings/:findingId" element={<RegisteredRoutePage route={route} />} />
          </Routes>
        </MemoryRouter>
      </ToastProvider>,
    )
    await screen.findByText('Finding 1')

    act(() => navigate!('/findings/2'))
    await screen.findByText('Finding 2')
    act(() => navigate!('/findings/2?tab=history'))

    expect(mounts).toBe(2)
  })

  it("shows the Tool's not-found state when the Tool it is bound to is hidden", async () => {
    apiGetMock.mockRejectedValue(new ApiError(404, 'Tool not found.'))

    renderPage(registered({ component: () => <p>Guarded page</p>, tool: 'findings' }))

    expect(await screen.findByText('Tool not found')).toBeTruthy()
    expect(screen.queryByText('Guarded page')).toBeNull()
    expect(apiGetMock.mock.calls[0]![0]).toBe('/api/tools/findings')
  })

  it('shows the lock page and opens the gate when the Tool is locked', async () => {
    const lock = { reason: 'gated', modal: { title: 'Upgrade to Pro', message: 'Findings need the Pro plan.' } }
    apiGetMock.mockResolvedValue({ locked: true, lock, tool: descriptor })

    renderPage(registered({ component: () => <p>Guarded page</p>, tool: 'findings' }))

    expect(await screen.findByRole('heading', { name: 'Upgrade to Pro' })).toBeTruthy()
    expect(screen.queryByText('Guarded page')).toBeNull()
    expect(gateOpen).toHaveBeenCalledWith(lock)
  })

  it('passes the resolved Tool to the page when the Tool is visible', async () => {
    apiGetMock.mockResolvedValue(descriptor)
    function ToolAware({ tool }: RegisteredRoutePageProps) {
      return <p>Page of {tool?.name}</p>
    }

    renderPage(registered({ component: ToolAware, tool: 'findings' }))

    expect(await screen.findByText('Page of Findings')).toBeTruthy()
  })
})
