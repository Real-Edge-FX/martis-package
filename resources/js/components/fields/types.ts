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
}
