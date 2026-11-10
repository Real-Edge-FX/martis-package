import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { config } from '@/lib/config'
import type { CardGridSpanSource } from '@/lib/cardGridSpan'
import type { DashboardDefinition } from '@/types'

/** The body of `GET /api/dashboards`. */
export interface DashboardsResponse {
  data: { dashboards: DashboardDefinition[] }
}

export const DASHBOARDS_QUERY_KEY = ['dashboards'] as const

let seedTaken = false
// When the page (and so its boot payload) was served: a seed taken later,
// by the first dashboards query of the session, is as old as the page.
const bootTime = Date.now()

/**
 * The list of dashboards the shell embedded in its boot payload
 * (`window.MartisConfig.dashboards`, v2.19.0), as the response the query
 * would have fetched. Handed out once: it describes the user the page was
 * served to, so a query built again later (another user signed in without
 * a reload) asks the server instead of reusing it.
 */
function takeDashboardsSeed(): DashboardsResponse | undefined {
  if (seedTaken) return undefined
  seedTaken = true
  const seeded = config.dashboards
  return Array.isArray(seeded) ? { data: { dashboards: seeded } } : undefined
}

/** Test helper: make the boot payload available again. */
export function resetDashboardsSeedForTests(): void {
  seedTaken = false
}

interface DashboardsQueryOptions {
  staleTime?: number
  refetchInterval?: false
  refetchOnWindowFocus?: boolean
}

/**
 * The registered dashboards this user may see. The page and the sidebar
 * share the query (one cache entry), seeded from the boot payload so the
 * first render already knows the current dashboard, the DASHBOARDS group
 * and the tab strip. Without a seed (pages served before sign-in, a shell
 * that could not resolve the list) the query fetches as before, and
 * `isPending` tells the callers to draw placeholders, never a fallback
 * name.
 */
export function useDashboardsQuery(options: DashboardsQueryOptions = {}) {
  return useQuery<DashboardsResponse>({
    queryKey: DASHBOARDS_QUERY_KEY,
    queryFn: () => api.get<DashboardsResponse>('/api/dashboards'),
    initialData: takeDashboardsSeed,
    // The seed is as old as the page, so a caller's staleTime refreshes it
    // in the background (lock state) once that much time has passed.
    initialDataUpdatedAt: bootTime,
    ...options,
  })
}

// ---------------------------------------------------------------------------
// Last known card layout, for the loading skeleton
// ---------------------------------------------------------------------------

/** localStorage key of the spans of each dashboard's cards. */
export const DASHBOARD_LAYOUTS_KEY = 'martis-dashboard-layouts'

/** Spans kept per dashboard, newest last; older entries are dropped. */
const MAX_REMEMBERED_DASHBOARDS = 50
const MAX_REMEMBERED_CARDS = 48

type RememberedSpan = [number | null, number | null, number | null]
type RememberedLayouts = Record<string, RememberedSpan[]>

function readLayouts(): RememberedLayouts {
  try {
    const raw = window.localStorage.getItem(DASHBOARD_LAYOUTS_KEY)
    if (raw === null) return {}
    const parsed: unknown = JSON.parse(raw)
    return parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed)
      ? (parsed as RememberedLayouts)
      : {}
  } catch {
    return {}
  }
}

function spanValue(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null
}

/**
 * Remember the grid spans of a dashboard's cards (only the spans: no
 * names, no values), so the next cold load draws its skeleton in the
 * dashboard's own shape instead of three generic cards.
 */
export function rememberDashboardLayout(uriKey: string, cards: CardGridSpanSource[]): void {
  try {
    const layouts = readLayouts()
    delete layouts[uriKey]
    layouts[uriKey] = cards
      .slice(0, MAX_REMEMBERED_CARDS)
      .map((card) => [spanValue(card.width), spanValue(card.widthMd), spanValue(card.widthLg)])
    const keys = Object.keys(layouts)
    for (const key of keys.slice(0, Math.max(0, keys.length - MAX_REMEMBERED_DASHBOARDS))) {
      delete layouts[key]
    }
    window.localStorage.setItem(DASHBOARD_LAYOUTS_KEY, JSON.stringify(layouts))
  } catch {
    // Storage unavailable (private mode, quota): the skeleton stays generic.
  }
}

/** The spans remembered for a dashboard, or null when there are none. */
export function recallDashboardLayout(uriKey: string | null): CardGridSpanSource[] | null {
  if (uriKey === null) return null
  const spans = readLayouts()[uriKey]
  if (!Array.isArray(spans) || spans.length === 0) return null
  return spans
    .filter((span): span is RememberedSpan => Array.isArray(span))
    .map(([width, widthMd, widthLg]) => ({
      width: spanValue(width),
      widthMd: spanValue(widthMd),
      widthLg: spanValue(widthLg),
    }))
}

/** Forget every remembered layout (sign-out). */
export function forgetDashboardLayouts(): void {
  try {
    window.localStorage.removeItem(DASHBOARD_LAYOUTS_KEY)
  } catch {
    // Storage unavailable: nothing was kept.
  }
}
