import { useEffect, useRef } from "react"
import { useLocation } from "react-router-dom"
import { useQueryClient } from "@tanstack/react-query"
import type { NavigationGroup, NavigationItem, NavigationResourceItem } from "@/types"

export function getNavigationItems(group: NavigationGroup): NavigationItem[] {
  return group.items
}

export function getNavigationResourceItems(group: NavigationGroup): NavigationResourceItem[] {
  return getNavigationItems(group).filter(
    (item): item is NavigationResourceItem => item.type === "resource",
  )
}

/**
 * Extract the resource count badge from a navigation item, returning
 * null when the item doesn't declare one (links, count-less resources,
 * or opt-outs).
 */
export function getItemCount(item: NavigationItem): number | null {
  if (item.type !== "resource") return null
  return typeof item.count === "number" ? item.count : null
}

/**
 * Compact count formatter used by the nav badges. Uses `Intl.NumberFormat`
 * to keep locale-appropriate thousand separators (1,284 in en, 1.284 in
 * pt). Falls back to a plain string on runtime issues.
 */
export function formatItemCount(value: number): string {
  try {
    return new Intl.NumberFormat().format(value)
  } catch {
    return String(value)
  }
}

/**
 * Reactive refresh of the navigation query when the user navigates.
 *
 * Pairs with the passive `refetchInterval` on the `['navigation']` query:
 * polling keeps badges in sync while idle, while this hook refreshes
 * them the moment a user interacts with the menu. Throttled to
 * `minIntervalMs` between consecutive navigations so rapid clicks don't
 * flood the server — the user's own mutations already invalidate the
 * query via the MutationCache, so this only matters for *other users'*
 * concurrent writes.
 */
export function useNavigationRefreshOnNavigate(minIntervalMs = 3000): void {
  const location = useLocation()
  const qc = useQueryClient()
  const last = useRef(0)

  useEffect(() => {
    const now = Date.now()
    if (now - last.current < minIntervalMs) return
    last.current = now
    void qc.invalidateQueries({ queryKey: ["navigation"] })
  }, [location.pathname, qc, minIntervalMs])
}
