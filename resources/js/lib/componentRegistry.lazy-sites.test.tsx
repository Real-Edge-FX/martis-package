import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router'
import type { ComponentType } from 'react'

/*
 * Every place Martis renders a registry component must work with a lazy one
 * (v2.20.0): the loader fallback shows while the chunk loads, then the
 * component. The lazy wrapper carries its own Suspense boundary, so no call
 * site needs to know a component is lazy.
 */

const apiGetMock = vi.fn()
const apiPostMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGetMock(...args),
      post: (...args: unknown[]) => apiPostMock(...args),
    },
  }
})
vi.mock('@/contexts/AuthContext', () => ({
  useAuth: () => ({ user: { name: 'Ada', email: 'ada@example.com' } }),
}))
vi.mock('@/components/ResourceIcon', () => ({ ResourceIcon: () => null }))

import { componentRegistry } from '@/lib/componentRegistry'
import { ToastProvider } from '@/contexts/ToastContext'
import { ToolPage } from '@/pages/ToolPage'
import { DashboardPage } from '@/pages/Dashboard'
import { MetricCard } from '@/components/metrics/MetricCard'
import { FilterPanel } from '@/components/FilterPanel'
import { ActionModal, type ActionMeta } from '@/components/Actions/ActionModal'
import { FieldDisplay, FieldInput, registerDefaultFields } from '@/components/fields/FieldRenderer'
import type { FieldDefinition, FilterDefinition, MetricDefinition } from '@/types'

registerDefaultFields()

interface Gate {
  component: ComponentType<never>
  release: () => void
}

/** A lazy registration whose chunk stays pending until `release()`. */
function lazyOn(key: string, render: (props: Record<string, unknown>) => JSX.Element): Gate {
  let release!: () => void
  const gate = new Promise<void>((resolve) => {
    release = resolve
  })
  const Loaded = (props: Record<string, unknown>) => render(props)

  componentRegistry.registerLazy(key, () => gate.then(() => ({ default: Loaded as unknown as ComponentType<never> })), {
    fallback: <span data-testid="chunk-wait">loading chunk</span>,
  })

  return { component: Loaded as unknown as ComponentType<never>, release: () => release() }
}

async function releaseAndSettle(gate: Gate) {
  await act(async () => {
    gate.release()
  })
}

const keys: string[] = []

function lazy(key: string, render: (props: Record<string, unknown>) => JSX.Element): Gate {
  keys.push(key)
  return lazyOn(key, render)
}

beforeEach(() => {
  apiGetMock.mockReset()
  apiPostMock.mockReset()
})

afterEach(() => {
  while (keys.length > 0) componentRegistry.unregister(keys.pop() as string)
})

function client() {
  return new QueryClient({ defaultOptions: { queries: { retry: false } } })
}

describe('ToolPage', () => {
  it('renders a lazy tool component after its chunk loads', async () => {
    const gate = lazy('tool:lazy-site', ({ tool }) => <div data-testid="tool-body">{`tool:${(tool as { uriKey: string }).uriKey}`}</div>)
    apiGetMock.mockResolvedValue({
      type: 'tool', name: 'Lazy', breadcrumb: null, uriKey: 'lazy-site', icon: null, component: 'tool:lazy-site', menuSection: null, meta: {},
    })

    render(
      <ToastProvider>
        <MemoryRouter initialEntries={['/tools/lazy-site']}>
          <Routes>
            <Route path="/tools/:uriKey" element={<ToolPage />} />
          </Routes>
        </MemoryRouter>
      </ToastProvider>,
    )

    await waitFor(() => expect(screen.getByTestId('chunk-wait')).toBeTruthy())
    await releaseAndSettle(gate)

    expect(screen.getByTestId('tool-body').textContent).toBe('tool:lazy-site')
  })
})

describe('MetricCard', () => {
  it('renders a lazy component-keyed metric with its computed result', async () => {
    const gate = lazy('card:lazy-metric', ({ result }) => <div data-testid="metric-body">{`value:${String((result as { value: number }).value)}`}</div>)
    apiGetMock.mockResolvedValue({ data: { result: { value: 7 } } })
    const metric = { type: 'metric', metricType: 'value', name: 'M', uriKey: 'm', component: 'card:lazy-metric', width: 4, meta: {} } as unknown as MetricDefinition

    render(
      <QueryClientProvider client={client()}>
        <MetricCard metric={metric} endpoint="/api/metrics/m" />
      </QueryClientProvider>,
    )

    await waitFor(() => expect(screen.getByTestId('chunk-wait')).toBeTruthy())
    await releaseAndSettle(gate)

    expect(screen.getByTestId('metric-body').textContent).toBe('value:7')
  })
})

describe('Dashboard card', () => {
  it('renders a lazy custom card on the dashboard grid', async () => {
    const gate = lazy('card:lazy-dash', ({ card }) => <div data-testid="dash-card">{`card:${(card as { uriKey: string }).uriKey}`}</div>)
    const dashboard = { uriKey: 'sales', name: 'Sales', parent: null, layout: 'grid', breadcrumb: null, showRefreshButton: false }
    apiGetMock.mockImplementation((path: string) => {
      if (path === '/api/navigation') return Promise.resolve([])
      if (path === '/api/dashboards') return Promise.resolve({ data: { dashboards: [dashboard] } })
      if (path === '/api/dashboards/sales') {
        return Promise.resolve({
          data: {
            dashboard,
            cards: [{ type: 'card', name: 'Projects', uriKey: 'projects', component: 'card:lazy-dash', width: 8, meta: {} }],
            filters: [],
          },
        })
      }
      return Promise.resolve({ data: { result: { value: 1 } } })
    })

    render(
      <QueryClientProvider client={client()}>
        <MemoryRouter initialEntries={['/dashboards/sales']}>
          <Routes>
            <Route path="/dashboards/:uriKey" element={<DashboardPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    )

    await waitFor(() => expect(screen.getByTestId('chunk-wait')).toBeTruthy())
    await releaseAndSettle(gate)

    expect(screen.getByTestId('dash-card').textContent).toBe('card:projects')
  })
})

describe('FilterPanel', () => {
  it('renders a lazy custom filter control', async () => {
    const gate = lazy('filter:lazy', ({ filter }) => <div data-testid="filter-body">{`filter:${(filter as { uriKey: string }).uriKey}`}</div>)
    const filter = {
      type: 'filter', filterType: 'select', name: 'Tags', uriKey: 'tags', component: 'filter:lazy', options: [], default: null, meta: {},
    } as unknown as FilterDefinition

    render(<FilterPanel filters={[filter]} value={{}} onChange={() => {}} open onOpenChange={() => {}} />)

    expect(screen.getByTestId('chunk-wait')).toBeTruthy()
    await releaseAndSettle(gate)

    expect(screen.getByTestId('filter-body').textContent).toBe('filter:tags')
  })
})

describe('ActionModal', () => {
  it('renders a lazy custom action component', async () => {
    const gate = lazy('action:lazy', ({ action }) => <div data-testid="action-body">{`action:${(action as { uriKey: string }).uriKey}`}</div>)
    const action: ActionMeta = {
      uriKey: 'rename', name: 'Rename', icon: null, showIcon: true, iconColor: null,
      group: null, destructive: false, showOnIndex: true, showOnDetail: true, showInline: false,
      executionMode: 'bulk', standalone: false, sole: false, queued: false, withConfirmation: true,
      confirmText: null, confirmButtonText: null, cancelButtonText: null, modalSize: 'md',
      supportsDryRun: false, customComponent: 'action:lazy', customComponentProps: {}, logEvents: true,
      isPivotAction: false, pivotLabel: null,
    }

    render(
      <QueryClientProvider client={client()}>
        <ToastProvider>
          <MemoryRouter>
            <ActionModal resource="posts" action={action} selectedIds={[1]} visible onHide={() => {}} onSuccess={() => {}} />
          </MemoryRouter>
        </ToastProvider>
      </QueryClientProvider>,
    )

    await waitFor(() => expect(screen.getByTestId('chunk-wait')).toBeTruthy())
    await releaseAndSettle(gate)

    expect(screen.getByTestId('action-body').textContent).toBe('action:rename')
  })
})

describe('field display and input', () => {
  const field = { attribute: 'price', label: 'Price', type: 'lazy_money', nullable: true, readonly: false, required: false, rules: [] } as unknown as FieldDefinition

  it('renders a lazy display registered for a field type', async () => {
    keys.push('field:display:lazy_money')
    const gate = lazyOn('field:display:lazy_money', ({ value }) => <span data-testid="money-display">{`display:${String(value)}`}</span>)

    render(<FieldDisplay field={field} value="12.50" />)

    expect(screen.getByTestId('chunk-wait')).toBeTruthy()
    await releaseAndSettle(gate)

    expect(screen.getByTestId('money-display').textContent).toBe('display:12.50')
  })

  it('renders a lazy input registered for a field type', async () => {
    keys.push('field:input:lazy_money')
    const gate = lazyOn('field:input:lazy_money', ({ value }) => <input data-testid="money-input" defaultValue={String(value)} />)

    render(<FieldInput field={field} value="3" onChange={() => {}} />)

    expect(screen.getByTestId('chunk-wait')).toBeTruthy()
    await releaseAndSettle(gate)

    expect((screen.getByTestId('money-input') as HTMLInputElement).value).toBe('3')
  })

  it('renders a lazy display registered for one field of one resource', async () => {
    keys.push('field:display:posts:price')
    const gate = lazyOn('field:display:posts:price', () => <span data-testid="resource-display">resource</span>)

    render(<FieldDisplay field={{ ...field, type: 'text' }} value="x" resourceKey="posts" />)

    expect(screen.getByTestId('chunk-wait')).toBeTruthy()
    await releaseAndSettle(gate)

    expect(screen.getByTestId('resource-display').textContent).toBe('resource')
  })
})
