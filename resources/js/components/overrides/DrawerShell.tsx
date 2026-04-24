import { useState, useEffect, useCallback, useRef, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { useTranslation } from 'react-i18next'
import { ResourceIcon } from '@/components/ResourceIcon'
import { consumeSuppressFlag, getModalLockCount } from '@/lib/historyLock'
import { config } from '@/lib/config'

export interface DrawerShellProps {
  /** Title displayed in the header. */
  title: string
  /** Optional subtitle below the title. */
  subtitle?: string | null
  /** Optional Phosphor icon name to display next to the title. */
  icon?: string | null
  /** Optional icon color (CSS color value). Defaults to accent color. */
  iconColor?: string | null
  /** Initial width (default: '720px', inherits `config('martis.drawer.width')`). */
  width?: string
  /** Width when expanded (default: '960px', inherits `config('martis.drawer.expanded_width')`). */
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
  /**
   * Guard fired when the drawer is about to close. When provided and it
   * returns `false` (or a Promise resolving to `false`), the close is
   * cancelled. Used by create/update drawers to show an "unsaved changes"
   * confirmation before discarding user input.
   */
  beforeClose?: () => boolean | Promise<boolean>
}

type DrawerState = 'normal' | 'expanded' | 'fullscreen'

// Module-level counter of drawers currently mounted. Used on unmount to
// decide whether to roll back the history sentinel: if another drawer has
// already taken our place (swap flow like detail → update), we must NOT
// `history.back()` — doing so would emit a popstate that closes the new
// drawer as soon as it opens.
let martisDrawerMountedCount = 0

export function DrawerShell({
  title,
  subtitle,
  icon,
  iconColor,
  width = '720px',
  expandedWidth = '960px',
  allowExpand = true,
  allowFullscreen = true,
  showCloseButton = true,
  position = 'right',
  backdrop = true,
  onClose,
  footer,
  children,
  beforeClose,
}: DrawerShellProps) {
  // F7-13 — global gate. When `config.drawer.expandable === false`, force
  // both buttons off so an app that locks the drawer to a single width
  // doesn't need to audit every `DrawerOverride` caller.
  const expandableGate = config.drawer?.expandable !== false
  const canExpand = allowExpand && expandableGate
  const canFullscreen = allowFullscreen && expandableGate
  const { t: tMsg } = useTranslation('messages')
  const [visible, setVisible] = useState(false)
  const [state, setState] = useState<DrawerState>('normal')

  // Animate in on mount
  useEffect(() => {
    requestAnimationFrame(() => setVisible(true))
  }, [])

  // Guard re-entrancy on close (e.g. double-clicking the X, or a popstate
  // firing while the dirty-check dialog is already open).
  const closingRef = useRef(false)

  const runClose = useCallback(() => {
    if (closingRef.current) return
    closingRef.current = true
    setVisible(false)
    setTimeout(onClose, 200)
  }, [onClose])

  // Keep a live reference to the latest beforeClose callback. The popstate
  // listener is registered only once (empty deps), so reading the prop
  // directly would capture a stale closure and miss the current dirty
  // state when the back button fires.
  const beforeCloseRef = useRef<(() => boolean | Promise<boolean>) | undefined>(beforeClose)
  useEffect(() => {
    beforeCloseRef.current = beforeClose
  }, [beforeClose])

  const handleClose = useCallback(async () => {
    const guard = beforeCloseRef.current
    if (guard) {
      const ok = await guard()
      if (!ok) return
    }
    runClose()
  }, [runClose])

  // ⭐ Camada A — integrate with browser history so the back button
  // closes the drawer instead of navigating away while the drawer is
  // still open. Pushing a sentinel state on mount means the first
  // popstate belongs to us; we then consume it by running the normal
  // close path (which, through beforeCloseRef, still honours the
  // dirty-check).
  useEffect(() => {
    martisDrawerMountedCount += 1
    const marker = { martisDrawer: true, key: Date.now() }
    try {
      window.history.pushState(marker, '')
    } catch {
      martisDrawerMountedCount -= 1
      return
    }

    // Track whether WE popped our own entry (clean close) vs. the
    // browser did (user hit back). On a browser pop we don't need
    // to history.back() again on unmount — the entry is already gone.
    let browserPopped = false
    // Skip the NEXT popstate — used when we intentionally call
    // history.go(-2) after the user confirms discard, so neither our
    // own listener nor React Router tries to reinterpret it.
    let skipNextPop = false

    const onPop = () => {
      if (skipNextPop) {
        skipNextPop = false
        return
      }
      // A modal on top of the drawer is handling its own back press
      // (it re-pushes its sentinel inside its own listener). Ignoring
      // this popstate prevents the drawer from closing underneath.
      if (getModalLockCount() > 0) return
      // A modal just closed and silently popped its own sentinel;
      // consume the suppress flag so the drawer stays put.
      if (consumeSuppressFlag()) return

      // Re-arm the sentinel IMMEDIATELY so any subsequent back press
      // while the dirty-check dialog is awaiting the user's decision
      // still lands on us instead of popping the real drawer entry
      // and escaping. If the guard resolves clean (no dirty), we pop
      // this re-armed sentinel + the real entry together below.
      try {
        window.history.pushState(marker, '')
      } catch {
        /* ignore */
      }
      browserPopped = false

      void (async () => {
        const guard = beforeCloseRef.current
        let ok = true
        if (guard) ok = await guard()
        if (!ok) {
          // User cancelled — sentinel already in place, stay put.
          return
        }
        // User confirmed discard (or no guard). The drawer's sentinel
        // shares the parent URL, so a single back() pops the re-armed
        // entry and lands the user back on the parent page. runClose
        // takes care of the visual drawer removal.
        browserPopped = true
        skipNextPop = true
        try {
          window.history.back()
        } catch {
          /* ignore */
        }
        runClose()
      })()
    }
    window.addEventListener('popstate', onPop)

    return () => {
      window.removeEventListener('popstate', onPop)
      martisDrawerMountedCount -= 1
      // If the drawer closed via UI (X, submit, etc.) our sentinel
      // entry is still on top of the history stack — remove it so the
      // user's next Back press goes to wherever they were before the
      // drawer opened, not to the drawer's URL twin. BUT: during a
      // drawer-to-drawer swap (detail → update), the new DrawerShell
      // mounts on the same render pass and would receive the popstate
      // we emit here, which closes it immediately. Defer the back()
      // via a microtask and skip it if another drawer is now mounted.
      //
      // Also skip the back() if history.state no longer points at our
      // sentinel — this means the user navigated FORWARD via a click
      // inside the drawer (e.g. the HasMany "Criar" button routes to
      // /resources/{related}/create). In that case React Router
      // already pushed a new entry on top of our sentinel; backing up
      // would ping-pong the user right back into the drawer page.
      if (!browserPopped) {
        const currentStateIsOurs = (() => {
          try {
            const s = window.history.state as { martisDrawer?: boolean } | null
            return s?.martisDrawer === true
          } catch {
            return false
          }
        })()
        if (!currentStateIsOurs) return
        queueMicrotask(() => {
          if (martisDrawerMountedCount === 0) {
            try {
              window.history.back()
            } catch {
              // ignore
            }
          }
        })
      }
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  // Keyboard shortcuts
  useEffect(() => {
    function handleKey(e: KeyboardEvent) {
      if (e.key === 'Escape') void handleClose()
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
          onClick={() => void handleClose()}
        />
      )}

      {/* Drawer panel — width locked with flexBasis + flexShrink:0 so
          create / update / detail render at exactly the same size
          regardless of inner content (Trix, Tabs, etc.) or scrollbar
          behaviour that could otherwise let flex-shrink trim the panel. */}
      <div
        className="martis-drawer-shell relative flex h-full flex-col transition-all duration-200 ease-out"
        style={{
          width: currentWidth,
          minWidth: currentWidth,
          maxWidth: '100vw',
          flex: `0 0 ${currentWidth}`,
          borderLeft: isRight ? '1px solid var(--martis-border)' : 'none',
          borderRight: isRight ? 'none' : '1px solid var(--martis-border)',
          transform: visible ? translateVisible : translateHidden,
        }}
      >
        {/* Header — spec: 16×20 padding, 72px min-height, 12px gap. */}
        <div className="martis-drawer-head">
          {icon && (
            <div
              className="martis-drawer-icon"
              style={
                iconColor
                  ? {
                      background: `color-mix(in srgb, ${iconColor} 14%, transparent)`,
                      color: iconColor,
                    }
                  : undefined
              }
            >
              <ResourceIcon iconName={icon} size={20} />
            </div>
          )}
          <div className="martis-drawer-head-main">
            <div className="martis-drawer-head-row">
              <h2 className="martis-drawer-title">{title}</h2>
            </div>
            {subtitle && <p className="martis-drawer-subtitle">{subtitle}</p>}
          </div>

          {/* Action buttons */}
          <div className="martis-drawer-actions">
            {canExpand && (
              <button
                type="button"
                onClick={toggleExpand}
                className="rounded-md p-1.5 transition-colors hover:bg-gray-100 dark:hover:bg-gray-800"
                style={{ color: 'var(--martis-text-muted)' }}
                data-pr-tooltip={state === 'expanded' ? tMsg('collapse', 'Collapse') : tMsg('expand', 'Expand')}
                data-pr-position="bottom"
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
            {canFullscreen && (
              <button
                type="button"
                onClick={toggleFullscreen}
                className="rounded-md p-1.5 transition-colors hover:bg-gray-100 dark:hover:bg-gray-800"
                style={{ color: 'var(--martis-text-muted)' }}
                data-pr-tooltip={state === 'fullscreen' ? tMsg('exit_fullscreen', 'Exit fullscreen') : tMsg('fullscreen', 'Fullscreen')}
                data-pr-position="bottom"
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
                onClick={() => void handleClose()}
                className="rounded-md p-1.5 transition-colors hover:bg-gray-100 dark:hover:bg-gray-800"
                style={{ color: 'var(--martis-text-muted)' }}
                data-pr-tooltip={tMsg('close', 'Close')}
                data-pr-position="bottom"
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

        {/* Footer — spec: 14×20 padding, surface-alt bg, border-top. */}
        {footer && (
          <div className="martis-drawer-foot">
            {footer}
          </div>
        )}
      </div>
    </div>
  )

  return createPortal(content, document.body)
}
