import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, act, fireEvent, cleanup } from '@testing-library/react'
import { MartisTooltip } from './MartisTooltip'
import { TOOLTIP_GAP, TOOLTIP_MARGIN } from '@/lib/tooltipPlacement'

/*
 * Global tooltip (`[data-pr-tooltip]`, event delegation).
 *
 * Two defects in one component: a plain tooltip was pinned to one line
 * (`white-space: nowrap`) inside a 300 px box, so a sentence ran out of the
 * bubble; and the bubble was centred on the trigger with `left` and never
 * compared with the viewport, so a trigger near an edge got a squeezed
 * bubble half off-screen. jsdom has no layout, so the geometry is stubbed:
 * the trigger reports a rect, the bubble a size.
 */

const BUBBLE = { width: 300, height: 30 }

let anchorRect = { left: 700, top: 500, width: 40, height: 20 }
const triggers: HTMLElement[] = []

function domRect({ left, top, width, height }: { left: number; top: number; width: number; height: number }): DOMRect {
  return {
    left, top, width, height, x: left, y: top, right: left + width, bottom: top + height,
    toJSON: () => ({}),
  } as DOMRect
}

beforeEach(() => {
  vi.useFakeTimers()
  anchorRect = { left: 700, top: 500, width: 40, height: 20 }
  Object.defineProperty(document.documentElement, 'clientWidth', { configurable: true, value: 1440 })
  Object.defineProperty(document.documentElement, 'clientHeight', { configurable: true, value: 1000 })
  vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
    if (this.getAttribute('role') === 'tooltip') {
      return domRect({ left: 0, top: 0, ...BUBBLE })
    }
    return domRect(anchorRect)
  })
  vi.spyOn(window, 'requestAnimationFrame').mockImplementation((cb: FrameRequestCallback) => {
    cb(0)
    return 0
  })
})

function reset() {
  cleanup()
  triggers.splice(0).forEach((trigger) => trigger.remove())
}

afterEach(() => {
  reset()
  vi.useRealTimers()
  vi.restoreAllMocks()
})

function hover(attributes: Record<string, string>) {
  const trigger = document.createElement('button')
  for (const [name, value] of Object.entries(attributes)) trigger.setAttribute(name, value)
  trigger.textContent = 'probe'
  document.body.appendChild(trigger)
  triggers.push(trigger)

  render(<MartisTooltip />)

  act(() => {
    fireEvent.mouseEnter(trigger)
    vi.advanceTimersByTime(500)
  })

  const bubble = document.querySelector<HTMLElement>('[role="tooltip"]')
  const content = document.querySelector<HTMLElement>('.martis-tooltip-content')
  return { trigger, bubble: bubble!, content: content! }
}

describe('MartisTooltip text', () => {
  const sentence = "System One's estimate that the entry is reusable and specific enough to serve a future question."

  it('lets a plain tooltip wrap inside its bubble instead of pinning it to one line', () => {
    const { content } = hover({ 'data-pr-tooltip': sentence })

    expect(content.textContent).toBe(sentence)
    expect(content.style.whiteSpace).toBe('normal')
    expect(content.style.overflowWrap).toBe('anywhere')
    expect(content.style.fontSize).toBe('11px')
    // No minimum width on a plain label: a short one stays a tight pill.
    expect(content.style.minWidth).toBe('')
  })

  it('renders plain text literally and HTML only behind the opt-in', () => {
    const plain = hover({ 'data-pr-tooltip': '<b>x</b>' })
    expect(plain.content.querySelector('b')).toBeNull()
    expect(plain.content.textContent).toBe('<b>x</b>')

    reset()

    const html = hover({ 'data-pr-tooltip': '<b>x</b>', 'data-pr-tooltip-html': 'true' })
    expect(html.content.querySelector('b')?.textContent).toBe('x')
    expect(html.content.style.fontSize).toBe('12px')
  })
})

describe('MartisTooltip position', () => {
  it('lays the bubble out at the viewport origin and moves it with a transform', () => {
    const { bubble } = hover({ 'data-pr-tooltip': 'Remove' })

    expect(bubble.style.left).toBe('0px')
    expect(bubble.style.top).toBe('0px')
    expect(bubble.style.visibility).toBe('')
    // Centred above the trigger (centre x = 720).
    expect(bubble.style.transform).toBe(`translate3d(${720 - 150}px, ${500 - TOOLTIP_GAP - 30}px, 0)`)
  })

  it('keeps a bubble near the right edge inside the viewport, arrow on the trigger', () => {
    anchorRect = { left: 1360, top: 500, width: 40, height: 20 }
    const { bubble, content } = hover({ 'data-pr-tooltip': 'Remove', 'data-pr-position': 'top' })

    const x = 1440 - BUBBLE.width - TOOLTIP_MARGIN
    expect(bubble.style.transform).toBe(`translate3d(${x}px, ${500 - TOOLTIP_GAP - 30}px, 0)`)

    const arrow = content.lastElementChild as HTMLElement
    expect(arrow.style.left).toBe(`${1380 - x}px`)
  })

  it('flips to the other side when the requested one has no room', () => {
    anchorRect = { left: 700, top: 10, width: 40, height: 20 }
    const { bubble } = hover({ 'data-pr-tooltip': 'Remove', 'data-pr-position': 'top' })

    expect(bubble.dataset.side).toBe('bottom')
    expect(bubble.style.transform).toBe(`translate3d(570px, ${30 + TOOLTIP_GAP}px, 0)`)
  })

  it('follows its trigger when a container scrolls, and hides once the trigger leaves the viewport', () => {
    const { bubble } = hover({ 'data-pr-tooltip': 'Remove' })
    expect(bubble.style.transform).toBe('translate3d(570px, 462px, 0)')

    anchorRect = { left: 700, top: 300, width: 40, height: 20 }
    act(() => {
      fireEvent.scroll(window)
    })
    expect(bubble.style.transform).toBe('translate3d(570px, 262px, 0)')

    anchorRect = { left: 700, top: -200, width: 40, height: 20 }
    act(() => {
      fireEvent.scroll(window)
    })
    expect(document.querySelector('[role="tooltip"]')).toBeNull()
  })
})
