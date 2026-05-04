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
