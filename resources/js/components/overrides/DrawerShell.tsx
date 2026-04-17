import { useState, useEffect, useCallback, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { useTranslation } from 'react-i18next'
import { ResourceIcon } from '@/components/ResourceIcon'

export interface DrawerShellProps {
  /** Title displayed in the header. */
  title: string
  /** Optional subtitle below the title. */
  subtitle?: string | null
  /** Optional Phosphor icon name to display next to the title. */
  icon?: string | null
  /** Optional icon color (CSS color value). Defaults to accent color. */
  iconColor?: string | null
  /** Initial width (default: '520px'). */
  width?: string
  /** Width when expanded (default: '800px'). */
  expandedWidth?: string
  /** Show expand/collapse button (default: true). */
  allowExpand?: boolean
  /** Show fullscreen button (default: true). */
  allowFullscreen?: boolean
  /** Show close button (default: true). */
  showCloseButton?: boolean
  /** 'right' (default) or 'left'. */
  position?: 'right' | 'left'
  /** Show dark backdrop (default: true). */
  backdrop?: boolean
  /** Called when the drawer closes. */
  onClose: () => void
  /** Footer content (buttons). */
  footer?: ReactNode
  /** Main content. */
  children: ReactNode
}

type DrawerState = 'normal' | 'expanded' | 'fullscreen'

export function DrawerShell({
  title,
  subtitle,
  icon,
  iconColor,
  width = '520px',
  expandedWidth = '800px',
  allowExpand = true,
  allowFullscreen = true,
  showCloseButton = true,
  position = 'right',
  backdrop = true,
  onClose,
  footer,
  children,
}: DrawerShellProps) {
  const { t: tMsg } = useTranslation('messages')
  const [visible, setVisible] = useState(false)
  const [state, setState] = useState<DrawerState>('normal')

  // Animate in on mount
  useEffect(() => {
    requestAnimationFrame(() => setVisible(true))
  }, [])

  const handleClose = useCallback(() => {
    setVisible(false)
    setTimeout(onClose, 200)
  }, [onClose])

  // Keyboard shortcuts
  useEffect(() => {
    function handleKey(e: KeyboardEvent) {
      if (e.key === 'Escape') handleClose()
    }
    document.addEventListener('keydown', handleKey)
    return () => document.removeEventListener('keydown', handleKey)
  }, [handleClose])

  function toggleExpand() {
    setState((s) => (s === 'expanded' ? 'normal' : 'expanded'))
  }

  function toggleFullscreen() {
    setState((s) => (s === 'fullscreen' ? 'normal' : 'fullscreen'))
  }

  // Resolve current width
  const currentWidth = state === 'fullscreen' ? '100vw' : state === 'expanded' ? expandedWidth : width

  // Slide direction
  const isRight = position === 'right'
  const translateHidden = isRight ? 'translateX(100%)' : 'translateX(-100%)'
  const translateVisible = 'translateX(0)'

  const content = (
    <div style={{ position: 'fixed', inset: 0, zIndex: 50 }} className={isRight ? 'flex justify-end' : 'flex justify-start'}>
      {/* Backdrop */}
      {backdrop && (
        <div
          className="absolute inset-0 transition-opacity duration-200"
          style={{
            backgroundColor: visible ? 'rgba(0,0,0,0.4)' : 'rgba(0,0,0,0)',
          }}
          onClick={handleClose}
        />
      )}

      {/* Drawer panel — width locked with flexBasis + flexShrink:0 so
          create / update / detail render at exactly the same size
          regardless of inner content (Trix, Tabs, etc.) or scrollbar
          behaviour that could otherwise let flex-shrink trim the panel. */}
      <div
        className="relative flex h-full flex-col shadow-xl transition-all duration-200 ease-out"
        style={{
          width: currentWidth,
          minWidth: currentWidth,
          maxWidth: '100vw',
          flex: `0 0 ${currentWidth}`,
          backgroundColor: 'var(--martis-card)',
          borderLeft: isRight ? '1px solid var(--martis-border)' : 'none',
          borderRight: isRight ? 'none' : '1px solid var(--martis-border)',
          transform: visible ? translateVisible : translateHidden,
        }}
      >
        {/* Header */}
        <div
          className="flex items-center justify-between border-b px-6 py-4"
          style={{ borderColor: 'var(--martis-border)' }}
        >
          {/* Icon + Title + Subtitle */}
          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-3">
              {icon && (
                <div
                  className="flex-shrink-0 flex items-center justify-center rounded-lg"
                  style={{
                    width: 36,
                    height: 36,
                    backgroundColor: iconColor ? `${iconColor}18` : 'var(--martis-surface)',
                    color: iconColor || 'var(--martis-accent)',
                  }}
                >
                  <ResourceIcon iconName={icon} size={20} />
                </div>
              )}
              <h2 className="min-w-0 flex-1 truncate text-lg font-semibold" style={{ color: 'var(--martis-text)' }}>
                {title}
              </h2>
            </div>
            {subtitle && (
              <p
                className="mt-0.5 truncate text-sm"
                style={{ color: 'var(--martis-text-muted)', marginLeft: icon ? 'calc(36px + 0.75rem)' : undefined }}
              >
                {subtitle}
              </p>
            )}
          </div>

          {/* Action buttons */}
          <div className="ml-4 flex items-center gap-1">
            {allowExpand && (
              <button
                type="button"
                onClick={toggleExpand}
                className="rounded-md p-1.5 transition-colors hover:bg-gray-100 dark:hover:bg-gray-800"
                style={{ color: 'var(--martis-text-muted)' }}
                data-pr-tooltip={state === 'expanded' ? tMsg('collapse', 'Collapse') : tMsg('expand', 'Expand')}
                data-pr-position="top"
              >
                {state === 'expanded' ? (
                  /* Arrow right to bar — collapse */
                  <svg width="18" height="18" viewBox="0 0 256 256" fill="currentColor"><path d="M224,40V216a8,8,0,0,1-16,0V40a8,8,0,0,1,16,0ZM189.66,133.66l-48,48a8,8,0,0,1-11.32-11.32L164.69,136H48a8,8,0,0,1,0-16H164.69L130.34,85.66a8,8,0,0,1,11.32-11.32l48,48A8,8,0,0,1,189.66,133.66Z"/></svg>
                ) : (
                  /* Arrow left to bar — expand */
                  <svg width="18" height="18" viewBox="0 0 256 256" fill="currentColor"><path d="M48,40V216a8,8,0,0,0,16,0V40a8,8,0,0,0-16,0ZM82.34,133.66l48,48a8,8,0,0,0,11.32-11.32L107.31,136H224a8,8,0,0,0,0-16H107.31l34.35-34.34a8,8,0,0,0-11.32-11.32l-48,48A8,8,0,0,0,82.34,133.66Z"/></svg>
                )}
              </button>
            )}
            {allowFullscreen && (
              <button
                type="button"
                onClick={toggleFullscreen}
                className="rounded-md p-1.5 transition-colors hover:bg-gray-100 dark:hover:bg-gray-800"
                style={{ color: 'var(--martis-text-muted)' }}
                data-pr-tooltip={state === 'fullscreen' ? tMsg('exit_fullscreen', 'Exit fullscreen') : tMsg('fullscreen', 'Fullscreen')}
                data-pr-position="top"
              >
                {state === 'fullscreen' ? (
                  /* Arrows in — exit fullscreen */
                  <svg width="18" height="18" viewBox="0 0 256 256" fill="currentColor"><path d="M148,96V48a8,8,0,0,1,16,0V88h40a8,8,0,0,1,0,16H156A8,8,0,0,1,148,96ZM100,148H52a8,8,0,0,0,0,16H92v40a8,8,0,0,0,16,0V156A8,8,0,0,0,100,148Zm56,0H148a8,8,0,0,0-8,8v48a8,8,0,0,0,16,0V168h40a8,8,0,0,0,0-16ZM100,96a8,8,0,0,0,8-8V48a8,8,0,0,0-16,0V88H52a8,8,0,0,0,0,16Z"/></svg>
                ) : (
                  /* Arrows out — fullscreen */
                  <svg width="18" height="18" viewBox="0 0 256 256" fill="currentColor"><path d="M208,32H48A16,16,0,0,0,32,48V208a16,16,0,0,0,16,16H208a16,16,0,0,0,16-16V48A16,16,0,0,0,208,32Zm0,176H48V48H208V208ZM80,96a8,8,0,0,0,8-8V72h16a8,8,0,0,0,0-16H88a8,8,0,0,0-8,8V80A8,8,0,0,0,80,96Zm96,64H160a8,8,0,0,0,0,16h16a8,8,0,0,0,8-8V152a8,8,0,0,0-16,0Z"/></svg>
                )}
              </button>
            )}
            {showCloseButton && (
              <button
                type="button"
                onClick={handleClose}
                className="rounded-md p-1.5 transition-colors hover:bg-gray-100 dark:hover:bg-gray-800"
                style={{ color: 'var(--martis-text-muted)' }}
                data-pr-tooltip={tMsg('close', 'Close')}
                data-pr-position="top"
              >
                <svg width="18" height="18" viewBox="0 0 256 256" fill="currentColor"><path d="M205.66,194.34a8,8,0,0,1-11.32,11.32L128,139.31,61.66,205.66a8,8,0,0,1-11.32-11.32L116.69,128,50.34,61.66A8,8,0,0,1,61.66,50.34L128,116.69l66.34-66.35a8,8,0,0,1,11.32,11.32L139.31,128Z"/></svg>
              </button>
            )}
          </div>
        </div>

        {/* Body — scrollable */}
        <div className="flex-1 overflow-y-auto">
          {children}
        </div>

        {/* Footer */}
        {footer && (
          <div
            className="flex items-center justify-end gap-3 border-t px-6 py-4"
            style={{
              borderColor: 'var(--martis-border)',
              backgroundColor: 'var(--martis-surface-alt)',
            }}
          >
            {footer}
          </div>
        )}
      </div>
    </div>
  )

  return createPortal(content, document.body)
}
