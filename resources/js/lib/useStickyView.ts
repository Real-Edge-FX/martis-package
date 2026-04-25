import { useEffect, useRef } from 'react'
import { config } from './config'

/**
 * Per-user view state persisted on resource index pages so a user
 * who applies filters, opens a record and clicks back finds the
 * table exactly as they left it. Backed by sessionStorage (or
 * localStorage, depending on `config.stickyViews.scope`).
 *
 * The hook is intentionally generic — pass any serialisable shape
 * and it'll round-trip. `useStickyView` writes; `readStickyView` /
 * `clearStickyView` are the imperative escape hatches.
 */

const STORAGE_KEY_PREFIX = 'martis:view:'

type StickyState = Record<string, unknown>

function isFeatureEnabled(): boolean {
  return config.stickyViews?.enabled !== false
}

function getStorage(): Storage | null {
  const scope = config.stickyViews?.scope ?? 'session'
  if (typeof window === 'undefined') return null
  if (scope === 'local') return window.localStorage
  if (scope === 'session') return window.sessionStorage
  // `server` scope is reserved for the next iteration; fall through to
  // session storage so the feature still works during the transition.
  return window.sessionStorage
}

function applyPersistFilter(state: StickyState): StickyState {
  const persist = config.stickyViews?.persist ?? {}
  const filtered: StickyState = {}

  // Filters bucket — applied filters, search query, soft-delete toggle,
  // and the panel's expanded / collapsed flag (so opening the panel on
  // one resource doesn't leak the open state into the next one).
  if (persist.filters !== false) {
    if ('activeFilters' in state) filtered.activeFilters = state.activeFilters
    if ('search' in state) filtered.search = state.search
    if ('trashedFilter' in state) filtered.trashedFilter = state.trashedFilter
    if ('filtersOpen' in state) filtered.filtersOpen = state.filtersOpen
  }
  // Sort bucket.
  if (persist.sorting !== false) {
    if ('sortBy' in state) filtered.sortBy = state.sortBy
    if ('sortDir' in state) filtered.sortDir = state.sortDir
  }
  // Pagination bucket — current page only. perPage rides its own toggle.
  if (persist.pagination !== false && 'page' in state) {
    filtered.page = state.page
  }
  if (persist.per_page !== false && 'perPage' in state) {
    filtered.perPage = state.perPage
  }
  // Column visibility bucket (forward-looking — column toggling not yet shipped).
  if (persist.columns !== false && 'columns' in state) {
    filtered.columns = state.columns
  }
  return filtered
}

/**
 * Imperative reader. Returns null when the feature is disabled, the
 * resource opted out, or no state has ever been written for that
 * uriKey.
 */
export function readStickyView(uriKey: string, enabled = true): StickyState | null {
  if (!enabled || !isFeatureEnabled()) return null
  const storage = getStorage()
  if (!storage) return null
  try {
    const raw = storage.getItem(STORAGE_KEY_PREFIX + uriKey)
    if (!raw) return null
    const parsed = JSON.parse(raw) as unknown
    return typeof parsed === 'object' && parsed !== null ? (parsed as StickyState) : null
  } catch {
    return null
  }
}

/**
 * Imperative writer. Applies the per-bucket `persist` toggles before
 * writing so a flag change in `config.stickyViews.persist` takes
 * effect on the next render without manual cleanup.
 */
export function writeStickyView(uriKey: string, state: StickyState, enabled = true): void {
  if (!enabled || !isFeatureEnabled()) return
  const storage = getStorage()
  if (!storage) return
  try {
    storage.setItem(STORAGE_KEY_PREFIX + uriKey, JSON.stringify(applyPersistFilter(state)))
  } catch {
    // Quota exceeded or storage disabled — silently drop. The next
    // navigation re-tries automatically.
  }
}

/**
 * Imperative clear — drops the saved state for one resource. Used by
 * the "Reset view" button on the index toolbar.
 */
export function clearStickyView(uriKey: string): void {
  const storage = getStorage()
  if (!storage) return
  try {
    storage.removeItem(STORAGE_KEY_PREFIX + uriKey)
  } catch {
    // ignore
  }
}

/**
 * Drop every Martis sticky-view entry across all resources. Wired to
 * the "Clear saved views" affordance in the user profile / preferences
 * surface.
 */
export function clearAllStickyViews(): void {
  const storage = getStorage()
  if (!storage) return
  try {
    const keys: string[] = []
    for (let i = 0; i < storage.length; i++) {
      const key = storage.key(i)
      if (key && key.startsWith(STORAGE_KEY_PREFIX)) keys.push(key)
    }
    keys.forEach((key) => storage.removeItem(key))
  } catch {
    // ignore
  }
}

/**
 * React hook that mirrors a state object into sessionStorage / localStorage
 * keyed by `uriKey`. Pass `enabled = false` (e.g. when the resource opted
 * out via `protected static bool $stickyView = false`) to short-circuit.
 */
export function useStickyView(uriKey: string, state: StickyState, enabled = true): void {
  // Track the last serialised payload so we don't write on every render
  // when the parent re-renders without semantic state changes.
  const lastSerialised = useRef<string>('')

  useEffect(() => {
    if (!enabled || !isFeatureEnabled()) return
    const filtered = applyPersistFilter(state)
    const next = JSON.stringify(filtered)
    if (next === lastSerialised.current) return
    lastSerialised.current = next
    writeStickyView(uriKey, state, enabled)
  }, [uriKey, enabled, state])
}
