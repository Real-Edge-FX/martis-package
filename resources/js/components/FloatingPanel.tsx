import { forwardRef, useCallback, useImperativeHandle, useLayoutEffect, useRef, type HTMLAttributes, type RefObject } from 'react'
import { createPortal } from 'react-dom'
import { computeFloatingPlacement } from '@/lib/floatingPlacement'

/** Whether an ancestor that clips its overflow hides the whole anchor. */
function isClippedOut(anchor: HTMLElement, rect: DOMRect): boolean {
  for (let el = anchor.parentElement; el && el !== document.body; el = el.parentElement) {
    const style = window.getComputedStyle(el)
    if (style.overflowX === 'visible' && style.overflowY === 'visible') continue
    const box = el.getBoundingClientRect()
    // No box (`display: contents`, or not laid out): nothing to clip against.
    if (box.width === 0 && box.height === 0) continue
    if (rect.bottom <= box.top || rect.top >= box.bottom || rect.right <= box.left || rect.left >= box.right) return true
  }
  return false
}

export interface FloatingPanelProps extends HTMLAttributes<HTMLDivElement> {
  /** The element the panel opens from (the picker's trigger or its wrapper). */
  anchorRef: RefObject<HTMLElement | null>
  /** Give the panel the anchor's width (the default). False lets its content size it. */
  matchWidth?: boolean
  /** Minimum width in pixels. */
  minWidth?: number
  /** Maximum height in pixels; the room left in the viewport caps it further. */
  maxHeight?: number
  /** Line the panel up with the anchor's left edge (`start`) or right edge (`end`). */
  align?: 'start' | 'end'
}

/**
 * A dropdown panel rendered in `document.body` with `position: fixed`.
 *
 * A panel positioned `absolute` inside its picker is cut by any ancestor
 * that scrolls or hides overflow (a modal body, a drawer, a card), so the
 * user has to scroll the container to reach the options. This panel floats
 * above every container instead: it follows its anchor while anything
 * scrolls or the window resizes, flips above the anchor when there is no
 * room below, and sits over the modals (`.martis-floating-panel`).
 *
 * The panel is no longer inside the picker's DOM, so a picker that closes
 * on an outside click must also treat a click inside the panel (the ref
 * this component forwards) as inside.
 */
export const FloatingPanel = forwardRef<HTMLDivElement, FloatingPanelProps>(function FloatingPanel(
  { anchorRef, matchWidth = true, minWidth, maxHeight, align = 'start', className, style, children, ...rest },
  ref,
) {
  const panelRef = useRef<HTMLDivElement>(null)
  useImperativeHandle(ref, () => panelRef.current as HTMLDivElement)

  // Writes the position straight to the element: it runs on every scroll
  // event, and a render per event would be wasted.
  const update = useCallback(() => {
    const panel = panelRef.current
    const anchor = anchorRef.current
    if (!panel || !anchor) return

    const rect = anchor.getBoundingClientRect()
    if (matchWidth) panel.style.width = `${Math.max(rect.width, minWidth ?? 0)}px`
    // Measure the height the panel wants before the room left caps it.
    panel.style.maxHeight = maxHeight !== undefined ? `${maxHeight}px` : 'none'

    const placement = computeFloatingPlacement({
      anchor: rect,
      panelHeight: panel.offsetHeight,
      panelWidth: panel.offsetWidth,
      viewportWidth: window.innerWidth,
      viewportHeight: window.innerHeight,
      align,
    })

    // An anchor scrolled out of its container (under a modal's footer)
    // takes its panel along: it shows again when the anchor scrolls back.
    const hidden = isClippedOut(anchor, rect)
    panel.style.visibility = hidden ? 'hidden' : ''
    panel.style.pointerEvents = hidden ? 'none' : ''

    panel.style.top = placement.top !== undefined ? `${placement.top}px` : 'auto'
    panel.style.bottom = placement.bottom !== undefined ? `${placement.bottom}px` : 'auto'
    panel.style.left = `${placement.left}px`
    panel.style.maxHeight = `${Math.min(placement.maxHeight, maxHeight ?? Infinity)}px`
    panel.dataset.side = placement.side
  }, [anchorRef, matchWidth, minWidth, maxHeight, align])

  useLayoutEffect(() => {
    update()

    // Capture: a scroll of any ancestor (a modal body, a drawer) moves the anchor.
    function onScroll(e: Event) {
      if (panelRef.current && e.target instanceof Node && panelRef.current.contains(e.target)) return
      update()
    }
    window.addEventListener('scroll', onScroll, true)
    window.addEventListener('resize', update)

    // Options loading or a search narrowing them changes the panel's height.
    let observer: ResizeObserver | null = null
    if (typeof ResizeObserver !== 'undefined') {
      observer = new ResizeObserver(() => update())
      if (panelRef.current) observer.observe(panelRef.current)
      if (anchorRef.current) observer.observe(anchorRef.current)
    }

    return () => {
      window.removeEventListener('scroll', onScroll, true)
      window.removeEventListener('resize', update)
      observer?.disconnect()
    }
  }, [update, anchorRef])

  return createPortal(
    <div
      {...rest}
      ref={panelRef}
      data-martis-floating-panel=""
      className={className ? `martis-floating-panel ${className}` : 'martis-floating-panel'}
      style={{ ...style, minWidth }}
    >
      {children}
    </div>,
    document.body,
  )
})
