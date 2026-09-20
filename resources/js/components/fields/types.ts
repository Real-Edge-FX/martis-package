import type { FieldDefinition } from '@/types'

/** Shared props for every field renderer. */
export interface FieldDisplayProps {
  field: FieldDefinition
  value: unknown
}

/** Props for renderers used inside a form context (create/update). */
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
  /** The form context the input renders in. Server-scoped fields send it so the backend looks the field up in the matching field set. */
  context?: 'create' | 'update'
  /**
   * The Tool URI key when the form is scoped to a Tool implementing
   * `ProvidesFields`. Routes server-backed field behaviours (remote Select
   * search) at `/api/tools/{toolKey}/...`. When both `toolKey` and
   * `resourceKey` are set, `toolKey` owns the option search (the Tool is
   * who declared the field) and `resourceKey` keeps scoping slug, relatable
   * and dependsOn calls.
   */
  toolKey?: string
}
