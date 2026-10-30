import { describe, it, expect } from 'vitest'
import { computeFloatingPlacement, FLOATING_GAP, FLOATING_MARGIN, type AnchorRect } from './floatingPlacement'

/*
 * A dropdown panel floats in document.body, placed from its anchor: below
 * it when it fits, above it when it does not and there is more room above,
 * and always inside the viewport.
 */

function anchorAt(top: number, left = 100, width = 200, height = 38): AnchorRect {
  return { top, bottom: top + height, left, right: left + width, width }
}

const viewport = { viewportWidth: 1280, viewportHeight: 800 }

describe('computeFloatingPlacement', () => {
  it('opens below the anchor when the panel fits', () => {
    const p = computeFloatingPlacement({ anchor: anchorAt(100), panelHeight: 256, panelWidth: 200, align: 'start', ...viewport })

    expect(p.side).toBe('below')
    expect(p.top).toBe(138 + FLOATING_GAP)
    expect(p.bottom).toBeUndefined()
    expect(p.left).toBe(100)
    expect(p.maxHeight).toBe(800 - 138 - FLOATING_GAP - FLOATING_MARGIN)
  })

  it('flips above the anchor near the bottom of the viewport', () => {
    const p = computeFloatingPlacement({ anchor: anchorAt(700), panelHeight: 256, panelWidth: 200, align: 'start', ...viewport })

    expect(p.side).toBe('above')
    expect(p.top).toBeUndefined()
    expect(p.bottom).toBe(800 - 700 + FLOATING_GAP)
    expect(p.maxHeight).toBe(700 - FLOATING_GAP - FLOATING_MARGIN)
  })

  it('stays below when the room below, though short, beats the room above', () => {
    const p = computeFloatingPlacement({ anchor: anchorAt(60, 100, 200, 38), panelHeight: 900, panelWidth: 200, align: 'start', ...viewport })

    expect(p.side).toBe('below')
    expect(p.maxHeight).toBe(800 - 98 - FLOATING_GAP - FLOATING_MARGIN)
  })

  it('keeps the panel inside the viewport horizontally', () => {
    const right = computeFloatingPlacement({ anchor: anchorAt(100, 1200, 60), panelHeight: 100, panelWidth: 260, align: 'start', ...viewport })
    expect(right.left).toBe(1280 - 260 - FLOATING_MARGIN)

    const left = computeFloatingPlacement({ anchor: anchorAt(100, 20, 60), panelHeight: 100, panelWidth: 220, align: 'end', ...viewport })
    expect(left.left).toBe(FLOATING_MARGIN)
  })

  it('lines the panel up with the right edge of the anchor for align end', () => {
    const p = computeFloatingPlacement({ anchor: anchorAt(100, 600, 120), panelHeight: 100, panelWidth: 220, align: 'end', ...viewport })

    expect(p.left).toBe(720 - 220)
  })
})
