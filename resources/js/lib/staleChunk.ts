/**
 * Stale chunk recovery for lazily loaded extension components.
 *
 * After a deploy, a tab that was opened before it still runs the old entry
 * bundle, which points at chunk files the deploy replaced (hashed names). The
 * dynamic `import()` then rejects with a chunk-load error. A single reload
 * fetches the new entry and the new chunk names, so `reloadOnStaleChunk`
 * reloads once per tab (a `sessionStorage` guard) and, when the reload did not
 * help, rethrows so the Martis error state shows instead of a reload loop.
 *
 * Kill switch: `config('martis.extensions_reload_on_stale')`, surfaced as
 * `window.MartisConfig.extensionsReloadOnStale` (default true).
 */

export const STALE_CHUNK_RELOAD_KEY = 'martis:stale-chunk-reloaded'

const CHUNK_ERROR_PATTERNS = [
  /failed to fetch dynamically imported module/i,
  /importing a module script failed/i,
  /error loading dynamically imported module/i,
  /loading (css )?chunk [\w-]+ failed/i,
]

/** True for the errors browsers and bundlers raise when a chunk cannot be loaded. */
export function isChunkLoadError(error: unknown): boolean {
  if (error === null || typeof error !== 'object') return false

  const { name, message } = error as { name?: unknown; message?: unknown }

  if (name === 'ChunkLoadError') return true

  return typeof message === 'string' && CHUNK_ERROR_PATTERNS.some((pattern) => pattern.test(message))
}

function reloadEnabled(): boolean {
  return window.MartisConfig?.extensionsReloadOnStale !== false
}

function alreadyReloaded(): boolean {
  try {
    return window.sessionStorage.getItem(STALE_CHUNK_RELOAD_KEY) !== null
  } catch {
    // Storage blocked: reloading cannot be guarded, so do not reload at all.
    return true
  }
}

function markReloaded(): void {
  try {
    window.sessionStorage.setItem(STALE_CHUNK_RELOAD_KEY, '1')
  } catch {
    // Ignored: alreadyReloaded() reports true when storage is unusable.
  }
}

/** Forget the guard once a chunk loaded, so a later deploy can reload again. */
export function clearStaleChunkGuard(): void {
  try {
    window.sessionStorage.removeItem(STALE_CHUNK_RELOAD_KEY)
  } catch {
    // Nothing to clear.
  }
}

/**
 * Use as the rejection handler of a dynamic import:
 * `import('./Page').catch(reloadOnStaleChunk)`.
 *
 * A chunk-load error reloads the page once per tab and returns a promise that
 * never settles (the page is going away). Any other error, a second chunk
 * error in the same tab, or the kill switch being off rethrows the error.
 */
export function reloadOnStaleChunk<T = never>(error: unknown): Promise<T> {
  if (isChunkLoadError(error) && reloadEnabled() && !alreadyReloaded()) {
    markReloaded()
    window.location.reload()

    return new Promise<T>(() => undefined)
  }

  throw error
}
