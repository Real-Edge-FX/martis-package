/**
 * Fitting a panel hung under a top-bar icon (notifications, preferences)
 * inside the screen.
 *
 * On a phone the top-bar icons sit away from the right edge, so a panel
 * right-aligned to its icon runs off the left edge, and a panel taller than
 * the room under the top bar runs off the bottom. These helpers work in
 * viewport coordinates (`getBoundingClientRect()`), so they hold whatever
 * the panel's containing block is.
 */

/** Space kept between a top-bar panel and the edges of the screen. */
export const TOPBAR_POPOVER_GUTTER = 8

/**
 * Height a panel may take under its anchor: the viewport height less the
 * anchor's bottom edge, the gap between anchor and panel, and the gutter.
 */
export function topbarPopoverRoom(
  anchorBottom: number,
  gap: number,
  viewportHeight: number = window.innerHeight,
): number {
  return Math.max(0, Math.floor(viewportHeight - anchorBottom - gap - TOPBAR_POPOVER_GUTTER))
}

/**
 * CSS `right` offset (zero or negative) that moves a panel right-aligned to
 * its anchor back inside the left gutter. The panel's width must already be
 * capped to the viewport (`max-width: calc(100vw - 16px)`), so moving it
 * right never pushes it past the right gutter.
 */
export function topbarPopoverShift(anchorRight: number, panelWidth: number): number {
  const overflow = TOPBAR_POPOVER_GUTTER - (anchorRight - panelWidth)
  return overflow > 0 ? -Math.ceil(overflow) : 0
}
