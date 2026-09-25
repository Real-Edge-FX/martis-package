import { useEffect, useRef, useState } from 'react'
import { api } from '@/lib/api'
import { repeaterRowQuery, withQuery } from '@/lib/relatableEndpoint'
import type { RepeaterRowScope } from '@/components/fields/types'

/** One option as the field-options endpoint returns it, value coerced to string. */
export interface RemoteSelectOption {
  label: string
  value: string
  /** Group heading, when the server returns grouped options. */
  group?: string
}

export interface UseRemoteSelectOptionsArgs {
  /** From `remoteOptionsEndpoint()`; `null` disables the hook entirely. */
  endpoint: string | null
  /** Whether the option panel is open. Nothing is fetched while closed. */
  open: boolean
  /** Current search term, raw. Trimmed before it is sent. */
  term: string
}

export interface UseRemoteSelectOptionsResult {
  /** `null` until the first response of the current open cycle arrives. */
  options: RemoteSelectOption[] | null
  loading: boolean
  error: boolean
}

/** Typing pause before a term is sent to the server. Matches BelongsTo. */
export const REMOTE_SELECT_DEBOUNCE_MS = 300

interface FieldOptionsEnvelope {
  data?: { options?: { label: string; value: string | number; group?: string }[] }
}

/**
 * Endpoint that backs `Select::searchOptionsUsing()` for the form's scope,
 * or `null` when the form is bound to neither a Resource nor a Tool (the
 * select then filters locally, the way `dependsOn` degrades offline).
 * `toolKey` wins over `resourceKey`: the Tool is who declared the field.
 * In the update context the record id travels along so the server can
 * bind the record before running the update policy. A select in a
 * Repeater row names the row (`repeater` + `repeatable`), where the server
 * finds the field.
 */
export function remoteOptionsEndpoint(
  attribute: string,
  scope: {
    resourceKey?: string
    toolKey?: string
    context?: 'create' | 'update'
    recordId?: string | number
    repeaterRow?: RepeaterRowScope
  },
): string | null {
  const attr = encodeURIComponent(attribute)
  const row = scope.repeaterRow ? repeaterRowQuery(scope.repeaterRow) : ''
  if (scope.toolKey) {
    return withQuery(`/api/tools/${encodeURIComponent(scope.toolKey)}/fields/${attr}/options`, row)
  }
  if (scope.resourceKey) {
    const context = scope.context ?? 'create'
    const id = context === 'update' && scope.recordId != null ? `&id=${encodeURIComponent(String(scope.recordId))}` : ''
    return withQuery(`/api/resources/${encodeURIComponent(scope.resourceKey)}/fields/${attr}/options?context=${context}${id}`, row)
  }
  return null
}

/**
 * Server-side option search for a Select: fetches once when the panel
 * opens (empty term), debounces typing, aborts the previous request when
 * a newer one starts, and resets when the panel closes.
 */
export function useRemoteSelectOptions({ endpoint, open, term }: UseRemoteSelectOptionsArgs): UseRemoteSelectOptionsResult {
  const [options, setOptions] = useState<RemoteSelectOption[] | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(false)
  const abortRef = useRef<AbortController | null>(null)

  useEffect(() => {
    if (endpoint === null || !open) {
      abortRef.current?.abort()
      abortRef.current = null
      setOptions(null)
      setLoading(false)
      setError(false)
      return
    }

    // The panel just opened: ask at once. Typing: wait for a pause.
    const delay = term === '' ? 0 : REMOTE_SELECT_DEBOUNCE_MS
    const timer = setTimeout(() => {
      abortRef.current?.abort()
      const controller = new AbortController()
      abortRef.current = controller
      setLoading(true)

      const separator = endpoint.includes('?') ? '&' : '?'
      api.get<FieldOptionsEnvelope>(`${endpoint}${separator}search=${encodeURIComponent(term.trim())}`, controller.signal)
        .then((res) => {
          if (controller.signal.aborted) return
          setOptions((res.data?.options ?? []).map((o) => ({
            label: o.label,
            value: String(o.value),
            ...(o.group ? { group: o.group } : {}),
          })))
          setError(false)
          setLoading(false)
        })
        .catch(() => {
          if (controller.signal.aborted) return
          setOptions([])
          setError(true)
          setLoading(false)
        })
    }, delay)

    return () => clearTimeout(timer)
  }, [endpoint, open, term])

  // Never let a response land on an unmounted field.
  useEffect(() => () => abortRef.current?.abort(), [])

  return { options, loading, error }
}
