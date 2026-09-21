import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (_key: string, fallback?: string) => fallback ?? _key,
  }),
}))

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(() => Promise.resolve({ data: { result: { value: 7.2 } } })) },
}))

vi.mock('@/components/ResourceIcon', () => ({
  ResourceIcon: () => null,
}))

// A component-keyed metric resolves 'card:custom' to a renderer fed { metric, result }.
vi.mock('@/lib/componentRegistry', () => ({
  componentRegistry: {
    resolve: (key: string) =>
      key === 'card:custom'
        ? ({ metric, result }: { metric: { uriKey: string }; result: Record<string, unknown> }) => (
            <div data-testid="custom-metric">{`custom:${metric.uriKey}:${String(result.value)}`}</div>
          )
        : null,
  },
}))

import { MetricCard } from './MetricCard'
import type { MetricDefinition } from '@/types'

function renderCard(metric: Partial<MetricDefinition>) {
  const def: MetricDefinition = {
    type: 'metric',
    metricType: 'value',
    name: 'NQ — Regime score',
    uriKey: 'regime-score-nq',
    component: null,
    width: 4,
    meta: {},
    ...metric,
  } as MetricDefinition

  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={client}>
      <MetricCard metric={def} endpoint="/martis/api/metrics/regime-score-nq" />
    </QueryClientProvider>,
  )
}

describe('MetricCard help tooltip (v1.8.18+)', () => {
  it('renders the FieldLabelTooltip "?" icon when metric.help is set', () => {
    renderCard({ help: 'A 0–10 reading of the current regime.' })
    // FieldLabelTooltip carries the text on data-pr-tooltip; assert by attribute.
    const tooltip = screen.getByLabelText('A 0–10 reading of the current regime.')
    expect(tooltip).toBeTruthy()
    expect(tooltip.getAttribute('data-pr-tooltip')).toBe('A 0–10 reading of the current regime.')
  })

  it('omits the tooltip element when metric.help is null / absent', () => {
    renderCard({ help: null })
    expect(screen.queryByLabelText(/regime/i)).toBeNull()
  })
})

describe('MetricCard component-keyed metric (custom result renderer)', () => {
  it('feeds the computed, filter-scoped result into the registry component', async () => {
    // A metric that declares component() renders its calculate() result through
    // a custom React component ({ metric, result }) instead of a native card —
    // the fix for "custom dashboard cards receive no computed result".
    renderCard({ component: 'card:custom', metricType: undefined, uriKey: 'top-queries' })

    const node = await screen.findByTestId('custom-metric')
    // result.value (7.2 from the api mock) reached the custom component.
    expect(node.textContent).toBe('custom:top-queries:7.2')
  })
})

describe('MetricCard responsive grid span (v1.37.2+)', () => {
  // The card never sets `grid-column` inline any more; it carries the
  // resolved `width` / `widthMd` / `widthLg` tiers as custom properties and
  // `.martis-dashboard-grid` (martis.css) places it per breakpoint, full row
  // below md. Before, `widthMd` / `widthLg` were serialised but never read.
  it('carries the width cascade as custom properties on the grid item', () => {
    const { container } = renderCard({ width: 12, widthMd: 12, widthLg: 8 })
    const card = container.querySelector('.martis-metric-card') as HTMLElement

    expect(card.style.getPropertyValue('--martis-card-span')).toBe('12')
    expect(card.style.getPropertyValue('--martis-card-span-md')).toBe('12')
    expect(card.style.getPropertyValue('--martis-card-span-lg')).toBe('8')
    expect(card.style.gridColumn).toBe('')
  })

  it('falls back to width for the md and lg tiers of a single-width card', () => {
    const { container } = renderCard({ width: 6 })
    const card = container.querySelector('.martis-metric-card') as HTMLElement

    expect(card.style.getPropertyValue('--martis-card-span-md')).toBe('6')
    expect(card.style.getPropertyValue('--martis-card-span-lg')).toBe('6')
  })
})
