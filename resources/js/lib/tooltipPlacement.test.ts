import { describe, it, expect } from 'vitest'
import { computeTooltipPlacement, TOOLTIP_GAP, TOOLTIP_MARGIN, TOOLTIP_ARROW_INSET } from './tooltipPlacement'

/*
 * The global tooltip centred its bubble on the trigger and never compared
 * the result with the viewport, so a trigger near an edge got a bubble
 * half off-screen. The placement is now computed from the measured bubble:
 * flip to the opposite side when the requested one has no room, clamp the
 * bubble inside the viewport (8 px margin) and keep the arrow on the
 * trigger.
 */

const desktop = { width: 1440, height: 1000 }
const phone = { width: 375, height: 812 }

function rect(left: number, top: number, width = 40, height = 20) {
  return { left, top, width, height }
}

describe('computeTooltipPlacement', () => {
  it('centres the bubble above a trigger in the middle of the page', () => {
    const anchor = rect(700, 500)
    const placement = computeTooltipPlacement(anchor, { width: 300, height: 30 }, desktop, 'top')

    expect(placement).toEqual({
      side: 'top',
      x: 720 - 150,
      y: 500 - TOOLTIP_GAP - 30,
      arrow: 150,
    })
  })

  it('places the bubble below the trigger for bottom', () => {
    const placement = computeTooltipPlacement(rect(700, 500), { width: 300, height: 30 }, desktop, 'bottom')

    expect(placement.side).toBe('bottom')
    expect(placement.y).toBe(520 + TOOLTIP_GAP)
    expect(placement.x).toBe(570)
  })

  it('keeps the whole bubble inside the viewport when the trigger is 60 px from the right edge', () => {
    // Trigger centre at 1440 - 60 = 1380.
    const anchor = rect(1360, 500)
    const placement = computeTooltipPlacement(anchor, { width: 300, height: 30 }, desktop, 'top')

    expect(placement.x).toBe(1440 - 300 - TOOLTIP_MARGIN)
    expect(placement.x + 300).toBeLessThanOrEqual(1440 - TOOLTIP_MARGIN)
    // The arrow still points at the trigger centre.
    expect(placement.x + placement.arrow).toBe(1380)
  })

  it('starts the bubble at the margin when the trigger is near the left edge of a phone', () => {
    const anchor = rect(100, 400)
    const placement = computeTooltipPlacement(anchor, { width: 240, height: 50 }, phone, 'top')

    expect(placement.x).toBe(TOOLTIP_MARGIN)
    expect(placement.x + placement.arrow).toBe(120)
  })

  it('pins a bubble wider than the viewport to the left margin', () => {
    const placement = computeTooltipPlacement(rect(150, 400), { width: 400, height: 40 }, phone, 'bottom')

    expect(placement.x).toBe(TOOLTIP_MARGIN)
  })

  it('flips top to bottom when the room above is too small, and bottom to top near the bottom edge', () => {
    const nearTop = computeTooltipPlacement(rect(700, 14), { width: 120, height: 24 }, desktop, 'top')
    expect(nearTop.side).toBe('bottom')
    expect(nearTop.y).toBe(34 + TOOLTIP_GAP)

    const nearBottom = computeTooltipPlacement(rect(700, 970), { width: 120, height: 24 }, desktop, 'bottom')
    expect(nearBottom.side).toBe('top')
    expect(nearBottom.y).toBe(970 - TOOLTIP_GAP - 24)
  })

  it('flips a tall bubble by its real height, not a fixed clearance', () => {
    // 60 px above the trigger is enough for a one-line label but not for a
    // 90 px paragraph; the trigger has plenty of room below.
    const placement = computeTooltipPlacement(rect(700, 60), { width: 360, height: 90 }, desktop, 'top')
    expect(placement.side).toBe('bottom')
  })

  it('keeps the requested side when neither side fits and it has more room, clamping inside the viewport', () => {
    const tiny = { width: 400, height: 100 }
    const placement = computeTooltipPlacement(rect(180, 50), { width: 200, height: 80 }, tiny, 'top')

    expect(placement.side).toBe('top')
    expect(placement.y).toBe(TOOLTIP_MARGIN)
  })

  it('flips left and right on the horizontal axis', () => {
    const left = computeTooltipPlacement(rect(10, 400), { width: 150, height: 24 }, desktop, 'left')
    expect(left.side).toBe('right')
    expect(left.x).toBe(50 + TOOLTIP_GAP)

    const right = computeTooltipPlacement(rect(1400, 400), { width: 150, height: 24 }, desktop, 'right')
    expect(right.side).toBe('left')
    expect(right.x).toBe(1400 - TOOLTIP_GAP - 150)
  })

  it('goes above or below when neither horizontal side has room for a side bubble', () => {
    // A phone: 151 px left of the trigger, 152 px right of it, bubble 300 px.
    const placement = computeTooltipPlacement(rect(167, 400), { width: 300, height: 40 }, phone, 'left')

    expect(placement.side).toBe('top')
    expect(placement.y).toBe(400 - TOOLTIP_GAP - 40)
    expect(placement.x + placement.arrow).toBe(187)
  })

  it('clamps a side bubble vertically and keeps its arrow on the trigger', () => {
    const placement = computeTooltipPlacement(rect(700, 5, 40, 10), { width: 150, height: 60 }, desktop, 'right')

    expect(placement.side).toBe('right')
    expect(placement.y).toBe(TOOLTIP_MARGIN)
    expect(placement.y + placement.arrow).toBe(TOOLTIP_ARROW_INSET + TOOLTIP_MARGIN)
  })

  it('keeps the arrow off the rounded corners when the trigger sits beyond the bubble edge', () => {
    // Trigger centre at x = 4 (inside the margin): the bubble cannot move
    // further left, so the arrow stops at the inset instead of leaving it.
    const placement = computeTooltipPlacement(rect(0, 400, 8, 20), { width: 200, height: 30 }, desktop, 'top')

    expect(placement.x).toBe(TOOLTIP_MARGIN)
    expect(placement.arrow).toBe(TOOLTIP_ARROW_INSET)
  })

  it('rounds the position to whole pixels', () => {
    const placement = computeTooltipPlacement(rect(700.4, 500.6, 41, 21), { width: 301, height: 31 }, desktop, 'top')

    expect(Number.isInteger(placement.x)).toBe(true)
    expect(Number.isInteger(placement.y)).toBe(true)
  })
})
