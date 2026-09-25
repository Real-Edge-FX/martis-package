import { useCallback, useMemo } from 'react'
import type { FieldDefinition } from '@/types'

/**
 * The parent form draft a many-to-many attach modal forwards (v1.8.2): the
 * unsaved values of the sibling fields a `BelongsToMany` / `MorphToMany`
 * declares with `dependsOn([...])` (`field.dependsOn.fields`), sent as
 * `form[attribute]=value` so a 3-argument `relatableQueryUsing()` closure
 * can filter on them. The attachable list and the attach both send it: the
 * attach checks the picked records against the query the picker ran.
 *
 * `snapshot` belongs in the attachable query's key, so the picker refetches
 * when a dependent value changes; `appendFormDraft` adds the draft to a
 * request's query string.
 */
export function useAttachFormDraft(field: FieldDefinition | undefined, formValues: Record<string, unknown> | undefined) {
  const dependentFieldNames = useMemo<string[]>(() => {
    const meta = (field as { dependsOn?: { fields?: string[] } } | undefined)?.dependsOn
    return Array.isArray(meta?.fields) ? meta.fields : []
  }, [field])

  const snapshot = useMemo(() => {
    if (dependentFieldNames.length === 0 || !formValues) return {}
    const out: Record<string, unknown> = {}
    for (const name of dependentFieldNames) {
      if (Object.prototype.hasOwnProperty.call(formValues, name)) {
        out[name] = formValues[name]
      }
    }
    return out
  }, [dependentFieldNames, formValues])

  const appendFormDraft = useCallback((params: URLSearchParams) => {
    for (const [k, v] of Object.entries(snapshot)) {
      if (v === null || v === undefined) continue
      if (typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean') {
        params.set(`form[${k}]`, String(v))
      }
    }
  }, [snapshot])

  return { snapshot, appendFormDraft }
}

/** `path` with the draft appended as a query string, when there is one. */
export function withFormDraft(path: string, appendFormDraft: (params: URLSearchParams) => void): string {
  const params = new URLSearchParams()
  appendFormDraft(params)
  const query = params.toString()
  return query ? `${path}?${query}` : path
}
