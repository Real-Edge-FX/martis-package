import { useState, useEffect, useCallback, useRef } from 'react'
import { createPortal } from 'react-dom'

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
  const [visible, setVisible] = useState(false)
  const [text, setText] = useState('')
  // When the trigger sets `data-pr-tooltip-html="true"` the content is rendered
  // via dangerouslySetInnerHTML so authors can use line breaks, bold, lists,
  // etc. Kept as a separate state so plain-text triggers stay safely escaped.
  const [isHtml, setIsHtml] = useState(false)
  const [position, setPosition] = useState<'top' | 'bottom' | 'left' | 'right'>('top')
  const [coords, setCoords] = useState({ x: 0, y: 0 })
  const showTimer = useRef<ReturnType<typeof setTimeout>>()
  const currentTarget = useRef<HTMLElement | null>(null)

  const show = useCallback((target: HTMLElement) => {
    const tooltipText = target.getAttribute('data-pr-tooltip')
    if (!tooltipText) return

    currentTarget.current = target
    const pos = (target.getAttribute('data-pr-position') as 'top' | 'bottom' | 'left' | 'right') || 'top'
    const htmlOptIn = target.getAttribute('data-pr-tooltip-html') === 'true'

    const rect = target.getBoundingClientRect()
    let x: number, y: number

    switch (pos) {
      case 'top':
        x = rect.left + rect.width / 2
        y = rect.top - 8
        break
      case 'bottom':
        x = rect.left + rect.width / 2
        y = rect.bottom + 8
        break
      case 'left':
        x = rect.left - 8
        y = rect.top + rect.height / 2
        break
      case 'right':
        x = rect.right + 8
        y = rect.top + rect.height / 2
        break
    }

    setText(tooltipText)
    setIsHtml(htmlOptIn)
    setPosition(pos)
    setCoords({ x, y })
    setVisible(true)
  }, [])

  const hide = useCallback(() => {
    clearTimeout(showTimer.current)
    currentTarget.current = null
    setVisible(false)
  }, [])

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

  if (!visible || !text) return null

  const arrowSize = 4
  const style: React.CSSProperties = {
    position: 'fixed',
    zIndex: 99999,
    pointerEvents: 'none',
    ...(position === 'top' && {
      left: coords.x,
      top: coords.y,
      transform: 'translate(-50%, -100%)',
    }),
    ...(position === 'bottom' && {
      left: coords.x,
      top: coords.y,
      transform: 'translate(-50%, 0)',
    }),
    ...(position === 'left' && {
      left: coords.x,
      top: coords.y,
      transform: 'translate(-100%, -50%)',
    }),
    ...(position === 'right' && {
      left: coords.x,
      top: coords.y,
      transform: 'translate(0, -50%)',
    }),
  }

  const arrowStyle: React.CSSProperties = {
    position: 'absolute',
    width: 0,
    height: 0,
    ...(position === 'top' && {
      bottom: -arrowSize,
      left: '50%',
      transform: 'translateX(-50%)',
      borderLeft: `${arrowSize}px solid transparent`,
      borderRight: `${arrowSize}px solid transparent`,
      borderTop: `${arrowSize}px solid var(--martis-tooltip-bg, var(--martis-text))`,
    }),
    ...(position === 'bottom' && {
      top: -arrowSize,
      left: '50%',
      transform: 'translateX(-50%)',
      borderLeft: `${arrowSize}px solid transparent`,
      borderRight: `${arrowSize}px solid transparent`,
      borderBottom: `${arrowSize}px solid var(--martis-tooltip-bg, var(--martis-text))`,
    }),
    ...(position === 'left' && {
      right: -arrowSize,
      top: '50%',
      transform: 'translateY(-50%)',
      borderTop: `${arrowSize}px solid transparent`,
      borderBottom: `${arrowSize}px solid transparent`,
      borderLeft: `${arrowSize}px solid var(--martis-tooltip-bg, var(--martis-text))`,
    }),
    ...(position === 'right' && {
      left: -arrowSize,
      top: '50%',
      transform: 'translateY(-50%)',
      borderTop: `${arrowSize}px solid transparent`,
      borderBottom: `${arrowSize}px solid transparent`,
      borderRight: `${arrowSize}px solid var(--martis-tooltip-bg, var(--martis-text))`,
    }),
  }

  return createPortal(
    <div style={style} role="tooltip">
      <div
        className="martis-tooltip-content"
        style={{
          backgroundColor: 'var(--martis-tooltip-bg, var(--martis-text))',
          color: 'var(--martis-tooltip-text, var(--martis-bg))',
          border: 'none',
          // HTML tooltips need more breathing room — bigger box, slightly
          // larger font, generous line-height — so multi-line explanations
          // read like a paragraph instead of a stacked column. Plain
          // tooltips stay tight (single line label, 11px).
          fontSize: isHtml ? '12px' : '11px',
          padding: isHtml ? '8px 12px' : '4px 8px',
          lineHeight: isHtml ? 1.45 : 1.2,
          borderRadius: '0.375rem',
          whiteSpace: isHtml ? 'normal' : 'nowrap',
          // The HTML variant typically wraps two or three lines of prose;
          // 360 keeps it readable without becoming a banner.
          maxWidth: isHtml ? 360 : 300,
          minWidth: isHtml ? 220 : undefined,
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
