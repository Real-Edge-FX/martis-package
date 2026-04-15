import type { NavigationGroup, NavigationItem, NavigationResourceItem } from "@/types"

export function getNavigationItems(group: NavigationGroup): NavigationItem[] {
  return group.items
}

export function getNavigationResourceItems(group: NavigationGroup): NavigationResourceItem[] {
  return getNavigationItems(group).filter(
    (item): item is NavigationResourceItem => item.type === "resource",
  )
}
