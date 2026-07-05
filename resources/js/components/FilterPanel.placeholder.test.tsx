import { describe, it, expect, vi } from 'vitest'
import { render } from '@testing-library/react'
import { FilterPanel } from './FilterPanel'
import type { FilterDefinition } from '@/types'

/*
 * Filters can set a placeholder distinct from their display name.
 * `FilterPanel` must show `filter.placeholder` in the select control
 * when set, and fall back to `filter.name` when it is absent — fully
 * backward-compatible with filters serialized before this feature.
 */

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, fallback?: string) => fallback ?? key,
  }),
}))

function selectFilter(extra: Partial<FilterDefinition> = {}): FilterDefinition {
  return {
    type: 'filter',
    filterType: 'select',
    name: 'Project',
    uriKey: 'project',
    component: null,
    options: [
      { label: 'Alpha', value: 'alpha' },
      { label: 'Beta', value: 'beta' },
    ],
    default: null,
    meta: {},
    ...extra,
  }
}

describe('FilterPanel select placeholder', () => {
  it('uses filter.placeholder when set', () => {
    const filter = selectFilter({ placeholder: 'Select…' })
    const { container } = render(
      <FilterPanel filters={[filter]} value={{}} onChange={() => {}} open onOpenChange={() => {}} />,
    )

    const dropdownLabel = container.querySelector('.p-dropdown-label')
    expect(dropdownLabel?.textContent).toBe('Select…')
  })

  it('falls back to filter.name when placeholder is absent', () => {
    const filter = selectFilter()
    const { container } = render(
      <FilterPanel filters={[filter]} value={{}} onChange={() => {}} open onOpenChange={() => {}} />,
    )

    const dropdownLabel = container.querySelector('.p-dropdown-label')
    expect(dropdownLabel?.textContent).toBe('Project')
  })
})
