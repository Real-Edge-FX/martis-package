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
let drawerBackPending = false

/**
 * State for a sentinel pushed on top of the current entry: the given flags
 * plus the router's own `usr` (location state) and `key` of that entry, so
 * React Router reads the same location when a pop lands on the sentinel
 * (a modal closing over a drawer pops back onto the drawer's sentinel). The
 * router's `idx` is left out: the sentinel is not one of its entries, and
 * an index it shares with the entry below would read as a zero-step pop.
 */
export function sentinelState<T extends Record<string, unknown>>(flags: T): T & { usr?: unknown; key?: unknown } {
  let current: Record<string, unknown> | null = null
  try {
    const state: unknown = window.history.state
    current = state !== null && typeof state === 'object' ? (state as Record<string, unknown>) : null
  } catch {
    current = null
  }
  return {
    ...(current && 'usr' in current ? { usr: current.usr } : {}),
    ...(current && 'key' in current ? { key: current.key } : {}),
    ...flags,
  }
}

/**
 * Set while the back() a closed drawer issued to drop its sentinel has not
 * landed yet (cleared by the next popstate).
 */
export function markDrawerBackPending(): void {
  drawerBackPending = true
  window.addEventListener('popstate', () => { drawerBackPending = false }, { once: true })
  // A back() with no entry to go to fires no popstate: do not hold the
  // next close for good.
  setTimeout(() => { drawerBackPending = false }, 1000)
}

export function clearDrawerBackPending(): void {
  drawerBackPending = false
}

export function isDrawerBackPending(): boolean {
  return drawerBackPending
}

/**
 * Whether no modal holds the history and no sentinel pop is on its way:
 * the top entry is the page's (or a drawer's sentinel on it).
 */
export function historySettled(): boolean {
  if (modalLockCount > 0 || drawerBackPending) return false
  try {
    const state = window.history.state as { martisModalLock?: unknown; martisModalSoftLock?: unknown } | null
    return state?.martisModalLock !== true && state?.martisModalSoftLock !== true
  } catch {
    return true
  }
}

/** Resolves once {@link historySettled} holds, or after `timeoutMs`. */
export function waitForHistoryToSettle(timeoutMs = 2000): Promise<void> {
  return new Promise((resolve) => {
    const started = Date.now()
    const check = () => {
      if (historySettled() || Date.now() - started >= timeoutMs) {
        resolve()
        return
      }
      setTimeout(check, 25)
    }
    // Let the commit that closed a modal run its cleanup first.
    setTimeout(check, 0)
  })
}

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
  // The flag belongs to the pop the modal is about to cause: a drawer
  // mounted under the modal consumes it in its own popstate listener,
  // registered before this one. With no drawer, nothing would, and the
  // first Back of the next drawer opened would be swallowed; this
  // listener, run last on that pop, clears it.
  if (typeof window !== 'undefined') {
    window.addEventListener('popstate', () => {
      suppressNextPopFlag = false
    }, { once: true })
  }
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
  const markerRef = useRef<{ martisModalLock: true; martisKey: number } | null>(null)

  useEffect(() => {
    if (!open) return
    if (typeof window === 'undefined') return

    incrementModalLock()
    const marker = sentinelState({ martisModalLock: true as const, martisKey: Date.now() + Math.random() })
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
      // clean after the modal closes. Skip this if the user has
      // already navigated away (e.g. via blocker.proceed in the
      // unsaved-changes guard) — in that case the sentinel is buried
      // under the new page entry and popping here would land the user
      // back on the old page.
      const current = window.history.state as { martisModalLock?: true } | null
      if (current?.martisModalLock) {
        suppressNextPop()
        try {
          window.history.back()
        } catch {
          /* ignore */
        }
      }
    }
  }, [open])
}

/**
 * Soft history lock for modals that should intercept the first back
 * press to close themselves, while letting a second back press navigate
 * normally. Useful for non-destructive modals (rich-text editor,
 * previews, filter panels) where users expect back to mean "close the
 * overlay" rather than "exit the page".
 *
 * Unlike {@link useModalHistoryLock}, this hook does NOT re-push the
 * sentinel on popstate — it calls `onClose` and lets the user decide
 * whether to press back again.
 */
export function useModalHistoryBackToClose(open: boolean, onClose: () => void): void {
  // Latest onClose without re-running the effect when the caller passes
  // an inline arrow (common React pattern). Without this, every parent
  // re-render would tear down and re-install the popstate listener,
  // which races with the actual back press and can drop the event.
  const onCloseRef = useRef(onClose)
  onCloseRef.current = onClose

  useEffect(() => {
    if (!open) return
    if (typeof window === 'undefined') return

    incrementModalLock()
    const marker = sentinelState({ martisModalSoftLock: true as const, martisKey: Date.now() + Math.random() })
    try {
      window.history.pushState(marker, '')
    } catch {
      decrementModalLock()
      return
    }

    const onPop = () => {
      // First back press — browser already popped our sentinel. Tell
      // DrawerShell to ignore this pop (it happened because *we* pushed
      // the state) and close the modal via the UI handler.
      suppressNextPop()
      onCloseRef.current()
    }
    window.addEventListener('popstate', onPop)

    return () => {
      window.removeEventListener('popstate', onPop)
      decrementModalLock()
      // If the modal is closing via a UI action (not via back), pop the
      // sentinel we pushed so the URL state stays clean.
      if (window.history.state && (window.history.state as { martisModalSoftLock?: true }).martisModalSoftLock) {
        suppressNextPop()
        try {
          window.history.back()
        } catch {
          /* ignore */
        }
      }
    }
  }, [open])
}
