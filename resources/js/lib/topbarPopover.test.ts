import { describe, expect, it } from 'vitest'
import { TOPBAR_POPOVER_GUTTER, topbarPopoverRoom, topbarPopoverShift } from './topbarPopover'

describe('topbarPopoverShift', () => {
  it('keeps a panel that already fits right-aligned to its anchor', () => {
    expect(topbarPopoverShift(1200, 360)).toBe(0)
    expect(topbarPopoverShift(360 + TOPBAR_POPOVER_GUTTER, 360)).toBe(0)
  })

  it('moves a panel that runs off the left edge back to the gutter', () => {
    // A 375px phone: the bell ends at x=230 and the panel is capped at 359px.
    const shift = topbarPopoverShift(230, 359)
    expect(shift).toBe(-137)
    const left = 230 - shift - 359
    expect(left).toBe(TOPBAR_POPOVER_GUTTER)
    expect(230 - shift).toBe(375 - TOPBAR_POPOVER_GUTTER)
  })
})

describe('topbarPopoverRoom', () => {
  it('is the height under the anchor less the gap and the gutter', () => {
    expect(topbarPopoverRoom(44.5, 8, 812)).toBe(751)
  })

  it('never goes negative', () => {
    expect(topbarPopoverRoom(800, 8, 600)).toBe(0)
  })
})
