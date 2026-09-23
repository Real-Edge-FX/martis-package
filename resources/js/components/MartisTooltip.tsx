import { useState, useEffect, useLayoutEffect, useCallback, useRef } from 'react'
import { createPortal } from 'react-dom'
import {
  computeTooltipPlacement,
  isTooltipSide,
  TOOLTIP_MARGIN,
  type TooltipPlacement,
  type TooltipSide,
} from '@/lib/tooltipPlacement'

interface ActiveTooltip {
  text: string
  // When the trigger sets `data-pr-tooltip-html="true"` the content is
  // rendered via dangerouslySetInnerHTML so authors can use line breaks,
  // bold, lists, etc. Plain-text triggers stay safely escaped.
  isHtml: boolean
  preferred: TooltipSide
}

function viewportSize() {
  return {
    width: document.documentElement.clientWidth || window.innerWidth,
    height: document.documentElement.clientHeight || window.innerHeight,
  }
}

/**
 * Global tooltip provider using event delegation.
 *
 * Listens for mouseenter/mouseleave on any element with [data-pr-tooltip]
 * via a single document-level listener. This avoids the PrimeReact Tooltip
 * target-selector approach which loses bindings on re-renders and fails
 * when hovering quickly between adjacent items.
 *
 * Renders a lightweight tooltip div styled to match the Martis design system.
 */
export function MartisTooltip() {
  const [tip, setTip] = useState<ActiveTooltip | null>(null)
  // Null while the bubble is laid out but not yet measured (it renders
  // hidden at the viewport origin for that one pass).
  const [placement, setPlacement] = useState<TooltipPlacement | null>(null)
  const bubbleRef = useRef<HTMLDivElement>(null)
  const showTimer = useRef<ReturnType<typeof setTimeout>>()
  const currentTarget = useRef<HTMLElement | null>(null)

  const show = useCallback((target: HTMLElement) => {
    const tooltipText = target.getAttribute('data-pr-tooltip')
    if (!tooltipText) return

    currentTarget.current = target
    const requested = target.getAttribute('data-pr-position')

    setTip({
      text: tooltipText,
      isHtml: target.getAttribute('data-pr-tooltip-html') === 'true',
      preferred: isTooltipSide(requested) ? requested : 'top',
    })
    setPlacement(null)
  }, [])

  const hide = useCallback(() => {
    clearTimeout(showTimer.current)
    currentTarget.current = null
    setTip(null)
    setPlacement(null)
  }, [])

  // Measure the laid-out bubble and move it next to its trigger. The
  // bubble sits at the viewport origin (left/top 0) and is positioned by
  // a transform, so layout always gives it the full viewport width to
  // shrink-to-fit against: its width no longer depends on how close the
  // trigger is to an edge. `computeTooltipPlacement()` flips the side
  // when the requested one has no room and clamps the bubble inside the
  // viewport.
  const place = useCallback(() => {
    const target = currentTarget.current
    const bubble = bubbleRef.current
    if (!tip || !target || !bubble) return

    const anchor = target.getBoundingClientRect()
    const viewport = viewportSize()

    // The trigger scrolled out of view: nothing left to point at.
    if (anchor.bottom < 0 || anchor.top > viewport.height || anchor.right < 0 || anchor.left > viewport.width) {
      hide()
      return
    }

    const size = bubble.getBoundingClientRect()
    const next = computeTooltipPlacement(anchor, { width: size.width, height: size.height }, viewport, tip.preferred)
    // Scroll and resize re-place on every frame; skip the render when
    // nothing moved.
    setPlacement((current) =>
      current
      && current.side === next.side
      && current.x === next.x
      && current.y === next.y
      && current.arrow === next.arrow
        ? current
        : next,
    )
  }, [tip, hide])

  // Runs before paint, so the measuring pass is never visible.
  useLayoutEffect(() => {
    place()
  }, [place])

  // Follow the trigger while the tooltip is open: any scroll (captured,
  // so scrolling containers count too) or resize re-places the bubble on
  // the next frame instead of leaving it where the trigger used to be.
  useEffect(() => {
    if (!tip) return

    let frame = 0
    const schedule = () => {
      cancelAnimationFrame(frame)
      frame = requestAnimationFrame(place)
    }

    window.addEventListener('scroll', schedule, true)
    window.addEventListener('resize', schedule)

    return () => {
      cancelAnimationFrame(frame)
      window.removeEventListener('scroll', schedule, true)
      window.removeEventListener('resize', schedule)
    }
  }, [tip, place])

  useEffect(() => {
    const handleMouseEnter = (e: MouseEvent) => {
      const target = (e.target as HTMLElement).closest?.('[data-pr-tooltip]') as HTMLElement | null
      if (!target) return

      clearTimeout(showTimer.current)

      // If already showing for a different target, switch immediately
      if (currentTarget.current && currentTarget.current !== target) {
        show(target)
        return
      }

      // First hover: show with delay. 500 ms is long enough that skimming
      // over an icon doesn't flash the tooltip, and short enough that
      // intentional hover still feels responsive.
      showTimer.current = setTimeout(() => show(target), 500)
    }

    const handleMouseLeave = (e: MouseEvent) => {
      const target = (e.target as HTMLElement).closest?.('[data-pr-tooltip]') as HTMLElement | null
      if (!target) return

      // Check if the related target (where the mouse is going) is also a tooltip target
      const relatedTarget = (e.relatedTarget as HTMLElement)?.closest?.('[data-pr-tooltip]') as HTMLElement | null
      if (relatedTarget) {
        // Moving to another tooltip target — switch immediately
        show(relatedTarget)
        return
      }

      hide()
    }

    // Hide tooltip on any click (the target element may be removed from DOM)
    const handleMouseDown = () => {
      hide()
    }

    // Hide tooltip if the current target is removed from the DOM
    const observer = new MutationObserver(() => {
      if (currentTarget.current && !document.body.contains(currentTarget.current)) {
        hide()
      }
    })
    observer.observe(document.body, { childList: true, subtree: true })

    document.addEventListener('mouseenter', handleMouseEnter, true)
    document.addEventListener('mouseleave', handleMouseLeave, true)
    document.addEventListener('mousedown', handleMouseDown, true)

    return () => {
      document.removeEventListener('mouseenter', handleMouseEnter, true)
      document.removeEventListener('mouseleave', handleMouseLeave, true)
      document.removeEventListener('mousedown', handleMouseDown, true)
      observer.disconnect()
      clearTimeout(showTimer.current)
    }
  }, [show, hide])

  if (!tip) return null

  const { text, isHtml } = tip
  const side = placement?.side ?? tip.preferred
  const arrowSize = 4

  const style: React.CSSProperties = {
    position: 'fixed',
    left: 0,
    top: 0,
    // A percentage on a fixed box resolves against the viewport without its
    // scrollbar, the same width the placement clamps against (`100vw`
    // would count a classic scrollbar).
    maxWidth: `calc(100% - ${2 * TOOLTIP_MARGIN}px)`,
    zIndex: 99999,
    pointerEvents: 'none',
    ...(placement
      ? { transform: `translate3d(${placement.x}px, ${placement.y}px, 0)` }
      : { visibility: 'hidden' }),
  }

  // The arrow sits where the trigger's centre meets the bubble edge, which
  // is off-centre once the bubble was clamped against a viewport edge.
  const arrowOffset = placement ? `${placement.arrow}px` : '50%'

  const arrowStyle: React.CSSProperties = {
    position: 'absolute',
    width: 0,
    height: 0,
    ...(side === 'top' && {
      bottom: -arrowSize,
      left: arrowOffset,
      transform: 'translateX(-50%)',
      borderLeft: `${arrowSize}px solid transparent`,
      borderRight: `${arrowSize}px solid transparent`,
      borderTop: `${arrowSize}px solid var(--martis-tooltip-bg, var(--martis-text))`,
    }),
    ...(side === 'bottom' && {
      top: -arrowSize,
      left: arrowOffset,
      transform: 'translateX(-50%)',
      borderLeft: `${arrowSize}px solid transparent`,
      borderRight: `${arrowSize}px solid transparent`,
      borderBottom: `${arrowSize}px solid var(--martis-tooltip-bg, var(--martis-text))`,
    }),
    ...(side === 'left' && {
      right: -arrowSize,
      top: arrowOffset,
      transform: 'translateY(-50%)',
      borderTop: `${arrowSize}px solid transparent`,
      borderBottom: `${arrowSize}px solid transparent`,
      borderLeft: `${arrowSize}px solid var(--martis-tooltip-bg, var(--martis-text))`,
    }),
    ...(side === 'right' && {
      left: -arrowSize,
      top: arrowOffset,
      transform: 'translateY(-50%)',
      borderTop: `${arrowSize}px solid transparent`,
      borderBottom: `${arrowSize}px solid transparent`,
      borderRight: `${arrowSize}px solid var(--martis-tooltip-bg, var(--martis-text))`,
    }),
  }

  return createPortal(
    <div ref={bubbleRef} style={style} role="tooltip" data-side={side}>
      <div
        className="martis-tooltip-content"
        style={{
          backgroundColor: 'var(--martis-tooltip-bg, var(--martis-text))',
          color: 'var(--martis-tooltip-text, var(--martis-bg))',
          border: 'none',
          // HTML tooltips need more breathing room — bigger box, slightly
          // larger font, generous line-height — so multi-line explanations
          // read like a paragraph instead of a stacked column. Plain
          // tooltips stay tight (11px) and read as a one-line label while
          // they fit.
          fontSize: isHtml ? '12px' : '11px',
          padding: isHtml ? '8px 12px' : '4px 8px',
          lineHeight: isHtml ? 1.45 : 1.2,
          borderRadius: '0.375rem',
          // Both variants wrap: the box is shrink-to-fit, so a short label
          // stays on one line and a sentence breaks at the max width (360 px
          // here, the viewport minus the margins on the wrapper) instead of
          // running out of the bubble. `anywhere` also breaks a long
          // unbroken token (a URL, an id) inside the box.
          whiteSpace: 'normal',
          overflowWrap: 'anywhere',
          maxWidth: 360,
          minWidth: isHtml ? `min(220px, calc(100vw - ${2 * TOOLTIP_MARGIN}px))` : undefined,
          position: 'relative',
          boxShadow: '0 4px 6px -1px rgba(0, 0, 0, 0.12), 0 2px 4px -2px rgba(0, 0, 0, 0.08)',
        }}
      >
        {isHtml ? (
          <span dangerouslySetInnerHTML={{ __html: text }} />
        ) : (
          text
        )}
        <div style={arrowStyle} />
      </div>
    </div>,
    document.body,
  )
}
