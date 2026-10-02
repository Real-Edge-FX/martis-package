import { useEffect, useRef } from 'react'
import { config } from './config'

/**
 * Per-user view state persisted on resource index pages so a user
 * who applies filters, opens a record and clicks back finds the
 * table exactly as they left it. Backed by sessionStorage (or
 * localStorage, depending on `config.stickyViews.scope`).
 *
 * An entry belongs to the signed-in user who wrote it: its key is
 * `martis:view:{userId}:{uriKey}`, and every reader and writer names that
 * user. A search term or a filter value can be personal data, and the
 * storage outlives a sign-out (the tab's sessionStorage survives the
 * reload on the login page, localStorage the browser), so another user of
 * the same browser must never read it: `signOut()` drops every entry, and
 * `purgeForeignStickyViews()` drops the ones of any other user (and the
 * keys of the earlier, unscoped format) when the session boots.
 *
 * The hook is intentionally generic — pass any serialisable shape
 * and it'll round-trip. `useStickyView` writes; `readStickyView` /
 * `clearStickyView` are the imperative escape hatches.
 */

const STORAGE_KEY_PREFIX = 'martis:view:'

type StickyState = Record<string, unknown>

/** The signed-in user an entry belongs to; `null` when nobody is signed in. */
export type StickyOwner = string | number | null | undefined

/** `martis:view:{userId}:`, the prefix of every entry of one user. The id is
 *  encoded, so no id can run into another's prefix. */
function ownerPrefix(owner: string | number): string {
  return `${STORAGE_KEY_PREFIX}${encodeURIComponent(String(owner))}:`
}

/** The storage key of one resource's view for one user, or `null` without a user. */
function stickyKey(owner: StickyOwner, uriKey: string): string | null {
  if (owner === null || owner === undefined || owner === '') return null
  return ownerPrefix(owner) + uriKey
}

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
 * resource opted out, nobody is signed in, or no state has ever been
 * written for that user and uriKey.
 */
export function readStickyView(owner: StickyOwner, uriKey: string, enabled = true): StickyState | null {
  if (!enabled || !isFeatureEnabled()) return null
  const storage = getStorage()
  const key = stickyKey(owner, uriKey)
  if (!storage || key === null) return null
  try {
    const raw = storage.getItem(key)
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
export function writeStickyView(owner: StickyOwner, uriKey: string, state: StickyState, enabled = true): void {
  if (!enabled || !isFeatureEnabled()) return
  const storage = getStorage()
  const key = stickyKey(owner, uriKey)
  if (!storage || key === null) return
  try {
    storage.setItem(key, JSON.stringify(applyPersistFilter(state)))
  } catch {
    // Quota exceeded or storage disabled — silently drop. The next
    // navigation re-tries automatically.
  }
}

/**
 * Imperative clear — drops the saved state for one resource. Used by
 * the "Reset view" button on the index toolbar.
 */
export function clearStickyView(owner: StickyOwner, uriKey: string): void {
  const storage = getStorage()
  const key = stickyKey(owner, uriKey)
  if (!storage || key === null) return
  try {
    storage.removeItem(key)
  } catch {
    // ignore
  }
}

/** Both web storages, whichever `scope` is configured: a sign-out must not
 *  leave an entry behind in the one the configuration no longer reads (the
 *  scope can change between two deployments, or between two visits). */
function allStorages(): Storage[] {
  if (typeof window === 'undefined') return []
  const storages: Storage[] = []
  for (const name of ['sessionStorage', 'localStorage'] as const) {
    try {
      storages.push(window[name])
    } catch {
      // Storage blocked (a private window, a policy): nothing to clear there.
    }
  }
  return storages
}

/** Remove every sticky-view entry of `storage` that `keep` does not spare. */
function removeStickyEntries(storage: Storage, keep: (key: string) => boolean): void {
  try {
    const keys: string[] = []
    for (let i = 0; i < storage.length; i++) {
      const key = storage.key(i)
      if (key && key.startsWith(STORAGE_KEY_PREFIX) && !keep(key)) keys.push(key)
    }
    keys.forEach((key) => storage.removeItem(key))
  } catch {
    // ignore
  }
}

/**
 * Drop every Martis sticky-view entry, of every user and resource, from
 * both `sessionStorage` and `localStorage`. `signOut()` calls it before it
 * leaves the page, so the next person to sign in on this browser starts
 * clean.
 */
export function clearAllStickyViews(): void {
  allStorages().forEach((storage) => removeStickyEntries(storage, () => false))
}

/**
 * Drop every sticky-view entry that does not belong to `owner`, from both
 * storages: the entries another user left (the session ended without a
 * `signOut()`: it expired, the tab was closed, the account was switched),
 * and the keys of the format before entries named their user
 * (`martis:view:{uriKey}`). Runs when the session boots with a user.
 */
export function purgeForeignStickyViews(owner: StickyOwner): void {
  if (owner === null || owner === undefined || owner === '') return
  const prefix = ownerPrefix(owner)
  allStorages().forEach((storage) => removeStickyEntries(storage, (key) => key.startsWith(prefix)))
}

/**
 * React hook that mirrors a state object into sessionStorage / localStorage
 * keyed by the signed-in user and `uriKey`. Pass `enabled = false` (e.g. when the resource opted
 * out via `protected static bool $stickyView = false`) to short-circuit.
 */
export function useStickyView(owner: StickyOwner, uriKey: string, state: StickyState, enabled = true): void {
  // Track the last serialised payload so we don't write on every render
  // when the parent re-renders without semantic state changes.
  const lastSerialised = useRef<string>('')

  useEffect(() => {
    if (!enabled || !isFeatureEnabled()) return
    const filtered = applyPersistFilter(state)
    // The payload and who it is written for: the same state under another
    // user (the session changed while the page stayed mounted) is a new write.
    const next = JSON.stringify([owner ?? null, filtered])
    if (next === lastSerialised.current) return
    lastSerialised.current = next
    writeStickyView(owner, uriKey, state, enabled)
  }, [owner, uriKey, enabled, state])
}
