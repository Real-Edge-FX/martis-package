import { useEffect, useRef } from 'react'

/**
 * Shared history-lock plumbing for modals that should block the
 * browser's back button while they are visible.
 *
 * The DrawerShell already integrates with `history.pushState` so the
 * back button closes the drawer (and routes through the dirty-check
 * dialog when appropriate). Modals on top of that (ActionModal,
 * DeleteModal) must NOT dismiss on back — the user has to pick a
 * button — but they mustn't accidentally close the drawer underneath
 * either. The two pieces coordinate through this module:
 *
 *   • When a modal mounts it calls {@link useModalHistoryLock}.
 *     That hook pushes a sentinel state and installs a listener that
 *     re-pushes the sentinel whenever the user presses back → the
 *     modal stays open.
 *
 *   • While `modalLockCount > 0` the DrawerShell's popstate handler
 *     bails out so the drawer does not also consume the same event
 *     (which otherwise would close it as soon as the modal re-pushed).
 *
 *   • When the modal closes via UI it calls {@link suppressNextPop}
 *     and runs `history.back()` to remove its sentinel. The DrawerShell
 *     consumes the suppress flag and skips the subsequent pop so the
 *     drawer also stays put.
 */

let modalLockCount = 0
let suppressNextPopFlag = false

export function incrementModalLock(): void {
  modalLockCount += 1
}

export function decrementModalLock(): void {
  modalLockCount = Math.max(0, modalLockCount - 1)
}

export function getModalLockCount(): number {
  return modalLockCount
}

export function suppressNextPop(): void {
  suppressNextPopFlag = true
}

export function consumeSuppressFlag(): boolean {
  if (suppressNextPopFlag) {
    suppressNextPopFlag = false
    return true
  }
  return false
}

/**
 * Install a history lock for a modal. While `open === true` the
 * browser back button is absorbed — the user must close the modal
 * through an explicit UI action (cancel, confirm, close button, ESC,
 * backdrop click).
 */
export function useModalHistoryLock(open: boolean): void {
  const markerRef = useRef<{ martisModalLock: true; key: number } | null>(null)

  useEffect(() => {
    if (!open) return
    if (typeof window === 'undefined') return

    incrementModalLock()
    const marker = { martisModalLock: true as const, key: Date.now() + Math.random() }
    markerRef.current = marker
    try {
      window.history.pushState(marker, '')
    } catch {
      decrementModalLock()
      return
    }

    const onPop = () => {
      // User pressed back → re-push our sentinel so the modal stays
      // on screen. The user has to pick a button.
      try {
        window.history.pushState(marker, '')
      } catch {
        /* ignore */
      }
    }
    window.addEventListener('popstate', onPop)

    return () => {
      window.removeEventListener('popstate', onPop)
      decrementModalLock()
      // Pop our sentinel off the history stack so the URL state is
      // clean after the modal closes. Tell the DrawerShell listener
      // to skip this particular popstate event.
      suppressNextPop()
      try {
        window.history.back()
      } catch {
        /* ignore */
      }
    }
  }, [open])
}
