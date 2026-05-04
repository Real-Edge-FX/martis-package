import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'

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
 * deepest match in the route tree. The static key is the fallback while the
 * page is still loading or when the descriptor never resolves.
 *
 * v1.10.2: introduced to fix the Tools breadcrumb showing the literal string
 * "tool" instead of the actual tool name (e.g. "Charts").
 */
type DynamicCrumbState = {
  label: string | null
  setLabel: (label: string | null) => void
}

const DynamicCrumbContext = createContext<DynamicCrumbState | null>(null)

export function DynamicCrumbProvider({ children }: { children: ReactNode }) {
  const [label, setLabelState] = useState<string | null>(null)
  const setLabel = useCallback((next: string | null) => setLabelState(next), [])

  return (
    <DynamicCrumbContext.Provider value={{ label, setLabel }}>
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

/**
 * Write-side: pages call this with their resolved title so the breadcrumb
 * updates in lock-step with the page heading. Pass `null` to fall back to
 * the static i18n key from the route handle.
 *
 * The hook resets to `null` on unmount, so navigating away from a tool that
 * had set a dynamic label doesn't leak that label into the next page's
 * breadcrumb.
 */
export function useDynamicCrumb(label: string | null | undefined): void {
  const ctx = useContext(DynamicCrumbContext)
  const setLabel = ctx?.setLabel
  const next = label !== undefined && label !== null && label.trim() !== '' ? label : null

  useEffect(() => {
    if (setLabel === undefined) return
    setLabel(next)
    return () => setLabel(null)
  }, [next, setLabel])
}
