import { useCallback, useEffect, useRef, useState } from 'react'
import { useBlocker, useLocation } from 'react-router-dom'
import { UnsavedChangesDialog } from '@/components/UnsavedChangesDialog'
import { consumeSuppressFlag, getModalLockCount } from '@/lib/historyLock'
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

/** Flag on the history entry the guard pushes on top of a page with unsaved changes. */
const SENTINEL = 'martisUnsavedGuard'

type EntryState = Record<string, unknown> | null

/** The router entry the sentinel stands on: its `key` and `idx`. */
interface PageEntry {
  key: unknown
  idx: unknown
}

function currentEntry(): EntryState {
  const state: unknown = window.history.state
  return state !== null && typeof state === 'object' ? (state as Record<string, unknown>) : null
}

/**
 * The location key the router reads from a history entry: the router's own
 * entries carry it, the first entry of the tab has none and reads as
 * `'default'`.
 */
function entryKey(state: EntryState): unknown {
  return state?.key ?? 'default'
}

function isGuardSentinel(state: EntryState): boolean {
  return state?.[SENTINEL] === true
}

/** A sentinel pushed by a modal history lock or a drawer. */
function isForeignSentinel(state: EntryState): boolean {
  return state?.martisModalLock === true || state?.martisModalSoftLock === true || state?.martisDrawer === true
}

function isPageEntry(state: EntryState, page: PageEntry | null): boolean {
  if (page === null || isGuardSentinel(state) || isForeignSentinel(state)) return false
  return state?.key === page.key && state?.idx === page.idx
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
 *   2. **Browser back/forward** — a clean form leaves the history alone,
 *      so Back and Forward work as on any other page. Once the form has
 *      unsaved changes, the guard pushes a sentinel: a copy of the page's
 *      own history entry (same URL, same router `key` and `idx`, so the
 *      router's bookkeeping stays right) flagged as the guard's. Back from
 *      the sentinel lands on the page entry, which a **capture-phase**
 *      popstate listener recognises and keeps from React Router with
 *      `stopImmediatePropagation()`: with unsaved changes it pushes the
 *      sentinel again and asks, and a confirmed discard steps over the
 *      page with `history.go(-2)`; changes saved since then let the pop
 *      carry on to the previous page with one more `history.back()`. Every
 *      other pop belongs to the router (and to the modal or drawer that
 *      pushed it).
 *
 * The sentinel stays behind when the page is left with it on top (a save
 * that redirects, a confirmed in-app navigation). When Back returns to it,
 * the page adopts it as its own, so the next Back walks past the page in one
 * press instead of stopping on a copy of the same URL.
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
  // The key of the location the router shows: the sentinel only ever stands
  // on this page's own entry, never on one a pop is about to leave for.
  const locationKeyRef = useRef<unknown>(null)
  locationKeyRef.current = useLocation().key

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
  // it's `blocker.proceed`; for popstate flows it's `history.go(-2)`.
  // Unified here so the dialog only needs one handler.
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
  // Whether the guard's sentinel is on the stack, and the page entry it
  // stands on. Refs, so the arming effect and the popstate listener share
  // them across re-runs.
  const armedRef = useRef(false)
  const pageEntryRef = useRef<PageEntry | null>(null)

  const pushSentinel = useCallback((pageState: EntryState) => {
    try {
      window.history.pushState({ ...(pageState ?? {}), [SENTINEL]: true }, '')
      pageEntryRef.current = { key: pageState?.key, idx: pageState?.idx }
      armedRef.current = true
    } catch {
      // The browser refused the entry (Safari caps pushState calls): Back
      // leaves unguarded, in-app links are still blocked.
    }
  }, [])

  // A sentinel left on the stack by this page (it was left with the
  // sentinel on top) stands on the page's entry just below it.
  const adoptSentinel = useCallback(() => {
    const state = currentEntry()
    if (!isGuardSentinel(state) || entryKey(state) !== locationKeyRef.current) return
    pageEntryRef.current = { key: state?.key, idx: state?.idx }
    armedRef.current = true
  }, [])

  const arm = useCallback(() => {
    if (armedRef.current || !isDirty()) return
    const state = currentEntry()
    // A modal or a drawer holds the top entry; the pop that removes it
    // arms the guard (see the popstate listener).
    if (isForeignSentinel(state)) return
    if (entryKey(state) !== locationKeyRef.current) return
    if (isGuardSentinel(state)) {
      adoptSentinel()
      return
    }
    pushSentinel(state)
  }, [isDirty, adoptSentinel, pushSentinel])

  // The first change that makes the form dirty arms the guard.
  useEffect(() => {
    if (enabled) arm()
  }, [enabled, values, initialSnapshot, bypass, arm])

  useEffect(() => {
    if (!enabled) return
    if (typeof window === 'undefined') return

    adoptSentinel()

    const onPop = (e: PopStateEvent) => {
      // If a nested modal lock (e.g. an InlineCreateModal) just popped its
      // own sentinel on unmount, it set the shared suppress flag. Consume
      // it here so we don't mistake that cleanup-driven popstate for a
      // real user back press. The pop lands back on this page, so a form
      // that became dirty while the modal held the top entry arms now.
      if (consumeSuppressFlag()) {
        e.stopImmediatePropagation()
        arm()
        return
      }
      // A modal on top owns the back button: it re-pushes its sentinel.
      if (getModalLockCount() > 0) return

      const state = currentEntry()
      if (!armedRef.current || !isPageEntry(state, pageEntryRef.current)) {
        // Any other pop belongs to the router. One that lands on the
        // sentinel of this very page (Forward onto it) re-arms the guard.
        adoptSentinel()
        return
      }

      // Back from the sentinel: the router already shows this page, so
      // it must not see the pop.
      e.stopImmediatePropagation()
      armedRef.current = false

      if (!isDirty()) {
        // Nothing to lose (the changes were saved since): carry on to the
        // previous page, as the back press meant to. That pop is not the
        // page entry, so it reaches the router.
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
      pushSentinel(state)

      pendingConfirmRef.current = () => {
        // The stack now has [..., prev, page, sentinel] with index at
        // sentinel. We want to reach `prev`, so go(-2) atomically pops
        // both entries; the pop lands on `prev`, which the listener
        // hands to React Router as a regular navigation.
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
      // A sentinel still on the stack (the page was left through a link, a
      // save or a closed tab) stays there: popping it now would fire a
      // popstate the next page's router would take for a Back press. The
      // page adopts it if Back returns to it.
    }
  }, [enabled, isDirty, arm, adoptSentinel, pushSentinel])

  const markSaved = useCallback(() => {
    bypassRef.current = true
  }, [])

  const dialog = (
    <UnsavedChangesDialog
      open={dialogOpen}
      config={config}
      skipHistoryLock
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
