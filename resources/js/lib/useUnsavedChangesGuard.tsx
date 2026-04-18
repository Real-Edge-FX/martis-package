import { useCallback, useEffect, useRef, useState } from 'react'
import { useBlocker } from 'react-router-dom'
import { UnsavedChangesDialog } from '@/components/UnsavedChangesDialog'
import type { ResourceSchema, UnsavedChangesConfig } from '@/types'

interface Options {
  /** Current form values (updated every render). */
  values: Record<string, unknown>
  /**
   * Baseline JSON string to compare against. Pass `null` to temporarily
   * disable the guard (e.g. while the record is still loading).
   */
  initialSnapshot: string | null
  /** Resource schema — reads `schema.confirmUnsavedChanges` to decide behaviour. */
  schema: ResourceSchema | undefined
  /**
   * Set to `true` after a successful submit. Suppresses the guard for
   * the post-save redirect.
   */
  bypass?: boolean
}

interface Result {
  /** React node to render in the tree (the dialog itself). */
  dialog: React.ReactNode
  /** Call from submit success to skip the next navigation warning. */
  markSaved: () => void
}

/**
 * Unsaved-changes guard for full-page create/update routes.
 *
 * Two separate paths cooperate so the browser back button and in-app
 * router navigations all land in the same confirmation modal:
 *
 *   1. **In-app navigation** — `<Link>` clicks and imperative
 *      `navigate()` calls go through React Router's `useBlocker`. We
 *      deliberately skip `historyAction === 'POP'` so the blocker does
 *      NOT attempt to handle the back button itself (known flicker
 *      bug in v6: the URL updates before the block takes effect).
 *
 *   2. **Browser back/forward** — handled manually via a history
 *      sentinel pushed on mount and a **capture-phase** popstate
 *      listener that calls `stopImmediatePropagation()` so React
 *      Router's own listener never fires for that event. On cancel we
 *      simply push the sentinel again; on confirm we set a suppress
 *      flag and call `history.back()` one more time to actually reach
 *      the user's intended destination.
 *
 * `beforeunload` is intentionally NOT wired up. It produced a double
 * prompt (native browser dialog + our custom modal) whenever the
 * previous history entry lived outside the SPA — a common case when
 * the page is opened in a fresh tab or via direct URL.
 */
export function useUnsavedChangesGuard({
  values,
  initialSnapshot,
  schema,
  bypass,
}: Options): Result {
  const confirmRaw = schema?.confirmUnsavedChanges
  const enabled = confirmRaw !== false && confirmRaw !== undefined
  const config =
    confirmRaw && typeof confirmRaw === 'object'
      ? (confirmRaw as UnsavedChangesConfig)
      : null

  const valuesRef = useRef(values)
  valuesRef.current = values
  const snapshotRef = useRef(initialSnapshot)
  snapshotRef.current = initialSnapshot
  const bypassRef = useRef(!!bypass)
  bypassRef.current = !!bypass

  const isDirty = useCallback(() => {
    if (!enabled) return false
    if (bypassRef.current) return false
    if (snapshotRef.current === null) return false
    return JSON.stringify(valuesRef.current) !== snapshotRef.current
  }, [enabled])

  // ── In-app router navigation ──────────────────────────────────────
  // Ignore POP (back/forward) — those are handled by the popstate
  // listener below. Return true to block on real clicks/navigate() calls.
  const blocker = useBlocker(({ currentLocation, nextLocation, historyAction }) => {
    if (historyAction === 'POP') return false
    if (
      currentLocation.pathname === nextLocation.pathname &&
      currentLocation.search === nextLocation.search
    ) {
      return false
    }
    return isDirty()
  })

  const [dialogOpen, setDialogOpen] = useState(false)
  // Holds the action to run when the user confirms. For blocker flows
  // it's `blocker.proceed`; for popstate flows it's `history.back` + a
  // suppress flag. Unified here so the dialog only needs one handler.
  const pendingConfirmRef = useRef<(() => void) | null>(null)
  const pendingCancelRef = useRef<(() => void) | null>(null)

  // Pipe blocker state changes into the unified dialog.
  useEffect(() => {
    if (blocker.state === 'blocked') {
      pendingConfirmRef.current = () => blocker.proceed?.()
      pendingCancelRef.current = () => blocker.reset?.()
      setDialogOpen(true)
    }
  }, [blocker.state])

  // ── Browser back / forward ────────────────────────────────────────
  useEffect(() => {
    if (!enabled) return
    if (typeof window === 'undefined') return

    const marker = { martisUnsavedGuard: true, key: Date.now() + Math.random() }
    let sentinelOnStack = false
    let suppressNext = false

    const ensureSentinel = () => {
      try {
        window.history.pushState(marker, '')
        sentinelOnStack = true
      } catch {
        sentinelOnStack = false
      }
    }
    ensureSentinel()

    const onPop = (e: PopStateEvent) => {
      if (suppressNext) {
        // This is our own programmatic back() that runs after the user
        // confirmed the discard. Let React Router see it and process
        // the navigation normally — don't stop propagation.
        suppressNext = false
        sentinelOnStack = false
        return
      }

      // Prevent React Router (and anyone else) from reacting to this
      // pop — we are going to either cancel it (re-push) or let it
      // through only after the user confirms.
      e.stopImmediatePropagation()
      sentinelOnStack = false

      if (!isDirty()) {
        // Form is clean — user expects back to work. Emit one more
        // back() so we skip past our sentinel and actually reach the
        // previous URL. The second popstate lands in the branch above.
        suppressNext = true
        try {
          window.history.back()
        } catch {
          /* ignore */
        }
        return
      }

      // CRITICAL: re-arm the back button IMMEDIATELY so any subsequent
      // back presses while the dialog is visible still land on us. If
      // we only re-armed on cancel, a user pressing back twice in a
      // row would escape the modal (first back pops our sentinel, the
      // dialog opens; second back pops the real page entry).
      ensureSentinel()

      pendingConfirmRef.current = () => {
        // The stack now has [..., prev, page, sentinel] with index at
        // sentinel. We want to reach `prev`, so go(-2) atomically pops
        // both entries. Suppress the resulting popstate so React
        // Router processes it as a regular navigation (our listener
        // lets it through unobstructed).
        suppressNext = true
        try {
          window.history.go(-2)
        } catch {
          try {
            window.history.back()
          } catch {
            /* ignore */
          }
        }
      }
      // No cancel action needed — the sentinel was already re-armed
      // above, so the user's next back press stays gated.
      pendingCancelRef.current = null
      setDialogOpen(true)
    }

    // Capture phase ensures we run BEFORE React Router's own popstate
    // handler. Combined with stopImmediatePropagation this keeps the
    // router's location fully in sync with the visible URL — no flicker.
    window.addEventListener('popstate', onPop, { capture: true })

    return () => {
      window.removeEventListener('popstate', onPop, { capture: true })
      // If the sentinel is still on the stack (user left via Link /
      // save / page close), leave it there: popping it now would fire
      // a popstate that no-one is listening for, and could confuse the
      // next page's React Router navigation. The stale entry harmlessly
      // lives on the tab's history stack — user's "back" will pass
      // through it as a no-op duplicate URL before reaching the real
      // previous entry.
      void sentinelOnStack
    }
  }, [enabled, isDirty])

  const markSaved = useCallback(() => {
    bypassRef.current = true
  }, [])

  const dialog = (
    <UnsavedChangesDialog
      open={dialogOpen}
      config={config}
      onCancel={() => {
        setDialogOpen(false)
        const run = pendingCancelRef.current
        pendingCancelRef.current = null
        pendingConfirmRef.current = null
        run?.()
      }}
      onConfirm={() => {
        setDialogOpen(false)
        const run = pendingConfirmRef.current
        pendingCancelRef.current = null
        pendingConfirmRef.current = null
        run?.()
      }}
    />
  )

  return { dialog, markSaved }
}
