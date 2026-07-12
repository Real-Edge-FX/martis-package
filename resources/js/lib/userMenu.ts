/**
 * User-menu (topbar avatar dropdown) custom-item helpers.
 *
 * Kept as a pure function so the i18n-label resolution and the
 * before/after-Profile placement can be tested without mounting the whole
 * Topbar (which depends on auth, router, config, PrimeReact, etc.).
 */

export interface UserMenuCustomItem {
  /** Absent for separator entries. */
  label?: string
  icon?: string
  url?: string
  separator?: boolean
  /** Placement relative to the built-in Profile entry. Defaults to "before". */
  position?: 'before' | 'after'
}

export type BuiltMenuItem =
  | { separator: true }
  | { label: string; icon?: string; url?: string }

/**
 * Build the topbar user-menu entries for the custom items at a given
 * placement. Items whose `position` (default "before") matches are kept, in
 * declared order; labels are passed through `resolveLabel` so a consumer can
 * supply a translation key (config files can't call `__()`), matching the
 * i18n behaviour of the built-in Profile entry.
 */
export function buildCustomMenuItems(
  items: UserMenuCustomItem[] | undefined,
  placement: 'before' | 'after',
  resolveLabel: (label: string) => string,
): BuiltMenuItem[] {
  return (items ?? [])
    .filter((item) => (item.position ?? 'before') === placement)
    .map((item) =>
      item.separator
        ? { separator: true as const }
        : { label: resolveLabel(item.label ?? ''), icon: item.icon, url: item.url },
    )
}
