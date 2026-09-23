import type { CSSProperties } from 'react'
import type { FilterDefinition } from '@/types'

/**
 * Resolves the span a filter occupies on the 12-column filter panel grid:
 * `Filter::span()` when declared, otherwise a quarter row, or half a row for
 * a date range (two calendars side by side).
 *
 * The span applies from `md` upward. The filter carries it as a custom
 * property (`filterGridSpanStyle`) and `.martis-filter-grid` in martis.css
 * owns `grid-column` per media query, full row below `md`, so the SPA never
 * writes an inline `grid-column` that a stylesheet could not override.
 */
export type FilterGridSpanSource = Partial<Pick<FilterDefinition, 'filterType' | 'span'>>

const DEFAULT_SPAN = 3
const DATE_RANGE_SPAN = 6

export function resolveFilterGridSpan(filter: FilterGridSpanSource): number {
  const span = filter.span ?? (filter.filterType === 'date-range' ? DATE_RANGE_SPAN : DEFAULT_SPAN)

  return Math.max(1, Math.min(12, Math.round(span)))
}

/**
 * Inline style for a filter: its span as the custom property
 * `.martis-filter-grid > *` reads from `md` upward.
 */
export function filterGridSpanStyle(filter: FilterGridSpanSource): CSSProperties {
  return { '--martis-filter-span': String(resolveFilterGridSpan(filter)) } as CSSProperties
}
