/**
 * Placement of the global tooltip bubble (`MartisTooltip`).
 *
 * The bubble is laid out at the viewport origin, measured, and only then
 * moved next to its trigger with the position computed here, so its width
 * never depends on where the trigger sits. The placement:
 *
 *   1. keeps the requested side when the bubble fits there, flips to the
 *      opposite side when only that one has room, and otherwise keeps
 *      whichever of the two has more room (a left / right bubble with room
 *      on neither side goes above or below the trigger instead);
 *   2. centres the bubble on the trigger along the other axis and clamps
 *      it inside the viewport, `TOOLTIP_MARGIN` px from every edge;
 *   3. reports where the arrow meets the bubble edge so it keeps pointing
 *      at the trigger after the clamp moved the bubble.
 */

export type TooltipSide = 'top' | 'bottom' | 'left' | 'right'

export interface TooltipAnchor {
  left: number
  top: number
  width: number
  height: number
}

export interface TooltipSize {
  width: number
  height: number
}

export interface TooltipPlacement {
  side: TooltipSide
  /** Left edge of the bubble, in viewport pixels. */
  x: number
  /** Top edge of the bubble, in viewport pixels. */
  y: number
  /**
   * Arrow centre along the edge facing the trigger: pixels from the
   * bubble's left edge for `top` / `bottom`, from its top edge for
   * `left` / `right`.
   */
  arrow: number
}

/** Space between the trigger and the bubble. */
export const TOOLTIP_GAP = 8

/** Minimum distance between the bubble and any viewport edge. */
export const TOOLTIP_MARGIN = 8

/** Minimum distance between the arrow centre and a bubble corner. */
export const TOOLTIP_ARROW_INSET = 8

const OPPOSITE: Record<TooltipSide, TooltipSide> = {
  top: 'bottom',
  bottom: 'top',
  left: 'right',
  right: 'left',
}

export function isTooltipSide(value: unknown): value is TooltipSide {
  return value === 'top' || value === 'bottom' || value === 'left' || value === 'right'
}

function clamp(value: number, min: number, max: number): number {
  // When the bubble is larger than the room (max < min) it sticks to `min`.
  return Math.max(min, Math.min(value, max))
}

export function computeTooltipPlacement(
  anchor: TooltipAnchor,
  bubble: TooltipSize,
  viewport: TooltipSize,
  preferred: TooltipSide,
): TooltipPlacement {
  const right = anchor.left + anchor.width
  const bottom = anchor.top + anchor.height
  const centreX = anchor.left + anchor.width / 2
  const centreY = anchor.top + anchor.height / 2

  const room: Record<TooltipSide, number> = {
    top: anchor.top - TOOLTIP_GAP - TOOLTIP_MARGIN,
    bottom: viewport.height - bottom - TOOLTIP_GAP - TOOLTIP_MARGIN,
    left: anchor.left - TOOLTIP_GAP - TOOLTIP_MARGIN,
    right: viewport.width - right - TOOLTIP_GAP - TOOLTIP_MARGIN,
  }
  const needs = (side: TooltipSide) => (side === 'top' || side === 'bottom' ? bubble.height : bubble.width)

  const opposite = OPPOSITE[preferred]
  let side = preferred
  if (room[preferred] < needs(preferred)) {
    if (room[opposite] >= needs(opposite) || room[opposite] > room[preferred]) {
      side = opposite
    }
  }

  // A side bubble with room on neither side (a narrow screen) goes above or
  // below the trigger instead of being clamped over it.
  if ((side === 'left' || side === 'right') && room[side] < needs(side)) {
    const vertical = (['top', 'bottom'] as const).find((alt) => room[alt] >= needs(alt))
    if (vertical) side = vertical
  }

  let x: number
  let y: number
  switch (side) {
    case 'top':
      x = centreX - bubble.width / 2
      y = anchor.top - TOOLTIP_GAP - bubble.height
      break
    case 'bottom':
      x = centreX - bubble.width / 2
      y = bottom + TOOLTIP_GAP
      break
    case 'left':
      x = anchor.left - TOOLTIP_GAP - bubble.width
      y = centreY - bubble.height / 2
      break
    case 'right':
      x = right + TOOLTIP_GAP
      y = centreY - bubble.height / 2
      break
  }

  x = Math.round(clamp(x, TOOLTIP_MARGIN, viewport.width - bubble.width - TOOLTIP_MARGIN))
  y = Math.round(clamp(y, TOOLTIP_MARGIN, viewport.height - bubble.height - TOOLTIP_MARGIN))

  const vertical = side === 'top' || side === 'bottom'
  const edge = vertical ? bubble.width : bubble.height
  const inset = Math.min(TOOLTIP_ARROW_INSET, edge / 2)
  const arrow = Math.round(clamp(vertical ? centreX - x : centreY - y, inset, edge - inset))

  return { side, x, y, arrow }
}
