import type { FieldDefinition } from '@/types'

/** Props compartilhados entre todos os renderers de field. */
export interface FieldDisplayProps {
  field: FieldDefinition
  value: unknown
}

/** Props para renderers em contexto de formulário (create/update). */
export interface FieldInputProps {
  field: FieldDefinition
  value: unknown
  onChange: (value: unknown) => void
  error?: string
  /** The resource URI key (e.g. 'posts') — used by relatable fields to build the correct API endpoint. */
  resourceKey?: string
  /** The record ID being edited — used by relatable fields for contextual relatable queries. */
  recordId?: string | number
  /** All current form values. Fields that need to react to other fields (e.g. Slug source, dependsOn) read this. */
  formValues?: Record<string, unknown>
}
