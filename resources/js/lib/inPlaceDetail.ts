import { useRef } from 'react'
import { useLocation, type Location } from 'react-router'
import type { ResourceSchema } from '@/types'
import { config } from '@/lib/config'
import { ownEntry } from '@/lib/ownEntry'
import { componentRegistry } from '@/lib/componentRegistry'

/**
 * A record whose resource declares a detail override (a drawer such as
 * `DrawerOverride::detail()`, or any custom override component) opens over
 * its index: `/resources/:resource/:id` renders the index page with the
 * override on top. Both URLs are served by the same route component
 * (`pages/ResourcePage.tsx`), so moving between them keeps the index
 * mounted: the list stays on screen and is not fetched again.
 *
 * The record URL is still pushed onto the history (deep links, the back
 * button and a reload keep working). The history entry carries this marker
 * so the router shell can tell an in-place open or close from a page
 * navigation (the top progress bar and the menu badge refresh skip it), and
 * so closing the override can step back to the index entry instead of
 * pushing a new one.
 */
export interface InPlaceDetailMarker {
  /** Pathname of the index page the override opens over. */
  backdrop: string
  /** History entries pushed since the index (one per record opened). */
  depth: number
  /** Pathname this entry was reached from, when it closes a deep-linked record. */
  from?: string
}

const STATE_KEY = 'martisInPlaceDetail'

/** The marker a history entry carries, if any. */
export function inPlaceMarker(state: unknown): InPlaceDetailMarker | null {
  if (!state || typeof state !== 'object') return null
  const marker = (state as Record<string, unknown>)[STATE_KEY]
  if (!marker || typeof marker !== 'object') return null
  const { backdrop, depth, from } = marker as Record<string, unknown>
  if (typeof backdrop !== 'string') return null
  return {
    backdrop,
    depth: typeof depth === 'number' && depth > 0 ? depth : 1,
    ...(typeof from === 'string' ? { from } : {}),
  }
}

/**
 * Whether the current history entry is the sentinel a `DrawerShell` pushes
 * on mount (same URL, so the back button closes the drawer first).
 */
export function drawerSentinelOnTop(): boolean {
  try {
    return (window.history.state as { martisDrawer?: unknown } | null)?.martisDrawer === true
  } catch {
    return false
  }
}

/** History state for an entry opened (or closed) in place over `backdrop`. */
export function inPlaceState(marker: InPlaceDetailMarker): Record<string, InPlaceDetailMarker> {
  return { [STATE_KEY]: marker }
}

/**
 * Whether the record route of this resource renders over its index: the
 * resource declares a detail override whose component is registered. An
 * override the registry cannot resolve (a typo, an extension bundle that did
 * not load) falls back to the full detail page.
 */
export function hasInPlaceDetail(schema: Pick<ResourceSchema, 'overrides'> | null | undefined): boolean {
  const component = schema?.overrides?.detail?.component
  return typeof component === 'string' && componentRegistry.resolve(component) !== undefined
}

/**
 * Whether a row click opens the record over the index: {@link hasInPlaceDetail},
 * and no custom record URL (`config.resourceRecordUrls`, e.g. a headless
 * resource whose records live in a Tool) sends the record elsewhere.
 */
export function opensDetailInPlace(schema: Pick<ResourceSchema, 'overrides'> | null | undefined, uriKey: string): boolean {
  return hasInPlaceDetail(schema) && !ownEntry(config.resourceRecordUrls, uriKey)
}

/**
 * Whether moving from `prev` to `next` only opens, swaps or closes a record
 * over the same index page (no page navigation).
 */
export function isInPlaceTransition(prev: Pick<Location, 'pathname' | 'state'>, next: Pick<Location, 'pathname' | 'state'>): boolean {
  const before = inPlaceMarker(prev.state)
  const after = inPlaceMarker(next.state)
  if (before === null && after === null) return false
  if ((before?.backdrop ?? prev.pathname) === (after?.backdrop ?? next.pathname)) return true

  return after?.from !== undefined && after.from === prev.pathname
}

/**
 * The pathname of the page on screen: it follows `useLocation().pathname`,
 * except across in-place transitions, where it keeps the index pathname.
 * Shell effects that mean "a new page was opened" depend on this instead of
 * the raw pathname.
 */
export function usePagePathname(): string {
  const location = useLocation()
  const ref = useRef<{ location: Location; page: string } | null>(null)
  const prev = ref.current

  let page = location.pathname
  if (prev !== null) {
    if (prev.location.key === location.key && prev.location.pathname === location.pathname) {
      page = prev.page
    } else if (isInPlaceTransition(prev.location, location)) {
      page = prev.page
    }
  }
  ref.current = { location, page }

  return page
}
