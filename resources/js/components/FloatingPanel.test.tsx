import { describe, it, expect, vi, afterEach } from 'vitest'
import { render } from '@testing-library/react'
import { useRef, type ReactNode } from 'react'
import { FloatingPanel } from './FloatingPanel'
import { FLOATING_MARGIN } from '@/lib/floatingPlacement'

/*
 * FloatingPanel places a list rendered in document.body from its anchor's
 * rectangle. It places it again when its picker renders (an anchor moved
 * without being resized), measures a content-sized panel away from its
 * previous position (or the viewport's edge narrows it and it creeps left on
 * every update), and carries the page's per-resource accent into the body.
 */

function rect(top: number, left: number, width: number, height = 38): DOMRect {
  return { top, bottom: top + height, left, right: left + width, width, height, x: left, y: top, toJSON: () => ({}) } as DOMRect
}

function Harness({ anchorRect, matchWidth, wrapper }: { anchorRect: DOMRect; matchWidth?: boolean; wrapper?: (children: ReactNode) => ReactNode }) {
  const anchorRef = useRef<HTMLButtonElement | null>(null)
  const content = (
    <>
      <button
        ref={(el) => {
          anchorRef.current = el
          if (el) el.getBoundingClientRect = () => anchorRect
        }}
        type="button"
      >
        trigger
      </button>
      <FloatingPanel anchorRef={anchorRef} matchWidth={matchWidth} data-testid="panel">
        options
      </FloatingPanel>
    </>
  )
  return <>{wrapper ? wrapper(content) : content}</>
}

const panel = () => document.body.querySelector('[data-testid="panel"]') as HTMLElement

afterEach(() => {
  vi.restoreAllMocks()
})

describe('FloatingPanel', () => {
  it('places the panel again when its picker renders with the anchor moved', () => {
    const { rerender } = render(<Harness anchorRect={rect(100, 40, 300)} />)
    expect(panel().style.top).toBe('142px')

    // A chip added above the trigger moves it down; its size is unchanged.
    rerender(<Harness anchorRect={rect(130, 40, 300)} />)
    expect(panel().style.top).toBe('172px')
  })

  it('measures a content-sized panel at the left edge, so it does not creep left near the right edge', () => {
    // A content-sized fixed box shrinks to the room right of its `left`.
    vi.spyOn(HTMLElement.prototype, 'offsetWidth', 'get').mockImplementation(function (this: HTMLElement) {
      const left = parseFloat(this.style.left || '0')
      return Math.min(300, window.innerWidth - left)
    })
    const anchorRect = rect(100, window.innerWidth - 60, 50)
    const { rerender } = render(<Harness anchorRect={anchorRect} matchWidth={false} />)
    const expected = `${window.innerWidth - 300 - FLOATING_MARGIN}px`
    expect(panel().style.left).toBe(expected)

    rerender(<Harness anchorRect={anchorRect} matchWidth={false} />)
    rerender(<Harness anchorRect={anchorRect} matchWidth={false} />)
    expect(panel().style.left).toBe(expected)
  })

  it('carries a named per-resource accent into document.body', () => {
    render(<Harness anchorRect={rect(100, 40, 300)} wrapper={(c) => <div data-resource-accent="teal">{c}</div>} />)
    expect(panel().getAttribute('data-resource-accent')).toBe('teal')
  })

  it('carries a hex per-resource accent', () => {
    render(
      <Harness
        anchorRect={rect(100, 40, 300)}
        wrapper={(c) => <div style={{ '--martis-accent': '#ff0066' } as React.CSSProperties}>{c}</div>}
      />,
    )
    expect(panel().style.getPropertyValue('--martis-accent')).toBe('#ff0066')
    expect(panel().hasAttribute('data-resource-accent')).toBe(false)
  })
})
