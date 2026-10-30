import { useSyncExternalStore } from 'react'

/**
 * The filters a user applied in a resource index's Filters panel, for the
 * sidebar's active item.
 *
 * The panel keeps its filters in the page's state, not in the URL: the
 * `?filters=` a link opened the index with stays in the URL after the user
 * changes the filters. The sidebar reads this store instead of the URL for
 * that page, so its highlight follows the panel (see isLeafActive). The
 * index clears it whenever the URL changes, where the URL is the truth
 * again.
 */
export interface IndexFilters {
  /** The index's pathname. */
  pathname: string
  /** The `?filters=` payload the panel's filters stand for, or null for none. */
  filters: string | null
}

let current: IndexFilters | null = null
const listeners = new Set<() => void>()

export function publishIndexFilters(next: IndexFilters | null): void {
  if (current === next) return
  current = next
  listeners.forEach((listener) => listener())
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}

export function useIndexFilters(): IndexFilters | null {
  return useSyncExternalStore(subscribe, () => current, () => null)
}

/** The search string with its `filters` param replaced by what the panel applied on that page. */
export function searchWithIndexFilters(
  location: { pathname: string; search: string },
  indexFilters: IndexFilters | null,
): string {
  if (!indexFilters || indexFilters.pathname !== location.pathname) return location.search
  const params = new URLSearchParams(location.search)
  if (indexFilters.filters === null) params.delete('filters')
  else params.set('filters', indexFilters.filters)
  const search = params.toString()
  return search ? `?${search}` : ''
}
