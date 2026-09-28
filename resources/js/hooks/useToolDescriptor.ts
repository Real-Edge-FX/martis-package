import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api, ApiError } from '@/lib/api'
import { useToast } from '@/contexts/ToastContext'
import { useGateOptional } from '@/contexts/GateContext'
import type { LockedToolResponse, ToolDescriptor } from '@/types'

/** Where `useToolDescriptor` stands for the Tool it resolves. */
export type ToolResolution =
  | { status: 'loading' }
  | { status: 'hidden' }
  | { status: 'locked'; payload: LockedToolResponse }
  | { status: 'ready'; descriptor: ToolDescriptor }

/**
 * Resolve a Tool for the current user through `GET /api/tools/{uriKey}`:
 * the check `/tools/{uriKey}` (`ToolPage`) and a registered page bound to a
 * Tool (`routeRegistry`, `tool` key, v2.2.0+) share.
 *
 *  - `404`: the Tool does not exist or its `canSee()` hides it (the API
 *    does not tell the two apart) → `hidden`.
 *  - `{ locked: true, lock, tool }`: a soft lock (v1.11.0+) → `locked`, and
 *    the GateModal opens.
 *  - Any other failure → an error toast; the state stays `loading`.
 *  - A request aborted because the page unmounted → nothing.
 *
 * A `prefilled` descriptor (a caller that already has it) skips the request.
 */
export function useToolDescriptor(uriKey: string | undefined, prefilled?: ToolDescriptor): ToolResolution {
  const { t } = useTranslation('messages')
  const { addToast } = useToast()
  const gate = useGateOptional()
  const [resolution, setResolution] = useState<ToolResolution>(
    prefilled ? { status: 'ready', descriptor: prefilled } : { status: 'loading' },
  )

  useEffect(() => {
    if (!uriKey || prefilled) return

    let cancelled = false
    const ac = new AbortController()

    api
      .get<ToolDescriptor | LockedToolResponse>(`/api/tools/${encodeURIComponent(uriKey)}`, ac.signal)
      .then((data) => {
        if (cancelled) return
        if ('locked' in data && data.locked === true) {
          setResolution({ status: 'locked', payload: data })
          if (gate !== null) gate.open(data.lock)
          return
        }
        setResolution({ status: 'ready', descriptor: data as ToolDescriptor })
      })
      .catch((e: unknown) => {
        if (cancelled) return
        // An unmount (a remount wrapper keyed on the path or the tool) aborts
        // the fetch: expected teardown, not a user-facing failure.
        if (ac.signal.aborted || (e instanceof DOMException && e.name === 'AbortError')) return
        if (e instanceof ApiError && e.status === 404) {
          setResolution({ status: 'hidden' })
          return
        }
        const message = e instanceof ApiError ? e.errorSummary() : t('tool_load_failed', 'Could not load this tool.')
        addToast('error', message)
      })

    return () => {
      cancelled = true
      ac.abort()
    }
  }, [uriKey, prefilled, addToast, t, gate])

  return resolution
}
