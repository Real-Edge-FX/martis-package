import { useMemo, useState } from 'react'
import type { FieldDefinition } from '@/types'
import { useDependsOnSync } from '@/hooks/useDependsOnSync'

export interface MartisFormOptions {
  fields: FieldDefinition[]
  initialValues?: Record<string, unknown>
  resourceKey?: string
  context?: 'create' | 'update'
  /**
   * Id of the record this form edits, when bound to one. Threaded to fields so
   * relatable behaviours (BelongsTo / MorphTo / Tag search + peek) scope their
   * queries to the right record. Resource pages pass the route id; Tools binding
   * to an existing record (Mode C) pass it explicitly. Omit for create forms.
   */
  recordId?: string | number
}

export interface MartisForm {
  values: Record<string, unknown>
  setValue: (attribute: string, value: unknown) => void
  setValues: (v: Record<string, unknown>) => void
  errors: Record<string, string>
  setErrors: (e: Record<string, string>) => void
  resolvedFields: FieldDefinition[]
  recordId?: string | number
  fieldProps: (field: FieldDefinition) => {
    field: FieldDefinition
    value: unknown
    onChange: (v: unknown) => void
    error?: string
    resourceKey?: string
    recordId?: string | number
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
  const { fields, initialValues, resourceKey, context = 'create', recordId } = options
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
    fields: flatFields,
    formValues: values,
    disabled: !resourceKey, // no server round-trip when there is no scope
  })

  const resolvedFields = useMemo(() => {
    if (overrides.size === 0) return fields
    return applyOverrides(fields, overrides) as FieldDefinition[]
  }, [fields, overrides])

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
    error: errors[field.attribute],
    resourceKey,
    recordId,
    formValues: values,
  })

  return { values, setValue, setValues, errors, setErrors, resolvedFields, recordId, fieldProps }
}
