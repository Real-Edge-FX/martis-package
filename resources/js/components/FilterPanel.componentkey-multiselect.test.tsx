import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import type { FilterDefinition } from '@/types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string, fallback?: string) => fallback ?? key }),
}))

// A filter with componentKey('my-custom-filter') resolves to a custom control.
vi.mock('@/lib/componentRegistry', () => ({
  componentRegistry: {
    resolve: (key: string) =>
      key === 'my-custom-filter'
        ? ({ filter }: { filter: { uriKey: string } }) => (
            <div data-testid="custom-filter">{`custom:${filter.uriKey}`}</div>
          )
        : null,
  },
}))

import { FilterPanel } from './FilterPanel'

function makeFilter(extra: Partial<FilterDefinition> = {}): FilterDefinition {
  return {
    type: 'filter',
    filterType: 'select',
    name: 'Tags',
    uriKey: 'tags',
    component: null,
    options: [
      { label: 'PHP', value: 'php' },
      { label: 'Vue', value: 'vue' },
    ],
    default: null,
    meta: {},
    ...extra,
  }
}

function renderPanel(filter: FilterDefinition) {
  return render(
    <FilterPanel filters={[filter]} value={{}} onChange={() => {}} open onOpenChange={() => {}} />,
  )
}

describe('FilterPanel — filter componentKey resolution (bug: was ignored by the SPA)', () => {
  it('renders a filter\'s custom component when componentKey is set', () => {
    renderPanel(makeFilter({ component: 'my-custom-filter' }))
    expect(screen.getByTestId('custom-filter').textContent).toBe('custom:tags')
  })

  it('falls back to the native control when the component key is unresolved', () => {
    const { container } = renderPanel(makeFilter({ component: 'nonexistent' }))
    expect(container.querySelector('.p-dropdown')).not.toBeNull()
    expect(screen.queryByTestId('custom-filter')).toBeNull()
  })
})

describe('FilterPanel — multi-select filter type', () => {
  it('renders a PrimeReact MultiSelect for a multi-select filter', () => {
    const { container } = renderPanel(makeFilter({ filterType: 'multi-select', meta: { searchable: true } }))
    expect(container.querySelector('.p-multiselect')).not.toBeNull()
    // Not the single-select Dropdown.
    expect(container.querySelector('.p-dropdown')).toBeNull()
  })
})

describe('FilterPanel — empty multi-select value is not an active filter', () => {
  function renderWithValue(value: Record<string, unknown>) {
    return render(
      <FilterPanel
        filters={[makeFilter({ filterType: 'multi-select' })]}
        value={value}
        onChange={() => {}}
        open
        onOpenChange={() => {}}
      />,
    )
  }

  it('treats an empty array as cleared: no badge, no chip', () => {
    // PrimeReact MultiSelect emits [] on clear / deselect-all. That must not
    // register as an active filter (stale chip, inflated badge, wasted query).
    const { container } = renderWithValue({ tags: [] })
    expect(container.querySelector('.martis-filter-chip')).toBeNull()
    // The active-count badge only renders when activeCount > 0.
    expect(container.querySelector('.rounded-full.text-xs.font-bold')).toBeNull()
  })

  it('counts a non-empty array as active and shows a chip with option labels', () => {
    const { container } = renderWithValue({ tags: ['php', 'vue'] })
    const chip = container.querySelector('.martis-filter-chip')
    expect(chip).not.toBeNull()
    // Chip value maps slugs → labels, not raw 'php,vue'.
    expect(container.querySelector('.martis-filter-chip-value')?.textContent).toBe('PHP, Vue')
  })
})
