import { useMemo, useState } from 'react'
import type { FieldDefinition } from '@/types'
import { useDependsOnSync } from '@/hooks/useDependsOnSync'

export interface MartisFormOptions {
  fields: FieldDefinition[]
  initialValues?: Record<string, unknown>
  resourceKey?: string
  context?: 'create' | 'update'
}

export interface MartisForm {
  values: Record<string, unknown>
  setValue: (attribute: string, value: unknown) => void
  setValues: (v: Record<string, unknown>) => void
  errors: Record<string, string>
  setErrors: (e: Record<string, string>) => void
  resolvedFields: FieldDefinition[]
  fieldProps: (field: FieldDefinition) => {
    field: FieldDefinition
    value: unknown
    onChange: (v: unknown) => void
    error?: string
    resourceKey?: string
    formValues: Record<string, unknown>
  }
}

export function useMartisForm(options: MartisFormOptions): MartisForm {
  const { fields, initialValues, resourceKey, context = 'create' } = options
  const [values, setValues] = useState<Record<string, unknown>>(initialValues ?? {})
  const [errors, setErrors] = useState<Record<string, string>>({})

  // Reuse the exact dependsOn machinery the Resource pages use. Pass a
  // synthetic resource key ('_') when the form is not bound to a resource so
  // the server-side dependsOn endpoint has a scope; overrides simply stay empty.
  const overrides = useDependsOnSync({
    resource: resourceKey ?? '_',
    context,
    fields,
    formValues: values,
    disabled: !resourceKey, // no server round-trip when there is no scope
  })

  const resolvedFields = useMemo(() => {
    if (overrides.size === 0) return fields
    return fields.map((f) => {
      const o = overrides.get(f.attribute)
      return o ? ({ ...f, ...o } as FieldDefinition) : f
    })
  }, [fields, overrides])

  const setValue = (attribute: string, value: unknown) =>
    setValues((prev) => ({ ...prev, [attribute]: value }))

  const fieldProps = (field: FieldDefinition) => ({
    field,
    value: values[field.attribute] ?? null,
    onChange: (v: unknown) => setValue(field.attribute, v),
    error: errors[field.attribute],
    resourceKey,
    formValues: values,
  })

  return { values, setValue, setValues, errors, setErrors, resolvedFields, fieldProps }
}
