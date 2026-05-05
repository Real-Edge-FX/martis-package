import { createContext, useCallback, useContext, useState, type ReactNode } from 'react'
import type { GateLock } from '@/types'

/**
 * GateContext — single source of truth for "is the gate modal open?"
 * and "what payload should it render?".
 *
 * The Sidebar opens it on click of a locked item; the per-page guard
 * (`<DashboardLockedView>` / `<ToolLockedView>`) opens it as the
 * default render when the API returns `{ locked: true, lock: ... }`.
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

  return (
    <GateContext.Provider value={{ isOpen: lock !== null, lock, open, close }}>
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
