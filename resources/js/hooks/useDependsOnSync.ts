import { useEffect, useMemo, useRef, useState } from 'react'
import { api } from '@/lib/api'
import type { FieldDefinition } from '@/types'

/**
 * Watch sibling fields declared via `Field::dependsOn(...)` and sync the
 * dependent field state with the server every time one of the watched
 * values changes.
 *
 * Returns a `Map<attribute, FieldDefinition>` of resolved overrides that
 * the form should layer on top of the static schema. Fields without
 * `dependsOn` (or whose watched siblings have not been touched) are not
 * present in the map; the caller falls back to the static descriptor.
 *
 * Implementation notes:
 * - We debounce by 200ms so a fast typist does not trigger one POST per
 *   keystroke. The trailing edge wins.
 * - Each request carries its own AbortController; if a newer change
 *   lands while a request is in flight, we cancel the older one. This
 *   keeps results monotonic — the last value wins, even if responses
 *   arrive out of order.
 * - Errors are swallowed silently. The static descriptor remains the
 *   fallback, so a transient network blip never wedges the form.
 */
export interface UseDependsOnSyncArgs {
  /** Resource URI key — `'projects'`, `'clients'`, etc. */
  resource: string
  /** Either `'create'` or `'update'`. Sets the server-side context. */
  context: 'create' | 'update'
  /** Static field set (already flattened from layout containers). */
  fields: FieldDefinition[]
  /** Live form payload, keyed by field attribute. */
  formValues: Record<string, unknown>
  /** Disable the hook entirely (e.g. while the schema is loading). */
  disabled?: boolean
}

export function useDependsOnSync({
  resource,
  context,
  fields,
  formValues,
  disabled,
}: UseDependsOnSyncArgs): Map<string, FieldDefinition> {
  const [overrides, setOverrides] = useState<Map<string, FieldDefinition>>(() => new Map())

  // Build the watch list once per schema. A field reacts only when its
  // own watched-siblings actually change; we collect the (attribute,
  // watchedFields) pairs up front so the effect below can skip work.
  const reactiveDescriptors = useMemo(() => {
    const out: Array<{ attribute: string; watched: string[] }> = []
    for (const f of fields) {
      const watched = f.dependsOn?.fields
      if (Array.isArray(watched) && watched.length > 0) {
        out.push({ attribute: f.attribute, watched })
      }
    }
    return out
  }, [fields])

  // Snapshot the watched values per reactive field. We re-run the effect
  // only when this snapshot string changes — keeps render-time work O(1)
  // and avoids posting on irrelevant edits.
  const watchedSnapshot = useMemo(() => {
    const parts: string[] = []
    for (const { attribute, watched } of reactiveDescriptors) {
      const slice: Record<string, unknown> = {}
      for (const w of watched) {
        slice[w] = formValues[w]
      }
      parts.push(`${attribute}:${JSON.stringify(slice)}`)
    }
    return parts.join('|')
  }, [reactiveDescriptors, formValues])

  // Hold the latest abort controllers per attribute so we can cancel
  // stale in-flight requests when the user keeps typing.
  const inflight = useRef<Map<string, AbortController>>(new Map())

  useEffect(() => {
    if (disabled || reactiveDescriptors.length === 0) return

    // 200ms debounce — leading-edge skip + trailing-edge fire is the
    // standard "don't spam while typing" cadence the rest of the app
    // uses for filter/search inputs.
    const handle = window.setTimeout(() => {
      for (const { attribute } of reactiveDescriptors) {
        // Cancel any prior in-flight request for this attribute.
        inflight.current.get(attribute)?.abort()
        const ac = new AbortController()
        inflight.current.set(attribute, ac)

        // Fire and forget. The handler updates the override map only
        // when the response is for the latest request (we re-check the
        // map identity to avoid clobbering an even-newer override).
        void api
          .post<FieldDefinition>(
            `/api/resources/${resource}/sync-field`,
            { field: attribute, formData: formValues, context },
            ac.signal,
          )
          .then((res) => {
            if (inflight.current.get(attribute) !== ac) return
            setOverrides((prev) => {
              const next = new Map(prev)
              next.set(attribute, res)
              return next
            })
          })
          .catch(() => {
            // AbortError or transient failure — fall back to the
            // static descriptor by leaving the override slot unchanged.
          })
      }
    }, 200)

    return () => {
      window.clearTimeout(handle)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [watchedSnapshot, disabled, resource, context])

  // Cancel everything on unmount so requests do not leak past the page.
  useEffect(() => {
    return () => {
      for (const ac of inflight.current.values()) ac.abort()
      inflight.current.clear()
    }
  }, [])

  return overrides
}
