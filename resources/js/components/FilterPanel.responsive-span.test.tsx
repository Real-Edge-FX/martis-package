import { describe, it, expect, vi } from 'vitest'
import { render } from '@testing-library/react'
import { FilterPanel } from './FilterPanel'
import type { FilterDefinition } from '@/types'

/*
 * The filter panel fixed its grid at `repeat(12, …)` inline and wrote
 * `grid-column: span N` inline on every filter, so it never collapsed below
 * md: a `span(3)` filter was a quarter of a phone screen. The panel now
 * carries `.martis-filter-grid` (martis.css owns the tracks and places each
 * filter: full row below md, its span from md) and each filter only carries
 * its span as `--martis-filter-span`.
 */

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, fallback?: string) => fallback ?? key,
  }),
}))

function filter(uriKey: string, extra: Partial<FilterDefinition> = {}): FilterDefinition {
  return {
    type: 'filter',
    filterType: 'select',
    name: uriKey,
    uriKey,
    component: null,
    options: [{ label: 'Alpha', value: 'alpha' }],
    default: null,
    meta: {},
    ...extra,
  }
}

function renderOpen(filters: FilterDefinition[]) {
  return render(<FilterPanel filters={filters} value={{}} onChange={() => {}} open onOpenChange={() => {}} />)
}

describe('FilterPanel responsive grid (v1.38.0+)', () => {
  it('lays the filters out on the filter grid class, with no inline tracks', () => {
    const { container } = renderOpen([filter('status')])

    const grid = container.querySelector('.martis-filter-grid') as HTMLElement
    expect(grid).not.toBeNull()
    expect(grid.style.gridTemplateColumns).toBe('')
    expect(grid.getAttribute('style') ?? '').not.toMatch(/grid-template-columns/)
  })

  it('hands each filter its span as a custom property and never an inline grid-column', () => {
    const { container } = renderOpen([
      filter('status', { span: 4 }),
      filter('region'),
      filter('period', { filterType: 'date-range' }),
    ])

    const items = Array.from(container.querySelectorAll('.martis-filter-grid > *')) as HTMLElement[]
    expect(items.map((item) => item.style.getPropertyValue('--martis-filter-span'))).toEqual(['4', '3', '6'])
    for (const item of items) {
      expect(item.style.gridColumn).toBe('')
      expect(item.getAttribute('style') ?? '').not.toMatch(/grid-column/)
    }
  })
})
