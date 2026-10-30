/**
 * Placement of a dropdown panel rendered in `document.body` with
 * `position: fixed` (see FloatingPanel), from the rectangle of the element
 * that opened it.
 *
 * The panel opens below its anchor. It flips above when it does not fit
 * below and there is more room above, and its height is capped by the room
 * on the side it opens on, so it never runs off the viewport.
 */

/** Space between the anchor and the panel. */
export const FLOATING_GAP = 4

/** Space kept between the panel and the viewport edges. */
export const FLOATING_MARGIN = 8

export interface AnchorRect {
  top: number
  bottom: number
  left: number
  right: number
  width: number
}

export interface FloatingPlacementInput {
  anchor: AnchorRect
  /** The height the panel wants: its content, capped by its own max height. */
  panelHeight: number
  /** The panel's rendered width, used to keep it inside the viewport. */
  panelWidth: number
  viewportWidth: number
  viewportHeight: number
  /** `start` lines the panel up with the anchor's left edge, `end` with its right edge. */
  align: 'start' | 'end'
}

export interface FloatingPlacement {
  side: 'below' | 'above'
  /** Set when the panel opens below the anchor. */
  top?: number
  /** Set when the panel opens above the anchor (distance from the viewport's bottom edge). */
  bottom?: number
  left: number
  /** The room on the side the panel opens on. */
  maxHeight: number
}

export function computeFloatingPlacement(input: FloatingPlacementInput): FloatingPlacement {
  const { anchor, panelHeight, panelWidth, viewportWidth, viewportHeight, align } = input

  const spaceBelow = Math.max(0, viewportHeight - anchor.bottom - FLOATING_GAP - FLOATING_MARGIN)
  const spaceAbove = Math.max(0, anchor.top - FLOATING_GAP - FLOATING_MARGIN)
  const side = panelHeight > spaceBelow && spaceAbove > spaceBelow ? 'above' : 'below'

  const preferredLeft = align === 'end' ? anchor.right - panelWidth : anchor.left
  const maxLeft = viewportWidth - panelWidth - FLOATING_MARGIN
  const left = Math.max(FLOATING_MARGIN, Math.min(preferredLeft, maxLeft))

  if (side === 'above') {
    return { side, bottom: viewportHeight - anchor.top + FLOATING_GAP, left, maxHeight: spaceAbove }
  }

  return { side, top: anchor.bottom + FLOATING_GAP, left, maxHeight: spaceBelow }
}
