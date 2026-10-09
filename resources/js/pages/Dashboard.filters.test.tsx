import { describe, it, expect, vi, beforeEach, beforeAll } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes, useLocation, useNavigate } from 'react-router'
import type { ActiveFilters, DashboardDefinition, FilterDefinition, MetricDefinition } from '@/types'
import { componentRegistry } from '@/lib/componentRegistry'
import { useDashboardFilters, type SetDashboardFilters } from '@/lib/dashboardFilters'

/*
 * A dashboard's filters live in its URL (v2.9.0, consumer report
 * 2026-10-03): `?filters=` sets them on open, a panel edit or a card's
 * setFilters() writes them back, Back / Forward step through a card's
 * changes, and a tab switch starts from none. Before, they were component
 * state only: no deep link, no reload, and no way for a card to set one.
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

import { DashboardPage } from '@/pages/Dashboard'

function dashboard(uriKey: string, name: string, parent: string | null = null): DashboardDefinition {
  return { uriKey, name, parent, layout: 'grid', breadcrumb: null, showRefreshButton: false } as unknown as DashboardDefinition
}

const DASHBOARDS = [dashboard('sales', 'Sales'), dashboard('sales-detail', 'Detail', 'sales')]

function filterDef(uriKey: string, overrides: Partial<FilterDefinition> = {}): FilterDefinition {
  return {
    type: 'filter',
    filterType: 'select',
    name: uriKey === 'project' ? 'Project' : 'Stage',
    uriKey,
    component: null,
    options: [
      { label: 'Alpha', value: 'a' },
      { label: 'Beta', value: 'b' },
    ],
    default: null,
    meta: {},
    ...overrides,
  } as FilterDefinition
}

const CARDS = [
  { type: 'metric', metricType: 'value', name: 'Revenue', uriKey: 'revenue', component: null, width: 4, meta: {} },
  { type: 'card', name: 'Projects', uriKey: 'projects', component: 'card:projects', width: 8, meta: {} },
] as unknown as MetricDefinition[]

let stageDefault: unknown = null

function mockApi() {
  apiGetMock.mockImplementation((path: string) => {
    if (path === '/api/navigation') return Promise.resolve([])
    if (path === '/api/dashboards') return Promise.resolve({ data: { dashboards: DASHBOARDS } })
    const match = /^\/api\/dashboards\/([^/?]+)$/.exec(path)
    if (match) {
      return Promise.resolve({
        data: {
          dashboard: DASHBOARDS.find((d) => d.uriKey === match[1]),
          cards: CARDS,
          filters: [filterDef('project'), filterDef('stage', { component: 'filter:stage', default: stageDefault })],
        },
      })
    }
    return Promise.resolve({ data: { result: { value: 1 } } })
  })
}

// Every setFilters identity the card was rendered with.
const seenSetters = new Set<SetDashboardFilters>()

// A custom card as a consumer writes one: it reads `filters` and sets them
// with the `setFilters` prop; a component nested inside reads the hook.
function ProjectsCard({ filters, setFilters }: { card: MetricDefinition; filters: ActiveFilters; setFilters: SetDashboardFilters }) {
  seenSetters.add(setFilters)
  return (
    <div>
      <span data-testid="card-filters">{JSON.stringify(filters)}</span>
      <button type="button" onClick={() => setFilters({ project: 'b' })}>Pick Beta</button>
      <button type="button" onClick={() => { setFilters({ project: 'b' }); setFilters({ stage: 'won' }) }}>Pick both</button>
      <button type="button" onClick={() => setFilters({ project: 'a' }, { replace: true })}>Pick Alpha in place</button>
      <NestedReader />
    </div>
  )
}

function NestedReader() {
  const dashboardFilters = useDashboardFilters()
  return <span data-testid="hook-filters">{JSON.stringify(dashboardFilters?.filters ?? null)}</span>
}

// A filter with its own control (`componentKey()`), so a test can edit the
// panel without driving PrimeReact's dropdown.
function StageControl({ onChange }: { filter: FilterDefinition; value: unknown; onChange: (value: unknown) => void }) {
  return <button type="button" onClick={() => onChange('won')}>Stage won</button>
}

beforeAll(() => {
  componentRegistry.register('card:projects', ProjectsCard)
  componentRegistry.register('filter:stage', StageControl)
})

function LocationProbe() {
  const location = useLocation()
  const navigate = useNavigate()
  return (
    <>
      <div data-testid="location">{location.pathname + location.search}</div>
      <div data-testid="hash">{location.hash}</div>
      <button type="button" onClick={() => { void navigate(-1) }}>Go back</button>
      <button type="button" onClick={() => { void navigate(1) }}>Go forward</button>
    </>
  )
}

function renderAt(...entries: string[]) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={entries} initialIndex={entries.length - 1}>
        <LocationProbe />
        <Routes>
          <Route path="/" element={<DashboardPage />} />
          <Route path="/dashboards/:uriKey" element={<DashboardPage />} />
          <Route path="/elsewhere" element={<div>Elsewhere</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

function withFilters(path: string, filters: Record<string, unknown>): string {
  return `${path}?filters=${encodeURIComponent(JSON.stringify(filters))}`
}

function urlFilters(): Record<string, unknown> | null {
  const location = screen.getByTestId('location').textContent ?? ''
  const raw = new URLSearchParams(location.split('?')[1] ?? '').get('filters')
  return raw === null ? null : JSON.parse(raw) as Record<string, unknown>
}

/** The `filters` each revenue metric request carried, in order. */
function metricRequestFilters(dashboardKey = 'sales'): Array<Record<string, unknown> | null> {
  return apiGetMock.mock.calls
    .map(([p]) => p as string)
    .filter((p) => p.startsWith(`/api/dashboards/${dashboardKey}/cards/revenue`))
    .map((p) => {
      const raw = new URLSearchParams(p.split('?')[1] ?? '').get('filters')
      return raw === null ? null : JSON.parse(raw) as Record<string, unknown>
    })
}

function chips(): string[] {
  return Array.from(document.querySelectorAll('.martis-filter-chip')).map((el) => el.textContent ?? '')
}

beforeEach(() => {
  apiGetMock.mockReset()
  document.body.innerHTML = ''
  stageDefault = null
  seenSetters.clear()
  mockApi()
})

describe('DashboardPage: filters in the URL', () => {
  it('sets the filters a ?filters= deep link names: panel, cards and requests', async () => {
    renderAt(withFilters('/', { project: 'a' }))

    await waitFor(() => expect(chips()).toEqual(['Project:Alpha']))
    await waitFor(() => expect(metricRequestFilters()).toEqual([{ project: 'a' }]))
    expect(screen.getByTestId('card-filters').textContent).toBe('{"project":"a"}')
    expect(screen.getByTestId('hook-filters').textContent).toBe('{"project":"a"}')
  })

  it('lets a card set a filter: the panel, every card and the URL follow, with one request', async () => {
    renderAt(withFilters('/', { project: 'a' }))
    await waitFor(() => expect(metricRequestFilters()).toEqual([{ project: 'a' }]))

    fireEvent.click(screen.getByRole('button', { name: 'Pick Beta' }))

    await waitFor(() => expect(urlFilters()).toEqual({ project: 'b' }))
    await waitFor(() => expect(chips()).toEqual(['Project:Beta']))
    await waitFor(() => expect(metricRequestFilters()).toEqual([{ project: 'a' }, { project: 'b' }]))
    expect(screen.getByTestId('card-filters').textContent).toBe('{"project":"b"}')
    expect(screen.getByTestId('hook-filters').textContent).toBe('{"project":"b"}')
  })

  it('builds two updates in the same tick on each other', async () => {
    renderAt('/')
    await waitFor(() => expect(screen.getByRole('button', { name: 'Pick both' })).toBeTruthy())

    fireEvent.click(screen.getByRole('button', { name: 'Pick both' }))

    await waitFor(() => expect(urlFilters()).toEqual({ project: 'b', stage: 'won' }))
  })

  it('adds a history entry for a card change, so Back and Forward step through it', async () => {
    renderAt(withFilters('/', { project: 'a' }))
    await waitFor(() => expect(screen.getByRole('button', { name: 'Pick Beta' })).toBeTruthy())

    fireEvent.click(screen.getByRole('button', { name: 'Pick Beta' }))
    await waitFor(() => expect(urlFilters()).toEqual({ project: 'b' }))

    fireEvent.click(screen.getByRole('button', { name: 'Go back' }))
    await waitFor(() => expect(urlFilters()).toEqual({ project: 'a' }))
    await waitFor(() => expect(chips()).toEqual(['Project:Alpha']))

    fireEvent.click(screen.getByRole('button', { name: 'Go forward' }))
    await waitFor(() => expect(urlFilters()).toEqual({ project: 'b' }))
  })

  it('replaces the history entry when a card asks for it', async () => {
    renderAt('/elsewhere', withFilters('/', { project: 'b' }))
    await waitFor(() => expect(screen.getByRole('button', { name: 'Pick Alpha in place' })).toBeTruthy())

    fireEvent.click(screen.getByRole('button', { name: 'Pick Alpha in place' }))
    await waitFor(() => expect(urlFilters()).toEqual({ project: 'a' }))

    fireEvent.click(screen.getByRole('button', { name: 'Go back' }))
    await waitFor(() => expect(screen.getByTestId('location').textContent).toBe('/elsewhere'))
  })

  it('writes a panel edit to the URL in place of the current entry', async () => {
    renderAt('/elsewhere', withFilters('/', { project: 'a' }))
    await waitFor(() => expect(chips()).toEqual(['Project:Alpha']))

    fireEvent.click(screen.getByTestId('filter-toggle'))
    fireEvent.click(screen.getByRole('button', { name: 'Stage won' }))

    await waitFor(() => expect(urlFilters()).toEqual({ project: 'a', stage: 'won' }))
    await waitFor(() => expect(metricRequestFilters()).toContainEqual({ project: 'a', stage: 'won' }))

    fireEvent.click(screen.getByRole('button', { name: 'Go back' }))
    await waitFor(() => expect(screen.getByTestId('location').textContent).toBe('/elsewhere'))
  })

  it('removes the parameter when the last filter is cleared', async () => {
    renderAt(withFilters('/', { project: 'a' }))
    await waitFor(() => expect(chips()).toEqual(['Project:Alpha']))

    fireEvent.click(screen.getByRole('button', { name: /Project$/ }))

    await waitFor(() => expect(screen.getByTestId('location').textContent).toBe('/'))
    expect(chips()).toEqual([])
    await waitFor(() => expect(metricRequestFilters()).toEqual([{ project: 'a' }, null]))
  })

  it('adds no history entry when a card sets the filters the dashboard already has', async () => {
    renderAt('/elsewhere', withFilters('/', { project: 'b' }))
    await waitFor(() => expect(chips()).toEqual(['Project:Beta']))

    fireEvent.click(screen.getByRole('button', { name: 'Pick Beta' }))
    fireEvent.click(screen.getByRole('button', { name: 'Pick Beta' }))

    fireEvent.click(screen.getByRole('button', { name: 'Go back' }))
    await waitFor(() => expect(screen.getByTestId('location').textContent).toBe('/elsewhere'))
  })

  it('keeps the fragment and the other query parameters of the address', async () => {
    renderAt(`${withFilters('/', { project: 'a' })}&tab=2#notes`)
    await waitFor(() => expect(chips()).toEqual(['Project:Alpha']))

    fireEvent.click(screen.getByRole('button', { name: 'Pick Beta' }))

    await waitFor(() => expect(urlFilters()).toEqual({ project: 'b' }))
    expect(screen.getByTestId('location').textContent).toContain('tab=2')
    expect(screen.getByTestId('hash').textContent).toBe('#notes')
  })

  it('gives cards one setFilters for the dashboard lifetime', async () => {
    renderAt(withFilters('/', { project: 'a' }))
    await waitFor(() => expect(chips()).toEqual(['Project:Alpha']))

    fireEvent.click(screen.getByRole('button', { name: 'Pick Beta' }))
    await waitFor(() => expect(chips()).toEqual(['Project:Beta']))

    expect(seenSetters.size).toBe(1)
  })

  it('ignores a malformed payload and keys no filter of the dashboard names', async () => {
    renderAt('/?filters=%7Bnot-json')
    await waitFor(() => expect(metricRequestFilters()).toEqual([null]))
    expect(chips()).toEqual([])

    document.body.innerHTML = ''
    apiGetMock.mockClear()
    renderAt(withFilters('/', { unknown: 'x', project: { id: 1 }, stage: 'won' }))
    await waitFor(() => expect(metricRequestFilters()).toEqual([{ stage: 'won' }]))
    expect(screen.getByTestId('card-filters').textContent).toBe('{"stage":"won"}')
  })

  it('starts a dashboard reached through a tab with no filters', async () => {
    renderAt(withFilters('/', { project: 'a' }))
    await waitFor(() => expect(chips()).toEqual(['Project:Alpha']))

    fireEvent.click(screen.getByRole('tab', { name: 'Detail' }))

    await waitFor(() => expect(screen.getByTestId('location').textContent).toBe('/dashboards/sales-detail'))
    await waitFor(() => expect(metricRequestFilters('sales-detail')).toEqual([null]))
    expect(chips()).toEqual([])
  })

  it('writes a filter default to the URL in place, without a history entry', async () => {
    stageDefault = 'won'
    renderAt('/elsewhere', '/')

    await waitFor(() => expect(urlFilters()).toEqual({ stage: 'won' }))
    await waitFor(() => expect(metricRequestFilters()).toContainEqual({ stage: 'won' }))

    fireEvent.click(screen.getByRole('button', { name: 'Go back' }))
    await waitFor(() => expect(screen.getByTestId('location').textContent).toBe('/elsewhere'))
  })

  it('writes {} when a defaulted filter is cleared, so the default stays cleared after a reload', async () => {
    stageDefault = 'won'
    renderAt('/')
    await waitFor(() => expect(urlFilters()).toEqual({ stage: 'won' }))

    fireEvent.click(screen.getByRole('button', { name: /Stage$/ }))
    await waitFor(() => expect(urlFilters()).toEqual({}))
    expect(chips()).toEqual([])

    // Reload: the same address in a fresh page.
    const address = screen.getByTestId('location').textContent ?? ''
    document.body.innerHTML = ''
    apiGetMock.mockClear()
    renderAt(address)
    await waitFor(() => expect(screen.getByTestId('filter-toggle')).toBeTruthy())
    await waitFor(() => expect(metricRequestFilters()).toEqual([null]))
    expect(urlFilters()).toEqual({})
    expect(chips()).toEqual([])
  })

  it('keeps a filter set by the URL over the default', async () => {
    stageDefault = 'won'
    renderAt(withFilters('/', { project: 'b' }))

    await waitFor(() => expect(chips()).toEqual(['Project:Beta']))
    expect(urlFilters()).toEqual({ project: 'b' })
  })
})

describe('useDashboardFilters', () => {
  it('is null outside a dashboard', () => {
    render(<NestedReader />)
    expect(screen.getByTestId('hook-filters').textContent).toBe('null')
  })
})
