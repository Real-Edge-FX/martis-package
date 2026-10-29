import { useCallback } from 'react'
import { useQueryClient } from '@tanstack/react-query'

/** What `useRefreshRecord()` returns: refresh one record, or a whole resource. */
export type RefreshRecord = (resourceKey: string, id?: string | number) => void

/**
 * Refresh what the SPA shows of a record after a custom component changed it
 * (a field that ran an action, saved through its own request, ...).
 *
 * Invalidates the detail query (`['resource', key, id]`, and the edit form's
 * `[..., 'update']` below it, the keys the detail page and the drawers read)
 * and the resource's index queries (`['resources', key]`), so open lists and
 * panels refetch. Without an `id` every detail query of the resource goes.
 * Nova has no core equivalent; this is Martis's way.
 */
export function useRefreshRecord(): RefreshRecord {
  const qc = useQueryClient()
  return useCallback(
    (resourceKey, id) => {
      // Compared as strings: a route param is a string, a row id may be a number.
      void qc.invalidateQueries({
        predicate: ({ queryKey }) =>
          queryKey[0] === 'resource' &&
          queryKey[1] === resourceKey &&
          (id === undefined || String(queryKey[2]) === String(id)),
      })
      void qc.invalidateQueries({ queryKey: ['resources', resourceKey] })
    },
    [qc],
  )
}
