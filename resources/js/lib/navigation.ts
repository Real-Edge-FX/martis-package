import { useEffect, useRef } from "react"
import { useLocation } from "react-router-dom"
import { useQueryClient } from "@tanstack/react-query"
import type {
  NavigationGroup,
  NavigationGroupChild,
  NavigationItem,
  NavigationNestedGroup,
  NavigationResourceItem,
} from "@/types"

export function isNestedGroup(child: NavigationGroupChild): child is NavigationNestedGroup {
  return (child as NavigationNestedGroup).type === "group"
}

/**
 * Flatten a navigation group's heterogeneous children into the leaf
 * items only — nested MenuGroup containers are preserved in `getNavigationItems`
 * but stripped here for callers that just want the resource/link entries.
 */
export function getNavigationItems(group: NavigationGroup): NavigationGroupChild[] {
  return group.items
}

/**
 * Collapse a group's heterogeneous children into a flat list of leaf
 * `NavigationItem`s by lifting nested-MenuGroup items up to the parent.
 * Used by surfaces that don't have a 3rd visual level — typically the
 * topnav, command palette, or anywhere a flat list is preferable.
 */
export function flattenNavigationItems(group: NavigationGroup): NavigationItem[] {
  const out: NavigationItem[] = []
  for (const child of group.items) {
    if (isNestedGroup(child)) {
      out.push(...child.items)
    } else {
      out.push(child)
    }
  }
  return out
}

export function getNavigationResourceItems(group: NavigationGroup): NavigationResourceItem[] {
  const out: NavigationResourceItem[] = []
  for (const child of group.items) {
    if (isNestedGroup(child)) {
      out.push(...child.items.filter((i): i is NavigationResourceItem => i.type === "resource"))
    } else if (child.type === "resource") {
      out.push(child)
    }
  }
  return out
}

/**
 * Extract the resource count badge from a navigation item, returning
 * null when the item doesn't declare one (links, count-less resources,
 * or opt-outs).
 */
export function getItemCount(item: NavigationItem): number | null {
  if (item.type !== "resource") return null
  return typeof (item as NavigationResourceItem).count === "number"
    ? (item as NavigationResourceItem).count!
    : null
}

/**
 * Threshold above which the badge switches from full digits to compact
 * notation (10K, 123K, 1.2M, …). Configurable via the SSR-injected
 * `MartisConfig.navigation.countCompactThreshold`. Default 10000 keeps
 * everyday counts readable (so you still see 1,234 / 9,872 in full)
 * while preventing badges from blowing up the sidebar at 50,000+.
 *
 * Set to `null` (or 0) to disable compaction and always show full digits.
 */
const DEFAULT_COMPACT_THRESHOLD = 10_000

function getCompactThreshold(): number | null {
  const cfg = (window as unknown as {
    MartisConfig?: { navigation?: { countCompactThreshold?: number | null } }
  }).MartisConfig?.navigation?.countCompactThreshold
  if (cfg === null) return null
  if (typeof cfg === 'number' && cfg >= 0) return cfg
  return DEFAULT_COMPACT_THRESHOLD
}

/**
 * Compact count formatter used by the nav badges.
 *
 * Below the threshold (default 10K): full digits with locale-aware
 * thousand separators (1,284 en / 1.284 pt).
 *
 * At or above the threshold: `Intl.NumberFormat` compact notation —
 * 10000 → "10K", 123456 → "123K", 1_234_567 → "1.2M". Sub-million
 * values get no decimals (cleaner badge); 1M+ gets one decimal so
 * 1.2M / 5.4M / 25M stay distinguishable.
 *
 * Falls back to a plain string on runtime issues (very old browsers
 * without `Intl` compact support).
 */
export function formatItemCount(value: number): string {
  try {
    const threshold = getCompactThreshold()
    if (threshold === null || value < threshold) {
      return new Intl.NumberFormat().format(value)
    }
    return new Intl.NumberFormat(undefined, {
      notation: 'compact',
      maximumFractionDigits: 1,
    }).format(value)
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
