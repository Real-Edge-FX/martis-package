/**
 * Stale chunk recovery for lazily loaded extension components.
 *
 * After a deploy, a tab that was opened before it still runs the old entry
 * bundle, which points at chunk files the deploy replaced (hashed names). The
 * dynamic `import()` then rejects with a chunk-load error. A single reload
 * fetches the new entry and the new chunk names, so `reloadOnStaleChunk`
 * reloads at most once per minute per tab (a `sessionStorage` timestamp, never
 * cleared by a successful load, so a good chunk next to a missing one cannot
 * restart the cycle) and rethrows otherwise, so the Martis error state shows
 * instead of a reload loop. It also rethrows while the browser is offline:
 * the failure is the network, not a deploy, and a reload would lose the
 * operator's unsaved state.
 *
 * Kill switch: `config('martis.extensions_reload_on_stale')`, surfaced as
 * `window.MartisConfig.extensionsReloadOnStale` (default true).
 */

export const STALE_CHUNK_RELOAD_KEY = 'martis:stale-chunk-reloaded'

/** No second stale-chunk reload in the same tab within this window (ms). */
export const STALE_CHUNK_GUARD_MS = 60_000

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

function reloadedRecently(): boolean {
  try {
    const raw = window.sessionStorage.getItem(STALE_CHUNK_RELOAD_KEY)

    if (raw === null) return false

    const at = Number(raw)

    // An unreadable value counts as recent: never risk a loop.
    return !Number.isFinite(at) || Date.now() - at < STALE_CHUNK_GUARD_MS
  } catch {
    // Storage blocked: reloading cannot be guarded, so do not reload at all.
    return true
  }
}

function markReloaded(): void {
  try {
    window.sessionStorage.setItem(STALE_CHUNK_RELOAD_KEY, String(Date.now()))
  } catch {
    // Ignored: reloadedRecently() reports true when storage is unusable.
  }
}

function online(): boolean {
  return typeof navigator === 'undefined' || navigator.onLine !== false
}

/**
 * Use as the rejection handler of a dynamic import:
 * `import('./Page').catch(reloadOnStaleChunk)`.
 *
 * A chunk-load error reloads the page (at most once per minute per tab, only online) and returns a promise that
 * never settles (the page is going away). Any other error, a second chunk
 * error within the minute, being offline, or the kill switch being off rethrows the error.
 */
export function reloadOnStaleChunk<T = never>(error: unknown): Promise<T> {
  if (isChunkLoadError(error) && reloadEnabled() && online() && !reloadedRecently()) {
    markReloaded()
    window.location.reload()

    return new Promise<T>(() => undefined)
  }

  throw error
}
