import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, Outlet, RouterProvider } from 'react-router'
import type { DashboardDefinition } from '@/types'

/*
 * A dashboard opened by a full page load (a bookmark, a reload) must never
 * name itself "Dashboard" while the list of dashboards loads (v2.19.0).
 *
 * The shell embeds the list in its boot payload, so the first render knows
 * the current dashboard: the crumb, the tab title and the sidebar are right
 * from the first paint. Without that seed the three show placeholders until
 * the list answers, and the "Dashboard" fallbacks only apply once the list
 * really is empty.
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

import { config } from '@/lib/config'
import { rememberDashboardLayout, resetDashboardsSeedForTests, DASHBOARD_LAYOUTS_KEY } from '@/lib/dashboards'
import { DynamicCrumbProvider } from '@/contexts/DynamicCrumbContext'
import { Breadcrumbs } from '@/components/Breadcrumbs'
import { Sidebar } from '@/components/Sidebar'
import { DashboardPage } from '@/pages/Dashboard'

function dashboard(uriKey: string, name: string, parent: string | null = null): DashboardDefinition {
  return {
    type: 'dashboard',
    uriKey,
    name,
    parent,
    layout: 'cards',
    breadcrumb: null,
    icon: null,
    component: null,
    badge: null,
    lock: null,
    meta: {},
    showRefreshButton: false,
  } as unknown as DashboardDefinition
}

const DASHBOARDS = [
  dashboard('support', 'Support'),
  dashboard('support-now', 'Now', 'support'),
  dashboard('support-team', 'Team', 'support'),
]

/** A list request that never answers, like a slow network. */
function listNeverAnswers(): void {
  apiGetMock.mockImplementation((path: string) => {
    if (path === '/api/dashboards') return new Promise(() => {})
    if (path === '/api/navigation') return Promise.resolve([])
    return new Promise(() => {})
  })
}

function listAnswers(dashboards: DashboardDefinition[]): void {
  apiGetMock.mockImplementation((path: string) => {
    if (path === '/api/dashboards') return Promise.resolve({ data: { dashboards } })
    if (path === '/api/navigation') return Promise.resolve([])
    if (path === '/api/navigation/badges') return Promise.resolve({})
    // The cards of a dashboard: never answer, the tests look at the shell.
    return new Promise(() => {})
  })
}

function renderPanel(path: string): ReturnType<typeof createMemoryRouter> {
  const routes = [
    {
      path: '/',
      element: (
        <>
          <Breadcrumbs />
          <Sidebar />
          <main>
            <Outlet />
          </main>
        </>
      ),
      children: [
        { index: true, element: <DashboardPage />, handle: { crumb: 'dashboard', crumbPending: true } },
        { path: 'dashboards/:uriKey', element: <DashboardPage />, handle: { crumb: 'dashboard', crumbPending: true } },
      ],
    },
  ]
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const router = createMemoryRouter(routes, { initialEntries: [path] })
  render(
    <QueryClientProvider client={qc}>
      <DynamicCrumbProvider>
        <RouterProvider router={router} />
      </DynamicCrumbProvider>
    </QueryClientProvider>,
  )
  return router
}

function trail(): HTMLElement {
  return screen.getByRole('navigation', { name: 'Breadcrumbs' })
}

function sidebar(): HTMLElement {
  return screen.getByRole('navigation', { name: 'Main navigation' })
}

beforeEach(() => {
  // The welcome view of an install without dashboards reads it.
  window.matchMedia ??= ((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addEventListener: () => {},
    removeEventListener: () => {},
    addListener: () => {},
    removeListener: () => {},
    dispatchEvent: () => false,
  })) as unknown as typeof window.matchMedia
  apiGetMock.mockReset()
  document.title = 'Team · Acme'
  delete (config as { dashboards?: unknown }).dashboards
  resetDashboardsSeedForTests()
  window.localStorage.removeItem(DASHBOARD_LAYOUTS_KEY)
})

afterEach(() => {
  delete (config as { dashboards?: unknown }).dashboards
})

describe('a dashboard opened while the list of dashboards loads', () => {
  it('names nothing: blank crumb, unchanged title, a placeholder sidebar row', async () => {
    listNeverAnswers()
    renderPanel('/dashboards/support-team')

    await screen.findByRole('navigation', { name: 'Breadcrumbs' })
    expect(trail().querySelector('.martis-breadcrumb-pending')).not.toBeNull()
    expect(trail().textContent).not.toContain('Dashboard')
    expect(document.title).toBe('Team · Acme')
    expect(screen.getByTestId('sidebar-dashboards-pending')).toBeTruthy()
    expect(sidebar().textContent).not.toMatch(/\bDashboard\b/)
    expect(screen.getByTestId('dashboard-skeleton').getAttribute('data-layout')).toBe('generic')
  })

  it('draws the skeleton in the shape the dashboard had on its last load', async () => {
    listNeverAnswers()
    rememberDashboardLayout('support-team', [{ width: 12 }, { width: 3 }, { width: 3 }, { width: 3 }, { width: 3 }])
    renderPanel('/dashboards/support-team')

    const skeleton = await screen.findByTestId('dashboard-skeleton')
    expect(skeleton.getAttribute('data-layout')).toBe('remembered')
    expect(skeleton.children).toHaveLength(5)
    expect((skeleton.children[0] as HTMLElement).style.getPropertyValue('--martis-card-span')).toBe('12')
    expect((skeleton.children[1] as HTMLElement).style.getPropertyValue('--martis-card-span')).toBe('3')
  })

  it('fills in the real names once the list answers', async () => {
    listAnswers(DASHBOARDS)
    renderPanel('/dashboards/support-team')

    await waitFor(() => expect(trail().textContent).toContain('Team'))
    expect(trail().textContent).not.toContain('Dashboard')
    expect(document.title).toBe('Team · Martis')
    expect(screen.queryByTestId('sidebar-dashboards-pending')).toBeNull()
    expect(sidebar().textContent).toContain('Support')
  })
})

describe('a dashboard opened with the list in the boot payload', () => {
  it('names the dashboard on the first render', () => {
    ;(config as { dashboards?: DashboardDefinition[] }).dashboards = DASHBOARDS
    listNeverAnswers()
    renderPanel('/dashboards/support-team')

    // No await: the seed makes the list known synchronously.
    expect(trail().textContent).toContain('Team')
    expect(trail().querySelector('.martis-breadcrumb-pending')).toBeNull()
    expect(document.title).toBe('Team · Martis')
    expect(screen.queryByTestId('sidebar-dashboards-pending')).toBeNull()
    expect(sidebar().textContent).toContain('Support')
    expect(sidebar().textContent).not.toMatch(/\bDashboard\b/)
    // The tab strip of the family is drawn too.
    expect(Array.from(document.querySelectorAll('a.martis-dashboard-tab')).map((a) => a.textContent)).toEqual([
      'Support',
      'Now',
      'Team',
    ])
  })

  it('swaps the crumb and the title when moving to another dashboard, with no stale label', async () => {
    ;(config as { dashboards?: DashboardDefinition[] }).dashboards = DASHBOARDS
    listNeverAnswers()
    const router = renderPanel('/dashboards/support-team')
    expect(trail().textContent).toContain('Team')

    await act(async () => {
      await router.navigate('/dashboards/support-now')
    })

    expect(trail().textContent).toContain('Now')
    expect(trail().textContent).not.toContain('Team')
    expect(trail().querySelector('.martis-breadcrumb-pending')).toBeNull()
    expect(document.title).toBe('Now · Martis')
  })

  it('hands the seed out once, so a query built later asks the server', async () => {
    ;(config as { dashboards?: DashboardDefinition[] }).dashboards = DASHBOARDS
    listNeverAnswers()
    renderPanel('/dashboards/support-team')
    expect(trail().textContent).toContain('Team')

    document.body.innerHTML = ''
    renderPanel('/dashboards/support-team')
    await screen.findByRole('navigation', { name: 'Breadcrumbs' })
    expect(trail().querySelector('.martis-breadcrumb-pending')).not.toBeNull()
  })
})

describe('an install with no dashboards', () => {
  it('shows the "Dashboard" fallbacks only once the list answered empty', async () => {
    listAnswers([])
    renderPanel('/')

    await waitFor(() => expect(screen.queryByTestId('sidebar-dashboards-pending')).toBeNull())
    expect(trail().textContent).toContain('Dashboard')
    expect(sidebar().textContent).toContain('Dashboard')
  })

  it('is drawn at once from an empty boot payload', () => {
    ;(config as { dashboards?: DashboardDefinition[] }).dashboards = []
    listNeverAnswers()
    renderPanel('/')

    expect(trail().textContent).toContain('Dashboard')
    expect(sidebar().textContent).toContain('Dashboard')
  })
})
