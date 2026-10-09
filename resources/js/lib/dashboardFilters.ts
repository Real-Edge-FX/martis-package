import { createContext, useContext } from 'react'
import { isBlankFilterValue } from '@/lib/filterValues'
import { hasOwnEntry } from '@/lib/ownEntry'
import type { ActiveFilters, FilterDefinition } from '@/types'

/**
 * A dashboard's active filters live in its URL (since v2.9.0), under
 * `?filters=`, as the JSON object `{ "<filter uriKey>": <value>, ... }`: the
 * same parameter and shape as a resource index deep link
 * (`MenuItem::filter()->applies()`) and the cards' compute endpoints. A
 * filtered dashboard can be reloaded, bookmarked and shared, and a link or a
 * card can open it with filters already set.
 */
export const DASHBOARD_FILTERS_PARAM = 'filters'

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/

function isPlainObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function isScalar(value: unknown): boolean {
  return typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean'
}

function isIsoDate(value: unknown): boolean {
  return typeof value === 'string' && ISO_DATE.test(value)
}

/**
 * Whether a value read from the URL has the shape the filter's control
 * writes, so the panel can render it: a select takes a scalar, a
 * multi-select a list of scalars, a boolean filter a map of options to
 * booleans, a date `YYYY-MM-DD`, a date range `{ from?, to? }` of dates. A
 * filter with its own control (`componentKey()`) owns its value shape. Which
 * values a filter accepts stays the server's call (the compute endpoint
 * resolves each one), as for any `?filters=` request.
 */
function hasFilterShape(filter: FilterDefinition, value: unknown): boolean {
  if (isBlankFilterValue(value)) return false
  if (filter.component) return true

  switch (filter.filterType) {
    case 'select':
      return isScalar(value)
    case 'multi-select':
      return Array.isArray(value) && value.every(isScalar)
    case 'boolean':
      return isPlainObject(value)
        && Object.values(value).every((v) => typeof v === 'boolean')
        && Object.values(value).some(Boolean)
    case 'date':
      return isIsoDate(value)
    case 'date-range': {
      if (!isPlainObject(value)) return false
      if (Object.keys(value).some((key) => key !== 'from' && key !== 'to')) return false
      const { from, to } = value
      return (from === undefined || isIsoDate(from))
        && (to === undefined || isIsoDate(to))
        && (from !== undefined || to !== undefined)
    }
    default:
      return true
  }
}

/**
 * The active filters a `?filters=` value sets on a dashboard, in the
 * dashboard's filter order. A malformed payload sets none; a key that names
 * no filter of this dashboard, and a value its control could not show, are
 * left out.
 */
export function parseDashboardFilters(raw: string | null, filters: readonly FilterDefinition[]): ActiveFilters {
  if (raw === null || raw === '') return {}

  let parsed: unknown
  try {
    parsed = JSON.parse(raw)
  } catch {
    return {}
  }
  if (!isPlainObject(parsed)) return {}

  const active: ActiveFilters = {}
  for (const filter of filters) {
    if (!hasOwnEntry(parsed, filter.uriKey)) continue
    const value = parsed[filter.uriKey]
    if (hasFilterShape(filter, value)) {
      active[filter.uriKey] = value
    }
  }

  return active
}

/**
 * The `?filters=` value for a set of active filters, or `null` when none is
 * set (the parameter is then removed, not left as `{}`).
 */
export function serializeDashboardFilters(filters: ActiveFilters): string | null {
  const entries = Object.entries(filters).filter(([, value]) => !isBlankFilterValue(value))

  return entries.length > 0 ? JSON.stringify(Object.fromEntries(entries)) : null
}

/**
 * Apply an update to the active filters: an object is merged in (a key set
 * to `null`, `''` or `[]` clears that filter), a function returns the whole
 * new set.
 */
export function applyDashboardFiltersUpdate(current: ActiveFilters, update: DashboardFiltersUpdate): ActiveFilters {
  const next = typeof update === 'function' ? update(current) : { ...current, ...update }

  return Object.fromEntries(Object.entries(next).filter(([, value]) => !isBlankFilterValue(value)))
}

export interface DashboardFiltersOptions {
  /**
   * Replace the current history entry instead of adding one. Default
   * `false`: Back returns to the filters the dashboard had before.
   */
  replace?: boolean
}

/** A patch merged into the active filters, or a function returning the new set. */
export type DashboardFiltersUpdate = ActiveFilters | ((current: ActiveFilters) => ActiveFilters)

export type SetDashboardFilters = (update: DashboardFiltersUpdate, options?: DashboardFiltersOptions) => void

export interface DashboardFiltersContextValue {
  /** The dashboard's active filters, as the cards receive them. */
  filters: ActiveFilters
  /** Change them: the filter panel and every card follow, and so does the URL. */
  setFilters: SetDashboardFilters
}

export const DashboardFiltersContext = createContext<DashboardFiltersContextValue | null>(null)

/**
 * The active filters of the dashboard the calling component renders in,
 * and their setter: a card, or any component inside one, can read them and
 * set them (`setFilters({ project: row.id })`). `null` outside a dashboard.
 */
export function useDashboardFilters(): DashboardFiltersContextValue | null {
  return useContext(DashboardFiltersContext)
}
