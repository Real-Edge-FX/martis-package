import { useMemo, useState } from 'react'
import type { FieldDefinition } from '@/types'
import { useDependsOnSync } from '@/hooks/useDependsOnSync'
import { lockImmutableFields } from '@/lib/lockImmutableFields'
import { fieldErrorProps } from '@/lib/fieldErrors'

export interface MartisFormOptions {
  fields: FieldDefinition[]
  initialValues?: Record<string, unknown>
  resourceKey?: string
  /**
   * URI key of the Tool that declared these fields (Mode B, `useToolFields`).
   * Scopes server-backed field behaviours that a Tool can own, today the
   * remote Select search, at `/api/tools/{toolKey}/...`. `dependsOn` sync
   * still needs a `resourceKey`.
   */
  toolKey?: string
  /** Defaults to `'create'`. On `'update'`, `resolvedFields` carries `immutable()` fields as `readonly`. */
  context?: 'create' | 'update'
  /**
   * Id of the record this form edits, when bound to one. Threaded to fields so
   * relatable behaviours (BelongsTo / MorphTo / Tag search + peek) scope their
   * queries to the right record. Resource pages pass the route id; Tools binding
   * to an existing record (Mode C) pass it explicitly. Omit for create forms.
   */
  recordId?: string | number
  /**
   * Suppress the server-side `dependsOn` sync while the form is not yet ready
   * (e.g. an update form whose record has not hydrated). When true, no
   * `sync-field` round-trip fires — mirroring the Resource update page's
   * `disabled: !initialized` gate, so an override is never computed from
   * empty form data on mount. Defaults to false. (A form with no `resourceKey`
   * never syncs regardless.)
   */
  syncDisabled?: boolean
}

export interface MartisForm {
  values: Record<string, unknown>
  setValue: (attribute: string, value: unknown) => void
  setValues: (v: Record<string, unknown>) => void
  /**
   * One message per validated path, as `ApiError.errorsByField()` returns a
   * 422: a field's own error under its attribute, the error of a value inside
   * a field's value under its dotted path (`lines.1.fields.name`).
   */
  errors: Record<string, string>
  setErrors: (e: Record<string, string>) => void
  resolvedFields: FieldDefinition[]
  resourceKey?: string
  toolKey?: string
  recordId?: string | number
  fieldProps: (field: FieldDefinition) => {
    field: FieldDefinition
    value: unknown
    onChange: (v: unknown) => void
    error?: string
    /** The errors inside the field's value (a Repeater's rows), keyed by their path below the attribute. */
    nestedErrors?: Record<string, string>
    resourceKey?: string
    recordId?: string | number
    toolKey?: string
    formValues: Record<string, unknown>
  }
}

/**
 * Layout containers (`tab_group` / `section` / `panel`) nest their child
 * fields under different keys: `section`/`panel` hold `fields`, and
 * `tab_group` holds `tabs[].fields` (each tab's `fields` may itself contain a
 * nested `panel`/`section`). These two walkers keep the dependsOn machinery
 * container-aware — mirroring the pre-refactor ResourceCreate page (see
 * `git show 722bf5f69:.../ResourceCreate.tsx` ~L106-159).
 */

/** Depth-first collect every leaf field, descending through all containers. */
function flattenFields(items: readonly unknown[]): FieldDefinition[] {
  const out: FieldDefinition[] = []
  const walk = (list: readonly unknown[]): void => {
    for (const item of list) {
      const f = item as Record<string, unknown>
      if (f.type === 'panel' || f.type === 'section') {
        walk((f.fields as unknown[]) ?? [])
      } else if (f.type === 'tab_group') {
        for (const tab of (f.tabs as { fields?: unknown[] }[]) ?? []) walk(tab.fields ?? [])
      } else {
        out.push(item as FieldDefinition)
      }
    }
  }
  walk(items)
  return out
}

/**
 * Rebuild the field tree, applying each leaf's override (if any) at every
 * level — top-level AND inside containers — preserving container shape so the
 * renderers still receive `section.fields` / `tab_group.tabs[].fields`.
 */
function applyOverrides(
  items: readonly unknown[],
  overrides: Map<string, FieldDefinition>,
): unknown[] {
  const walk = (list: readonly unknown[]): unknown[] =>
    list.map((item) => {
      const f = item as Record<string, unknown>
      if (f.type === 'panel' || f.type === 'section') {
        return { ...f, fields: walk((f.fields as unknown[]) ?? []) }
      }
      if (f.type === 'tab_group') {
        const tabs = ((f.tabs as { fields?: unknown[] }[]) ?? []).map((tab) => ({
          ...tab,
          fields: walk(tab.fields ?? []),
        }))
        return { ...f, tabs }
      }
      const field = item as FieldDefinition
      const o = overrides.get(field.attribute)
      return o ? ({ ...field, ...o } as FieldDefinition) : field
    })
  return walk(items)
}

export function useMartisForm(options: MartisFormOptions): MartisForm {
  const { fields, initialValues, resourceKey, toolKey, context = 'create', recordId, syncDisabled } = options
  const [values, setValues] = useState<Record<string, unknown>>(initialValues ?? {})
  const [errors, setErrors] = useState<Record<string, string>>({})

  // Flatten the (possibly container-nested) field tree so `useDependsOnSync`
  // tracks the `dependsOn` of EVERY field, not only the top-level ones. The
  // renderer re-applies overrides through the container tree below.
  const flatFields = useMemo(() => flattenFields(fields), [fields])

  // Reuse the exact dependsOn machinery the Resource pages use. Pass a
  // synthetic resource key ('_') when the form is not bound to a resource so
  // the server-side dependsOn endpoint has a scope; overrides simply stay empty.
  const overrides = useDependsOnSync({
    resource: resourceKey ?? '_',
    context,
    recordId,
    fields: flatFields,
    formValues: values,
    // No server round-trip when there is no scope, or while the caller marks
    // the form not-ready (e.g. update form before the record hydrates).
    disabled: !resourceKey || Boolean(syncDisabled),
  })

  // An update form renders an `immutable()` field read-only (every update
  // endpoint skips it). Applied after the dependsOn overrides, which carry
  // the flag the way the schema does.
  const resolvedFields = useMemo(() => {
    const resolved = overrides.size === 0 ? fields : (applyOverrides(fields, overrides) as FieldDefinition[])
    return context === 'update' ? lockImmutableFields(resolved) : resolved
  }, [fields, overrides, context])

  const setValue = (attribute: string, value: unknown) => {
    setValues((prev) => ({ ...prev, [attribute]: value }))
    // Clear this field's error on edit — restores the per-keystroke error
    // clearing the pre-refactor page did (see old handleChange ~L337).
    setErrors((prev) => (prev[attribute] ? { ...prev, [attribute]: '' } : prev))
  }

  const fieldProps = (field: FieldDefinition) => ({
    field,
    value: values[field.attribute] ?? null,
    onChange: (v: unknown) => setValue(field.attribute, v),
    ...fieldErrorProps(errors, field.attribute),
    resourceKey,
    recordId,
    toolKey,
    formValues: values,
  })

  return { values, setValue, setValues, errors, setErrors, resolvedFields, resourceKey, toolKey, recordId, fieldProps }
}
