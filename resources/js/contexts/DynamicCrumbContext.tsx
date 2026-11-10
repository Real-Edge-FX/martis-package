import { createContext, useCallback, useContext, useLayoutEffect, useState, type ReactNode } from 'react'

/**
 * DynamicCrumbContext — runtime override for the breadcrumb of the
 * currently-active route.
 *
 * Routes whose final segment cannot be statically named (e.g. `/tools/:uriKey`,
 * any record-detail page) declare `handle.crumb` as a static i18n key just to
 * mark themselves as breadcrumb-eligible, then publish their real label at
 * render-time via `useDynamicCrumb(label)`.
 *
 * Breadcrumbs.tsx prefers the dynamic label over the static i18n key for the
 * deepest match in the route tree. The static key is the fallback when the
 * page publishes no label. While the label is still loading, a page passes
 * `{ pending: true }`, and a route whose static key would name the wrong
 * thing declares `handle.crumbPending`; the crumb is then an empty
 * placeholder until the label arrives (v2.19.0).
 *
 * v1.10.2: introduced to fix the Tools breadcrumb showing the literal string
 * "tool" instead of the actual tool name (e.g. "Charts").
 */
type DynamicCrumbState = {
  label: string | null
  /** A page said its label is still being resolved (v2.19.0). */
  pending: boolean
  /** A page has published its label (or its pending state) since mounting. */
  settled: boolean
  publish: (label: string | null, pending: boolean) => void
  reset: () => void
}

/**
 * What Breadcrumbs.tsx reads: the published label, and whether the deepest
 * crumb should show a neutral placeholder instead of its static label.
 */
export interface DynamicCrumbSnapshot {
  label: string | null
  pending: boolean
  settled: boolean
}

const DynamicCrumbContext = createContext<DynamicCrumbState | null>(null)

export function DynamicCrumbProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<DynamicCrumbSnapshot>({ label: null, pending: false, settled: false })
  const publish = useCallback(
    (label: string | null, pending: boolean) => setState({ label, pending, settled: true }),
    [],
  )
  const reset = useCallback(() => setState({ label: null, pending: false, settled: false }), [])

  return (
    <DynamicCrumbContext.Provider value={{ ...state, publish, reset }}>
      {children}
    </DynamicCrumbContext.Provider>
  )
}

/**
 * Read-side: Breadcrumbs.tsx uses this to override the deepest crumb.
 * Returns `null` when no page has set a dynamic label.
 */
export function useDynamicCrumbLabel(): string | null {
  const ctx = useContext(DynamicCrumbContext)
  return ctx?.label ?? null
}

/** Read-side with the pending state (v2.19.0): see `useDynamicCrumb`. */
export function useDynamicCrumbState(): DynamicCrumbSnapshot {
  const ctx = useContext(DynamicCrumbContext)
  return { label: ctx?.label ?? null, pending: ctx?.pending ?? false, settled: ctx?.settled ?? false }
}

export interface DynamicCrumbOptions {
  /**
   * The label is not known yet (its data is still loading). The deepest
   * crumb then shows an empty placeholder instead of the route's static
   * label, which would name the wrong thing, e.g. "Dashboard" for a
   * dashboard called "Team" (v2.19.0).
   */
  pending?: boolean
}

/**
 * Write-side: pages call this with their resolved title so the breadcrumb
 * updates in lock-step with the page heading. Pass `null` to fall back to
 * the static i18n key from the route handle, or `{ pending: true }` while
 * the label is still loading to show a neutral placeholder instead.
 *
 * Published in a layout effect, so a label known on the first render is in
 * the breadcrumb on the first paint.
 *
 * The hook resets on unmount, so navigating away from a tool that had set
 * a dynamic label doesn't leak that label into the next page's breadcrumb.
 */
export function useDynamicCrumb(label: string | null | undefined, options: DynamicCrumbOptions = {}): void {
  const ctx = useContext(DynamicCrumbContext)
  const publish = ctx?.publish
  const reset = ctx?.reset
  const next = label !== undefined && label !== null && label.trim() !== '' ? label : null
  // A label that is known wins over a pending flag.
  const pending = next === null && options.pending === true

  useLayoutEffect(() => {
    if (publish === undefined || reset === undefined) return
    publish(next, pending)
    return () => reset()
  }, [next, pending, publish, reset])
}
