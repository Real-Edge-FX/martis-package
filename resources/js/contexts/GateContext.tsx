import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { LOCKED_EVENT } from '@/lib/lockEvent'
import type { GateLock } from '@/types'

/**
 * GateContext — single source of truth for "is the gate modal open?"
 * and "what payload should it render?".
 *
 * The Sidebar opens it on click of a locked item; the per-page guard
 * (`<DashboardLockedView>` / `<ToolLockedView>`) opens it as the
 * default render when the API returns `{ locked: true, lock: ... }`, and
 * the API client opens it when a data endpoint answers 403 with that
 * payload (`LOCKED_EVENT`).
 *
 * The provider owns the open/closed state and the active payload.
 * The actual modal component (`<GateModal>`) reads from this context.
 *
 * v1.11.0+.
 */

type GateContextValue = {
  isOpen: boolean
  lock: GateLock | null
  open: (lock: GateLock) => void
  close: () => void
}

const GateContext = createContext<GateContextValue | null>(null)

export function GateProvider({ children }: { children: ReactNode }) {
  const [lock, setLock] = useState<GateLock | null>(null)

  const open = useCallback((next: GateLock) => setLock(next), [])
  const close = useCallback(() => setLock(null), [])

  // A data endpoint that answers 403 with a lock payload (a locked
  // resource's URL, a locked tool's route, v2.4.0) opens the same modal.
  useEffect(() => {
    const onLocked = (event: Event) => setLock((event as CustomEvent<GateLock>).detail)

    window.addEventListener(LOCKED_EVENT, onLocked)
    return () => window.removeEventListener(LOCKED_EVENT, onLocked)
  }, [])

  // One value per lock, not per render: a consumer effect that depends on
  // the gate (the Tool resolution opens it) must not re-run because the
  // provider re-rendered.
  const value = useMemo(() => ({ isOpen: lock !== null, lock, open, close }), [lock, open, close])

  return (
    <GateContext.Provider value={value}>
      {children}
    </GateContext.Provider>
  )
}

/**
 * Read-side hook — throws when used outside the provider so a
 * mistake at component boundaries fails loud.
 */
export function useGate(): GateContextValue {
  const ctx = useContext(GateContext)
  if (ctx === null) {
    throw new Error('useGate must be used within a <GateProvider>')
  }
  return ctx
}

/**
 * Optional variant — returns null when the provider is not mounted
 * (eg. in component tests that render a leaf component without the
 * full shell).
 */
export function useGateOptional(): GateContextValue | null {
  return useContext(GateContext)
}
