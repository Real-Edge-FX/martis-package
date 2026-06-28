import { describe, expect, it, beforeEach, afterEach } from 'vitest'
import { render } from '@testing-library/react'
import { useIsTruncated } from '@/hooks/useIsTruncated'

interface ProbeProps {
  // The test controls scrollWidth/clientWidth via these props so the
  // hook can be exercised without depending on real layout — jsdom
  // does not implement ResizeObserver or measured box sizes natively.
  scrollWidth: number
  clientWidth: number
}

function Probe({ scrollWidth, clientWidth }: ProbeProps) {
  const [ref, truncated] = useIsTruncated<HTMLSpanElement>()
  return (
    <span
      ref={(el) => {
        if (!el) return
        Object.defineProperty(el, 'scrollWidth', { configurable: true, get: () => scrollWidth })
        Object.defineProperty(el, 'clientWidth', { configurable: true, get: () => clientWidth })
        ;(ref as React.MutableRefObject<HTMLSpanElement | null>).current = el
      }}
      data-truncated={truncated ? 'yes' : 'no'}
    />
  )
}

// jsdom does not ship ResizeObserver. The hook must handle this
// gracefully (one initial measure, no crash) — the polyfill below
// just calls the callback once on observe() and stays silent
// afterwards, which models the worst-case "no resize signal" path.
class FakeResizeObserver {
  constructor(public cb: ResizeObserverCallback) {}
  observe(_: Element) { this.cb([], this as unknown as ResizeObserver) }
  unobserve(_: Element) {}
  disconnect() {}
}

beforeEach(() => {
  ;(globalThis as { ResizeObserver?: typeof ResizeObserver }).ResizeObserver = FakeResizeObserver as unknown as typeof ResizeObserver
})

afterEach(() => {
  delete (globalThis as { ResizeObserver?: typeof ResizeObserver }).ResizeObserver
})

describe('useIsTruncated', () => {
  it('returns truncated=false when scrollWidth fits inside clientWidth', () => {
    const { container } = render(<Probe scrollWidth={100} clientWidth={120} />)
    const span = container.querySelector('span')
    expect(span?.getAttribute('data-truncated')).toBe('no')
  })

  it('returns truncated=true when scrollWidth exceeds clientWidth', () => {
    const { container } = render(<Probe scrollWidth={200} clientWidth={120} />)
    const span = container.querySelector('span')
    expect(span?.getAttribute('data-truncated')).toBe('yes')
  })

  it('ignores sub-pixel rounding (scrollWidth == clientWidth + 1 still counts as fitting)', () => {
    // The +1 fudge keeps the tooltip from flickering on rows whose
    // layout is sub-pixel-perfect after a fractional zoom.
    const { container } = render(<Probe scrollWidth={101} clientWidth={100} />)
    const span = container.querySelector('span')
    expect(span?.getAttribute('data-truncated')).toBe('no')
  })
})
