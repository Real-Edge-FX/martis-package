import { describe, it, expect, vi, beforeEach } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import type { DashboardDefinition } from '@/types'

/*
 * The nested-dashboard tab strip must navigate.
 *
 * The page derives the current dashboard from the `/dashboards/:uriKey`
 * route parameter. The tabs used to write a local selection that this
 * derivation shadowed whenever the page was opened at a deep link (the
 * only way to reach a non-root family), so clicking a child changed
 * nothing: no URL, no request, no active state. Each tab is now a link to
 * the dashboard's own URL; the view is keyed on the dashboard so the
 * filters start empty after a switch.
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args) },
  }
})

vi.mock('@/contexts/AuthContext', () => ({
  useAuth: () => ({ user: { name: 'Ada', email: 'ada@example.com' } }),
}))

import { DashboardPage, dashboardPath } from '@/pages/Dashboard'

function dashboard(uriKey: string, name: string, parent: string | null = null): DashboardDefinition {
  return {
    uriKey,
    name,
    parent,
    layout: 'grid',
    breadcrumb: null,
    showRefreshButton: false,
  } as unknown as DashboardDefinition
}

const DASHBOARDS = [
  dashboard('default', 'Home'),
  dashboard('system', 'System'),
  dashboard('system-models', 'Providers & Models', 'system'),
  dashboard('system-pipeline', 'Pipeline', 'system'),
]

function mockApi() {
  apiGetMock.mockImplementation((path: string) => {
    if (path === '/api/navigation') return Promise.resolve([])
    if (path === '/api/dashboards') return Promise.resolve({ data: { dashboards: DASHBOARDS } })
    const match = /^\/api\/dashboards\/([^/]+)$/.exec(path)
    if (match) {
      return Promise.resolve({
        data: {
          dashboard: DASHBOARDS.find((d) => d.uriKey === match[1]),
          cards: [],
          filters: [],
        },
      })
    }
    return Promise.resolve({ data: [] })
  })
}

// Mirrors the two routes that render the page in router.tsx. A plain
// MemoryRouter (not a data router) so a navigation does not build a
// `Request` from jsdom's AbortSignal.
function LocationProbe() {
  const { pathname } = useLocation()
  return <div data-testid="location">{pathname}</div>
}

function renderAt(initialPath: string) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[initialPath]}>
        <LocationProbe />
        <Routes>
          <Route path="/" element={<DashboardPage />} />
          <Route path="/dashboards/:uriKey" element={<DashboardPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

function pathname(): string {
  return screen.getByTestId('location').textContent ?? ''
}

function tabs(): HTMLAnchorElement[] {
  return Array.from(document.querySelectorAll<HTMLAnchorElement>('a.martis-dashboard-tab'))
}

function dashboardRequests(): string[] {
  return apiGetMock.mock.calls
    .map(([p]) => p as string)
    .filter((p) => /^\/api\/dashboards\/[^/]+$/.test(p))
}

beforeEach(() => {
  apiGetMock.mockReset()
  document.body.innerHTML = ''
  mockApi()
})

describe('dashboardPath', () => {
  it('addresses the first registered dashboard at "/" and every other one at its deep link', () => {
    expect(dashboardPath(DASHBOARDS[0], DASHBOARDS)).toBe('/')
    expect(dashboardPath(DASHBOARDS[1], DASHBOARDS)).toBe('/dashboards/system')
    expect(dashboardPath(DASHBOARDS[2], DASHBOARDS)).toBe('/dashboards/system-models')
  })
})

describe('DashboardPage — nested-dashboard tab strip', () => {
  it('renders the family as links to each dashboard URL with the current one marked active', async () => {
    renderAt('/dashboards/system')

    await waitFor(() => expect(tabs()).toHaveLength(3))

    expect(tabs().map((a) => a.getAttribute('href'))).toEqual([
      '/dashboards/system',
      '/dashboards/system-models',
      '/dashboards/system-pipeline',
    ])
    expect(tabs().map((a) => a.dataset.active)).toEqual(['true', 'false', 'false'])
    expect(tabs()[0].getAttribute('aria-current')).toBe('page')
  })

  it('navigates to the child dashboard on click, requests its data and marks it active', async () => {
    renderAt('/dashboards/system')
    await waitFor(() => expect(tabs()).toHaveLength(3))

    fireEvent.click(screen.getByRole('tab', { name: 'Providers & Models' }))

    await waitFor(() => expect(pathname()).toBe('/dashboards/system-models'))
    await waitFor(() => expect(dashboardRequests()).toContain('/api/dashboards/system-models'))
    expect(tabs().map((a) => a.dataset.active)).toEqual(['false', 'true', 'false'])
  })

  it('goes from a child to a sibling and back to the root', async () => {
    renderAt('/dashboards/system-models')
    await waitFor(() => expect(tabs()).toHaveLength(3))
    expect(tabs().map((a) => a.dataset.active)).toEqual(['false', 'true', 'false'])

    fireEvent.click(screen.getByRole('tab', { name: 'Pipeline' }))
    await waitFor(() => expect(pathname()).toBe('/dashboards/system-pipeline'))

    fireEvent.click(screen.getByRole('tab', { name: 'System' }))
    await waitFor(() => expect(pathname()).toBe('/dashboards/system'))
    expect(tabs().map((a) => a.dataset.active)).toEqual(['true', 'false', 'false'])
  })

  it('renders no strip for a dashboard whose family has one member', async () => {
    renderAt('/')

    await waitFor(() => expect(dashboardRequests()).toContain('/api/dashboards/default'))
    expect(tabs()).toHaveLength(0)
  })
})
